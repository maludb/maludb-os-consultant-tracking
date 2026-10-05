<?php
declare(strict_types=1);

/**
 * The worker's passes about people and clients (slice 8): Monday's missing-time nudges, the overdue notices and reminders, the claims left waiting, the secure links' expiry, housekeeping. Each takes the clock as an ISO 8601 string
 * (CT_WORKER_NOW in the proofs, else now) and answers counts. Every write goes through the earlier slices' functions — queue_time_notice(), send_invoice(), queue_billing_notice() — logged `source = 'cron'`, actor none.
 */
require_once dirname(__DIR__) . '/time/queries.php';
require_once dirname(__DIR__) . '/time/write.php';
require_once dirname(__DIR__) . '/invoices/handler.php';

/**
 * Last week's missing time, on the settings' nudge day (from 06:00 firm time): one `missing_time` notice per person who has not submitted it, through slice 3's queue_time_notice() with its dedupe key `nudge:<member>:<week>` —
 * so a second pass, the Clerk's own timesheet_nudge_send and a manager's button never make a second nudge. Text too when the person chose it. Answers how many people were nudged.
 */
function nudge_pass(PDO $pdo, string $now): int
{
    $local = (new DateTimeImmutable($now))->setTimezone(worker_zone($pdo));
    if ((int) $local->format('w') !== (int) one_value($pdo, 'SELECT missing_time_nudge_dow FROM ct_settings WHERE id = 1') || (int) $local->format('G') < WORKER_DAILY_HOUR) {
        return 0;
    }
    $actor = worker_actor($pdo);
    if ($actor === null) {
        return 0;
    }
    $ws = (string) one_value($pdo, 'SELECT ct_week_start(CAST(:d AS date)) - 7', ['d' => $local->format('Y-m-d')]);
    $prev = worker_act_as($pdo, $actor);
    try {
        $rows = missing_time($pdo, $ws);
    } finally {
        worker_act_as($pdo, $prev === '' ? null : (int) $prev);
    }
    $n = 0;
    foreach ($rows as $r) {
        // The notice's own dedupe key lives on its OUTBOX row, which a person who turned every channel off never gets — so the log is the record that this week was nudged (the button writes it too).
        if ((int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'timesheet.nudge' AND after->>'member_id' = :m AND after->>'week_start' = :w", ['m' => (string) $r['member_id'], 'w' => $ws]) > 0) {
            continue;
        }
        $pdo->beginTransaction();
        $made = queue_time_notice($pdo, $r['member_id'], 'missing_time', $r['timesheet_id'], ['title' => 'Please submit your ' . strtolower(week_label($ws)),
            'body' => $r['empty_weekdays'] . ' empty weekday' . ($r['empty_weekdays'] === 1 ? '' : 's') . ', ' . fmt_hours($r['hours_logged']) . ' logged. Open your timesheet: ' . rtrim((string) env('APP_URL', ''), '/') . '/time/?week=' . $ws,
            'dedupe' => 'nudge:' . $r['member_id'] . ':' . $ws]);
        if ($made > 0) {
            log_activity($pdo, 'timesheet.nudge', $r['timesheet_id'] === null ? 'member' : 'timesheet', $r['timesheet_id'] ?? $r['member_id'], ['source' => 'cron', 'actor_member_id' => null, 'timesheet_id' => $r['timesheet_id'],
                'after' => ['member_id' => $r['member_id'], 'week_start' => $ws, 'empty_weekdays' => $r['empty_weekdays'], 'hours_logged' => $r['hours_logged'], 'nudged_by' => null, 'via' => 'worker']]);
            $n++;
        }
        $pdo->commit();
    }
    return $n;
}

/**
 * Open invoices past due: each threshold of the settings' `reminder_days` an invoice crosses is told to billing and the engagement's manager ONCE (`invoice_overdue`, dedupe `overdue:<invoice>:<days>`), and a reminder goes to the client
 * through slice 6's send_invoice(kind reminder) — fresh secure link and all — while the reminders sent (any hand's) number fewer than the thresholds crossed and none went in the last seven days. `reminder_days = {}` turns the pass off.
 * Logged `invoice.remind` (cron, with the threshold). An invoice nobody can be written to is counted `no_recipient`, never retried into a loop of notices. Answers counts.
 */
function reminders_pass(PDO $pdo, string $now): array
{
    $out = ['reminded' => 0, 'notified' => 0, 'no_recipient' => 0, 'failed' => 0, 'off' => false];
    $days = pg_int_array((string) one_value($pdo, 'SELECT reminder_days FROM ct_settings WHERE id = 1'));
    sort($days);
    $days = array_values(array_filter($days, static fn (int $d): bool => $d > 0));
    if ($days === []) {
        $out['off'] = true;
        return $out;
    }
    $today = worker_today($pdo, new DateTimeImmutable($now));
    $st = $pdo->prepare("SELECT i.id, i.number, i.engagement_id, i.client_id, i.total, i.balance_due, i.currency, i.due_date, i.reminder_count, i.last_reminder_at, (CAST(:today AS date) - i.due_date) AS late,
                                (i.last_reminder_at IS NULL OR i.last_reminder_at < CAST(:now AS timestamptz) - interval '7 days') AS may_remind
                           FROM invoices i WHERE i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.balance_due > 0 AND i.due_date < CAST(:today2 AS date) ORDER BY i.id");
    $st->execute(['today' => $today, 'today2' => $today, 'now' => (new DateTimeImmutable($now))->format('Y-m-d H:i:s.uP')]);
    foreach ($st->fetchAll() as $i) {
        $late = (int) $i['late'];
        $crossed = array_values(array_filter($days, static fn (int $d): bool => $d <= $late));
        if ($crossed === []) {
            continue;
        }
        $threshold = max($crossed);
        $id = (int) $i['id'];
        $cur = trim((string) $i['currency']);
        $told = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'invoice.overdue' AND invoice_id = :i AND after->>'threshold' = :t", ['i' => $id, 't' => (string) $threshold]) > 0;     // the log is the record: a notice with no outbox row has no dedupe key
        $pdo->beginTransaction();
        $data = ['title' => $i['number'] . ' is ' . $late . ' days past due (' . fmt_money($i['balance_due'], $cur) . ' owed)', 'body' => 'Due ' . format_date((string) $i['due_date']) . '. Open it: ' . invoice_link($id), 'dedupe' => 'overdue:' . $id . ':' . $threshold];
        if (!$told) {
            $made = notify_billing($pdo, 'invoice_overdue', 'invoice', $id, $data);
            $mgr = (int) one_value($pdo, 'SELECT manager_member_id FROM engagements WHERE id = :e', ['e' => (int) $i['engagement_id']]);
            if ($mgr > 0 && !in_array($mgr, billing_recipients($pdo), true)) {
                $made += queue_billing_notice($pdo, $mgr, 'invoice_overdue', 'invoice', $id, $data);
            }
            log_activity($pdo, 'invoice.overdue', 'invoice', $id, ['source' => 'cron', 'actor_member_id' => null, 'invoice_id' => $id, 'engagement_id' => (int) $i['engagement_id'], 'client_id' => (int) $i['client_id'],
                'after' => ['number' => $i['number'], 'amount' => $i['balance_due'], 'currency' => $cur, 'days_past_due' => $late, 'threshold' => $threshold, 'told' => $made, 'via' => 'worker']]);
            $out['notified']++;
        }
        $pdo->commit();
        if ((int) $i['reminder_count'] < count($crossed) && $i['may_remind']) {
            try {
                $pdo->beginTransaction();
                $r = send_invoice($pdo, $id, null, null, null, 'reminder');
                log_activity($pdo, 'invoice.remind', 'invoice', $id, ['source' => 'cron', 'actor_member_id' => null, 'invoice_id' => $id, 'engagement_id' => (int) $i['engagement_id'], 'client_id' => (int) $i['client_id'],
                    'after' => ['number' => $i['number'], 'amount' => $i['balance_due'], 'currency' => $cur, 'recipients' => $r['recipients'], 'queued' => $r['queued'], 'reminder_count' => (int) $r['invoice']['reminder_count'], 'days_past_due' => $late, 'threshold' => $threshold, 'via' => 'worker']]);
                $pdo->commit();
                $out['reminded']++;
            } catch (DomainException $e) {
                $pdo->rollBack();
                $out[str_contains($e->getMessage(), 'Nobody to send it to') ? 'no_recipient' : 'failed']++;
            }
        }
    }
    return $out;
}

/** Claims a client made that nobody has decided in 14 days: billing is told again, once per fortnight of waiting (`claim_submitted`, dedupe `claim-age:<claim>:<fortnights>`). Answers ['told' => n]. */
function claims_pass(PDO $pdo, string $now): array
{
    $st = $pdo->prepare("SELECT k.id, k.invoice_id, i.number, floor(extract(epoch FROM (CAST(:now AS timestamptz) - k.created_at)) / (14 * 86400))::int AS fortnights
                           FROM payment_claims k JOIN invoices i ON i.id = k.invoice_id WHERE k.status = 'submitted' AND k.created_at <= CAST(:now2 AS timestamptz) - interval '14 days' ORDER BY k.id");
    $stamp = (new DateTimeImmutable($now))->format('Y-m-d H:i:s.uP');
    $st->execute(['now' => $stamp, 'now2' => $stamp]);
    $out = ['told' => 0];
    foreach ($st->fetchAll() as $k) {
        if ((int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'payment_claim.age_notice' AND entity_id = :k AND after->>'fortnights' = :f", ['k' => (int) $k['id'], 'f' => (string) $k['fortnights']]) > 0) {
            continue;
        }
        $pdo->beginTransaction();
        log_activity($pdo, 'payment_claim.age_notice', 'payment_claim', (int) $k['id'], ['source' => 'cron', 'actor_member_id' => null, 'invoice_id' => (int) $k['invoice_id'], 'after' => ['fortnights' => (string) $k['fortnights'], 'via' => 'worker']]);
        $out['told'] += notify_billing($pdo, 'claim_submitted', 'invoice', (int) $k['invoice_id'], ['title' => 'A client\'s payment claim on ' . $k['number'] . ' is still waiting', 'body' => 'It has waited ' . ((int) $k['fortnights'] * 14) . ' days or more. Decide it: ' . rtrim((string) env('APP_URL', ''), '/') . '/billing/claims',
            'dedupe' => 'claim-age:' . $k['id'] . ':' . $k['fortnights']]) > 0 ? 1 : 0;
        $pdo->commit();
    }
    return $out;
}

/**
 * A paid invoice's live link dies `link_lifetime_days` after the day it was paid (D9): the expiry is set once, when the invoice is paid and its live link has none (an expired or rotated link answers the one page —
 * ct_secure_link_invoice() already refuses it). Answers ['set' => links given an expiry, 'expired' => live links past it].
 */
function links_pass(PDO $pdo, string $now): array
{
    $pdo->beginTransaction();
    $set = $pdo->prepare("UPDATE invoice_links_secure l SET expires_at = i.paid_at + make_interval(days => (SELECT link_lifetime_days FROM ct_settings WHERE id = 1))
                            FROM invoices i WHERE i.id = l.invoice_id AND l.rotated_at IS NULL AND l.expires_at IS NULL AND i.status = 'paid' AND i.paid_at IS NOT NULL");
    $set->execute();
    $n = $set->rowCount();
    if ($n > 0) {
        log_activity($pdo, 'invoice.link_expiry_set', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['links' => $n, 'link_lifetime_days' => (int) one_value($pdo, 'SELECT link_lifetime_days FROM ct_settings WHERE id = 1')]]);
    }
    $pdo->commit();
    $expired = $pdo->prepare('SELECT count(*) FROM invoice_links_secure WHERE rotated_at IS NULL AND expires_at <= CAST(:now AS timestamptz)');
    $expired->execute(['now' => (new DateTimeImmutable($now))->format('Y-m-d H:i:s.uP')]);
    return ['set' => $n, 'expired' => (int) $expired->fetchColumn()];
}

/**
 * Tidying that touches no checkpoint and no count: used hand-off nonces older than a day, sessions ended over 90 days ago, and the bodies of outbox rows sent over 180 days ago (blanked — the row, its status, its provider
 * reference and its log stay). Answers counts.
 */
function housekeeping_pass(PDO $pdo, string $now): array
{
    $stamp = (new DateTimeImmutable($now))->format('Y-m-d H:i:s.uP');
    $run = static function (string $sql) use ($pdo, $stamp): int {
        $st = $pdo->prepare($sql);
        $st->execute(['now' => $stamp]);
        return $st->rowCount();
    };
    return [
        'sso_nonces' => $run("DELETE FROM sso_nonces WHERE expires_at < CAST(:now AS timestamptz) - interval '1 day'"),
        'member_sessions' => $run("DELETE FROM member_sessions WHERE ended_at IS NOT NULL AND ended_at < CAST(:now AS timestamptz) - interval '90 days'"),
        'outbox_bodies' => $run("UPDATE notification_outbox SET body = '', body_html = NULL WHERE status = 'sent' AND sent_at < CAST(:now AS timestamptz) - interval '180 days' AND (body <> '' OR body_html IS NOT NULL)"),
    ];
}
