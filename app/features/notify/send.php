<?php
declare(strict_types=1);

/**
 * Sending the outbox (slice 8): outbox_send_batch() is what the worker's `send` step calls every minute. One row at a time, each locked while it is sent (a second run skips it):
 *   EMAIL through MaluMail (`malumail_send`, MAIL_FROM) — 2xx accepted = sent with the provider's message id; a refused or suppressed address = skipped (`undeliverable:<reason>`); no address = skipped (`no_address`); a person who turned
 *   email off since = skipped (`opted_out`); 429, 5xx or no answer = retried after a backoff of 2, 4, 8, 16 minutes, the fifth failure final (`failed`). No MALUMAIL_API_KEY leaves the row queued, uncounted.
 *   TEXT through the kernel's K6 (`POST {OS_INTERNAL_URL}/api/v1/notify/sms.php`, the application's token — this application never holds a Twilio key) — 202 = sent (the kernel's notification id kept); every refusal the kernel names
 *   (`no_sender`, `not_held`, `no_verified_phone`, `opted_out`, `rate_limited`, `invalid`) = skipped with the code, and the email row, a row of its own, still goes; 5xx, 401 or no answer = retried like an email.
 * A row is never deleted. Each outcome is logged `notification.send|skip|fail` with the kind, the channel and the code — never a body, an address or a link.
 */
require_once __DIR__ . '/kinds.php';
require_once __DIR__ . '/body.php';
require_once dirname(__DIR__) . '/settings/queries.php';

/**
 * Send what is due. $now fakes the clock for the selection and the backoff (CT_WORKER_NOW in the proofs). Answers counts: sent, skipped, retried, failed, unconfigured.
 */
function outbox_send_batch(PDO $pdo, int $limit = 100, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable();
    $out = ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'unconfigured' => 0];
    $stamp = $now->format('Y-m-d H:i:s.uP');
    $ids = $pdo->prepare("SELECT id FROM notification_outbox WHERE status = 'queued' AND send_after <= CAST(:now AS timestamptz) ORDER BY id LIMIT " . max(1, (int) $limit));
    $ids->execute(['now' => $stamp]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM notification_outbox WHERE id = :id AND status = 'queued' FOR UPDATE SKIP LOCKED");
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if ($row === false) {
            $pdo->rollBack();
            continue;
        }
        [$outcome, $code, $ref] = $row['channel'] === 'text' ? outbox_send_text($pdo, $row) : outbox_send_email($pdo, $row);
        if ($outcome === 'unconfigured') {
            $pdo->rollBack();
            $out['unconfigured']++;
            continue;
        }
        $attempts = (int) $row['attempts'] + 1;
        if ($outcome === 'retry') {
            if ($attempts >= OUTBOX_MAX_ATTEMPTS) {
                $outcome = 'failed';
                $code = 'failed:' . $code;
            } else {
                $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = CAST(:now AS timestamptz) + make_interval(mins => :m) WHERE id = :id")
                    ->execute(['a' => $attempts, 'd' => 'retry:' . $code, 'now' => $stamp, 'm' => min(60, 2 ** $attempts), 'id' => $id]);
                $out['retried']++;
                $pdo->commit();
                continue;
            }
        }
        $pdo->prepare("UPDATE notification_outbox SET status = :s, attempts = :a, detail = :d, provider_ref = :p, sent_at = CASE WHEN :s2 = 'sent' THEN CAST(:now AS timestamptz) ELSE sent_at END WHERE id = :id")
            ->execute(['s' => $outcome, 'a' => $attempts, 'd' => $code, 'p' => $ref, 's2' => $outcome, 'now' => $stamp, 'id' => $id]);
        log_activity($pdo, 'notification.' . ($outcome === 'sent' ? 'send' : ($outcome === 'skipped' ? 'skip' : 'fail')), 'notification_outbox', (int) $id, [
            'source' => 'cron', 'actor_member_id' => null, 'client_id' => $row['client_id'] === null ? null : (int) $row['client_id'],
            'invoice_id' => $row['record_type'] === 'invoice' && $row['record_id'] !== null ? (int) $row['record_id'] : null,
            'after' => ['kind' => $row['kind'], 'channel' => $row['channel'], 'code' => $code, 'attempts' => $attempts]]);
        $pdo->commit();
        $out[$outcome]++;
    }
    return $out;
}

/** What a person has chosen NOW for a channel: false when they turned it off since the row was queued. */
function outbox_member_allows(PDO $pdo, int $memberId, string $channel): bool
{
    $p = find_prefs($pdo, $memberId);
    return $channel === 'text' ? $p['text_enabled'] : $p['email_enabled'];
}

/** [outcome, code, provider_ref] for an email row: sent | skipped | retry | unconfigured. */
function outbox_send_email(PDO $pdo, array $row): array
{
    if ((string) env('MALUMAIL_API_KEY', '') === '') {
        return ['unconfigured', 'unconfigured', null];
    }
    if ($row['member_id'] !== null) {
        $st = $pdo->prepare("SELECT email::text FROM members WHERE id = :m AND status = 'active'");
        $st->execute(['m' => (int) $row['member_id']]);
        $to = $st->fetchColumn() ?: null;
        if ($to !== null && !outbox_member_allows($pdo, (int) $row['member_id'], 'email')) {
            return ['skipped', 'opted_out', null];
        }
    } else {
        $to = ($row['to_email'] ?? '') !== '' ? (string) $row['to_email'] : null;
    }
    if ($to === null) {
        return ['skipped', 'no_address', null];
    }
    [$subject, $text, $html] = outbox_email_parts($row);
    try {
        $r = malumail_send(['from' => (string) env('MAIL_FROM', 'noreply@localhost'), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()), 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html]);
    } catch (RuntimeException) {
        return ['retry', 'transport', null];
    }
    $rejected = (array) ($r['body']['rejected'] ?? []);
    if ($r['status'] >= 200 && $r['status'] < 300 && $rejected === [] && $r['message_id'] !== null) {
        return ['sent', null, $r['message_id']];
    }
    if ($r['status'] === 400 || ($r['status'] >= 200 && $r['status'] < 300)) {
        $why = (string) ($rejected[0]['reason'] ?? ($r['body']['error'] ?? 'undeliverable'));
        return ['skipped', 'undeliverable:' . mb_substr($why, 0, 80), null];
    }
    return ['retry', (string) $r['status'], null];
}

/** [outcome, code, provider_ref] for a text row through the kernel's K6: sent | skipped | retry | unconfigured. */
function outbox_send_text(PDO $pdo, array $row): array
{
    if ((string) env('OS_APPLICATION_TOKEN', '') === '') {
        return ['unconfigured', 'unconfigured', null];
    }
    if (!outbox_member_allows($pdo, (int) $row['member_id'], 'text')) {
        return ['skipped', 'opted_out', null];
    }
    $answer = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => (int) $row['member_id'], 'text' => outbox_text_words($row), 'reference' => 'ct-outbox-' . $row['id']]);
    if ($answer === null) {
        return ['retry', 'unreachable', null];
    }
    if ($answer['status'] === 202 || $answer['status'] === 200) {
        $kid = $answer['body']['notification']['id'] ?? null;
        return ['sent', null, $kid === null ? null : 'kernel:' . $kid];
    }
    $code = (string) ($answer['body']['error']['code'] ?? ('http_' . $answer['status']));
    if (in_array($answer['status'], [422, 503, 404, 403], true)) {
        return ['skipped', $code, null];
    }
    return ['retry', $code, null];
}
