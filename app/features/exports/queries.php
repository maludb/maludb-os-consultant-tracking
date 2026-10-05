<?php
declare(strict_types=1);

/**
 * The exports page (slice 8): the seven downloads — invoices issued, credit notes, expenses by category, reimbursements, WIP, time detail, usage detail — each the very function a share answers (documents.php: one truth, two doors),
 * as JSON (the document itself) or CSV (one presenter: the document's rows flattened, a cell that begins like a formula quoted for spreadsheets). A period, or an "as of" for the three that have none, and nothing else.
 */
require_once __DIR__ . '/documents.php';
require_once dirname(__DIR__) . '/engagements/queries.php';

const EXPORTS = [
    'invoices'       => ['title' => 'Invoices issued', 'schema' => 'os.ct-invoices/1', 'blurb' => 'Every invoice issued in the period, with its lines summarised by kind — the ledger\'s source import.', 'icon' => 'feather-file-text', 'period' => 'period'],
    'credit_notes'   => ['title' => 'Credit notes', 'schema' => 'os.ct-credit-notes/1', 'blurb' => 'Every credit note issued in the period, amounts positive.', 'icon' => 'feather-corner-up-left', 'period' => 'period'],
    'expenses'       => ['title' => 'Expenses by category', 'schema' => 'os.ct-expenses/1', 'blurb' => 'Approved company-card expenses by month, category and engagement; no person named.', 'icon' => 'feather-credit-card', 'period' => 'period'],
    'reimbursements' => ['title' => 'Reimbursements', 'schema' => 'os.ct-reimbursements/1', 'blurb' => 'Approved personal-card expenses nobody has reimbursed yet, per person, up to the date.', 'icon' => 'feather-users', 'period' => 'as_of'],
    'wip'            => ['title' => 'Work in progress', 'schema' => 'os.ct-wip/1', 'blurb' => 'Approved work not yet on an invoice, at bill value per engagement, as of the date.', 'icon' => 'feather-layers', 'period' => 'as_of'],
    'time'           => ['title' => 'Time detail', 'schema' => 'os.ct-time/1', 'blurb' => 'Approved time entries in the period: day, person, engagement, hours, billable, bill value. No cost.', 'icon' => 'feather-clock', 'period' => 'period'],
    'usage'          => ['title' => 'Usage detail', 'schema' => 'os.ct-usage/1', 'blurb' => 'AI and hosting charges in the period: provider, kind, quantity, cost, billable, billed.', 'icon' => 'feather-cpu', 'period' => 'period'],
];
const EXPORT_FORMATS = ['csv' => 'CSV', 'json' => 'JSON'];

/** The export the request names, from the allow-list, else 404. */
function export_from_request(string $raw): string
{
    return isset(EXPORTS[$raw]) ? $raw : refuse(404, 'There is no such export.');
}

/** The period of a request: ?from=&to= (dates; last month by default; at most 24 months, refused in words) — or for an "as of" export `to` (today by default). Answers ['from' => ?, 'to' => ...]. */
function export_period(string $export, array $query, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $asOf = EXPORTS[$export]['period'] === 'as_of';
    $to = trim((string) ($query['to'] ?? $query['as_of'] ?? ''));
    $from = trim((string) ($query['from'] ?? ''));
    $day = static function (string $d, string $what): DateTimeImmutable {
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d ? $x : refuse(422, $what . ' is a date (YYYY-MM-DD).');
    };
    if ($asOf) {
        return ['from' => null, 'to' => $to === '' ? $today->format('Y-m-d') : $day($to, 'The date')->format('Y-m-d')];
    }
    if ($from === '' && $to === '') {
        $f = $today->modify('first day of last month');
        return ['from' => $f->format('Y-m-d'), 'to' => $f->modify('last day of this month')->format('Y-m-d')];
    }
    $df = $from === '' ? $day($to, 'The last day')->modify('first day of this month') : $day($from, 'The first day of the period');
    $dt = $to === '' ? max($today, $df) : $day($to, 'The last day of the period');
    if ($dt < $df) {
        refuse(422, 'The period ends before it begins.');
    }
    if ($dt > $df->modify('+' . REPORT_MAX_MONTHS_EXPORT . ' months')) {
        refuse(422, 'An export covers at most ' . REPORT_MAX_MONTHS_EXPORT . ' months; choose a shorter period.');
    }
    return ['from' => $df->format('Y-m-d'), 'to' => $dt->format('Y-m-d')];
}
const REPORT_MAX_MONTHS_EXPORT = 24;

