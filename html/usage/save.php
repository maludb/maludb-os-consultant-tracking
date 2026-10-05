<?php
declare(strict_types=1);
/**
 * Action `usage_charge_draft` (log `usage_charge.draft`: number, kind, provider, quantity, unit, cost_amount, billable_amount, currency, source; `usage_charge.attach` when the provider's invoice came): usage.write + manage of the
 * engagement. A new charge is a draft by hand; `charge` changes a draft (an approved one is locked by the database, in words). The unit cost is what is set — the database writes the cost from quantity × unit cost and the
 * billable amount from the pricing. A charge the OS statement made keeps its cost, quantity, period and provider (only its pricing, billable flag and description change). The provider's invoice (`file`, multipart) is stored in the same transaction.
 */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = usage_id_from_request();
$cur = null;
if ($id !== null) {
    $cur = find_usage_charge($pdo, $id) ?? refuse(404, 'Usage charge not found.');
    usage_engagement_gate($pdo, $cur['engagement_id']);
}
$errors = [];
$engField = req_has('engagement') ? 'engagement' : 'engagement_id';
$engagement = ct_ref($pdo, $engField, $cur['engagement_id'] ?? null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
if ($engagement === null && !isset($errors[$engField])) {
    $errors['engagement'] = 'Choose the engagement the charge is on.';
}
if ($engagement !== null && ($cur === null || $engagement !== $cur['engagement_id'])) {
    usage_engagement_gate($pdo, $engagement);
}
$kernel = $cur !== null && $cur['source'] === 'kernel_ledger';
$kind = $cur['kind'] ?? null;
if (!$kernel && (req_has('kind') || $cur === null)) {
    $kind = (string) req_val('kind');
    if (!isset(USAGE_KINDS[$kind])) {
        $errors['kind'] = 'The kind is model_usage, api, hosting, storage or other.';
    }
}
$provField = req_has('provider') ? 'provider' : 'provider_id';
$provider = $kernel ? $cur['provider_id'] : ct_ref($pdo, $provField, $cur['provider_id'] ?? null, 'SELECT 1 FROM usage_providers WHERE id = :id AND archived_at IS NULL', 'provider', $errors, true);
$description = $cur['description'] ?? null;
if (req_has('description') || $cur === null) {
    $description = mb_substr(trim((string) req_val('description')), 0, 500);
    if ($description === '') {
        $errors['description'] = 'Say what it is (a few words).';
    }
}
$ps = $kernel ? $cur['period_start'] : ct_date('period_start', $cur['period_start'] ?? null, 'The period start', $errors);
$pe = $kernel ? $cur['period_end'] : ct_date('period_end', $cur['period_end'] ?? null, 'The period end', $errors);
if ($ps === null && !isset($errors['period_start'])) {
    $errors['period_start'] = 'The first day of the period the charge covers (2026-09-01).';
}
if ($cur === null && $ps !== null && !req_has('period_end') && !isset($errors['period_end'])) {
    $pe = $ps;                                              // a one-day charge: the end is the start
}
if (!isset($errors['period_start']) && !isset($errors['period_end']) && $ps !== null && $pe !== null && $pe < $ps) {
    $errors['period_end'] = 'The period ends on or after the day it starts.';
}
$unit = $cur['unit'] ?? 'units';
if (!$kernel && req_has('unit')) {
    $unit = (string) req_val('unit');
    if (!isset(USAGE_UNITS[$unit])) {
        $errors['unit'] = 'The unit is tokens, calls, gb, gb_month, vm_hours, months or units.';
    }
}
$quantity = $kernel ? $cur['quantity'] : ct_decimal('quantity', $cur['quantity'] ?? '1.0000', 0, 99999999999999, 'The quantity', $errors, false, 4);
$unitCost = $kernel ? $cur['unit_cost'] : ct_decimal('unit_cost', $cur['unit_cost'] ?? '0.000000', 0, 99999999, 'The unit cost', $errors, $cur === null, 6);
$unitCost ??= '0.000000';
[$pricing, $markup, $resale] = ct_pricing_fields($cur, $errors);
$billable = $cur['billable'] ?? true;
if (req_has('billable')) {
    $billable = ct_yes('billable');
}
$file = null;
if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['file'];
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['engagement_id' => $engagement, 'kind' => $kind, 'provider_id' => $provider, 'description' => $description, 'period_start' => $ps, 'period_end' => $pe, 'quantity' => $quantity, 'unit' => $unit, 'unit_cost' => $unitCost,
      'pricing' => $pricing, 'markup_pct' => $markup, 'resale_unit_price' => $resale, 'billable' => $billable];
$loggable = static fn (array $u): array => ['engagement_id' => (int) $u['engagement_id'], 'kind' => $u['kind'], 'provider_id' => $u['provider_id'] === null ? null : (int) $u['provider_id'], 'period_start' => $u['period_start'], 'period_end' => $u['period_end'],
    'quantity' => $u['quantity'], 'unit' => $u['unit'], 'unit_cost' => $u['unit_cost'], 'cost_amount' => $u['cost_amount'], 'pricing' => $u['pricing'], 'markup_pct' => $u['markup_pct'], 'resale_unit_price' => $u['resale_unit_price'],
    'billable_amount' => $u['billable_amount'], 'billable' => (bool) $u['billable']];
$out = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f, $file, $loggable): array {
    $pdo->beginTransaction();
    try {
        $newId = save_usage_charge($pdo, $id, $f, $me, $file);
        $row = $pdo->prepare('SELECT id AS usage_charge_id, number, engagement_id, kind, provider_id, period_start, period_end, quantity, unit, unit_cost, cost_amount, pricing, markup_pct, resale_unit_price, billable_amount, billable, currency, source, status FROM usage_charges WHERE id = :id');
        $row->execute(['id' => $newId]);
        $after = $row->fetch();
        $after['usage_charge_id'] = (int) $after['usage_charge_id'];
        $after['engagement_id'] = (int) $after['engagement_id'];
        $money = ['number' => $after['number'], 'amount' => $after['billable_amount'], 'currency' => trim((string) $after['currency']), 'source' => $after['source'], 'kind' => $after['kind']];
        if ($cur === null) {
            usage_log($pdo, 'usage_charge.draft', $newId, $after['engagement_id'], ['after' => $loggable($after) + $money + ['recorded_by' => $me]]);
            if ($after['source'] === 'manual') {
                notify_usage_drafted($pdo, $after + ['usage_charge_id' => $newId], $me, 'by ' . (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $me]));
            }
        } else {
            $d = ct_diff($loggable($cur + ['unit_cost' => $cur['unit_cost'], 'cost_amount' => $cur['cost_amount']]), $loggable($after));
            if ($d['after'] !== [] || $cur['description'] !== $f['description']) {
                usage_log($pdo, 'usage_charge.draft', $newId, $after['engagement_id'], ['before' => $d['before'], 'after' => $d['after'] + $money + ['recorded_by' => $me]]);
            }
        }
        if ($file !== null) {
            $att = $pdo->prepare("SELECT filename, byte_size, mime_type FROM attachments WHERE record_type = 'usage_charge' AND record_id = :id ORDER BY id DESC LIMIT 1");
            $att->execute(['id' => $newId]);
            $a = $att->fetch();
            usage_log($pdo, 'usage_charge.attach', $newId, $after['engagement_id'], ['after' => ['number' => $after['number'], 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'mime_type' => $a['mime_type']]]);
        }
        $pdo->commit();
        usage_stored_files(null, true);
        return [$newId, $after];
    } catch (Throwable $t) {
        usage_discard_stored_files();
        throw $t;
    }
});
[$newId, $after] = $out;
$label = $after['number'] . ' (' . fmt_money($after['billable_amount'], trim((string) $after['currency'])) . ')';
ct_done(($id === null ? 'Drafted ' : 'Changed ') . $label, $newId, ct_land(return_path('/usage/' . $newId), 'saved'), 'usageChanged',
    ['usage_charge_id' => $newId, 'number' => $after['number'], 'status' => $after['status'], 'engagement_id' => $after['engagement_id'], 'billable_amount' => $after['billable_amount'], 'cost_amount' => $after['cost_amount']]);
