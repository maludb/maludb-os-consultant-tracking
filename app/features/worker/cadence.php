<?php
declare(strict_types=1);

/**
 * The worker's passes that DRAFT (slice 8): the invoices falling due on each engagement's cadence, the hosting schedules falling due, the budget checks. Nothing here issues, sends or approves — a draft is a draft, a person decides.
 * Every write is the earlier slices' own function (create_invoice() with add_unbilled, run_schedule(), the database's ct_budget_alerts_check()), logged as that slice logs it with `source = 'cron'` and actor none; the managers and billing are told
 * through the slices' queue_*_notice() with the same dedupe keys a person's click uses, so the Clerk, a person and the worker never make a second draft's notice.
 */
require_once dirname(__DIR__) . '/invoices/handler.php';
require_once dirname(__DIR__) . '/usage/handler.php';

/** The cadences a billing date advances by: [recurring cadence, every n]. on_milestone and manual never come due by date. */
const CADENCE_STEPS = ['weekly' => ['weekly', 1], 'biweekly' => ['weekly', 2], 'monthly' => ['monthly', 1]];

/** The date after $date by a cadence, moved on until it is after $today (a worker that was off for weeks drafts ONE invoice for the lapsed time, then catches the date up). */
function next_billing_after(PDO $pdo, string $date, string $cadence, string $today): string
{
    [$c, $n] = CADENCE_STEPS[$cadence];
    for ($i = 0; $i < 600; $i++) {
        $date = (string) one_value($pdo, 'SELECT ct_recurring_next(CAST(:d AS date), :c, :n)', ['d' => $date, 'c' => $c, 'n' => $n]);
        if ($date > $today) {
            break;
        }
    }
    return $date;
}

/** Move an engagement's next billing date and say so in the log (`engagement.update`, cron). */
function move_billing_date(PDO $pdo, array $e, string $to): void
{
    $pdo->prepare('UPDATE engagements SET next_billing_date = CAST(:d AS date) WHERE id = :id')->execute(['d' => $to, 'id' => (int) $e['engagement_id']]);
    log_activity($pdo, 'engagement.update', 'engagement', (int) $e['engagement_id'], ['source' => 'cron', 'actor_member_id' => null, 'client_id' => (int) $e['client_id'],
        'before' => ['next_billing_date' => $e['next_billing_date']], 'after' => ['next_billing_date' => $to, 'via' => 'cadence']]);
}

/**
 * One draft: slice 6's create_invoice() with the approved unbilled items pulled through $through, then the notices — the engagement's manager, then billing — and the `invoice.draft` row (`after.via = cadence`).
 * The period runs from the day after the engagement's last invoice (else its start) to $through. Answers the invoice id. Inside the caller's transaction.
 */
function worker_draft_invoice(PDO $pdo, array $e, string $through, string $how): int
{
    $last = one_value($pdo, "SELECT max(period_end) + 1 FROM invoices WHERE engagement_id = :e AND status NOT IN ('draft', 'void') AND period_end IS NOT NULL", ['e' => (int) $e['engagement_id']]);
    $start = $last ?? (string) $e['start_date'];
    $made = create_invoice($pdo, (int) $e['engagement_id'], ['period_start' => $start !== null && $start <= $through ? $start : null, 'period_end' => $through], null, true, $through);
    $inv = find_invoice($pdo, $made['id']);
    log_activity($pdo, 'invoice.draft', 'invoice', $made['id'], ['source' => 'cron', 'actor_member_id' => null, 'invoice_id' => $made['id'], 'engagement_id' => (int) $e['engagement_id'], 'client_id' => (int) $e['client_id'],
        'after' => ['amount' => $inv['total'], 'currency' => $inv['currency'], 'total' => $inv['total'], 'period_start' => $inv['period_start'], 'period_end' => $inv['period_end'], 'po_number' => $inv['po_number'], 'added_unbilled' => true,
                    'counts' => $made['counts'], 'via' => 'cadence', 'trigger' => $how]]);
    $data = ['title' => 'A draft invoice was started for ' . $inv['engagement_number'] . ' (' . fmt_money($inv['total'], $inv['currency']) . ')', 'body' => 'It was drafted on the billing cadence. Open it to check the lines: ' . invoice_link($made['id']),
             'dedupe' => 'inv-drafted:' . $made['id']];
    $mgr = (int) one_value($pdo, 'SELECT manager_member_id FROM engagements WHERE id = :e', ['e' => (int) $e['engagement_id']]);
    if ($mgr > 0) {
        queue_billing_notice($pdo, $mgr, 'invoice_drafted', 'invoice', $made['id'], $data);
    }
    notify_billing($pdo, 'invoice_drafted', 'invoice', $made['id'], $data, $mgr);
    return $made['id'];
}

