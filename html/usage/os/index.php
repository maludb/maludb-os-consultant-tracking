<?php
declare(strict_types=1);
/** /usage/os?period= — the kernel's months as this application holds them (screen `os-statement`): period, the OS's status, pulled, allocated, the totals and the remainder; Pull; one month opened shows its lines, the charges it made, Allocate / Re-allocate (a closed month only). usage.write. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$period = request_string('period');
if ($period !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    refuse(422, 'A month is written YYYY-MM.');
}
$months = ai_statement_status($pdo);
$open = $period === '' ? null : (ai_statement_status($pdo, $period)[0] ?? null);
if ($period !== '' && $open === null) {
    $open = ['period' => $period, 'missing' => true];
}
$lines = $summary = $charges = [];
if ($open !== null && !isset($open['missing'])) {
    $lines = ai_statement_lines($pdo, $open['ai_statement_id']);
    $summary = ai_statement_summary($pdo, $open['ai_statement_id']);
    $charges = ai_statement_charges($pdo, $open['ai_statement_id']);
}
log_screen_view($pdo, 'os-statement');
if (wants_json()) {
    respond_screen(['months' => array_map('present_statement', $months), 'month' => $open === null || isset($open['missing']) ? null : present_statement($open), 'lines' => array_map('present_statement_line', $lines), 'summary' => $summary,
        'charges' => array_map('present_usage_charge', $charges)]);
}
$notices = ['pulled' => ['success', 'Pulled from the OS.'], 'allocated' => ['success', 'Allocated by the rules; the drafts are for the engagements\' managers to approve.'], 'nothing' => ['info', 'Nothing new in the OS to pull.']];
render_screen('The OS statement', view('usage/os.php', ['months' => $months, 'open' => $open, 'lines' => $lines, 'summary' => $summary, 'charges' => $charges, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices), 'rules' => (int) one_value($pdo, 'SELECT count(*) FROM mcp_allocation_rules WHERE active')]), ['activeNav' => 'os-statement', 'screen' => 'os-statement', 'entity' => 'ai_statement']);
