<?php
declare(strict_types=1);
/**
 * Action `morning_note_send` (log `morning_note.send`: the recipient, the length — never the note's words, which carry money): billing.write — Billing, or the Billing Clerk under its run token (the Clerk's duty ends here, `0 7 * * *`).
 * `body` is the note; `to_member` defaults to the settings' recipient. The note lands in that person's bell (and their mail, if they chose it) and in the home's Clerk region; nothing leaves the application but by their own preferences.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notify/queue.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$body = (string) req_val('body');
if ($body === '') {
    $errors['body'] = 'The note is required.';
} elseif (mb_strlen($body) > 8000) {
    $errors['body'] = 'The note is at most 8,000 characters.';
}
$to = ct_ref($pdo, 'to_member', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'person', $errors, true);
if ($to === null && $errors === [] && one_value($pdo, 'SELECT morning_note_member_id FROM ct_settings WHERE id = 1') === null) {
    $errors['to_member'] = 'No one receives the note: name a person, or choose the morning-note recipient in the settings.';
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$id = ct_guard($pdo, static function () use ($pdo, $me, $body, $to): int {
    $pdo->beginTransaction();
    $recipient = $to ?? (int) one_value($pdo, 'SELECT morning_note_member_id FROM ct_settings WHERE id = 1');
    $id = send_morning_note($pdo, $me, $body, $to);
    if ($id > 0) {
        log_activity($pdo, 'morning_note.send', 'notification', $id, ['after' => ['to_member_id' => $recipient, 'length' => mb_strlen($body)]]);
    }
    $pdo->commit();
    return $id;
});
if ($id === 0) {
    refuse(422, 'The person is not admitted here, so the note reaches no one.');
}
ct_done('Left the morning note', $id, ct_land(return_path('/notifications'), 'sent'), 'notificationChanged', ['notification_id' => $id]);
