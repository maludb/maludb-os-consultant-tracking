<?php
declare(strict_types=1);
/**
 * /usage/import?provider=&engagement= (screen `usage-import`) — step 1 the file and the provider (its saved mapping prefilled); POST `usage_import` (log `usage_import.run`: provider, engagement, file_name, rows, inserted, skipped, refused,
 * group_by_month, mapping_saved): with `preview=1` step 2 (the mapping and a five-row preview with the duplicates counted, the file kept for the next step), else step 3 the import — draft charges, one per row or grouped by month. usage.write +
 * manage. A JSON caller sends the file (`file`, multipart) and `mapping` (JSON; the provider's saved one by default) in one POST. The file is not kept after the import.
 */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
$pdo = db();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost) {
    ct_handler_begin();
}
require_right('usage.write');
if (!wants_json()) {
    require_human();
}
$me = (int) current_member_id();
$providers = usage_providers_for_pick($pdo);
$engagements = usage_engagements($pdo);

/** Render the page at a step with what it needs (a browser); the JSON preview answers the same facts. */
$show = static function (array $data, int $status = 200) use ($pdo, $providers, $engagements): never {
    log_screen_view($pdo, 'usage-import');
    if ($status !== 200) {
        http_response_code($status);
    }
    render_screen('Import usage', view('usage/import.php', $data + ['providers' => $providers, 'engagements' => $engagements, 'recent' => find_usage_imports($pdo, 5), 'fields' => CSV_FIELDS, 'errors' => [], 'step' => 1, 'plan' => null,
        'preview' => null, 'mapping' => null, 'token' => '', 'provider' => null, 'engagement' => null, 'fileName' => '', 'byMonth' => false, 'saveMapping' => false, 'here' => here_url()]), ['activeNav' => 'usage', 'screen' => 'usage-import', 'entity' => 'usage_import']);
    exit;
};
$providerPick = static function (?int $id) use ($providers): ?array {
    foreach ($providers as $p) {
        if ($p['provider_id'] === $id) {
            return $p;
        }
    }
    return null;
};

if (!$isPost) {
    $prov = $providerPick(request_integer('provider'));
    $eng = request_integer('engagement');
    if (wants_json()) {
        log_screen_view($pdo, 'usage-import');
        respond_screen(['providers' => array_map(static fn (array $p): array => ['provider_id' => $p['provider_id'], 'provider_key' => $p['provider_key'], 'name' => $p['name'], 'csv_mapping' => $p['csv_mapping']], $providers),
            'engagements' => $engagements, 'fields' => CSV_FIELDS, 'shipped' => array_keys(SHIPPED_CSV_MAPPINGS), 'recent' => array_map('present_usage_import', find_usage_imports($pdo, 10))]);
    }
    $show(['provider' => $prov, 'engagement' => $eng]);
}

