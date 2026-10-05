<?php
declare(strict_types=1);
/**
 * Action `ai_statement_allocate` (log `ai_statement.allocate`: period, charges, kept, allocated_amount, remainder, currency): usage.write. A CLOSED month only (an open one is refused in the database's words); the rules valid in the month place each line's pieces
 * — rounded once per piece, the remainder never billed — as draft charges, one per engagement, provider and pricing; approved charges are kept and the drafts remade (Re-allocate). The engagements' managers are told.
 */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$sid = request_integer('statement') ?? request_integer('statement_id') ?? request_integer('id');
$period = request_string('period');
if ($sid === null && $period === '') {
    refuse(422, 'Say which month (statement or period).');
}
$statement = null;
foreach (ai_statement_status($pdo) as $s) {
    if (($sid !== null && $s['ai_statement_id'] === $sid) || ($sid === null && $s['period'] === $period)) {
        $statement = $s;
    }
}
$statement ?? refuse(404, 'Statement not found; pull the month first.');
$out = ct_guard($pdo, static function () use ($pdo, $me, $statement): array {
    $pdo->beginTransaction();
    $r = allocate_ai_statement($pdo, $statement['ai_statement_id'], $me);
    $totals = [];
    foreach ($r['summary'] as $s) {
        $t = &$totals[$s['currency']];
        $t ??= ['statement' => 0.0, 'allocated' => 0.0, 'remainder' => 0.0];
        $t['statement'] += (float) $s['statement_amount'];
        $t['allocated'] += (float) $s['allocated_amount'];
        $t['remainder'] += (float) $s['remainder'];
        unset($t);
    }
    $cur = count($totals) === 1 ? array_key_first($totals) : null;
    $sum = $cur === null ? null : $totals[$cur];
    log_activity($pdo, 'ai_statement.allocate', 'ai_statement', $statement['ai_statement_id'], ['after' => ['period' => $statement['period'], 'charges' => $r['made'], 'kept' => $r['kept'],
        'allocated_amount' => $sum === null ? null : number_format($sum['allocated'], 2, '.', ''), 'remainder' => $sum === null ? null : number_format($sum['remainder'], 2, '.', ''), 'currency' => $cur,
        'amount' => $sum === null ? null : number_format($sum['allocated'], 2, '.', '')]]);
    $told = [];                                                                  // one notice per manager: the engagements that received draft charges and how many
    foreach ($r['charges'] as $c) {
        if ($c['status'] === 'draft') {
            $mgr = usage_manager_of($pdo, $c['engagement_id'], $me);
            if ($mgr !== null) {
                $told[$mgr][$c['engagement_number']] = ($told[$mgr][$c['engagement_number']] ?? 0) + 1;
            }
        }
    }
    foreach ($told as $mgr => $byEngagement) {
        $n = array_sum($byEngagement);
        queue_usage_notice($pdo, (int) $mgr, 'ai_statement_allocated', 'ai_statement', $statement['ai_statement_id'], ['title' => 'The ' . $statement['period'] . ' OS statement placed ' . $n . ' draft charge' . ($n === 1 ? '' : 's') . ' on ' . implode(', ', array_keys($byEngagement)),
            'body' => 'Open the list to approve or void them: ' . rtrim((string) env('APP_URL', ''), '/') . '/usage/?statement=' . $statement['ai_statement_id'], 'dedupe' => 'ai-allocated:' . $statement['ai_statement_id'] . ':' . $mgr . ':' . date('YmdHi')]);
    }
    $pdo->commit();
    return [$r, $sum, $cur];
});
[$r, $sum, $cur] = $out;
ct_done('Allocated ' . $statement['period'] . ': ' . $r['made'] . ' draft charge' . ($r['made'] === 1 ? '' : 's') . ($sum === null ? '' : ', remainder ' . fmt_money(number_format($sum['remainder'], 2, '.', ''), (string) $cur)), $statement['ai_statement_id'],
    ct_land(return_path('/usage/os?period=' . rawurlencode($statement['period'])), 'allocated'), 'usageChanged',
    ['statement_id' => $statement['ai_statement_id'], 'period' => $statement['period'], 'charges' => $r['made'], 'kept' => $r['kept'], 'allocated_amount' => $sum === null ? null : number_format($sum['allocated'], 2, '.', ''),
     'remainder' => $sum === null ? null : number_format($sum['remainder'], 2, '.', ''), 'currency' => $cur, 'summary' => $r['summary']]);
