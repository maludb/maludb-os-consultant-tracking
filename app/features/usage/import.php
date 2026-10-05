<?php
declare(strict_types=1);

/**
 * The provider's CSV usage export (slice 5): the columns mapped once per provider (saved on usage_providers.csv_mapping), a five-row preview with duplicates counted, then draft charges — one per row or
 * grouped by month. The file is not kept: the import row (name, counts, mapping) and its charges are the record. A cost is never written alone: the guard recomputes it from quantity × unit cost, so the
 * unit cost is set from the file's cost, and a row whose cost cannot be written exactly at six decimals of unit cost is refused in words (never a quiet change of the money).
 */

/** The mapping's fields: the column that holds each (case-insensitive), and the two fixed choices. */
const CSV_FIELDS = ['date' => 'Date', 'quantity' => 'Quantity', 'cost' => 'Cost', 'model' => 'Model (optional)', 'description' => 'Description (optional)'];
const CSV_MAX_ROWS = 20000;

/** The suggestions shipped for the providers we know (their exports' usual column names, tried in order); a saved mapping wins. A person confirms the mapping at the preview. */
const SHIPPED_CSV_MAPPINGS = [
    'anthropic' => ['date' => ['usage_date_utc', 'date', 'usage_date', 'day'], 'quantity' => ['total_tokens', 'tokens', 'quantity'], 'cost' => ['cost_usd', 'cost', 'amount_usd', 'amount'], 'model' => ['model', 'model_name'], 'unit' => 'tokens', 'kind' => 'model_usage'],
    'openai' => ['date' => ['date', 'start_time', 'timestamp', 'day'], 'quantity' => ['n_tokens_total', 'total_tokens', 'tokens', 'quantity'], 'cost' => ['cost_usd', 'cost', 'amount', 'amount_usd'], 'model' => ['model', 'snapshot_id'], 'unit' => 'tokens', 'kind' => 'model_usage'],
];

/** A mapping from the form or a provider's saved JSON, with only the known keys: fields → column names; unit and kind → fixed choices. */
function csv_mapping_clean(array $m): array
{
    $out = [];
    foreach (array_keys(CSV_FIELDS) as $k) {
        $v = trim((string) ($m[$k] ?? ''));
        if ($v !== '') {
            $out[$k] = mb_substr($v, 0, 120);
        }
    }
    $unit = (string) ($m['unit'] ?? 'tokens');
    $out['unit'] = isset(USAGE_UNITS[$unit]) ? $unit : 'tokens';
    $kind = (string) ($m['kind'] ?? 'model_usage');
    $out['kind'] = in_array($kind, ['model_usage', 'api'], true) ? $kind : 'model_usage';
    return $out;
}

/** The suggested mapping for a provider's file: its saved one, else the shipped one matched against the file's headers, else empty fields. */
function csv_suggest_mapping(array $headers, ?array $saved, ?string $providerKey): array
{
    if ($saved !== null && $saved !== []) {
        return csv_mapping_clean($saved);
    }
    $shipped = SHIPPED_CSV_MAPPINGS[$providerKey ?? ''] ?? null;
    $lower = array_map(static fn (string $h): string => strtolower(trim($h)), $headers);
    $out = ['unit' => $shipped['unit'] ?? 'tokens', 'kind' => $shipped['kind'] ?? 'model_usage'];
    foreach ($shipped === null ? [] : ['date', 'quantity', 'cost', 'model'] as $field) {
        foreach ($shipped[$field] as $candidate) {
            $at = array_search($candidate, $lower, true);
            if ($at !== false) {
                $out[$field] = $headers[$at];
                break;
            }
        }
    }
    return $out;
}

/** The file's header row only (BOM stripped), for the mapping form. */
function csv_headers(string $path): array
{
    $h = @fopen($path, 'rb');
    if ($h === false) {
        return [];
    }
    $row = fgetcsv($h, 0, ',', '"', '\\');
    fclose($h);
    if (!is_array($row)) {
        return [];
    }
    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
    return array_map(static fn ($c): string => trim((string) $c), $row);
}

