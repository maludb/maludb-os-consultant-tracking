<?php
declare(strict_types=1);

/**
 * The worker's thirteen steps (slice 8). worker_pass() runs the named ones under the caller's advisory lock, each in its own try (one failing never stops the next) and answers the report; bin/worker.php logs it once as `worker.pass`.
 * A step has a rhythm — every minute, hourly, or daily from 06:00 firm time — kept by the `worker.pass` rows themselves (the clock the pass used is stamped in the row), so a step that is not due answers `{skipped: ...}`; naming steps
 * on the command line (`--steps=`) runs exactly those, due or not (the proofs, and a person who wants one NOW). The worker owns no business rule: every write goes through the earlier slices' functions and the database's verbs.
 * The steps that read through the mcp_* views (reminders, cadence, the statement) read as the firm's administrator (worker_actor()) — the views answer nothing to nobody; every row it writes is `source = 'cron'`, actor none.
 */

const WORKER_STEPS = ['send', 'nudge', 'cadence', 'statement', 'schedules', 'budgets', 'reminders', 'receipts', 'reimbursements', 'claims', 'links', 'dispatch', 'housekeeping'];
/** How often a step runs: minute | hourly | daily. */
const WORKER_RHYTHM = ['send' => 'minute', 'nudge' => 'minute', 'cadence' => 'daily', 'statement' => 'hourly', 'schedules' => 'daily', 'budgets' => 'daily', 'reminders' => 'daily', 'receipts' => 'hourly',
                       'reimbursements' => 'hourly', 'claims' => 'daily', 'links' => 'daily', 'dispatch' => 'minute', 'housekeeping' => 'daily'];
const WORKER_DAILY_HOUR = 6;                      // firm time; a daily step does not run before it

/** The firm's time zone (settings), UTC when it is not a zone PHP knows. */
function worker_zone(PDO $pdo): DateTimeZone
{
    try {
        return new DateTimeZone((string) (one_value($pdo, 'SELECT timezone FROM ct_settings WHERE id = 1') ?: 'UTC'));
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

/** The firm's local date (Y-m-d) at $now. */
function worker_today(PDO $pdo, DateTimeImmutable $now): string
{
    return $now->setTimezone(worker_zone($pdo))->format('Y-m-d');
}

/** The firm's administrator the views are read as: the lowest-numbered live human super-admin (else an admin-capability human). Null when there is none — the steps that need one say so. */
function worker_actor(PDO $pdo): ?int
{
    $v = one_value($pdo, "SELECT id FROM members WHERE status = 'active' AND member_kind = 'human' AND capability IS NOT NULL AND (business_role = 'super_admin' OR capability = 'admin') ORDER BY (business_role = 'super_admin') DESC, id LIMIT 1");
    return $v === null ? null : (int) $v;
}

/** Make the connection read as $memberId (or as nobody, null) — the database's views and gates ask app.member_id. Answers the previous value. */
function worker_act_as(PDO $pdo, ?int $memberId): string
{
    $prev = (string) one_value($pdo, "SELECT COALESCE(current_setting('app.member_id', true), '')");
    $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', $memberId === null ? '' : (string) $memberId]);
    return $prev;
}

/** The stamp (the clock the pass used) of the last pass that ran $step, or null. */
function worker_last_run(PDO $pdo, string $step): ?DateTimeImmutable
{
    $v = one_value($pdo, "SELECT max((after->>'at')::timestamptz) FROM activity_log WHERE action = 'worker.pass' AND jsonb_exists(after->'ran', :s)", ['s' => $step]);
    return $v === null ? null : new DateTimeImmutable((string) $v);
}

/** Is the step due at $now by its rhythm? */
function worker_due(PDO $pdo, string $step, DateTimeImmutable $now): bool
{
    $rhythm = WORKER_RHYTHM[$step] ?? 'minute';
    if ($rhythm === 'minute') {
        return true;
    }
    $last = worker_last_run($pdo, $step);
    if ($last === null) {
        return $rhythm === 'hourly' || (int) $now->setTimezone(worker_zone($pdo))->format('G') >= WORKER_DAILY_HOUR;
    }
    if ($now < $last) {
        return false;                                                       // the clock was moved back; nothing is due twice
    }
    if ($rhythm === 'hourly') {
        return $now->getTimestamp() - $last->getTimestamp() >= 3540;       // a minute's grace so a pass at :00 and the next at :59 do not skip an hour
    }
    $zone = worker_zone($pdo);
    return $now->setTimezone($zone)->format('Y-m-d') > $last->setTimezone($zone)->format('Y-m-d') && (int) $now->setTimezone($zone)->format('G') >= WORKER_DAILY_HOUR;
}

/** One step by name. $limit bounds the rows a step touches. Answers the step's counts. */
function worker_run_step(PDO $pdo, string $step, DateTimeImmutable $now, int $limit): array
{
    $iso = $now->format(DATE_ATOM);
    return match ($step) {
        'send' => outbox_send_batch($pdo, $limit, $now),
        'nudge' => ['nudged' => nudge_pass($pdo, $iso)],
        'cadence' => cadence_pass($pdo, $iso),
        'statement' => statement_pass($pdo, $iso),
        'schedules' => schedules_pass($pdo, $iso),
        'budgets' => budgets_pass($pdo, $iso),
        'reminders' => reminders_pass($pdo, $iso),
        'receipts' => receipts_pass($pdo, $iso),
        'reimbursements' => reimbursements_pass($pdo, $iso),
        'claims' => claims_pass($pdo, $iso),
        'links' => links_pass($pdo, $iso),
        'dispatch' => dispatch_retry_pass($pdo, $iso),
        'housekeeping' => housekeeping_pass($pdo, $iso),
    };
}

/**
 * Run the steps (all, or $only, which ignores their rhythm) and answer ['at', 'ran' => [step…], 'results' => [step => counts], 'skipped' => [step => why], 'errors' => [step => sentence]].
 * A step that is not due is `skipped`; one that threw is `errors` (its transaction rolled back) and counts as not having run.
 */
function worker_pass(PDO $pdo, array $only, int $limit, DateTimeImmutable $now): array
{
    $steps = $only === [] ? WORKER_STEPS : array_values(array_intersect(WORKER_STEPS, $only));
    $report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'results' => [], 'skipped' => [], 'errors' => []];
    foreach ($steps as $s) {
        if ($only === [] && !worker_due($pdo, $s, $now)) {
            $report['skipped'][$s] = 'not due';
            continue;
        }
        try {
            $report['results'][$s] = worker_run_step($pdo, $s, $now, $limit);
            $report['ran'][] = $s;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('worker step ' . $s . ': ' . $e->getMessage());
            $report['errors'][$s] = mb_substr($e->getMessage(), 0, 300);
        }
    }
    return $report;
}