/**
 * The invoices falling due: every ACTIVE engagement on a weekly, biweekly or monthly cadence whose billing date has come and that has approved unbilled work and no open draft gets ONE draft through the billing date, and its date moves
 * on by the cadence; one with nothing to bill has its date moved on without a draft; one with a draft already open is left alone (a second is never made). An `on_milestone` engagement with an accepted, uninvoiced milestone and no open
 * draft gets a draft through today. Reads as the firm's administrator. Answers ['drafted', 'milestones', 'rolled', 'open_draft', 'failed', 'invoices' => [ids]].
 */
function cadence_pass(PDO $pdo, string $now): array
{
    $out = ['drafted' => 0, 'milestones' => 0, 'rolled' => 0, 'open_draft' => 0, 'failed' => 0, 'invoices' => []];
    $actor = worker_actor($pdo);
    if ($actor === null) {
        $out['error'] = 'no administrator to read as';
        return $out;
    }
    $today = worker_today($pdo, new DateTimeImmutable($now));
    $prev = worker_act_as($pdo, $actor);
    try {
        $due = invoices_due_to_draft($pdo, $today);
        $open = array_map('intval', $pdo->query("SELECT DISTINCT engagement_id FROM invoices WHERE status = 'draft'")->fetchAll(PDO::FETCH_COLUMN));
        $byId = [];
        foreach ($pdo->query("SELECT id AS engagement_id, client_id, number, start_date, billing_cadence, next_billing_date, status FROM engagements")->fetchAll() as $r) {
            $byId[(int) $r['engagement_id']] = $r;
        }
        $handled = [];
        foreach ($due['due'] as $d) {
            $e = $byId[$d['engagement_id']];
            $handled[$d['engagement_id']] = true;
            if (in_array($d['engagement_id'], $open, true)) {
                $out['open_draft']++;
                continue;
            }
            try {
                $pdo->beginTransaction();
                $id = worker_draft_invoice($pdo, $e, (string) $e['next_billing_date'], 'billing_date');
                move_billing_date($pdo, $e, next_billing_after($pdo, (string) $e['next_billing_date'], (string) $e['billing_cadence'], $today));
                $pdo->commit();
                $out['drafted']++;
                $out['invoices'][] = $id;
                $open[] = $d['engagement_id'];
            } catch (DomainException $x) {
                $pdo->rollBack();
                $out['failed']++;
                error_log('cadence draft ' . $e['number'] . ': ' . $x->getMessage());
            }
        }
        // Past their date with nothing approved to bill (and no open draft): the date moves on, nothing is drafted.
        foreach ($byId as $id => $e) {
            if ($e['status'] === 'active' && isset(CADENCE_STEPS[$e['billing_cadence']]) && $e['next_billing_date'] !== null && $e['next_billing_date'] <= $today && !isset($handled[$id]) && !in_array($id, $open, true)) {
                $pdo->beginTransaction();
                move_billing_date($pdo, $e, next_billing_after($pdo, (string) $e['next_billing_date'], (string) $e['billing_cadence'], $today));
                $pdo->commit();
                $out['rolled']++;
            }
        }
        $seen = [];
        foreach ($due['milestones'] as $m) {
            $id = $m['engagement_id'];
            $e = $byId[$id] ?? null;
            if ($e === null || isset($seen[$id]) || $e['status'] !== 'active' || $e['billing_cadence'] !== 'on_milestone') {
                continue;
            }
            $seen[$id] = true;
            if (in_array($id, $open, true)) {
                $out['open_draft']++;
                continue;
            }
            try {
                $pdo->beginTransaction();
                $out['invoices'][] = worker_draft_invoice($pdo, $e, $today, 'milestone');
                $pdo->commit();
                $open[] = $id;
                $out['milestones']++;
            } catch (DomainException $x) {
                $pdo->rollBack();
                $out['failed']++;
                error_log('milestone draft ' . $e['number'] . ': ' . $x->getMessage());
            }
        }
    } finally {
        worker_act_as($pdo, $prev === '' ? null : (int) $prev);
    }
    return $out;
}

/**
 * The hosting schedules falling due (`next_date` on or before today): slice 5's run_schedule() makes ONE draft charge each and moves the date on (the database's verb — an end date deactivates); the engagement's manager is told
 * (`usage_drafted`, dedupe `usage-drafted:<charge>`). Logged `recurring_charge.run` and `usage_charge.draft` (cron). Answers ['ran' => n, 'failed' => n].
 */
