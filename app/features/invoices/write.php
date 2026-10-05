<?php
declare(strict_types=1);

/**
 * Writes (slice 6): a draft invoice to the base table (the guards are the referee — the currency, the engagement, the lock on an issued one); the database VERBS for everything else (add unbilled, issue — the
 * gapless number, the PO rule, the not-to-exceed cap —, mark sent, void, write off); the notices and the mail this slice queues (slice 8 sends). Totals, tax per line, the balance, "billed" and the number are the
 * database's: PHP shows the result.
 */

/** A verb call: the row it answers, or its RAISE as a DomainException (the caller's ct_guard shows the sentence). */
function billing_verb(PDO $pdo, string $sql, array $args): array
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

/** A base-table write: the guard's RAISE as a DomainException. */
function billing_write(PDO $pdo, string $sql, array $args): PDOStatement
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That could not be saved.'));
        }
        if ((string) $e->getCode() === '23514') {
            throw new DomainException('A date or an amount is out of range (the due date is on or after the issue date, the period ends on or after it starts, a quantity is above zero).');
        }
        throw $e;
    }
}

/**
 * Draft an invoice for an engagement. $f: period_start, period_end, issue_date (today), due_date (the client's terms, else the settings'), po_number (the engagement's when empty — the guard's), memo, terms_text, internal_note.
 * With $addUnbilled the approved unbilled items are pulled through the engagement's grouping up to $through (today). Answers ['id', 'counts' => the verb's row | null].
 */
