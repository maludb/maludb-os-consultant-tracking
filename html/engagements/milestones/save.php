<?php
declare(strict_types=1);
/** Action `milestone_save` (by the presence of `milestone`; log `milestone.save`: name, amount, currency, due_date — changed fields on an update): milestones.write + manage. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('milestones.write');
$pdo = db();
$me = (int) current_member_id();
$milestoneId = request_integer('milestone') ?? request_integer('milestone_id');
$cur = null;
if ($milestoneId !== null) {
    $cur = milestone_state($pdo, $milestoneId) ?? refuse(404, 'Milestone not found.');
    $_POST['engagement'] = (string) $cur['engagement_id'];
}
$e = engagement_from_request($pdo);
require_manages($e);
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 160) {
    $errors['name'] = 'Give the milestone a name of up to 160 characters.';
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 2000)) : ($cur['description'] ?? null);
$amount = $cur['amount'] ?? '0.00';
if (req_has('amount')) {
    $amount = (string) req_val('amount') === '' ? '0.00' : parse_money(req_val('amount'));
    if ($amount === null || (float) $amount < 0) {
        $errors['amount'] = 'The amount is zero or more (0 on a deliverable that is not billed on acceptance).';
    }
}
$due = $cur['due_date'] ?? null;
if (req_has('due_date')) {
    $d = request_date('due_date');
    if ($d === false) {
        $errors['due_date'] = 'The due date is a date, or blank.';
    } else {
        $due = $d;
    }
}
$sort = ct_int('sort_order', $cur['sort_order'] ?? ((int) one_value($pdo, 'SELECT COALESCE(max(sort_order), 0) + 1 FROM milestones WHERE engagement_id = :e', ['e' => $e['engagement_id']])), 0, 1000, 'The order', $errors);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['name' => $name, 'description' => $description, 'amount' => $amount, 'due_date' => $due, 'sort_order' => $sort];
$loggable = static fn (array $m): array => ['name' => $m['name'], 'amount' => $m['amount'], 'due_date' => $m['due_date'], 'sort_order' => $m['sort_order']];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $e, $milestoneId, $cur, $f, $loggable): int {
    $pdo->beginTransaction();
    $newId = save_milestone($pdo, $milestoneId, $e['engagement_id'], $f, $me);
    $after = $loggable(milestone_state($pdo, $newId)) + ['currency' => $e['currency']];
    if ($cur === null) {
        engagement_log($pdo, $e, 'milestone.save', ['after' => $after], 'milestone', $newId);
    } else {
        $d = ct_diff($loggable($cur), $loggable(milestone_state($pdo, $newId)));
        if ($d['after'] !== []) {
            engagement_log($pdo, $e, 'milestone.save', ['before' => $d['before'], 'after' => $d['after']], 'milestone', $newId);
        }
    }
    $pdo->commit();
    return $newId;
});
ct_done(($milestoneId === null ? 'Added the milestone ' : 'Saved the milestone ') . $f['name'] . ' on ' . $e['number'], $newId, ct_land(return_path('/engagements/' . $e['engagement_id'] . '/milestones'), 'milestone', 'milestone-card-' . $newId), 'engagementChanged', ['milestone_id' => $newId, 'engagement_id' => $e['engagement_id']]);