function schedules_pass(PDO $pdo, string $now): array
{
    $out = ['ran' => 0, 'failed' => 0];
    $today = worker_today($pdo, new DateTimeImmutable($now));
    $st = $pdo->prepare('SELECT id, engagement_id, description FROM recurring_charges WHERE active AND next_date <= CAST(:t AS date) ORDER BY id');
    $st->execute(['t' => $today]);
    foreach ($st->fetchAll() as $s) {
        try {
            $pdo->beginTransaction();
            $u = run_schedule($pdo, (int) $s['id'], null);
            $n = $pdo->prepare('SELECT next_date, active FROM recurring_charges WHERE id = :id');
            $n->execute(['id' => (int) $s['id']]);
            $next = $n->fetch();
            log_activity($pdo, 'recurring_charge.run', 'recurring_charge', (int) $s['id'], ['source' => 'cron', 'actor_member_id' => null, 'engagement_id' => (int) $s['engagement_id'],
                'after' => ['schedule_id' => (int) $s['id'], 'charge_id' => (int) $u['id'], 'number' => $u['number'], 'period_start' => $u['period_start'], 'period_end' => $u['period_end'], 'amount' => $u['billable_amount'],
                            'currency' => trim((string) $u['currency']), 'next_date' => $next['next_date'], 'active' => (bool) $next['active'], 'via' => 'worker']]);
            log_activity($pdo, 'usage_charge.draft', 'usage_charge', (int) $u['id'], ['source' => 'cron', 'actor_member_id' => null, 'engagement_id' => (int) $s['engagement_id'],
                'after' => ['number' => $u['number'], 'kind' => $u['kind'], 'quantity' => $u['quantity'], 'unit' => $u['unit'], 'cost_amount' => $u['cost_amount'], 'billable_amount' => $u['billable_amount'], 'currency' => trim((string) $u['currency']),
                            'source' => 'schedule', 'amount' => $u['billable_amount'], 'schedule_id' => (int) $s['id']]]);
            notify_usage_drafted($pdo, ['usage_charge_id' => (int) $u['id'], 'engagement_id' => (int) $s['engagement_id'], 'number' => $u['number'], 'billable_amount' => $u['billable_amount'], 'currency' => $u['currency']], 0,
                'by the schedule "' . $s['description'] . '"');
            $pdo->commit();
            $out['ran']++;
        } catch (DomainException $x) {
            $pdo->rollBack();
            $out['failed']++;
            error_log('schedule ' . $s['id'] . ': ' . $x->getMessage());
        }
    }
    return $out;
}

/**
 * Every active engagement's budget against the settings' thresholds: the database's ct_budget_alerts_check() writes each crossing once (the alert is unique per engagement, kind and threshold); each NEW alert is logged
 * `budget_alert.write` (cron) and its engagement's manager told (`budget_alert`, dedupe `budget-alert:<alert>`). Reads as the firm's administrator. Answers ['checked' => n, 'alerts' => n].
 */
function budgets_pass(PDO $pdo, string $now): array
{
    $out = ['checked' => 0, 'alerts' => 0];
    $actor = worker_actor($pdo);
    if ($actor === null) {
        $out['error'] = 'no administrator to read as';
        return $out;
    }
    $prev = worker_act_as($pdo, $actor);
    try {
        $before = array_map('intval', $pdo->query('SELECT id FROM budget_alerts')->fetchAll(PDO::FETCH_COLUMN));
        $check = $pdo->prepare('SELECT ct_budget_alerts_check(:e)');
        foreach ($pdo->query("SELECT id FROM engagements WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $check->execute(['e' => (int) $eid]);
            $out['checked']++;
        }
        $new = $pdo->prepare('SELECT b.id, b.engagement_id, b.kind, b.threshold_pct, b.value_at, b.budget_at, e.client_id, e.department_id, e.number, e.manager_member_id
                                FROM budget_alerts b JOIN engagements e ON e.id = b.engagement_id WHERE NOT (b.id = ANY (CAST(:before AS bigint[]))) ORDER BY b.id');
        $new->execute(['before' => pg_array_literal($before)]);
        foreach ($new->fetchAll() as $a) {
            $pdo->beginTransaction();
            log_activity($pdo, 'budget_alert.write', 'budget_alert', (int) $a['id'], ['source' => 'cron', 'actor_member_id' => null, 'engagement_id' => (int) $a['engagement_id'], 'client_id' => (int) $a['client_id'],
                'department_id' => $a['department_id'] === null ? null : (int) $a['department_id'],
                'after' => ['kind' => $a['kind'], 'threshold_pct' => (int) $a['threshold_pct'], 'value_at' => $a['kind'] === 'ai' ? null : $a['value_at'], 'budget_at' => $a['kind'] === 'ai' ? null : $a['budget_at'], 'via' => 'worker']]);
            if ($a['manager_member_id'] !== null) {
                $what = ['hours' => 'hours budget', 'amount' => 'fee budget', 'nte' => 'not-to-exceed cap', 'ai' => 'AI budget'][$a['kind']];
                queue_usage_notice($pdo, (int) $a['manager_member_id'], 'budget_alert', 'engagement', (int) $a['engagement_id'], ['title' => $a['number'] . ' has used ' . (int) $a['threshold_pct'] . '% of its ' . $what,
                    'body' => 'See the engagement: ' . rtrim((string) env('APP_URL', ''), '/') . '/engagements/' . (int) $a['engagement_id'], 'dedupe' => 'budget-alert:' . $a['id']]);
            }
            $pdo->commit();
            $out['alerts']++;
        }
    } finally {
        worker_act_as($pdo, $prev === '' ? null : (int) $prev);
    }
    return $out;
}
