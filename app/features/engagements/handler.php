<?php
declare(strict_types=1);

/** The prelude of an engagement handler: the feature's files, the engagement a request names (seen → else 404; managed → else 403), the log with its keys. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/documents/write.php';
require_once dirname(__DIR__) . '/activity/queries.php';

/** The engagement the request names (`engagement`, `engagement_id`, or `id`), through the view: 404 when unseen. */
function engagement_from_request(PDO $pdo, ?int $id = null): array
{
    $id ??= request_integer('engagement') ?? request_integer('engagement_id') ?? request_integer('id') ?? refuse(422, 'Say which engagement.');
    return find_engagement($pdo, $id) ?? refuse(404, 'Engagement not found.');
}

/** The gate of every write on an engagement (the kit's require_can_manage_engagement() takes the id; this one has the row): its manager, an admin of its practice, Billing, the admin. */
function require_manages(array $e): void
{
    if (!$e['i_manage']) {
        refuse(403, 'You may not manage ' . $e['number'] . '; its manager, the practice\'s admin or Billing may.');
    }
}

/** log_activity with the engagement's keys (engagement_id, client_id, department_id). */
function engagement_log(PDO $pdo, array $e, string $action, array $opts = [], ?string $entityType = null, ?int $entityId = null): void
{
    log_activity($pdo, $action, $entityType ?? 'engagement', $entityId ?? $e['engagement_id'], $opts + ['engagement_id' => $e['engagement_id'], 'client_id' => $e['client_id'], 'department_id' => $e['department_id']]);
}

/** The milestone the request names, with its engagement (seen → else 404). [milestone, engagement] */
function milestone_from_request(PDO $pdo): array
{
    $id = request_integer('milestone') ?? request_integer('milestone_id') ?? request_integer('id') ?? refuse(422, 'Say which milestone.');
    $m = find_milestone($pdo, $id) ?? refuse(404, 'Milestone not found.');
    return [$m, engagement_from_request($pdo, $m['engagement_id'])];
}

/** The words for a status move and its landing notice. */
const ENGAGEMENT_MOVES = [
    'activate' => ['active', 'Activated', 'activated'], 'hold' => ['on_hold', 'Put on hold', 'held'], 'close' => ['closed', 'Closed', 'closed'],
    'reopen' => ['active', 'Reopened', 'reopened'], 'cancel' => ['cancelled', 'Cancelled', 'cancelled'],
];

/** The shared body of activate/hold/close/reopen/cancel. */
function engagement_move_handler(string $verb, bool $reasonRequired = false): never
{
    ct_handler_begin();
    require_right('engagements.manage');
    $pdo = db();
    $me = (int) current_member_id();
    $e = engagement_from_request($pdo);
    require_manages($e);
    $reason = req_has('reason') ? mb_substr((string) req_val('reason'), 0, 500) : '';
    if ($reasonRequired && $reason === '') {
        ct_refuse_fields(['reason' => 'Say why (a reason is kept on the record).']);
    }
    [$to, $did, $notice] = ENGAGEMENT_MOVES[$verb];
    $moved = ct_guard($pdo, static function () use ($pdo, $me, $e, $verb, $to, $reason): array {
        $pdo->beginTransaction();
        $moved = move_engagement($pdo, $e['engagement_id'], $to, $reason === '' ? null : $reason, $me);
        engagement_log($pdo, $e, 'engagement.' . $verb, ['before' => ['status' => $moved[0]], 'after' => ['status' => $moved[1], 'reason' => $reason === '' ? null : $reason]]);
        $pdo->commit();
        return $moved;
    });
    ct_done($did . ' ' . $e['number'], $e['engagement_id'], ct_land(return_path('/engagements/' . $e['engagement_id']), $notice), 'engagementChanged', ['status' => $moved[1]]);
}