function csv_number(string $raw): ?string
{
    $raw = trim(str_replace(['$', ',', ' ', "\u{00A0}"], '', $raw));
    return $raw !== '' && is_numeric($raw) && (float) $raw >= 0 && (float) $raw < 1.0E+14 ? $raw : null;
}

function csv_date(string $raw): ?string
{
    $raw = trim($raw);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
        return $d !== false && $d->format('Y-m-d') === $m[1] ? $m[1] : null;
    }
    try {
        return $raw === '' ? null : (new DateTimeImmutable($raw))->format('Y-m-d');
    } catch (Exception) {
        return null;
    }
}

/**
 * Read the file under a mapping: ['headers', 'rows' => [{line, date, quantity, cost, model, description, error}], 'missing' => [fields whose column the file lacks], 'truncated' => bool].
 * A row with a bad date, quantity or cost carries its error; blank lines are dropped.
 */
function csv_parse(string $path, array $mapping): array
{
    $mapping = csv_mapping_clean($mapping);
    $h = @fopen($path, 'rb');
    $headers = [];
    $rows = [];
    $missing = [];
    $truncated = false;
    if ($h === false) {
        return ['headers' => [], 'rows' => [], 'missing' => array_keys(CSV_FIELDS), 'truncated' => false];
    }
    $first = true;
    $at = [];
    $line = 0;
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($first) {
            $first = false;
            $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $r[0]);
            $headers = array_map(static fn ($c): string => trim((string) $c), $r);
            $lower = array_map('strtolower', $headers);
            foreach (['date', 'quantity', 'cost'] as $need) {
                $col = strtolower($mapping[$need] ?? '');
                $idx = $col === '' ? false : array_search($col, $lower, true);
                if ($idx === false) {
                    $missing[] = $need;
                } else {
                    $at[$need] = $idx;
                }
            }
            foreach (['model', 'description'] as $opt) {
                $col = strtolower($mapping[$opt] ?? '');
                $idx = $col === '' ? false : array_search($col, $lower, true);
                if ($idx !== false) {
                    $at[$opt] = $idx;
                }
            }
            if ($missing !== []) {
                break;
            }
            continue;
        }
        if ($r === [null] || implode('', array_map('strval', $r)) === '') {
            continue;
        }
        if (count($rows) >= CSV_MAX_ROWS) {
            $truncated = true;
            break;
        }
        $date = csv_date((string) ($r[$at['date']] ?? ''));
        $qty = csv_number((string) ($r[$at['quantity']] ?? ''));
        $cost = csv_number((string) ($r[$at['cost']] ?? ''));
        $error = $date === null ? 'the date is not a day' : ($qty === null ? 'the quantity is not a number' : ($cost === null ? 'the cost is not a number' : null));
        $rows[] = ['line' => $line, 'date' => $date, 'quantity' => $qty, 'cost' => $cost, 'model' => isset($at['model']) ? trim((string) ($r[$at['model']] ?? '')) : '',
                   'description' => isset($at['description']) ? trim((string) ($r[$at['description']] ?? '')) : '', 'error' => $error];
    }
    fclose($h);
    return ['headers' => $headers, 'rows' => $rows, 'missing' => $missing, 'truncated' => $truncated];
}

/** The first rows of the file as the mapping reads them, with the counts: ['headers', 'rows' (the first $rows), 'total', 'errors', 'missing', 'truncated']. */
function csv_preview(string $path, array $mapping, int $rows = 5): array
{
    $p = csv_parse($path, $mapping);
    return ['headers' => $p['headers'], 'rows' => array_slice($p['rows'], 0, max(1, $rows)), 'total' => count($p['rows']), 'errors' => count(array_filter($p['rows'], static fn (array $r): bool => $r['error'] !== null)),
            'missing' => $p['missing'], 'truncated' => $p['truncated']];
}

/**
 * What the import would do, charge by charge, without writing: [{period_start, period_end, quantity, cost, unit_cost, description, rows, state: new|duplicate|refused, reason}]. A charge is a duplicate when a
 * non-void CSV charge of the same provider, period, quantity and cost exists (or an earlier line of this same file made it); refused when a row is unreadable or its cost cannot be written exactly.
 */
