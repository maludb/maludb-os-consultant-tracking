<?php
declare(strict_types=1);

/**
 * The one queueing function for what the WORKER and the Clerk's note say (slice 8): notify() writes the in-app row and an outbox row per channel the person's preferences allow — exactly what queue_time_notice, queue_expense_notice,
 * queue_usage_notice and queue_billing_notice do for their slices, with the record type a parameter — and notify_client() queues a client's mail through slice 6's queue_client_mail(). Nothing here reaches MaluMail or the kernel:
 * outbox_send_batch() does, a minute later.
 */
require_once dirname(__DIR__) . '/settings/queries.php';

/**
 * Tell a person: the in-app row (always, for an active admitted member) and an outbox row per channel they chose (email on and the kind among their kinds; text on and among their text kinds); an agent gets the in-app row only.
 * $opts: dedupe (a key; a second queue of the same key for the same person is a no-op), text (the words of the text, default the title and the body, at most 300 characters). An eval run queues nothing. Answers how many rows were made.
 */
function notify(PDO $pdo, int $memberId, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body, array $opts = []): int
{
    if ($memberId < 1 || (($_SERVER['HTTP_X_EVAL_RUN'] ?? '') === '1')) {
        return 0;
    }
    $m = $pdo->prepare('SELECT member_kind, status, capability FROM members WHERE id = :m');
    $m->execute(['m' => $memberId]);
    $member = $m->fetch();
    if ($member === false || $member['status'] !== 'active' || $member['capability'] === null) {
        return 0;
    }
    $dedupe = $opts['dedupe'] ?? null;
    if ($dedupe !== null && (int) one_value($pdo, 'SELECT count(*) FROM notification_outbox WHERE dedupe_key LIKE :d', ['d' => $dedupe . ':m' . $memberId . ':%']) > 0) {
        return 0;
    }
    $body = (string) ($body ?? '');
    $pdo->prepare('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, :t, :r, :ti, :b)')
        ->execute(['m' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'ti' => mb_substr($title, 0, 300), 'b' => $body]);
    $n = 1;
    if ($member['member_kind'] === 'agent') {
        return $n;
    }
    $prefs = find_prefs($pdo, $memberId);
    $ins = $pdo->prepare('INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body) VALUES (:c, :m, :k, :t, :r, :d, :s, :b)');
    if ($prefs['email_enabled'] && in_array($kind, $prefs['kinds'], true)) {
        $ins->execute(['c' => 'email', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':email', 's' => mb_substr($title, 0, 300), 'b' => $body]);
        $n++;
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':text', 's' => mb_substr($title, 0, 300),
            'b' => mb_substr((string) ($opts['text'] ?? $body), 0, 300)]);
        $n++;
    }
    return $n;
}

/**
 * Queue a mail to a client: one outbox row per address (the address as it is now), the secure link already in $body and $bodyHtml (slice 6 puts it there). $kind is invoice, reminder or statement; $recordType and $recordId name the document.
 * Answers how many rows were made. $toEmails is a list of addresses or an [address => name] map.
 */
function notify_client(PDO $pdo, int $clientId, array $toEmails, string $kind, string $recordType, int $recordId, string $subject, string $body, string $bodyHtml): int
{
    if (!in_array($kind, OUTBOX_CLIENT_KINDS, true)) {
        throw new DomainException('A client is told with an invoice, a reminder or a statement.');
    }
    require_once dirname(__DIR__) . '/invoices/write.php';
    $recipients = [];
    foreach ($toEmails as $k => $v) {
        $recipients[strtolower((string) (is_string($k) ? $k : $v))] = true;
    }
    return queue_client_mail($pdo, $recordType, $recordId, $clientId, $kind, $recipients, $subject, $body, $bodyHtml);
}

/**
 * The Billing Clerk's morning note (D11; action `morning_note_send`): what the Clerk drafted, allocated and found, left for the person the settings name (or $toMember) as an in-app notice and, by their preferences, an email — the
 * home's `clerk_note` region shows the latest. $by is who wrote it (the Clerk's own member id under its run token, or a person). Answers the notification's id (0 when none was made: the recipient is not an admitted member).
 */
function send_morning_note(PDO $pdo, int $by, string $body, ?int $toMember): int
{
    $to = $toMember ?? (($v = one_value($pdo, 'SELECT morning_note_member_id FROM ct_settings WHERE id = 1')) === null ? null : (int) $v);
    if ($to === null) {
        throw new DomainException('No one receives the note: name a person, or choose the morning-note recipient in the settings.');
    }
    $day = (new DateTimeImmutable('now', new DateTimeZone((string) (one_value($pdo, 'SELECT timezone FROM ct_settings WHERE id = 1') ?: 'UTC'))))->format('M j');
    $made = notify($pdo, $to, 'morning_note', null, null, 'The morning note, ' . $day, $body, ['text' => 'The morning note, ' . $day . ': ' . $body]);
    if ($made === 0) {
        return 0;
    }
    return (int) one_value($pdo, "SELECT max(id) FROM notifications WHERE member_id = :m AND kind = 'morning_note'", ['m' => $to]);
}
