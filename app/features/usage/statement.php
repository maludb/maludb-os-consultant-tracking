<?php
declare(strict_types=1);

/**
 * The kernel's monthly AI statement (slice 5): the call to the kernel's ledger feed (the one kernel_call() client), each document kept VERBATIM by ct_ai_statement_load(), and the allocation by rules
 * (ct_ai_statement_allocate()) — the arithmetic, the rounding once per piece, the remainder and the 100 % rule are the database's. A statement the kernel has not closed is shown, never allocated.
 */

/** The kernel did not answer (down, no token, a refusal): nothing is changed; the handler answers 503 in words. */
final class KernelUnavailable extends RuntimeException
{
}

/** One call to the kernel's ledger feed: the decoded body, or KernelUnavailable in words. */
function kernel_ledger(?string $period = null): array
{
    $path = '/api/v1/ledger/periods.php' . ($period === null ? '' : '?period=' . rawurlencode($period));
    $answer = kernel_call('GET', $path);
    if ($answer === null) {
        throw new KernelUnavailable('The OS could not be reached, so nothing was pulled. Try again in a moment.');
    }
    if ($answer['status'] === 401 || $answer['status'] === 403) {
        throw new KernelUnavailable('The OS did not accept this application\'s token for its statements. Ask a super-admin to check the application\'s token.');
    }
    if ($answer['status'] === 422 || $answer['status'] === 404) {
        throw new DomainException('The OS has no statement for ' . ($period ?? 'that period') . '.');
    }
    if ($answer['status'] !== 200 || !is_array($answer['body'])) {
        throw new KernelUnavailable('The OS answered ' . $answer['status'] . ' for its statements, so nothing was pulled.');
    }
    return $answer['body'];
}

/**
 * Pull the kernel's months into this application. No $period: every month the kernel lists that this application does not hold yet, and every month it holds that the kernel still had open (their figures
 * move until the close); a month already pulled and closed is left alone. With $period: that month, whatever it is (the database refuses a changed document for a month already allocated).
 * Answers the months touched: [{period, kernel_status, status, lines, document_hash, new, changed}].
 */
function pull_ai_statements(PDO $pdo, ?string $period, int $by): array
{
    $docs = [];
    if ($period !== null) {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new DomainException('A month is written YYYY-MM.');
        }
        $docs[] = kernel_ledger($period);
    } else {
        $list = kernel_ledger();
        $held = [];
        foreach ($pdo->query('SELECT period, kernel_status FROM ai_statements')->fetchAll() as $h) {
            $held[$h['period']] = $h['kernel_status'];
        }
        foreach (($list['periods'] ?? []) as $m) {
            $p = (string) ($m['period'] ?? '');
            if (preg_match('/^\d{4}-\d{2}$/', $p) && (!isset($held[$p]) || $held[$p] === 'open')) {
                $docs[] = kernel_ledger($p);
            }
        }
    }
    $touched = [];
    $before = $pdo->prepare('SELECT document_hash FROM ai_statements WHERE period = :p');
    foreach ($docs as $doc) {
        $before->execute(['p' => (string) ($doc['period'] ?? '')]);
        $was = $before->fetchColumn();
        try {
            $st = $pdo->prepare('SELECT * FROM ct_ai_statement_load(CAST(:d AS jsonb))');
            $st->execute(['d' => json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $s = $st->fetch();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === 'P0001') {
                throw new DomainException(db_message($e, 'That statement could not be kept.'));
            }
            throw $e;
        }
        $lines = (int) one_value($pdo, 'SELECT count(*) FROM ai_statement_lines WHERE statement_id = :s', ['s' => $s['id']]);
        $touched[] = ['statement_id' => (int) $s['id'], 'period' => $s['period'], 'kernel_status' => $s['kernel_status'], 'status' => $s['status'], 'lines' => $lines, 'document_hash' => $s['document_hash'],
                      'new' => $was === false, 'changed' => $was !== false && $was !== $s['document_hash']];
    }
    return $touched;
}

/**
 * Allocate a CLOSED month by the rules valid in it (the verb; an open month is refused in its own words). Answers ['made' => how many charges, 'summary' => per provider and currency (the statement's total,
 * what is allocated, the remainder), 'charges' => the month's charges, 'kept' => approved charges the allocation left as they were].
 */
function allocate_ai_statement(PDO $pdo, int $statementId, int $by): array
{
    $kept = (int) one_value($pdo, "SELECT count(*) FROM usage_charges WHERE statement_id = :s AND status = 'approved'", ['s' => $statementId]);
    $row = usage_verb($pdo, 'SELECT ct_ai_statement_allocate(:s, :by) AS made', ['s' => $statementId, 'by' => $by]);
    return ['made' => (int) $row['made'], 'summary' => ai_statement_summary($pdo, $statementId), 'charges' => ai_statement_charges($pdo, $statementId), 'kept' => $kept];
}