function csv_plan(PDO $pdo, int $providerId, string $providerName, string $path, array $mapping, bool $byMonth): array
{
    $mapping = csv_mapping_clean($mapping);
    $p = csv_parse($path, $mapping);
    $plan = [];
    $groups = [];
    foreach ($p['rows'] as $r) {
        if ($r['error'] !== null) {
            $plan[] = ['period_start' => null, 'period_end' => null, 'quantity' => $r['quantity'], 'cost' => $r['cost'], 'unit_cost' => null, 'description' => 'Line ' . $r['line'], 'rows' => 1, 'state' => 'refused', 'reason' => 'Line ' . $r['line'] . ': ' . $r['error'] . '.'];
            continue;
        }
        if ($byMonth) {
            $key = substr($r['date'], 0, 7);
            $g = &$groups[$key];
            $g ??= ['period_start' => $key . '-01', 'period_end' => date('Y-m-t', strtotime($key . '-01')), 'quantity' => '0', 'cost' => '0', 'rows' => 0, 'models' => []];
            $g['quantity'] = bcadd_s($g['quantity'], $r['quantity']);
            $g['cost'] = bcadd_s($g['cost'], $r['cost']);
            $g['rows']++;
            if ($r['model'] !== '') {
                $g['models'][$r['model']] = true;
            }
            unset($g);
        } else {
            $groups[] = ['period_start' => $r['date'], 'period_end' => $r['date'], 'quantity' => $r['quantity'], 'cost' => $r['cost'], 'rows' => 1, 'models' => $r['model'] === '' ? [] : [$r['model'] => true],
                         'description' => $r['description'], 'line' => $r['line']];
        }
    }
    $seen = [];
    $dup = $pdo->prepare("SELECT 1 FROM usage_charges WHERE provider_id = :p AND period_start = :ps AND period_end = :pe AND quantity = CAST(:q AS numeric) AND cost_amount = round(CAST(:c AS numeric), 2)
                           AND source = 'csv_import' AND status <> 'void' LIMIT 1");
    foreach ($groups as $g) {
        $q = number_format((float) $g['quantity'], 4, '.', '');
        $c = number_format((float) $g['cost'], 2, '.', '');
        $desc = $g['description'] ?? '';
        if ($desc === '') {
            $models = array_keys($g['models']);
            $desc = $providerName . ($models === [] ? '' : ' ' . implode(', ', array_slice($models, 0, 3))) . ' usage ' . ($byMonth ? substr($g['period_start'], 0, 7) . ' (' . $g['rows'] . ' rows)' : $g['period_start']);
        }
        $item = ['period_start' => $g['period_start'], 'period_end' => $g['period_end'], 'quantity' => $q, 'cost' => $c, 'unit_cost' => '0', 'description' => mb_substr($desc, 0, 500), 'rows' => $g['rows'], 'state' => 'new', 'reason' => null];
        if ((float) $q > 0) {
            $item['unit_cost'] = (string) one_value($pdo, 'SELECT round(CAST(:c AS numeric) / CAST(:q AS numeric), 6)', ['c' => $c, 'q' => $q]);
            if ((string) one_value($pdo, 'SELECT round(CAST(:q AS numeric) * CAST(:u AS numeric), 2)', ['q' => $q, 'u' => $item['unit_cost']]) !== $c) {
                $item['state'] = 'refused';
                $item['reason'] = 'The cost ' . $c . ' of ' . $q . ' ' . $mapping['unit'] . ' on ' . $g['period_start'] . ' cannot be written exactly at six decimals of unit cost; enter it by hand.';
            }
        } elseif ((float) $c > 0) {
            $item['state'] = 'refused';
            $item['reason'] = 'A cost of ' . $c . ' on ' . $g['period_start'] . ' has no quantity to price.';
        }
        if ($item['state'] === 'new') {
            $key = $g['period_start'] . '|' . $g['period_end'] . '|' . $q . '|' . $c;
            $dup->execute(['p' => $providerId, 'ps' => $g['period_start'], 'pe' => $g['period_end'], 'q' => $q, 'c' => $c]);
            if (isset($seen[$key]) || $dup->fetchColumn() !== false) {
                $item['state'] = 'duplicate';
            }
            $seen[$key] = true;
        }
        $plan[] = $item;
    }
    return $plan;
}

/** Decimal addition without floats (bcmath is not assumed): both are plain decimal strings. */
function bcadd_s(string $a, string $b): string
{
    return function_exists('bcadd') ? bcadd($a, $b, 6) : number_format((float) $a + (float) $b, 6, '.', '');
}

/** The counts of a plan: ['new' => n, 'duplicate' => n, 'refused' => n]. */
function csv_plan_counts(array $plan): array
{
    $c = ['new' => 0, 'duplicate' => 0, 'refused' => 0];
    foreach ($plan as $p) {
        $c[$p['state']]++;
    }
    return $c;
}

/** Remember a mapping on the provider (the next import offers it). */
function save_provider_mapping(PDO $pdo, int $providerId, array $mapping): void
{
    $pdo->prepare('UPDATE usage_providers SET csv_mapping = CAST(:m AS jsonb) WHERE id = :id')->execute(['m' => json_encode(csv_mapping_clean($mapping), JSON_THROW_ON_ERROR), 'id' => $providerId]);
}

/**
 * Run an import: the import row, then a draft charge per new line of the plan (kind and unit from the mapping, the unit cost from the file's cost, source csv_import). Duplicates are skipped and counted;
 * an unreadable or unrepresentable line is refused and told in the import's detail. The file is not kept. Answers ['import_id', 'rows', 'inserted', 'skipped', 'refused', 'charge_ids', 'reasons'].
 */
function run_usage_import(PDO $pdo, int $providerId, int $engagementId, string $path, string $fileName, array $mapping, bool $groupByMonth, int $by): array
{
    $mapping = csv_mapping_clean($mapping);
    $provider = $pdo->prepare('SELECT name FROM usage_providers WHERE id = :id AND archived_at IS NULL');
    $provider->execute(['id' => $providerId]);
    $name = $provider->fetchColumn();
    if ($name === false) {
        throw new DomainException('That provider is not here.');
    }
    $parsed = csv_parse($path, $mapping);
    if ($parsed['missing'] !== []) {
        throw new DomainException('The file has no column for ' . implode(', ', $parsed['missing']) . '; map it first.');
    }
    $plan = csv_plan($pdo, $providerId, (string) $name, $path, $mapping, $groupByMonth);
    if ($plan === []) {
        throw new DomainException('The file has no usage rows under that mapping.');
    }
    $counts = csv_plan_counts($plan);
    $rowCount = count($plan);
    $st = $pdo->prepare('INSERT INTO usage_imports (provider_id, file_name, mapping, row_count, created_by) VALUES (:p, :f, CAST(:m AS jsonb), :n, :by) RETURNING id');
    $st->execute(['p' => $providerId, 'f' => mb_substr($fileName, 0, 255), 'm' => json_encode($mapping, JSON_THROW_ON_ERROR), 'n' => $rowCount, 'by' => $by]);
    $importId = (int) $st->fetchColumn();
    $ids = [];
    $reasons = [];
    $ins = $pdo->prepare("INSERT INTO usage_charges (engagement_id, kind, provider_id, description, period_start, period_end, quantity, unit, unit_cost, pricing, billable, currency, source, import_id, created_by)
                          VALUES (:e, :k, :p, :d, :ps, :pe, :q, :u, :uc, 'cost', true, 'USD', 'csv_import', :i, :by) RETURNING id");
    try {
        foreach ($plan as $item) {
            if ($item['state'] === 'refused') {
                $reasons[] = $item['reason'];
                continue;
            }
            if ($item['state'] !== 'new') {
                continue;
            }
            $ins->execute(['e' => $engagementId, 'k' => $mapping['kind'], 'p' => $providerId, 'd' => $item['description'], 'ps' => $item['period_start'], 'pe' => $item['period_end'], 'q' => $item['quantity'],
                           'u' => $mapping['unit'], 'uc' => $item['unit_cost'], 'i' => $importId, 'by' => $by]);
            $ids[] = (int) $ins->fetchColumn();
        }
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'The import could not be made.'));
        }
        throw $e;
    }
    $detail = $reasons === [] ? null : mb_substr(implode(' ', array_slice($reasons, 0, 10)) . (count($reasons) > 10 ? ' …and ' . (count($reasons) - 10) . ' more.' : ''), 0, 2000);
    $pdo->prepare('UPDATE usage_imports SET inserted = :i, skipped = :s, detail = :d WHERE id = :id')->execute(['i' => count($ids), 's' => $counts['duplicate'], 'd' => $detail, 'id' => $importId]);
    return ['import_id' => $importId, 'rows' => $rowCount, 'inserted' => count($ids), 'skipped' => $counts['duplicate'], 'refused' => $counts['refused'], 'charge_ids' => $ids, 'reasons' => $reasons];
}

