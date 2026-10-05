<?php
declare(strict_types=1);

/**
 * The kernel's AI statement poll (slice 8), hourly while the settings' `ai_statement_poll` is on: slice 5's pull_ai_statements() keeps every month the kernel lists that this application does not hold, and every month still open there (their
 * figures move until the close); then allocate_ai_statement() places each month the kernel has CLOSED and this application has not allocated — the database's verb refuses an open month in its own words, so an open one is never allocated.
 * Billing is told how the month came out with the remainder no rule placed (`ai_statement_allocated`), and the managers of the engagements that received draft charges are told how many. Logged `ai_statement.pull|allocate` (cron).
 */
require_once dirname(__DIR__) . '/usage/handler.php';
require_once dirname(__DIR__) . '/invoices/handler.php';

/** Answers ['polled', 'pulled' => months touched, 'allocated' => months, 'charges' => drafts made, 'open' => months still open in the OS, 'error' => ?sentence]. */
function statement_pass(PDO $pdo, string $now): array
{
    $out = ['polled' => false, 'pulled' => 0, 'allocated' => 0, 'charges' => 0, 'open' => 0, 'error' => null];
    if (!(bool) one_value($pdo, 'SELECT ai_statement_poll FROM ct_settings WHERE id = 1')) {
        return $out;
    }
    $actor = worker_actor($pdo);
    if ($actor === null) {
        $out['error'] = 'no administrator to read as';
        return $out;
    }
    $out['polled'] = true;
    $prev = worker_act_as($pdo, $actor);
    try {
        try {
            $pdo->beginTransaction();
            $months = pull_ai_statements($pdo, null, null);
            foreach ($months as $m) {
                log_activity($pdo, 'ai_statement.pull', 'ai_statement', $m['statement_id'], ['source' => 'cron', 'actor_member_id' => null,
                    'after' => ['period' => $m['period'], 'kernel_status' => $m['kernel_status'], 'status' => $m['status'], 'lines' => $m['lines'], 'hash' => $m['document_hash'], 'new' => $m['new'], 'changed' => $m['changed'], 'via' => 'worker']]);
            }
            $pdo->commit();
            $out['pulled'] = count($months);
        } catch (KernelUnavailable | DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['error'] = $e->getMessage();
        }
        $out['open'] = (int) one_value($pdo, "SELECT count(*) FROM ai_statements WHERE kernel_status = 'open'");
        foreach ($pdo->query("SELECT id, period FROM ai_statements WHERE kernel_status = 'closed' AND status = 'pulled' ORDER BY period")->fetchAll() as $s) {
            try {
                $pdo->beginTransaction();
                $r = allocate_ai_statement($pdo, (int) $s['id'], null);
                $totals = [];
                foreach ($r['summary'] as $x) {
                    $t = &$totals[$x['currency']];
                    $t ??= ['statement' => 0.0, 'allocated' => 0.0, 'remainder' => 0.0];
                    $t['statement'] += (float) $x['statement_amount'];
                    $t['allocated'] += (float) $x['allocated_amount'];
                    $t['remainder'] += (float) $x['remainder'];
                    unset($t);
                }
                $cur = count($totals) === 1 ? array_key_first($totals) : null;
                $sum = $cur === null ? null : $totals[$cur];
                $fmt = static fn (float $v): string => number_format($v, 2, '.', '');
                log_activity($pdo, 'ai_statement.allocate', 'ai_statement', (int) $s['id'], ['source' => 'cron', 'actor_member_id' => null,
                    'after' => ['period' => $s['period'], 'charges' => $r['made'], 'kept' => $r['kept'], 'allocated_amount' => $sum === null ? null : $fmt($sum['allocated']), 'remainder' => $sum === null ? null : $fmt($sum['remainder']),
                                'currency' => $cur, 'amount' => $sum === null ? null : $fmt($sum['allocated']), 'via' => 'worker']]);
                $told = [];
                foreach ($r['charges'] as $c) {
                    if ($c['status'] === 'draft') {
                        $mgr = usage_manager_of($pdo, $c['engagement_id'], 0);
                        if ($mgr !== null) {
                            $told[$mgr][$c['engagement_number']] = ($told[$mgr][$c['engagement_number']] ?? 0) + 1;
                        }
                    }
                }
                foreach ($told as $mgr => $byEngagement) {
                    $n = array_sum($byEngagement);
                    queue_usage_notice($pdo, (int) $mgr, 'ai_statement_allocated', 'ai_statement', (int) $s['id'], ['title' => 'The ' . $s['period'] . ' OS statement placed ' . $n . ' draft charge' . ($n === 1 ? '' : 's') . ' on ' . implode(', ', array_keys($byEngagement)),
                        'body' => 'Open the list to approve or void them: ' . rtrim((string) env('APP_URL', ''), '/') . '/usage/?statement=' . (int) $s['id'], 'dedupe' => 'ai-allocated:' . $s['id'] . ':' . $mgr]);
                }
                $remainder = $sum === null ? 'see the OS statement screen' : $fmt($sum['remainder']) . ' ' . $cur . ' of ' . $fmt($sum['statement']) . ' ' . $cur;
                foreach (billing_recipients($pdo) as $b) {
                    queue_usage_notice($pdo, $b, 'ai_statement_allocated', 'ai_statement', (int) $s['id'], ['title' => 'The ' . $s['period'] . ' OS statement was allocated: ' . $r['made'] . ' draft charge' . ($r['made'] === 1 ? '' : 's'),
                        'body' => 'The remainder no rule placed: ' . $remainder . '. Open it: ' . rtrim((string) env('APP_URL', ''), '/') . '/usage/os?period=' . rawurlencode((string) $s['period']), 'dedupe' => 'ai-allocated-billing:' . $s['id']]);
                }
                $pdo->commit();
                $out['allocated']++;
                $out['charges'] += $r['made'];
            } catch (DomainException $e) {
                $pdo->rollBack();
                $out['error'] = $e->getMessage();
            }
        }
    } finally {
        worker_act_as($pdo, $prev === '' ? null : (int) $prev);
    }
    return $out;
}