/** The document an export names, for a period (as the signed-in member reads it). $opts: engagement, client, member. */
function export_document(PDO $pdo, string $export, ?string $from, string $to, array $opts = []): array
{
    $eng = isset($opts['engagement']) ? (int) $opts['engagement'] : null;
    $client = isset($opts['client']) ? (int) $opts['client'] : null;
    $member = isset($opts['member']) ? (int) $opts['member'] : null;
    return match ($export) {
        'invoices' => share_invoices_issued($pdo, (string) $from, $to, $client),
        'credit_notes' => share_credit_notes_issued($pdo, (string) $from, $to, $client),
        'expenses' => share_expenses_by_category($pdo, (string) $from, $to),
        'reimbursements' => share_reimbursements_approved($pdo, $to, $member),
        'wip' => share_unbilled_summary($pdo, $to, $eng, $client),
        'time' => export_time_detail($pdo, (string) $from, $to, $eng),
        'usage' => export_usage_detail($pdo, (string) $from, $to, $eng),
        default => throw new DomainException('There is no such export.'),
    };
}

/** One cell of the CSV: a scalar as text, a boolean yes/no, null empty. */
function csv_cell(mixed $v): string
{
    return $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
}

/** A row flattened to [column => text]: a nested object becomes `parent_child` columns; a list of line summaries becomes one text ("time 1,260.00 (tax 0.00, total 1,260.00); expense …"). */
function csv_flatten(array $row, string $prefix = ''): array
{
    $out = [];
    foreach ($row as $k => $v) {
        if (is_array($v) && array_is_list($v)) {
            $out[$prefix . $k] = implode('; ', array_map(static fn ($e): string => is_array($e) && isset($e['kind'], $e['subtotal'])
                ? $e['kind'] . ' ' . $e['subtotal'] . ' (tax ' . $e['tax'] . ', total ' . $e['total'] . ')' : (is_array($e) ? implode(' ', array_map('csv_cell', $e)) : csv_cell($e)), $v));
        } elseif (is_array($v)) {
            $out += csv_flatten($v, $prefix . $k . '_');
        } else {
            $out[$prefix . $k] = csv_cell($v);
        }
    }
    return $out;
}

/** The CSV text of a document: the header (the union of the rows' columns, in order of appearance), then a line per row — a cell that begins with = + - @ is quoted with a leading apostrophe, as spreadsheets need. */
function document_csv(array $doc): string
{
    $flat = array_map('csv_flatten', $doc['rows'] ?? []);
    $header = [];
    foreach ($flat as $r) {
        foreach (array_keys($r) as $k) {
            $header[$k] = true;
        }
    }
    $header = array_keys($header);
    $out = fopen('php://temp', 'r+');
    $safe = static fn (string $c): string => preg_match('/^[=+\-@\t\r]/', $c) === 1 ? "'" . $c : $c;
    fputcsv($out, $header, ',', '"', '');
    foreach ($flat as $r) {
        fputcsv($out, array_map(static fn (string $k): string => $safe($r[$k] ?? ''), $header), ',', '"', '');
    }
    rewind($out);
    $text = (string) stream_get_contents($out);
    fclose($out);
    return $text;
}

/** The download's name: "ct-invoices-2026-09-01_2026-09-30.csv"; an as-of export "ct-wip-2026-10-05.json". */
function export_filename(string $export, ?string $from, string $to, string $format): string
{
    return 'ct-' . str_replace('_', '-', $export) . '-' . ($from === null ? '' : $from . '_') . $to . '.' . $format;
}
