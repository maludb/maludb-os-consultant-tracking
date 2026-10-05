<?php
declare(strict_types=1);

/** Writes: a draft to the base table (the guard decides), the verbs for every status move, the receipt in the same transaction, the notices this slice queues. */

/** The files stored by this request (full paths), so a refused save can take them away again. */
function expense_stored_files(?string $add = null, bool $clear = false): array
{
    static $files = [];
    if ($add !== null) {
        $files[] = $add;
    }
    $out = $files;
    if ($clear) {
        $files = [];
    }
    return $out;
}

/** Take away what a refused request stored (the rows rolled back; the files remain until here). */
function expense_discard_stored_files(): void
{
    foreach (expense_stored_files(null, true) as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/**
 * Add ($id null) or change an expense. $f: engagement_id, expense_date, category_id, amount, tax_amount, vendor_name, description, paid_with, billable, markup_pct (null = leave to the
 * database's policy on a new one; kept on a change). $file: one $_FILES entry — the receipt, stored in the same transaction. The guard refuses in words (the caller's ct_guard).
 */
function save_expense(PDO $pdo, ?int $id, array $f, int $memberId, int $by, ?array $file): int
{
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO expenses (member_id, engagement_id, expense_date, category_id, vendor_name, description, amount, tax_amount, currency, paid_with, billable, markup_pct, created_by)
                                 VALUES (:m, :e, :d, :c, :v, :ds, :a, :t, \'USD\', :p, :b, :mk, :by) RETURNING id');
            $st->execute(['m' => $memberId, 'e' => $f['engagement_id'], 'd' => $f['expense_date'], 'c' => $f['category_id'], 'v' => $f['vendor_name'], 'ds' => $f['description'], 'a' => $f['amount'], 't' => $f['tax_amount'],
                          'p' => $f['paid_with'], 'b' => $f['billable'] ? 't' : 'f', 'mk' => $f['markup_pct'] ?? '0', 'by' => $by]);
            $id = (int) $st->fetchColumn();
        } else {
            $pdo->prepare('UPDATE expenses SET engagement_id = :e, expense_date = :d, category_id = :c, vendor_name = :v, description = :ds, amount = :a, tax_amount = :t, paid_with = :p, billable = :b, markup_pct = COALESCE(CAST(:mk AS numeric), markup_pct) WHERE id = :id')
                ->execute(['e' => $f['engagement_id'], 'd' => $f['expense_date'], 'c' => $f['category_id'], 'v' => $f['vendor_name'], 'ds' => $f['description'], 'a' => $f['amount'], 't' => $f['tax_amount'],
                           'p' => $f['paid_with'], 'b' => $f['billable'] ? 't' : 'f', 'mk' => $f['markup_pct'], 'id' => $id]);
        }
        if ($file !== null) {
            store_expense_receipt($pdo, $id, $file, $by);
        }
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That expense could not be saved.'));
        }
        throw $e;
    }
}

/** A receipt: an attachment of the expense, shared with the client when the engagement shares receipts (its flag, else the settings'). Answers the attachment id. */
function store_expense_receipt(PDO $pdo, int $expenseId, array $file, int $by): int
{
    $share = db_bool($pdo, 'SELECT COALESCE(g.share_receipts_with_client, (SELECT share_receipts_default FROM ct_settings WHERE id = 1)) FROM expenses x JOIN engagements g ON g.id = x.engagement_id WHERE x.id = :x', ['x' => $expenseId]);
    $aid = store_attachment($pdo, 'expense', $expenseId, $file, $by, $share);
    expense_stored_files(APP_ROOT . '/storage/' . (string) one_value($pdo, 'SELECT storage_path FROM attachments WHERE id = :id', ['id' => $aid]));
    return $aid;
}

