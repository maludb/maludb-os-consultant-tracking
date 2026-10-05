<?php
declare(strict_types=1);

/**
 * The prelude of an invoicing handler (slice 6): the feature's files; the invoice (or credit note, payment, claim) a request names — seen through its view, else 404 —; the gates; the log with the invoice's keys on every row.
 * `invoices.draft` AND manage the engagement is DRAFTING; `billing.write` is issuing, sending, settling.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/lines.php';
require_once dirname(__DIR__) . '/payments/queries.php';
require_once dirname(__DIR__) . '/payments/present.php';
require_once dirname(__DIR__) . '/payments/write.php';
require_once dirname(__DIR__) . '/credit_notes/queries.php';
require_once dirname(__DIR__) . '/credit_notes/present.php';
require_once dirname(__DIR__) . '/credit_notes/write.php';
require_once dirname(__DIR__) . '/documents/write.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/engagements/queries.php';

/** The invoice the request names, through the view: 404 when unseen. */
function invoice_from_request(PDO $pdo): array
{
    $id = invoice_id_from_request();
    if ($id === null && ($n = trim((string) ($_POST['number'] ?? $_GET['number'] ?? ''))) !== '') {
        $id = find_invoice_by_number($pdo, $n);
    }
    return find_invoice($pdo, $id ?? refuse(422, 'Say which invoice.')) ?? refuse(404, 'Invoice not found.');
}

/** May the caller DRAFT on this engagement: invoices.draft and manage (Billing manages every engagement). */
function may_draft_on(int $engagementId): bool
{
    return has_right('invoices.draft') && can_manage_engagement($engagementId);
}

/** Drafting gate for an invoice's handler: invoices.draft AND manage the engagement — else 403 in words (404 when the invoice is unseen, earlier). */
function require_draft_on(array $inv): void
{
    if (!has_right('invoices.draft')) {
        refuse(403, 'You may not draft invoices.');
    }
    if (!can_manage_engagement($inv['engagement_id'])) {
        refuse(403, 'You do not manage ' . $inv['engagement_number'] . ', so you may not change its invoices.');
    }
}

/** log_activity with the invoice's keys (invoice_id, engagement_id, client_id). */
function invoice_log(PDO $pdo, string $action, array $inv, array $opts = [], ?string $entityType = null, ?int $entityId = null): void
{
    log_activity($pdo, $action, $entityType ?? 'invoice', $entityId ?? $inv['invoice_id'], $opts + ['invoice_id' => $inv['invoice_id'], 'engagement_id' => $inv['engagement_id'], 'client_id' => $inv['client_id']]);
}

/** The money of an invoice for a log payload: number, amount (the total unless $amount), currency. Never a cost, never a token. */
function invoice_money(array $inv, ?string $amount = null): array
{
    return ['number' => $inv['number'], 'amount' => $amount ?? $inv['total'], 'currency' => $inv['currency'], 'total' => $inv['total'], 'client_id' => $inv['client_id'], 'engagement_id' => $inv['engagement_id']];
}

/** "INV-00042 (1,200.00 USD)" for a sentence; a draft is "Draft invoice #12". */
function invoice_label(array $inv): string
{
    return ($inv['number'] ?? null) !== null ? $inv['number'] . ' (' . fmt_money($inv['total'], $inv['currency']) . ')' : 'the draft for ' . $inv['engagement_number'];
}

function invoice_link(int $id): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/invoices/' . $id;
}

/** A request's money field as a canonical string, or an entry in $errors. $required: say so when empty. */
function req_money(string $name, string $label, array &$errors, bool $required = true, bool $positive = true): ?string
{
    $raw = req_val($name);
    if ($raw === null || $raw === '') {
        if ($required) { $errors[$name] = $label . ' is required.'; }
        return null;
    }
    $m = parse_money($raw);
    if ($m === null) {
        $errors[$name] = $label . ' is a number with at most two decimals (1,200.00).';
        return null;
    }
    if ($positive && (float) $m <= 0) {
        $errors[$name] = $label . ' is more than zero.';
        return null;
    }
    return $m;
}

/** A date field as Y-m-d, null when empty/left out; an entry in $errors when malformed. */
function req_date(string $name, string $label, array &$errors): ?string
{
    if (!req_has($name)) {
        return null;
    }
    $d = request_date($name);
    if ($d === false) {
        $errors[$name] = $label . ' is a day (2026-03-02).';
        return null;
    }
    return $d;
}

/** A sentence that carries numbers (the counts of an add-unbilled) for the page the handler lands on: kept in the session for one read. A key-based notice (`?notice=`) stays the default. */
function ct_flash(string $kind, string $text): void
{
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ct_flash'] = [$kind, $text];
    }
}

/** The flash, once. [kind, text] or null. */
function ct_take_flash(): ?array
{
    $f = $_SESSION['ct_flash'] ?? null;
    unset($_SESSION['ct_flash']);
    return is_array($f) ? $f : null;
}

/** The notice to show: the `?notice=` key's sentence, else a flash. */
function ct_page_notice(array $map): ?array
{
    $flash = ct_take_flash();
    return ct_notice($_GET['notice'] ?? null, $map) ?? $flash;
}

/** An invoice just became paid: tell billing and the engagement's manager (once per invoice). */
function notify_paid(PDO $pdo, array $inv, int $exceptMember): void
{
    $data = ['title' => $inv['number'] . ' is paid (' . fmt_money($inv['total'], $inv['currency']) . ')', 'body' => 'It was paid in full: ' . invoice_link($inv['invoice_id']), 'dedupe' => 'inv-paid:' . $inv['invoice_id'] . ':' . substr((string) ($inv['paid_at'] ?? date('c')), 0, 19)];
    notify_billing($pdo, 'invoice_paid', 'invoice', $inv['invoice_id'], $data, $exceptMember);
    $mgr = (int) one_value($pdo, 'SELECT manager_member_id FROM engagements WHERE id = :e', ['e' => $inv['engagement_id']]);
    if ($mgr > 0 && $mgr !== $exceptMember && !in_array($mgr, billing_recipients($pdo), true)) {
        queue_billing_notice($pdo, $mgr, 'invoice_paid', 'invoice', $inv['invoice_id'], $data);
    }
}

/** The credit note the request names, through the view: 404 when unseen. */
function credit_note_from_request(PDO $pdo): array
{
    return find_credit_note($pdo, credit_note_id_from_request() ?? refuse(422, 'Say which credit note.')) ?? refuse(404, 'Credit note not found.');
}

/** log_activity for a credit note: entity credit_note, the client's key (and the invoice's when it corrects one). */
function credit_log(PDO $pdo, string $action, array $c, array $opts = []): void
{
    log_activity($pdo, $action, 'credit_note', $c['credit_note_id'], $opts + ['client_id' => $c['client_id'], 'engagement_id' => $c['engagement_id'], 'invoice_id' => $c['invoice_id']]);
}

/** The money of a credit note for a log payload. */
function credit_money(array $c, ?string $amount = null): array
{
    return ['number' => $c['number'], 'amount' => $amount ?? $c['total'], 'currency' => $c['currency'], 'total' => $c['total'], 'invoice_id' => $c['invoice_id'], 'reason' => $c['reason']];
}
