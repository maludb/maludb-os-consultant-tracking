<?php
declare(strict_types=1);

/**
 * What the worker READS from the rest of the estate through the kernel (K7), and the agents it hands things to (slice 8). A read is one POST to `{OS_INTERNAL_URL}/api/v1/apps/read.php` with the application's token — the kernel calls
 * the provider's shared tool only over a connection a super-admin approved (403 `no_connection` otherwise; Consultant Tracking never writes to another application). Two reads:
 *   `gl.invoice_receipts` (G1, owed by General Ledger) — the receipts the ledger holds against OUR invoice numbers → a payment of source `ledger` on the invoice, once per (reference, received date);
 *   `hr.reimbursements_paid` (H2, owed by HR) — the expenses a pay run paid → the expense marked reimbursed with the pay run's reference, by the database's verb.
 * A kernel with no connection approved, or no provider yet, is logged once a day and changes nothing. Then the agent dispatches: a failed hand-off to an agent is retried through the chat endpoint, at most three attempts.
 */
require_once dirname(__DIR__) . '/invoices/handler.php';
require_once dirname(__DIR__) . '/expenses/handler.php';

/**
 * One K7 read. Answers ['status' => HTTP status (0 = the kernel did not answer), 'result' => the provider's answer | null, 'error' => the kernel's code | null].
 * Logs `share.read` (cron): the provider, the tool, the argument KEYS, the outcome and the rows answered — never the answer; a refusal already logged in the last 24 hours is not logged again.
 */
function kernel_read(PDO $pdo, string $provider, string $tool, array $arguments): array
{
    $answer = kernel_call('POST', '/api/v1/apps/read.php', ['provider' => $provider, 'tool' => $tool, 'arguments' => (object) $arguments], [], 20);
    $status = $answer === null ? 0 : $answer['status'];
    $body = $answer['body'] ?? [];
    $result = $status === 200 ? ($body['result'] ?? []) : null;
    $code = $status === 200 ? null : (string) ($body['error']['code'] ?? ($status === 0 ? 'unreachable' : 'http_' . $status));
    $rows = is_array($result) ? count(array_is_list($result) ? $result : ($result['rows'] ?? $result['receipts'] ?? $result['items'] ?? [])) : null;
    $again = $code !== null && (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'share.read' AND source = 'cron' AND after->>'provider' = :p AND after->>'tool' = :t AND after->>'code' = :c AND occurred_at > now() - interval '24 hours'",
        ['p' => $provider, 't' => $tool, 'c' => $code]) > 0;
    if (!$again) {
        log_activity($pdo, 'share.read', 'share', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['direction' => 'out', 'provider' => $provider, 'tool' => $tool, 'keys' => array_keys($arguments), 'status' => $status, 'code' => $code, 'rows' => $rows]]);
    }
    return ['status' => $status, 'result' => $result, 'error' => $code];
}