function create_invoice(PDO $pdo, int $engagementId, array $f, int $by, bool $addUnbilled, ?string $through): array
{
    $e = $pdo->prepare('SELECT e.client_id, COALESCE(c.terms_days, (SELECT default_terms_days FROM ct_settings WHERE id = 1)) AS terms FROM engagements e JOIN clients c ON c.id = e.client_id WHERE e.id = :e');
    $e->execute(['e' => $engagementId]);
    $eng = $e->fetch();
    if ($eng === false) {
        throw new DomainException('Not found.');
    }
    $st = billing_write($pdo, "INSERT INTO invoices (client_id, engagement_id, period_start, period_end, issue_date, due_date, currency, po_number, memo, terms_text, internal_note, created_by)
        VALUES (:c, :e, :ps, :pe, COALESCE(CAST(:issue AS date), current_date), COALESCE(CAST(:due AS date), COALESCE(CAST(:issue2 AS date), current_date) + CAST(:terms AS integer)), 'USD', :po, :memo, :tt, :note, :by) RETURNING id",
        ['c' => (int) $eng['client_id'], 'e' => $engagementId, 'ps' => $f['period_start'] ?? null, 'pe' => $f['period_end'] ?? null, 'issue' => $f['issue_date'] ?? null, 'issue2' => $f['issue_date'] ?? null, 'due' => $f['due_date'] ?? null,
         'terms' => (int) $eng['terms'], 'po' => $f['po_number'] ?? null, 'memo' => $f['memo'] ?? null, 'tt' => $f['terms_text'] ?? null, 'note' => $f['internal_note'] ?? null, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    if ($id < 1) {
        throw new DomainException('Not found.');
    }
    $counts = $addUnbilled ? add_unbilled($pdo, $id, $by, $through) : null;
    return ['id' => $id, 'counts' => $counts];
}

/** Change a draft's head. $f carries only what changed (a field left out stays). The guard refuses an issued invoice in words. */
function update_invoice(PDO $pdo, int $id, array $f, int $by): array
{
    $cols = ['period_start', 'period_end', 'issue_date', 'due_date', 'po_number', 'memo', 'terms_text', 'internal_note'];
    $set = [];
    $args = ['id' => $id];
    foreach ($cols as $c) {
        if (array_key_exists($c, $f)) {
            $set[] = $c . ' = :' . $c;
            $args[$c] = $f[$c];
        }
    }
    if ($set === []) {
        return [];
    }
    billing_write($pdo, 'UPDATE invoices SET ' . implode(', ', $set) . ' WHERE id = :id', $args);
    return array_values(array_intersect($cols, array_keys($f)));
}

/** Pull the engagement's approved, billable, unbilled items onto a draft: [time_lines, time_entries_billed, time_no_rate, expense_lines, usage_lines, milestone_lines]. */
function add_unbilled(PDO $pdo, int $invoiceId, int $by, ?string $through): array
{
    $r = billing_verb($pdo, 'SELECT * FROM ct_invoice_add_unbilled(:i, :by, COALESCE(CAST(:t AS date), current_date))', ['i' => $invoiceId, 'by' => $by, 't' => $through]);
    $out = [];
    foreach (['time_lines', 'time_entries_billed', 'time_no_rate', 'expense_lines', 'usage_lines', 'milestone_lines'] as $k) {
        $out[$k] = (int) ($r[$k] ?? 0);
    }
    $out['no_rate_hours'] = $out['time_no_rate'] > 0 ? (string) one_value($pdo, "SELECT COALESCE(sum(t.hours), 0) FROM time_entries t JOIN invoices i ON i.engagement_id = t.engagement_id
        WHERE i.id = :i AND t.status = 'approved' AND t.billable AND t.bill_rate IS NULL AND t.entry_date <= COALESCE(CAST(:t AS date), current_date) AND NOT ct_item_billed('time_entry', t.id)", ['i' => $invoiceId, 't' => $through]) : '0';
    return $out;
}

/** "4 entries have no rate" in words for the engagement: "4.0 h on ENG-0042 have no rate — set one and re-approve". */
function no_rate_sentence(array $counts, string $engagementNumber): ?string
{
    if (($counts['time_no_rate'] ?? 0) < 1) {
        return null;
    }
    return days_label($counts['no_rate_hours']) . ' h on ' . $engagementNumber . ' have no rate — set one and re-approve; they are not on the invoice.';
}

/** Delete a draft (its lines and items go with it; the items are unbilled again; no number is burned — a draft has none). Answers the row for the log. */
function delete_invoice(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT engagement_id, client_id, status, total, currency FROM invoices WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    if ($r['status'] !== 'draft') {
        throw new DomainException('Only a draft is deleted; an issued invoice is voided.');
    }
    billing_write($pdo, 'DELETE FROM invoice_lines WHERE invoice_id = :id', ['id' => $id]);       // lines first: their items go with them while the invoice is still a draft
    billing_write($pdo, 'DELETE FROM invoices WHERE id = :id', ['id' => $id]);
    return ['engagement_id' => (int) $r['engagement_id'], 'client_id' => (int) $r['client_id'], 'total' => $r['total'], 'currency' => trim((string) $r['currency'])];
}

// ---- the verbs ----------------------------------------------------------------------------------------------------------------------------------------------------

/**
 * Issue: the PO rule and the not-to-exceed cap in the database's words (an override reason crosses the cap — Billing only), the gapless number, the milestones marked invoiced. Then the link is minted (the raw token is
 * discarded: it exists only at mint time, and a send mints its own), and the receipts of the invoice's expenses are shared with the client exactly when the engagement says so (its flag, else the settings'). Answers the invoice row.
 */
function issue_invoice(PDO $pdo, int $id, int $by, ?string $nteReason): array
{
    $row = billing_verb($pdo, 'SELECT * FROM ct_invoice_issue(:i, :by, :r)', ['i' => $id, 'by' => $by, 'r' => $nteReason]);
    $row['receipts_shared'] = share_invoice_receipts($pdo, $id);
    $row['raw_token'] = (string) billing_verb($pdo, 'SELECT ct_secure_link_mint(:i) AS t', ['i' => $id])['t'];       // answered once; held by the caller only if it sends at once; never logged
    return $row;
}

/** Set `client_visible` on the receipts of the invoice's expenses to what the engagement allows; answers how many receipts are now shared. */
function share_invoice_receipts(PDO $pdo, int $invoiceId): int
{
    $st = $pdo->prepare("UPDATE attachments a SET client_visible = COALESCE(g.share_receipts_with_client, (SELECT share_receipts_default FROM ct_settings WHERE id = 1))
                           FROM invoice_line_items x JOIN invoices i ON i.id = x.invoice_id JOIN engagements g ON g.id = i.engagement_id
                          WHERE x.invoice_id = :i AND x.expense_id IS NOT NULL AND a.record_type = 'expense' AND a.record_id = x.expense_id");
    $st->execute(['i' => $invoiceId]);
    return (int) one_value($pdo, "SELECT count(*) FROM attachments a JOIN invoice_line_items x ON x.expense_id = a.record_id WHERE x.invoice_id = :i AND a.record_type = 'expense' AND a.client_visible", ['i' => $invoiceId]);
}

/** Mint a fresh secure link (the old one dies) and answer the raw token — once. */
function rotate_secure_link(PDO $pdo, int $invoiceId, int $by): string
{
    $r = billing_verb($pdo, 'SELECT ct_secure_link_mint(:i) AS t', ['i' => $invoiceId]);
    return (string) $r['t'];
}

/** The link a client opens, for a raw token. */
function secure_link_url(string $raw): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/i/' . $raw;
}

function void_invoice(PDO $pdo, int $id, int $by, string $reason): array
{
    return billing_verb($pdo, 'SELECT * FROM ct_invoice_void(:i, :by, :r)', ['i' => $id, 'by' => $by, 'r' => $reason]);
}

function write_off_invoice(PDO $pdo, int $id, int $by, string $reason): array
{
    return billing_verb($pdo, 'SELECT * FROM ct_invoice_write_off(:i, :by, :r)', ['i' => $id, 'by' => $by, 'r' => $reason]);
}

/** What the ledger calls the invoice. Answers [before, after]. */
function set_external_ref(PDO $pdo, int $id, ?string $ref, int $by): array
{
    $before = one_value($pdo, 'SELECT external_ref FROM invoices WHERE id = :i', ['i' => $id]);
    billing_write($pdo, 'UPDATE invoices SET external_ref = :r WHERE id = :i', ['r' => $ref, 'i' => $id]);
    return [$before, $ref];
}

/**
 * Mark an issued invoice sent and queue one mail per recipient with a FRESH secure link (the raw token exists only at mint time, so a send makes its own and the previous link dies).
 * $recipients: [email => name|null]. Answers ['invoice' => row, 'queued' => n, 'recipients' => n]; refuses when there is nobody to send to.
 */
function send_invoice(PDO $pdo, int $id, int $by, ?string $toEmail, ?string $message, string $kind = 'invoice'): array
{
    $inv = $pdo->prepare('SELECT i.*, c.name AS client_name, g.name AS engagement_name FROM invoices i JOIN clients c ON c.id = i.client_id JOIN engagements g ON g.id = i.engagement_id WHERE i.id = :i');
    $inv->execute(['i' => $id]);
    $i = $inv->fetch();
    if ($i === false) {
        throw new DomainException('Not found.');
    }
    if ($i['status'] === 'draft') {
        throw new DomainException('Issue the invoice before sending it');
    }
    if (!in_array($i['status'], OPEN_INVOICE_STATUSES, true)) {
        throw new DomainException('Invoice ' . $i['number'] . ' is ' . $i['status'] . ' and is not sent.');
    }
    $to = invoice_recipients($pdo, (int) $i['client_id'], $toEmail);
    if ($to === []) {
        throw new DomainException('Nobody to send it to: the client has no contact who receives invoices and no email — add one, or type an address.');
    }
    $row = billing_verb($pdo, 'SELECT * FROM ct_invoice_mark_sent(:i)', ['i' => $id]);
    $raw = rotate_secure_link($pdo, $id, $by);
    if ($kind === 'reminder') {
        billing_write($pdo, 'UPDATE invoices SET last_reminder_at = now(), reminder_count = reminder_count + 1 WHERE id = :i', ['i' => $id]);
        $row = billing_verb($pdo, 'SELECT * FROM invoices WHERE id = :i', ['i' => $id]);
    }
    [$subject, $intro] = invoice_mail_text($pdo, $i, $kind, $message);
    queue_invoice_mail($pdo, $id, $kind, $to, $subject, $intro, $raw);
    return ['invoice' => $row, 'queued' => count($to), 'recipients' => count($to)];
}

/** The contacts who receive invoices (else the client's email, else nobody); an address typed overrides them. [email => name]. */
function invoice_recipients(PDO $pdo, int $clientId, ?string $toEmail): array
{
    if ($toEmail !== null && $toEmail !== '') {
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('That is not an email address.');
        }
        return [strtolower($toEmail) => null];
    }
    $out = [];
    $st = $pdo->prepare('SELECT email::text AS email, name FROM client_contacts WHERE client_id = :c AND receives_invoices AND archived_at IS NULL AND email IS NOT NULL ORDER BY is_primary DESC, id');
    $st->execute(['c' => $clientId]);
    foreach ($st->fetchAll() as $k) {
        $out[strtolower($k['email'])] = $k['name'];
    }
    if ($out === []) {
        $e = one_value($pdo, 'SELECT email::text FROM clients WHERE id = :c', ['c' => $clientId]);
        if ($e !== null && $e !== '') {
            $out[strtolower((string) $e)] = null;
        }
    }
    return $out;
}

/** The subject and the words before the link of an invoice mail or a reminder — the tone of `writing-to-clients`: plain, never a threat, never a cost or another client's name. [subject, intro] */
function invoice_mail_text(PDO $pdo, array $i, string $kind, ?string $message): array
{
    $cur = trim((string) $i['currency']);
    $what = 'Invoice ' . $i['number'] . ' — ' . $i['engagement_name'] . ($i['period_end'] !== null ? ', ' . date('F Y', strtotime((string) $i['period_end'])) : '');
    $balance = fmt_money($i['balance_due'], $cur);
    if ($kind === 'reminder') {
        $subject = 'A reminder: ' . $what;
        $lines = ['Hello,', '', 'A gentle reminder that invoice ' . $i['number'] . ' for ' . $i['engagement_name'] . ' was due on ' . format_date((string) $i['due_date']) . ' and shows a balance of ' . $balance . '.',
                  'If it has already been paid, thank you — you can tell us on the invoice page and we will confirm it when it shows:'];
    } else {
        $subject = $what;
        $lines = ['Hello,', '', 'Please find invoice ' . $i['number'] . ' for ' . $i['engagement_name'] . '. The total is ' . fmt_money($i['total'], $cur) . ', due on ' . format_date((string) $i['due_date']) . '.',
                  'You can see the detail behind every line, print it, and tell us once it is paid, on this page:'];
    }
    if ($message !== null && $message !== '') {
        array_splice($lines, 2, 0, [$message, '']);
    }
    return [$subject, implode("\n", $lines)];
}

/**
 * One outbox row per recipient (channel email, the client set, the address as it is today): the invoice's mail with the secure link at its foot. $body is the text before the link; the link only ever travels in the body.
 * Slice 8's worker sends. Nothing here reaches MaluMail.
 */
function queue_invoice_mail(PDO $pdo, int $invoiceId, string $kind, array $recipients, string $subject, string $body, string $rawLink): void
{
    $clientId = (int) one_value($pdo, 'SELECT client_id FROM invoices WHERE id = :i', ['i' => $invoiceId]);
    $firm = (string) (one_value($pdo, 'SELECT firm_name FROM ct_settings WHERE id = 1') ?: 'Consultant Tracking');
    $link = secure_link_url($rawLink);
    $text = $body . "\n\n" . $link . "\n\nThank you,\n" . $firm . "\n";
    $html = '<p>' . implode('</p><p>', array_map(static fn (string $l): string => e($l), array_values(array_filter(explode("\n", $body), static fn (string $l): bool => trim($l) !== '')))) . '</p><p><a href="' . e($link) . '">' . e($link) . '</a></p><p>Thank you,<br>' . e($firm) . '</p>';
    queue_client_mail($pdo, 'invoice', $invoiceId, $clientId, $kind, $recipients, $subject, $text, $html);
}

/** One outbox row per recipient for a client mail of any kind (invoice, reminder, statement). Answers how many rows. */
function queue_client_mail(PDO $pdo, string $recordType, int $recordId, int $clientId, string $kind, array $recipients, string $subject, string $text, ?string $html = null): int
{
    $ins = $pdo->prepare("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body, body_html) VALUES ('email', :c, :to, :k, :rt, :r, :s, :b, :h)");
    $n = 0;
    foreach (array_keys($recipients) as $email) {
        $ins->execute(['c' => $clientId, 'to' => (string) $email, 'k' => $kind, 'rt' => $recordType, 'r' => $recordId, 's' => mb_substr($subject, 0, 300), 'b' => $text, 'h' => $html]);
        $n++;
    }
    return $n;
}

// ---- the notices --------------------------------------------------------------------------------------------------------------------------------------------------

/** The people who are "billing": humans holding Billing or the admin role, and the super-admin — active and admitted. */
function billing_recipients(PDO $pdo): array
{
    return array_map('intval', $pdo->query("SELECT m.id FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human'
        AND (m.business_role = 'super_admin' OR m.roles && ARRAY['billing', 'admin']) ORDER BY m.id")->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Queue a notice about an invoice, a claim or a payment (the sibling of queue_expense_notice and queue_usage_notice; $recordType is 'invoice' or 'payment_claim'): the in-app row, and an outbox row per channel the
 * person chose; an agent gets the in-app row only; a dedupe key (per channel) makes a second queue of the same key a no-op; an eval run queues nothing. Slice 8 sends. Answers how many rows were made.
 */
function queue_billing_notice(PDO $pdo, int $memberId, string $kind, string $recordType, int $recordId, array $data): int
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
    $pdo->prepare('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, :t, :r, :ti, :b)')
        ->execute(['m' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'ti' => mb_substr($title, 0, 300), 'b' => mb_substr($body, 0, 2000)]);
    $n = 1;
    if ($member['member_kind'] === 'agent') {
        return $n;
    }
    $prefs = find_prefs($pdo, $memberId);
    $ins = $pdo->prepare('INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body) VALUES (:c, :m, :k, :t, :r, :d, :s, :b)');
    if ($prefs['email_enabled'] && in_array($kind, $prefs['kinds'], true)) {
        $ins->execute(['c' => 'email', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':email', 's' => $title, 'b' => $body]);
        $n++;
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':text', 's' => $title, 'b' => mb_substr($body, 0, 300)]);
        $n++;
    }
    return $n;
}

/** Tell every billing person (and nobody twice). Answers how many notice rows were made. */
function notify_billing(PDO $pdo, string $kind, string $recordType, int $recordId, array $data, int $exceptMember = 0): int
{
    $n = 0;
    foreach (billing_recipients($pdo) as $m) {
        if ($m !== $exceptMember) {
            $n += queue_billing_notice($pdo, $m, $kind, $recordType, $recordId, $data);
        }
    }
    return $n;
}