/**
 * Remove an import's still-draft charges (an approved or void one stays, with the import row it came from; an import left with no charge goes too). Answers how many drafts it removed.
 */
function delete_usage_import(PDO $pdo, int $id, int $by): int
{
    $st = $pdo->prepare('SELECT id FROM usage_charges WHERE import_id = :i AND status = \'draft\' ORDER BY id');
    $st->execute(['i' => $id]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        foreach ($pdo->query("SELECT id FROM attachments WHERE record_type = 'usage_charge' AND record_id = " . (int) $cid)->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            delete_attachment($pdo, (int) $aid);
        }
        $pdo->prepare("DELETE FROM notes WHERE record_type = 'usage_charge' AND record_id = :id")->execute(['id' => $cid]);
        try {
            $pdo->prepare('DELETE FROM usage_charges WHERE id = :id')->execute(['id' => $cid]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === 'P0001') {
                throw new DomainException(db_message($e, 'That import could not be removed.'));
            }
            throw $e;
        }
        $n++;
    }
    $left = (int) one_value($pdo, 'SELECT count(*) FROM usage_charges WHERE import_id = :i', ['i' => $id]);
    if ($left === 0) {
        $pdo->prepare('DELETE FROM usage_imports WHERE id = :id')->execute(['id' => $id]);
    } else {
        $pdo->prepare('UPDATE usage_imports SET inserted = :n, detail = :d WHERE id = :id')->execute(['n' => $left, 'd' => $n . ' draft charge' . ($n === 1 ? '' : 's') . ' removed on ' . date('Y-m-d') . '; ' . $left . ' kept (approved or void).', 'id' => $id]);
    }
    return $n;
}

