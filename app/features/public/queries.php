<?php
declare(strict_types=1);

/**
 * The client's door (slice 6, design §4, D9): a page with NO session — the token is the authority. These reads go to BASE tables as the writer with no acting member ($GLOBALS['__public_door'] = 'portal'), like Help Desk's
 * /t/, and choose their columns: never a cost, a rate card's name, an internal note, the external ref, a person's rate, another invoice. The staff preview reads the same document, after its own gate.
 */

/** The first view of a link, asked before the view is counted: was the invoice already viewed? null when no live link has this token. */
function secure_first_view(PDO $pdo, string $token): ?bool
{
    $st = $pdo->prepare("SELECT i.viewed_at IS NULL FROM invoice_links_secure l JOIN invoices i ON i.id = l.invoice_id
                          WHERE l.token_hash = encode(sha256(CAST(CAST(:t AS text) AS bytea)), 'hex') AND l.rotated_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now())");
    $st->execute(['t' => $token]);
    $v = $st->fetchColumn();
    return $v === false ? null : (bool) $v;
}

/** The invoice a token opens (the database's ct_secure_link_invoice: live, not rotated, not expired; it counts the view and stamps the invoice viewed), or null — one answer for every failure. [invoice id, first view?] */
function secure_invoice_id(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $first = secure_first_view($pdo, $token);
    $st = $pdo->prepare('SELECT ct_secure_link_invoice(:t)');
    $st->execute(['t' => $token]);
    $id = $st->fetchColumn();
    return $id === false || $id === null ? null : [(int) $id, (bool) $first];
}