// ---- POST: preview or import ----
$errors = [];
$prov = $providerPick(request_integer('provider'));
if ($prov === null) {
    $errors['provider'] = 'Choose the provider whose usage export this is.';
}
$engagementId = ct_ref($pdo, 'engagement', null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
if ($engagementId === null && !isset($errors['engagement'])) {
    $errors['engagement'] = 'Choose the engagement the charges go on.';
}
if ($engagementId !== null) {
    usage_engagement_gate($pdo, $engagementId);
}
$token = (string) req_val('stash');
$path = null;
$name = 'usage.csv';
try {
    if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        csv_unstash($token);
        [$token, $path, $name] = csv_stash($_FILES['file']);
    } elseif ($token !== '' && ($kept = csv_stashed($token)) !== null) {
        [$path, $name] = $kept;
    } else {
        $errors['file'] = 'Choose the provider\'s usage export (a CSV file).';
    }
} catch (DomainException $e) {
    $errors['file'] = $e->getMessage();
}
$byMonth = ct_yes('group_by_month', false);
$saveMapping = ct_yes('save_mapping', false);
$mapping = null;
if (req_has('mapping') && (string) req_val('mapping') !== '') {
    $m = json_decode((string) req_val('mapping'), true);
    if (!is_array($m)) {
        $errors['mapping'] = 'The mapping is a JSON object (column names to fields).';
    } else {
        $mapping = csv_mapping_clean($m);
    }
} elseif (req_has('map_date') || req_has('map_quantity') || req_has('map_cost')) {
    $mapping = csv_mapping_clean(['date' => req_val('map_date'), 'quantity' => req_val('map_quantity'), 'cost' => req_val('map_cost'), 'model' => req_val('map_model'), 'description' => req_val('map_description'),
        'unit' => req_val('map_unit') ?: 'tokens', 'kind' => req_val('map_kind') ?: 'model_usage']);
}
if ($errors === [] && $mapping === null) {
    $mapping = csv_suggest_mapping(csv_headers((string) $path), $prov['csv_mapping'], $prov['provider_key']);
}
$fail = static function (array $errors) use ($show, $prov, $engagementId, $token, $name, $byMonth, $saveMapping, $mapping): never {
    emit_action_status(false, ['errors' => array_values($errors), 'fields' => $errors]);
    if (wants_json()) {
        respond_invalid($errors, $errors);
    }
    $show(['errors' => array_values($errors), 'provider' => $prov, 'engagement' => $engagementId, 'token' => $token, 'fileName' => $name, 'byMonth' => $byMonth, 'saveMapping' => $saveMapping, 'mapping' => $mapping, 'step' => $token !== '' && $mapping !== null ? 2 : 1], 422);
};
if ($errors !== []) {
    $fail($errors);
}
$parsedHeaders = csv_headers((string) $path);
$lowerHeaders = array_map('strtolower', $parsedHeaders);
foreach (['date' => 'date', 'quantity' => 'quantity', 'cost' => 'cost'] as $f => $label) {
    $col = strtolower((string) ($mapping[$f] ?? ''));
    if ($col === '' || !in_array($col, $lowerHeaders, true)) {
        $errors['map_' . $f] = 'Say which column holds the ' . $label . ($col !== '' ? ' (the file has no column "' . $mapping[$f] . '")' : '') . '.';
    }
}
$preview = null;
$plan = null;
if ($errors === []) {
    $preview = csv_preview((string) $path, $mapping, 5);
    $plan = csv_plan($pdo, $prov['provider_id'], $prov['name'], (string) $path, $mapping, $byMonth);
}
$isPreview = ct_yes('preview', false) || $errors !== [];
if ($isPreview) {
    if ($errors !== [] && wants_json()) {
        $fail($errors);
    }
    $counts = $plan === null ? null : csv_plan_counts($plan);
    emit_action_status(true, ['did' => 'Previewed ' . $name, 'refresh' => '']);
    if (wants_json()) {
        respond_screen(['stash' => $token, 'file_name' => $name, 'headers' => $parsedHeaders, 'mapping' => $mapping, 'preview' => $preview, 'plan' => array_map('present_csv_plan_item', $plan ?? []), 'counts' => $counts]);
    }
    $show(['step' => 2, 'errors' => array_values($errors), 'provider' => $prov, 'engagement' => $engagementId, 'token' => $token, 'fileName' => $name, 'byMonth' => $byMonth, 'saveMapping' => $saveMapping, 'mapping' => $mapping,
        'preview' => $preview, 'plan' => $plan, 'headers' => $parsedHeaders, 'counts' => $counts], $errors === [] ? 200 : 422);
}
$out = ct_guard($pdo, static function () use ($pdo, $me, $prov, $engagementId, $path, $name, $mapping, $byMonth, $saveMapping): array {
    $pdo->beginTransaction();
    $r = run_usage_import($pdo, $prov['provider_id'], $engagementId, (string) $path, $name, $mapping, $byMonth, $me);
    if ($saveMapping) {
        save_provider_mapping($pdo, $prov['provider_id'], $mapping);
    }
    log_activity($pdo, 'usage_import.run', 'usage_import', $r['import_id'], ['engagement_id' => $engagementId, 'after' => ['provider_id' => $prov['provider_id'], 'provider' => $prov['provider_key'], 'engagement_id' => $engagementId,
        'file_name' => $name, 'rows' => $r['rows'], 'inserted' => $r['inserted'], 'skipped' => $r['skipped'], 'refused' => $r['refused'], 'group_by_month' => $byMonth, 'mapping_saved' => $saveMapping,
        'charge_ids' => array_slice($r['charge_ids'], 0, 100)]]);
    if ($r['inserted'] > 0) {
        $mgr = usage_manager_of($pdo, $engagementId, $me);
        if ($mgr !== null) {
            queue_usage_notice($pdo, $mgr, 'usage_drafted', 'usage_charge', $r['charge_ids'][0], ['title' => $r['inserted'] . ' usage charge' . ($r['inserted'] === 1 ? '' : 's') . ' drafted from ' . $name, 'body' => 'Open the list to approve or void them: ' . rtrim((string) env('APP_URL', ''), '/') . '/usage/?import=' . $r['import_id'],
                'dedupe' => 'usage-import:' . $r['import_id']]);
        }
    }
    $pdo->commit();
    return $r;
});
csv_unstash($token);
$words = $out['inserted'] . ' draft charge' . ($out['inserted'] === 1 ? '' : 's') . ' made' . ($out['skipped'] > 0 ? ', ' . $out['skipped'] . ' duplicate' . ($out['skipped'] === 1 ? '' : 's') . ' skipped' : '') . ($out['refused'] > 0 ? ', ' . $out['refused'] . ' refused (see the import)' : '');
ct_done('Imported ' . $name . ': ' . $words, $out['import_id'], ct_land(return_path('/usage/?import=' . $out['import_id']), 'imported'), 'usageChanged',
    ['import_id' => $out['import_id'], 'rows' => $out['rows'], 'inserted' => $out['inserted'], 'skipped' => $out['skipped'], 'refused' => $out['refused'], 'charge_ids' => $out['charge_ids']]);