/** Delete a draft or rejected expense (the trigger refuses anything else), then its receipts and notes. Answers the row for the log. */
function delete_expense(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT number, engagement_id, member_id, amount, currency, status FROM expenses WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    try {
        $pdo->prepare('DELETE FROM expenses WHERE id = :id')->execute(['id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That expense is not deleted.'));
        }
        throw $e;
    }
    $ids = $pdo->prepare("SELECT id FROM attachments WHERE record_type = 'expense' AND record_id = :id");
    $ids->execute(['id' => $id]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        delete_attachment($pdo, (int) $aid);
    }
    $pdo->prepare("DELETE FROM notes WHERE record_type = 'expense' AND record_id = :id")->execute(['id' => $id]);
    return ['number' => $r['number'], 'engagement_id' => (int) $r['engagement_id'], 'member_id' => (int) $r['member_id'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'status' => $r['status']];
}

/** A verb call: the row it answers, or its RAISE as a DomainException. */
function expense_verb(PDO $pdo, string $sql, array $args): array
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

function submit_expense(PDO $pdo, int $id, int $by): array
{
    return expense_verb($pdo, 'SELECT * FROM ct_expense_submit(:x, :by)', ['x' => $id, 'by' => $by]);
}

function approve_expense(PDO $pdo, int $id, int $by): array
{
    return expense_verb($pdo, 'SELECT * FROM ct_expense_approve(:x, :by)', ['x' => $id, 'by' => $by]);
}

function reject_expense(PDO $pdo, int $id, int $by, string $reason): array
{
    return expense_verb($pdo, 'SELECT * FROM ct_expense_reject(:x, :by, :r)', ['x' => $id, 'by' => $by, 'r' => $reason]);
}

/** Mark each expense reimbursed with one reference (the verb refuses one that is not approved, not personal, or already paid). Answers how many it changed. */
function mark_reimbursed(PDO $pdo, array $ids, ?int $by, ?string $reference): int
{
    $n = 0;
    foreach ($ids as $id) {
        expense_verb($pdo, 'SELECT * FROM ct_expense_mark_reimbursed(:x, :by, :ref)', ['x' => (int) $id, 'by' => $by, 'ref' => $reference]);
        $n++;
    }
    return $n;
}

/**
 * Queue a notice about an expense (the sibling of queue_time_notice, with the record type 'expense'): the in-app row, and an outbox row per channel the person chose; an agent gets
 * the in-app row only; a dedupe key (per channel) makes a second queue the same key a no-op; an eval run queues nothing. Slice 8 sends. Answers how many rows were made.
 */
function queue_expense_notice(PDO $pdo, int $memberId, string $kind, int $expenseId, array $data): int
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
    $title = (string) ($data['title'] ?? $kind);
    $body = (string) ($data['body'] ?? '');
    $dedupe = $data['dedupe'] ?? null;
    if ($dedupe !== null && (int) one_value($pdo, 'SELECT count(*) FROM notification_outbox WHERE dedupe_key LIKE :d', ['d' => $dedupe . ':m' . $memberId . ':%']) > 0) {
        return 0;
    }
    $pdo->prepare('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, \'expense\', :r, :t, :b)')
        ->execute(['m' => $memberId, 'k' => $kind, 'r' => $expenseId, 't' => mb_substr($title, 0, 300), 'b' => mb_substr($body, 0, 2000)]);
    $n = 1;
    if ($member['member_kind'] === 'agent') {
        return $n;
    }
    $prefs = find_prefs($pdo, $memberId);
    $ins = $pdo->prepare('INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body) VALUES (:c, :m, :k, \'expense\', :r, :d, :s, :b)');
    if ($prefs['email_enabled'] && in_array($kind, $prefs['kinds'], true)) {
        $ins->execute(['c' => 'email', 'm' => $memberId, 'k' => $kind, 'r' => $expenseId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':email', 's' => $title, 'b' => $body]);
        $n++;
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'm' => $memberId, 'k' => $kind, 'r' => $expenseId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':text', 's' => $title, 'b' => mb_substr($body, 0, 300)]);
        $n++;
    }
    return $n;
}
