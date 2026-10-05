<?php
declare(strict_types=1);
/**
 * Action `milestone_accept` (log `milestone.accept`: before/after status, accepted_by, the evidence attachment id; confirm; `other` for agents): milestones.write + manage.
 * Needs an acceptance note (who at the client accepted and how); accepted_by defaults to the caller; a multipart `file` is stored first as the evidence (record_type milestone).
 */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('milestones.write');
$pdo = db();
$me = (int) current_member_id();
[$m, $e] = milestone_from_request($pdo);
require_manages($e);
$errors = [];
$note = trim((string) req_val('acceptance_note'));
if ($note === '' || mb_strlen($note) > 2000) {
    $errors['acceptance_note'] = 'Say who at the client accepted it and how (up to 2,000 characters).';
}
$by = ct_ref($pdo, req_has('accepted_by') ? 'accepted_by' : 'accepted_by_id', $me, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors) ?? $me;
$file = $_FILES['file'] ?? null;
if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $file = null;
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
[$moved, $aid] = ct_guard($pdo, static function () use ($pdo, $me, $e, $m, $note, $by, $file): array {
    $pdo->beginTransaction();
    $aid = $file === null ? null : store_attachment($pdo, 'milestone', $m['milestone_id'], $file, $me, false);
    $moved = move_milestone($pdo, $m['milestone_id'], 'accepted', ['accepted_by' => $by, 'acceptance_note' => $note], $me);
    engagement_log($pdo, $e, 'milestone.accept', ['before' => ['status' => $moved[0]], 'after' => ['status' => 'accepted', 'name' => $m['name'], 'amount' => $m['amount'], 'currency' => $e['currency'], 'accepted_by' => $by, 'attachment_id' => $aid]], 'milestone', $m['milestone_id']);
    $pdo->commit();
    return [$moved, $aid];
});
ct_done('Accepted ' . $m['name'] . ' on ' . $e['number'] . ((float) $m['amount'] > 0 ? ' (' . fmt_money($m['amount'], $e['currency']) . ' to invoice)' : ''), $m['milestone_id'], ct_land(return_path('/engagements/' . $e['engagement_id'] . '/milestones'), 'accepted', 'milestone-card-' . $m['milestone_id']), 'engagementChanged', ['status' => 'accepted', 'attachment_id' => $aid]);
