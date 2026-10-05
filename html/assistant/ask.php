<?php
declare(strict_types=1);

/**
 * The command bar (chat-actions), run by the kernel (sign-on-and-directory.md §5): the utterance goes to the kernel's chat
 * endpoint as the acting person; ONE turn of Consultant Tracking's expert answers. Consultant Tracking holds no model key. Renders the reply and
 * the actions (a paused one as "waits for approval"), follows nothing blindly; fires HX-Trigger per entity changed. Not an
 * action of the manifest — the kernel runs the agent. Logs `assistant.ask` with the utterance's length and the run id, never the text.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login();
require_post();
verify_csrf();
require_human();
$pdo = db();

$utterance = trim((string) ($_POST['utterance'] ?? $_POST['message'] ?? ''));
$screen = request_string('screen');
$entity = request_string('entity');
$recordId = request_integer('record_id');
if ($utterance === '' || mb_strlen($utterance) > 2000) {
    http_response_code(422);
    echo view('shared/assistant-reply.php', ['error' => 'Say what you want in a sentence or two.']);
    exit;
}
$_SESSION['assistant_conversation'] ??= 'ct-' . bin2hex(random_bytes(8));
$conversation = (string) $_SESSION['assistant_conversation'];

$answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=expert', [
    'utterance' => $utterance, 'screen' => $screen,
    'context' => ['entity' => $entity, 'record_id' => $recordId, 'application' => app_key()],
    'conversation_id' => $conversation, 'wait' => 60,
], ['X-Acting-Member: ' . current_member_id()], 75);            // the kernel holds the request up to `wait`

$fallback = [
    400 => 'The kernel did not know who was asking.',
    403 => 'You are not allowed to use the assistant here.',
    404 => 'Consultant Tracking has no expert yet — a super-admin names one in the kernel.',
    409 => 'The expert is busy; try again in a moment.',
    422 => 'Say what you want in a sentence or two.',
];
$body = $answer['body'] ?? [];
if ($answer === null || $answer['status'] === 401 || $answer['status'] >= 500) {
    log_activity($pdo, 'assistant.ask', null, null, ['screen' => $screen, 'after' => ['length' => mb_strlen($utterance), 'record_id' => $recordId, 'kernel' => $answer['status'] ?? 'unreachable']]);
    http_response_code(503);
    echo view('shared/assistant-reply.php', ['error' => 'The kernel is not reachable right now.']);
    exit;
}
if ($answer['status'] >= 400) {
    // A kernel refusal is shown in the kernel's words.
    log_activity($pdo, 'assistant.ask', null, null, ['screen' => $screen, 'after' => ['length' => mb_strlen($utterance), 'record_id' => $recordId, 'kernel' => $answer['status']]]);
    http_response_code($answer['status']);
    echo view('shared/assistant-reply.php', ['error' => (string) ($body['error']['message'] ?? ($fallback[$answer['status']] ?? 'The assistant could not answer.'))]);
    exit;
}
// A long run: poll until finished or the wait is spent.
$deadline = time() + 55;
while ($answer['status'] === 202 && empty($body['finished']) && time() < $deadline && !empty($body['run_id'])) {
    usleep(1500000);
    $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $body['run_id'], null, [], 20);
    if ($answer === null || $answer['status'] >= 400) {
        break;
    }
    $body = $answer['body'] ?? [];
}
$actions = is_array($body['actions'] ?? null) ? $body['actions'] : [];
// A tool `{entity}_{verb}` that succeeded refreshes the screens listening for `{entity}Changed` (chat-actions, Pattern D).
$families = ['client' => 'client', 'engagement' => 'engagement', 'milestone' => 'engagement', 'time' => 'timesheet', 'timer' => 'timesheet', 'timesheet' => 'timesheet', 'expense' => 'expense',
             'usage' => 'usage', 'ai' => 'usage', 'allocation' => 'usage', 'recurring' => 'usage', 'invoice' => 'invoice', 'payment' => 'invoice', 'credit' => 'invoice', 'secure' => 'invoice',
             'rate' => 'rateCard', 'cost' => 'rateCard', 'budget' => 'engagement', 'note' => 'note', 'attachment' => 'attachment', 'prefs' => 'prefs', 'token' => 'token', 'notification' => 'notification'];
$events = [];
foreach ($actions as $a) {
    if (($a['status'] ?? '') === 'ok' && preg_match('/^([a-z]+)(?:_[a-z_]+)?$/', (string) ($a['tool'] ?? ''), $m) && isset($families[$m[1]])) {
        $events[] = $families[$m[1]] . 'Changed';
    }
}
if ($events !== []) {
    hx_trigger(implode(', ', array_unique($events)));
}
log_activity($pdo, 'assistant.ask', null, null, ['screen' => $screen, 'after' => [
    'length' => mb_strlen($utterance), 'record_id' => $recordId, 'run_id' => $body['run_id'] ?? null, 'status' => $body['status'] ?? null, 'actions' => count($actions), 'cost' => $body['cost'] ?? null]]);
echo view('shared/assistant-reply.php', ['reply' => (string) ($body['reply'] ?? ''), 'status' => (string) ($body['status'] ?? ''),
    'actions' => $actions, 'approval' => $body['approval_request_id'] ?? null, 'finished' => !empty($body['finished'])]);