/** The document a client sees (also the staff preview): [firm, client, invoice, lines with their items, payments, credits, waiting claims]. */
function public_invoice_document(PDO $pdo, int $invoiceId): ?array
{
    $st = $pdo->prepare("SELECT i.id AS invoice_id, i.number, i.status, i.period_start, i.period_end, i.issue_date, i.due_date, i.currency, i.subtotal, i.tax_total, i.total, i.amount_paid, i.amount_credited, i.balance_due, i.po_number,
                                i.memo, i.terms_text, i.sent_at, g.name AS engagement_name, c.name AS client_name, c.legal_name, c.billing_address, c.tax_id AS client_tax_id,
                                (i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.due_date < current_date) AS overdue, (current_date - i.due_date) AS days_past_due
                           FROM invoices i JOIN clients c ON c.id = i.client_id JOIN engagements g ON g.id = i.engagement_id WHERE i.id = :i");
    $st->execute(['i' => $invoiceId]);
    $i = $st->fetch();
    if ($i === false) {
        return null;
    }
    $i['currency'] = trim((string) $i['currency']);
    $i['overdue'] = (bool) $i['overdue'];
    $firm = $pdo->query('SELECT firm_name, firm_address, firm_tax_id, invoice_footer FROM ct_settings WHERE id = 1')->fetch() ?: [];
    $ls = $pdo->prepare('SELECT id AS line_id, sort_order, kind, description, quantity, unit, unit_price, tax_rate, line_subtotal, line_tax, line_total FROM invoice_lines WHERE invoice_id = :i ORDER BY sort_order, id');
    $ls->execute(['i' => $invoiceId]);
    $lines = [];
    foreach ($ls->fetchAll() as $l) {
        $l['line_id'] = (int) $l['line_id'];
        $l['items'] = [];
        $lines[$l['line_id']] = $l;
    }
    $it = $pdo->prepare("SELECT x.id AS item_id, x.line_id, x.amount, x.time_entry_id, x.expense_id, x.usage_charge_id, x.milestone_id,
            t.entry_date AS t_date, tm.display_name AS t_person, t.hours AS t_hours, t.note AS t_note,
            e.expense_date AS e_date, ec.label AS e_category, e.vendor_name AS e_vendor, e.description AS e_description,
            u.description AS u_description, up.name AS u_provider, u.period_start AS u_start, u.period_end AS u_end, u.quantity AS u_quantity, u.unit AS u_unit,
            ms.name AS m_name, ms.accepted_at AS m_accepted
          FROM invoice_line_items x
          LEFT JOIN time_entries t ON t.id = x.time_entry_id LEFT JOIN members tm ON tm.id = t.member_id
          LEFT JOIN expenses e ON e.id = x.expense_id LEFT JOIN expense_categories ec ON ec.id = e.category_id
          LEFT JOIN usage_charges u ON u.id = x.usage_charge_id LEFT JOIN usage_providers up ON up.id = u.provider_id
          LEFT JOIN milestones ms ON ms.id = x.milestone_id
         WHERE x.invoice_id = :i ORDER BY COALESCE(t.entry_date, e.expense_date, u.period_end, ms.accepted_at::date), tm.display_name, x.id");
    $it->execute(['i' => $invoiceId]);
    $rc = $pdo->prepare("SELECT a.id, a.filename, a.mime_type FROM attachments a WHERE a.record_type = 'expense' AND a.record_id = :x AND a.client_visible ORDER BY a.id");
    foreach ($it->fetchAll() as $x) {
        $x['receipts'] = [];
        if ($x['expense_id'] !== null) {
            $rc->execute(['x' => (int) $x['expense_id']]);
            $x['receipts'] = $rc->fetchAll();
        }
        $x['kind'] = $x['time_entry_id'] !== null ? 'time' : ($x['expense_id'] !== null ? 'expense' : ($x['usage_charge_id'] !== null ? 'usage' : 'milestone'));
        if (isset($lines[(int) $x['line_id']])) {
            $lines[(int) $x['line_id']]['items'][] = $x;
        }
    }
    $pay = $pdo->prepare("SELECT paid_on, amount, currency, method FROM invoice_payments WHERE invoice_id = :i AND status = 'recorded' ORDER BY paid_on, id");
    $pay->execute(['i' => $invoiceId]);
    $cr = $pdo->prepare("SELECT c.number, a.amount, a.applied_at FROM credit_applications a JOIN credit_notes c ON c.id = a.credit_note_id AND c.status = 'issued' WHERE a.invoice_id = :i ORDER BY a.applied_at");
    $cr->execute(['i' => $invoiceId]);
    $waiting = (int) one_value($pdo, "SELECT count(*) FROM payment_claims WHERE invoice_id = :i AND status = 'submitted'", ['i' => $invoiceId]);
    return ['firm' => $firm, 'invoice' => $i, 'lines' => array_values($lines), 'payments' => $pay->fetchAll(), 'credits' => $cr->fetchAll(), 'waiting_claims' => $waiting];
}

/** The invoice a token opens with the whole document the client sees: ['invoice_id', 'first_view', 'document'], or null — one answer for every failure. The view is counted (the database's ct_secure_link_invoice). */
function secure_invoice(PDO $pdo, string $token): ?array
{
    $r = secure_invoice_id($pdo, $token);
    if ($r === null) {
        return null;
    }
    $doc = public_invoice_document($pdo, $r[0]);
    return $doc === null ? null : ['invoice_id' => $r[0], 'first_view' => $r[1], 'document' => $doc];
}

/** A receipt the client may open: an attachment of an EXPENSE billed on the invoice this token opens whose `client_visible` is set. [path, filename, mime, size, invoice_id] or null. */
function secure_attachment(PDO $pdo, string $token, int $attachmentId): ?array
{
    $r = secure_invoice_id($pdo, $token);
    if ($r === null) {
        return null;
    }
    $st = $pdo->prepare("SELECT a.filename, a.mime_type, a.byte_size, a.storage_path FROM attachments a JOIN invoice_line_items x ON x.expense_id = a.record_id AND x.invoice_id = :i
                          WHERE a.id = :a AND a.record_type = 'expense' AND a.client_visible");
    $st->execute(['i' => $r[0], 'a' => $attachmentId]);
    $f = $st->fetch();
    return $f === false ? null : ['path' => APP_ROOT . '/storage/' . $f['storage_path'], 'filename' => $f['filename'], 'mime' => $f['mime_type'], 'size' => (int) $f['byte_size'], 'invoice_id' => $r[0]];
}

/** How many claims the invoice's link took in the last hour (the rate limit is five). */
function claims_last_hour(PDO $pdo, int $invoiceId): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM payment_claims WHERE invoice_id = :i AND created_at > now() - interval '1 hour'", ['i' => $invoiceId]);
}

const CLAIMS_PER_HOUR = 5;

/**
 * Record what a client said (never money until Billing confirms): $f claimed_on, method, reference, amount, note. The token is resolved here (a dead link is DomainException('gone')); only an open invoice takes a claim; five a link an hour
 * (DomainException('rate')). Answers [claim id, invoice id].
 */
function submit_claim(PDO $pdo, string $token, array $f, string $ip): array
{
    $r = secure_invoice_id($pdo, $token);
    if ($r === null) {
        throw new DomainException('gone');
    }
    [$invoiceId] = $r;
    $status = (string) one_value($pdo, 'SELECT status FROM invoices WHERE id = :i', ['i' => $invoiceId]);
    if (!in_array($status, ['issued', 'sent', 'viewed', 'partially_paid'], true)) {
        throw new DomainException($status === 'paid' ? 'This invoice is already paid — thank you.' : 'This invoice is not open for payment.');
    }
    if (claims_last_hour($pdo, $invoiceId) >= CLAIMS_PER_HOUR) {
        throw new DomainException('rate');
    }
    $st = $pdo->prepare('INSERT INTO payment_claims (invoice_id, claimed_on, method, reference, amount, note, ip_address) VALUES (:i, :d, :m, :r, :a, :n, CAST(:ip AS inet)) RETURNING id');
    $st->execute(['i' => $invoiceId, 'd' => $f['claimed_on'], 'm' => $f['method'], 'r' => $f['reference'], 'a' => $f['amount'], 'n' => $f['note'], 'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null]);
    return [(int) $st->fetchColumn(), $invoiceId];
}
