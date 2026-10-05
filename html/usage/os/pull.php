<?php
declare(strict_types=1);
/**
 * Action `ai_statement_pull` (log `ai_statement.pull` per month: period, kernel_status, status, lines, hash, new, changed): usage.write. Pulls the kernel's months — with `period` that one, else every month this application does not hold yet and the ones still open
 * (a month already pulled and closed is left alone). Each document is kept verbatim; the kernel unreachable is a 503 in words and nothing changes. An eval run changes nothing.
 */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$period = req_has('period') && req_val('period') !== '' ? (string) req_val('period') : (isset($_GET['period']) && $_GET['period'] !== '' ? (string) $_GET['period'] : null);
try {
    $months = ct_guard($pdo, static function () use ($pdo, $me, $period): array {
        $pdo->beginTransaction();
        $months = pull_ai_statements($pdo, $period, $me);
        foreach ($months as $m) {
            log_activity($pdo, 'ai_statement.pull', 'ai_statement', $m['statement_id'], ['after' => ['period' => $m['period'], 'kernel_status' => $m['kernel_status'], 'status' => $m['status'], 'lines' => $m['lines'], 'hash' => $m['document_hash'], 'new' => $m['new'], 'changed' => $m['changed']]]);
        }
        $pdo->commit();
        return $months;
    });
} catch (KernelUnavailable $k) {
    refuse(503, $k->getMessage());
}
$did = $months === [] ? 'Nothing new in the OS to pull' : 'Pulled ' . implode(', ', array_map(static fn (array $m): string => $m['period'] . ' (' . $m['kernel_status'] . ')', $months));
ct_done($did, $months === [] ? null : $months[0]['statement_id'], ct_land(return_path('/usage/os' . ($period === null ? '' : '?period=' . rawurlencode($period))), $months === [] ? 'nothing' : 'pulled'), 'usageChanged',
    ['months' => array_map(static fn (array $m): array => ['period' => $m['period'], 'kernel_status' => $m['kernel_status'], 'status' => $m['status'], 'lines' => $m['lines'], 'new' => $m['new'], 'changed' => $m['changed']], $months)]);
