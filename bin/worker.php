<?php
declare(strict_types=1);

/**
 * The Consultant Tracking worker (slice 8): one pass a minute from consultant_tracking-worker.timer — the outbox (email through MaluMail, texts through the kernel's K6), Monday's missing-time nudges, the invoices falling due on each
 * engagement's cadence, the kernel's AI statement poll (hourly), hosting schedules, budget checks, overdue notices and reminders, the ledger's receipts and HR's reimbursements read through K7 (hourly), claims ageing, secure links' expiry,
 * agent-dispatch retries, housekeeping. Idempotent; advisory-locked so two runs never overlap; each step its own try; the report is printed as one JSON line and logged once as `worker.pass` (counts and sentences, never a body).
 *   php bin/worker.php [--steps=send,nudge,cadence,statement,schedules,budgets,reminders,receipts,reimbursements,claims,links,dispatch,housekeeping] [--limit=200]
 * With --steps the named steps run whether or not they are due; without it each runs at its own rhythm (minute, hourly, daily from 06:00 firm time).
 * CT_WORKER_NOW (ISO 8601) replaces the clock the steps SELECT by; it is honoured only when APP_ENV is not prod. The writes stamp the real time. Exits 1 when a step failed.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/notify/queue.php';
require_once dirname(__DIR__) . '/app/features/notify/send.php';
require_once dirname(__DIR__) . '/app/features/worker/steps.php';
require_once dirname(__DIR__) . '/app/features/worker/outbox.php';
require_once dirname(__DIR__) . '/app/features/worker/cadence.php';
require_once dirname(__DIR__) . '/app/features/worker/statement.php';
require_once dirname(__DIR__) . '/app/features/worker/reads.php';

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--steps=')) {
        $only = array_values(array_filter(explode(',', substr($a, 8))));
        if (array_diff($only, WORKER_STEPS) !== []) {
            fwrite(STDERR, 'Unknown step: ' . implode(', ', array_diff($only, WORKER_STEPS)) . '. Steps: ' . implode(', ', WORKER_STEPS) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--steps=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('CT_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('consultant_tracking_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$started = microtime(true);
$report = worker_pass($pdo, $only, $limit, $now);
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('consultant_tracking_worker'))");
exit($report['errors'] === [] ? 0 : 1);