/** The rows of a provider's answer: the list itself, or its `rows`, `receipts` or `items`. */
function kernel_rows(?array $result): array
{
    if ($result === null) {
        return [];
    }
    $rows = array_is_list($result) ? $result : ($result['rows'] ?? $result['receipts'] ?? $result['items'] ?? []);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/**
 * The ledger's receipts for the last 60 days → payments of source `ledger` on our invoices (matched by the invoice NUMBER the row names as `external_ref`), once per (reference, received date): a receipt already recorded — even one a person
 * since voided — is never recorded again. A receipt the invoice cannot take (not open, another currency, more than is owed) is refused in the database's words and counted. When the row names what the ledger calls the invoice
 * (`ledger_ref`) and the invoice has none, that becomes its `external_ref`. A paid invoice tells billing and the manager (`invoice_paid`). Answers counts; `status` is the read's.
 */
function receipts_pass(PDO $pdo, string $now): array
{
    $out = ['status' => 0, 'rows' => 0, 'recorded' => 0, 'duplicates' => 0, 'unknown' => 0, 'refused' => 0, 'refs_set' => 0, 'code' => null];
    $today = worker_today($pdo, new DateTimeImmutable($now));
    $r = kernel_read($pdo, 'gl', 'invoice_receipts', ['from' => (new DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d'), 'to' => $today]);
    $out['status'] = $r['status'];
    $out['code'] = $r['error'];
    if ($r['status'] !== 200) {
        return $out;
    }
    foreach (kernel_rows($r['result']) as $row) {
        $out['rows']++;
        $number = trim((string) ($row['external_ref'] ?? $row['invoice_number'] ?? ''));
        $on = (string) ($row['received_on'] ?? '');
        $amount = parse_money((string) ($row['amount'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));
        $reference = $reference === '' ? null : mb_substr($reference, 0, 200);
        $inv = $number === '' ? false : $pdo->prepare('SELECT id, engagement_id, client_id, number, currency, status, external_ref FROM invoices WHERE number = :n');
        if ($inv !== false) {
            $inv->execute(['n' => $number]);
            $inv = $inv->fetch();
        }
        if ($inv === false || $amount === null || (float) $amount <= 0 || DateTimeImmutable::createFromFormat('!Y-m-d', $on) === false) {
            $out['unknown']++;
            continue;
        }
        $id = (int) $inv['id'];
        if ((int) one_value($pdo, "SELECT count(*) FROM invoice_payments WHERE invoice_id = :i AND source = 'ledger' AND paid_on = CAST(:d AS date) AND reference IS NOT DISTINCT FROM :r", ['i' => $id, 'd' => $on, 'r' => $reference]) > 0) {
            $out['duplicates']++;
            continue;
        }
        if (isset($row['currency']) && strtoupper(trim((string) $row['currency'])) !== trim((string) $inv['currency'])) {
            $out['refused']++;
            continue;
        }
        $method = isset(PAYMENT_METHODS[(string) ($row['method'] ?? '')]) ? (string) $row['method'] : 'other';
        try {
            $pdo->beginTransaction();
            $pid = record_payment($pdo, $id, ['paid_on' => $on, 'amount' => $amount, 'method' => $method, 'reference' => $reference], null, 'ledger');
            $after = $pdo->prepare('SELECT id AS invoice_id, engagement_id, number, total, currency, status, balance_due, paid_at FROM invoices WHERE id = :i');
            $after->execute(['i' => $id]);
            $a = $after->fetch();
            log_activity($pdo, 'payment.record', 'payment', $pid, ['source' => 'cron', 'actor_member_id' => null, 'invoice_id' => $id, 'engagement_id' => (int) $inv['engagement_id'], 'client_id' => (int) $inv['client_id'],
                'after' => ['payment_id' => $pid, 'invoice_number' => $inv['number'], 'amount' => $amount, 'currency' => trim((string) $inv['currency']), 'method' => $method, 'reference' => $reference, 'source' => 'ledger',
                            'invoice_status' => $a['status'], 'balance_due' => $a['balance_due'], 'via' => 'ledger']]);
            $ledgerRef = trim((string) ($row['ledger_ref'] ?? ''));
            if ($ledgerRef !== '' && ($inv['external_ref'] ?? '') === '') {
                [$was, $now2] = set_external_ref($pdo, $id, mb_substr($ledgerRef, 0, 200), null);
                log_activity($pdo, 'invoice.external_ref', 'invoice', $id, ['source' => 'cron', 'actor_member_id' => null, 'invoice_id' => $id, 'engagement_id' => (int) $inv['engagement_id'], 'client_id' => (int) $inv['client_id'],
                    'before' => ['external_ref' => $was], 'after' => ['external_ref' => $now2, 'number' => $inv['number'], 'via' => 'ledger']]);
                $out['refs_set']++;
            }
            if ($a['status'] === 'paid' && $inv['status'] !== 'paid') {
                notify_paid($pdo, $a, 0);
            }
            $pdo->commit();
            $out['recorded']++;
        } catch (DomainException $e) {
            $pdo->rollBack();
            $out['refused']++;
        }
    }
    return $out;
}

/**
 * HR's paid reimbursements → the expenses marked reimbursed, with the pay run's reference, by ct_expense_mark_reimbursed() (the database refuses one that is not approved, was paid with the company card, or is already reimbursed —
 * an already-reimbursed expense is counted and left alone). The person is told (`expense_reimbursed`, the same dedupe key as the button's). Logged `expense.reimburse` (cron, `after.via = hr`). Answers counts; `status` is the read's.
 */
function reimbursements_pass(PDO $pdo, string $now): array
{
    $out = ['status' => 0, 'rows' => 0, 'marked' => 0, 'already' => 0, 'unknown' => 0, 'refused' => 0, 'code' => null];
    $today = worker_today($pdo, new DateTimeImmutable($now));
    $r = kernel_read($pdo, 'hr', 'reimbursements_paid', ['from' => (new DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d'), 'to' => $today]);
    $out['status'] = $r['status'];
    $out['code'] = $r['error'];
    if ($r['status'] !== 200) {
        return $out;
    }
    foreach (kernel_rows($r['result']) as $row) {
        $out['rows']++;
        $number = trim((string) ($row['expense_number'] ?? ''));
        $ref = trim((string) ($row['reference'] ?? $row['pay_run'] ?? ''));
        $st = $pdo->prepare('SELECT id, number, member_id, engagement_id, amount, currency, billable_amount, reimbursed_at FROM expenses WHERE number = :n');
        $st->execute(['n' => $number]);
        $x = $number === '' ? false : $st->fetch();
        if ($x === false) {
            $out['unknown']++;
            continue;
        }
        if ($x['reimbursed_at'] !== null) {
            $out['already']++;
            continue;
        }
        $ref = $ref === '' ? null : mb_substr($ref, 0, 200);
        try {
            $pdo->beginTransaction();
            mark_reimbursed($pdo, [(int) $x['id']], null, $ref);
            log_activity($pdo, 'expense.reimburse', 'expense', (int) $x['id'], ['source' => 'cron', 'actor_member_id' => null, 'engagement_id' => (int) $x['engagement_id'],
                'before' => ['reimbursed' => false], 'after' => ['reimbursed' => true, 'member_id' => (int) $x['member_id'], 'reference' => $ref, 'number' => $x['number'], 'amount' => $x['amount'], 'currency' => trim((string) $x['currency']),
                                                                    'billable_amount' => $x['billable_amount'], 'via' => 'hr']]);
            queue_expense_notice($pdo, (int) $x['member_id'], 'expense_reimbursed', (int) $x['id'], ['title' => 'Your expense ' . $x['number'] . ' (' . fmt_money($x['amount'], trim((string) $x['currency'])) . ') was reimbursed',
                'body' => $ref === null ? 'It was marked paid.' : 'Reference: ' . $ref, 'dedupe' => 'exp-reimbursed:' . $x['id']]);
            $pdo->commit();
            $out['marked']++;
        } catch (DomainException) {
            $pdo->rollBack();
            $out['refused']++;
        }
    }
    return $out;
}

// ---- agent dispatches ----------------------------------------------------------------------------------------------------------------------------------------------------------

/** The words an agent is given for a kind of dispatch and its record — the facts, in one sentence, and what to do (an agent drafts; a person approves). */
function dispatch_utterance(string $kind, ?string $recordType, ?int $recordId): string
{
    $what = $recordType === null || $recordId === null ? 'a record' : str_replace('_', ' ', $recordType) . ' ' . $recordId;
    return match ($kind) {
        'timesheet_submitted' => 'A week was submitted (' . $what . '). Check it against the engagement and tell me what needs a person; approve nothing.',
        'budget_crossed'      => 'An engagement crossed a budget threshold (' . $what . '). Say where the budget stands and what a manager should look at.',
        'statement_closed'    => 'The OS closed a monthly AI statement (' . $what . '). Pull and allocate it by the rules, and report the remainder.',
        'invoice_viewed'      => 'A client opened an invoice (' . $what . ') that is still unpaid. Say whether a reminder is due; send nothing.',
        'claim_submitted'     => 'A client says they paid an invoice (' . $what . '). Check what the ledger shows and tell billing whether it can be confirmed.',
        default               => 'Please look at ' . $what . ' and tell me what needs a person.',
    };
}

/**
 * One turn through the kernel's chat endpoint (or, when the dispatch already has a run, a look at that run — a second turn is never started for work the kernel already began).
 * Answers ['status' => answered | refused | failed | awaiting_approval, 'detail', 'reply' (≤ 200 characters), 'run_id'].
 */
function dispatch_turn(array $d): array
{
    $acting = (int) ($d['acting_member_id'] ?? $d['agent_member_id']);
    if ($d['run_id'] !== null) {
        $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $d['run_id'], null, [], 20);
    } else {
        $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . (int) $d['agent_member_id'], ['utterance' => dispatch_utterance((string) $d['kind'], $d['record_type'], $d['record_id'] === null ? null : (int) $d['record_id']),
            'context' => ['application' => app_key(), 'dispatch' => (int) $d['id']], 'conversation_id' => 'ct-dispatch-' . (string) ($d['request_id'] ?? $d['id']), 'wait' => 20], ['X-Acting-Member: ' . $acting], 30);
    }
    if ($answer === null || $answer['status'] >= 500 || $answer['status'] === 401) {
        return ['status' => 'failed', 'detail' => $answer === null ? 'The OS did not answer.' : 'The OS answered HTTP ' . $answer['status'] . '.', 'reply' => null, 'run_id' => $d['run_id']];
    }
    $b = $answer['body'] ?? [];
    $run = isset($b['run_id']) ? (int) $b['run_id'] : ($d['run_id'] === null ? null : (int) $d['run_id']);
    if ($answer['status'] >= 400) {
        return ['status' => 'refused', 'detail' => mb_substr((string) ($b['error']['message'] ?? 'The OS refused it.'), 0, 300), 'reply' => null, 'run_id' => $run];
    }
    if (($b['status'] ?? '') === 'awaiting_approval') {
        return ['status' => 'awaiting_approval', 'detail' => null, 'reply' => null, 'run_id' => $run];
    }
    if (empty($b['finished']) && ($b['status'] ?? '') !== 'succeeded') {
        return ['status' => 'failed', 'detail' => 'The turn did not finish (' . (string) ($b['status'] ?? 'unknown') . ').', 'reply' => null, 'run_id' => $run];
    }
    return ['status' => 'answered', 'detail' => null, 'reply' => mb_substr((string) ($b['reply'] ?? ''), 0, 200), 'run_id' => $run];
}

/** Failed dispatches with fewer than three attempts, oldest first (at most 20 a pass): one more turn each. Logged `agent.dispatch` (cron: kind, via, status, attempts — never the reply). Answers ['retried', 'answered', 'failed', 'refused']. */
function dispatch_retry_pass(PDO $pdo, string $now): array
{
    $out = ['retried' => 0, 'answered' => 0, 'failed' => 0, 'refused' => 0];
    foreach ($pdo->query("SELECT * FROM agent_dispatches WHERE status = 'failed' AND attempts < 3 ORDER BY id LIMIT 20")->fetchAll() as $d) {
        $r = dispatch_turn($d);
        $attempts = (int) $d['attempts'] + 1;
        $pdo->prepare("UPDATE agent_dispatches SET status = :s, detail = :d, reply_excerpt = :r, run_id = :run, attempts = :a, answered_at = CASE WHEN :s2 IN ('answered', 'awaiting_approval') THEN now() ELSE answered_at END WHERE id = :id")
            ->execute(['s' => $r['status'], 'd' => $r['detail'], 'r' => $r['reply'], 'run' => $r['run_id'], 'a' => $attempts, 's2' => $r['status'], 'id' => (int) $d['id']]);
        log_activity($pdo, 'agent.dispatch', 'agent_dispatch', (int) $d['id'], ['source' => 'cron', 'actor_member_id' => null, 'after' => ['kind' => $d['kind'], 'via' => $d['via'], 'status' => $r['status'], 'agent_member_id' => (int) $d['agent_member_id'],
            'record_type' => $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id'], 'attempts' => $attempts]]);
        $out['retried']++;
        $out[in_array($r['status'], ['answered', 'awaiting_approval'], true) ? 'answered' : $r['status']]++;
    }
    return $out;
}
