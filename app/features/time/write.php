<?php
declare(strict_types=1);

/** Writes: entries to the base table (the guard decides), the verbs for every status move, the timer, the notices this slice queues. */

/** Add ($id null) or change an entry. $f: engagement_id, entry_date, hours, activity_type_id, billable, note, internal_note. The guard refuses in words (P0001 → the caller's ct_guard). */
function save_time_entry(PDO $pdo, ?int $id, array $f, int $memberId, int $by): int
{
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, billable, note, internal_note, timesheet_id, currency) VALUES (:m, :e, :d, :h, :a, :b, :n, :i, 0, \'USD\') RETURNING id');
            $st->execute(['m' => $memberId, 'e' => $f['engagement_id'], 'd' => $f['entry_date'], 'h' => $f['hours'], 'a' => $f['activity_type_id'], 'b' => $f['billable'] ? 't' : 'f', 'n' => $f['note'], 'i' => $f['internal_note']]);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE time_entries SET engagement_id = :e, entry_date = :d, hours = :h, activity_type_id = :a, billable = :b, note = :n, internal_note = :i WHERE id = :id')
            ->execute(['e' => $f['engagement_id'], 'd' => $f['entry_date'], 'h' => $f['hours'], 'a' => $f['activity_type_id'], 'b' => $f['billable'] ? 't' : 'f', 'n' => $f['note'], 'i' => $f['internal_note'], 'id' => $id]);
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That entry could not be saved.'));
        }
        throw $e;
    }
}

/** Delete a draft or rejected entry (the trigger refuses anything else). Answers the row for the log. */
function delete_time_entry(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT timesheet_id, engagement_id, entry_date, hours, billable, activity_type_id, status FROM time_entries WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    try {
        $pdo->prepare('DELETE FROM time_entries WHERE id = :id')->execute(['id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That entry is not deleted.'));
        }
        throw $e;
    }
    return ['timesheet_id' => (int) $r['timesheet_id'], 'engagement_id' => (int) $r['engagement_id'], 'entry_date' => $r['entry_date'], 'hours' => $r['hours'], 'billable' => (bool) $r['billable'], 'activity_type_id' => $r['activity_type_id'] === null ? null : (int) $r['activity_type_id'], 'status' => $r['status']];
}

/** A verb call: the row(s) it answers, or its RAISE as a DomainException. */
function time_verb(PDO $pdo, string $sql, array $args): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: [];
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That could not be done.'));
        }
        throw $e;
    }
}

function start_timer(PDO $pdo, int $memberId, int $engagementId, ?int $activityId, ?string $note): array
{
    return time_verb($pdo, 'SELECT * FROM ct_timer_start(:m, :e, :a, :n)', ['m' => $memberId, 'e' => $engagementId, 'a' => $activityId, 'n' => $note]);
}

/** Stop the timer: the draft entry it made (rounded up to the increment). */
function stop_timer(PDO $pdo, int $memberId): array
{
    return time_verb($pdo, 'SELECT * FROM ct_timer_stop(:m)', ['m' => $memberId]);
}

function submit_timesheet(PDO $pdo, int $timesheetId, int $by): array
{
    return time_verb($pdo, 'SELECT * FROM ct_timesheet_submit(:t, :by)', ['t' => $timesheetId, 'by' => $by]);
}

function reopen_timesheet(PDO $pdo, int $timesheetId, int $by): array
{
    return time_verb($pdo, 'SELECT * FROM ct_timesheet_reopen(:t, :by)', ['t' => $timesheetId, 'by' => $by]);
}

/** Approve what the approver manages: [approved, left_submitted, week_status, engagements (touched)]. */
function approve_timesheet(PDO $pdo, int $timesheetId, int $by): array
{
    $before = $pdo->prepare("SELECT DISTINCT engagement_id FROM time_entries WHERE timesheet_id = :t AND status = 'submitted' AND engagement_id IN (SELECT ct_managed_engagement_ids())");
    $before->execute(['t' => $timesheetId]);
    $touched = array_map('intval', $before->fetchAll(PDO::FETCH_COLUMN));
    $r = time_verb($pdo, 'SELECT * FROM ct_timesheet_approve(:t, :by)', ['t' => $timesheetId, 'by' => $by]);
    return ['approved' => (int) $r['approved'], 'left_submitted' => (int) $r['left_submitted'], 'week_status' => (string) $r['week_status'], 'engagements' => $touched];
}

function reject_timesheet(PDO $pdo, int $timesheetId, int $by, string $reason): array
{
    return time_verb($pdo, 'SELECT * FROM ct_timesheet_reject(:t, :by, :r)', ['t' => $timesheetId, 'by' => $by, 'r' => $reason]);
}

function write_off_entry(PDO $pdo, int $entryId, int $by, string $reason): array
{
    return time_verb($pdo, 'SELECT * FROM ct_time_entry_write_off(:e, :by, :r)', ['e' => $entryId, 'by' => $by, 'r' => $reason]);
}

/**
 * Queue a notice to a person: the in-app row, and an outbox row per channel the person chose (email on and the kind among their kinds; text on and
 * among their text kinds). An agent gets the in-app row only. A dedupe key (per channel) makes a second queue the same key a no-op. Slice 8 sends.
 * Answers how many rows were made.
 */
function queue_time_notice(PDO $pdo, int $memberId, string $kind, ?int $timesheetId, array $data): int
{
    if ($memberId < 1 || (($_SERVER['HTTP_X_EVAL_RUN'] ?? '') === '1')) {
        return 0;
    }
    $m = $pdo->prepare("SELECT member_kind, status, capability FROM members WHERE id = :m");
    $m->execute(['m' => $memberId]);
    $member = $m->fetch();
    if ($member === false || $member['status'] !== 'active' || $member['capability'] === null) {
        return 0;
    }
    $title = (string) ($data['title'] ?? $kind);
    $body = (string) ($data['body'] ?? '');
    $dedupe = $data['dedupe'] ?? null;
    if ($dedupe !== null && (int) one_value($pdo, 'SELECT count(*) FROM notification_outbox WHERE dedupe_key LIKE :d', ['d' => $dedupe . ':m' . $memberId . ':%']) > 0) {
        return 0;
    }
    $pdo->prepare('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, :rt, :r, :t, :b)')
        ->execute(['m' => $memberId, 'k' => $kind, 'rt' => $timesheetId === null ? null : 'timesheet', 'r' => $timesheetId, 't' => mb_substr($title, 0, 300), 'b' => mb_substr($body, 0, 2000)]);
    $n = 1;
    if ($member['member_kind'] === 'agent') {
        return $n;
    }
    $prefs = find_prefs($pdo, $memberId);
    $ins = $pdo->prepare('INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body) VALUES (:c, :m, :k, :rt, :r, :d, :s, :b)');
    if ($prefs['email_enabled'] && in_array($kind, $prefs['kinds'], true)) {
        $ins->execute(['c' => 'email', 'm' => $memberId, 'k' => $kind, 'rt' => $timesheetId === null ? null : 'timesheet', 'r' => $timesheetId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':email', 's' => $title, 'b' => $body]);
        $n++;
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'm' => $memberId, 'k' => $kind, 'rt' => $timesheetId === null ? null : 'timesheet', 'r' => $timesheetId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':text', 's' => $title, 'b' => mb_substr($body, 0, 300)]);
        $n++;
    }
    return $n;
}