/** The directory the preview step keeps a chosen file in between the preview and the import (a random token, one day at most; the file is the person's own upload and goes when the import runs). */
function csv_stash_dir(): string
{
    $d = APP_ROOT . '/storage/tmp/usage-import';
    if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) {
        throw new DomainException('The upload store is not writable.');
    }
    return $d;
}

/** Keep an uploaded CSV for the next step: validate it (size, text/csv or text/plain by finfo), answer [token, path, name]. Files older than a day are cleared. */
function csv_stash(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'Choose the provider\'s usage export (a CSV file).');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    if ((int) ($file['size'] ?? 0) > attachment_max_bytes()) {
        throw new DomainException('A file is at most ' . intdiv(attachment_max_bytes(), 1048576) . ' MB.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        throw new DomainException('That is not a CSV file (it reads as ' . $mime . ').');
    }
    $dir = csv_stash_dir();
    foreach (glob($dir . '/*.csv') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
            @unlink($old . '.name');
        }
    }
    $token = bin2hex(random_bytes(16));
    $path = $dir . '/' . $token . '.csv';
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $path) : copy($tmp, $path))) {
        throw new DomainException('The file could not be kept for the next step.');
    }
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'usage.csv')));
    file_put_contents($path . '.name', preg_replace('/[^\w.\- ()]+/u', '_', $name));
    return [$token, $path, $name];
}

/** A stashed file by token: [path, name] or null (a token is 32 hex, never a path). */
function csv_stashed(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $path = csv_stash_dir() . '/' . $token . '.csv';
    return is_file($path) ? [$path, is_file($path . '.name') ? (string) file_get_contents($path . '.name') : 'usage.csv'] : null;
}

function csv_unstash(string $token): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $token)) {
        $path = csv_stash_dir() . '/' . $token . '.csv';
        @unlink($path);
        @unlink($path . '.name');
    }
}
