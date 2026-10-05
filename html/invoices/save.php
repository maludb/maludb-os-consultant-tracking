<?php
declare(strict_types=1);
/**
 * Actions `invoice_draft` (by `engagement`; log `invoice.draft`: number, amount, currency, client_id, engagement_id, period, the add-unbilled counts) and `invoice_update` (by `invoice`, a draft; log `invoice.update`: the changed fields):
 * invoices.draft AND manage the engagement (404 when the engagement is unseen). `add_unbilled=yes` pulls the approved, billable, unbilled items through the engagement's grouping up to `through` (the period's end, else today); the
 * counts and the hours with no rate are answered. The due date is the client's terms from the issue date unless given; the PO the engagement's unless typed; the currency the engagement's. The database refuses in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('invoices.draft');
$pdo = db();
$me = (int) current_member_id();
$invId = request_integer('invoice') ?? request_integer('invoice_id');
$cur = $invId === null ? null : (find_invoice($pdo, $invId) ?? refuse(404, 'Invoice not found.'));
$errors = [];
if ($cur === null) {
    $engField = req_has('engagement') ? 'engagement' : 'engagement_id';
    $eng = ct_ref($pdo, $engField, null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
    if ($eng === null && $errors === []) {
        $errors['engagement'] = 'The engagement is required.';
    }
    if ($eng !== null && !can_see_engagement($eng)) {
        refuse(404, 'Engagement not found.');
    }
    if ($eng !== null && !can_manage_engagement($eng)) {
        refuse(403, 'You do not manage that engagement, so you may not draft its invoices.');
    }
} else {
    require_draft_on($cur);
    $eng = $cur['engagement_id'];
}
$f = [];
foreach (['period_start' => 'The period\'s start', 'period_end' => 'The period\'s end', 'issue_date' => 'The issue date', 'due_date' => 'The due date'] as $k => $label) {
    if (!req_has($k)) {
        continue;
    }
    if ((string) req_val($k) === '') {
        if (in_array($k, ['period_start', 'period_end'], true)) { $f[$k] = null; }       // a period may be cleared; a date the invoice needs is simply left as it was
        continue;
    }
    $d = request_date($k);
    if ($d === false || $d === null) { $errors[$k] = $label . ' is a day (2026-03-02).'; continue; }
    $f[$k] = $d;
}
foreach (['po_number' => 200, 'memo' => 4000, 'terms_text' => 4000, 'internal_note' => 4000] as $k => $max) {
    if (req_has($k)) {
        $v = (string) req_val($k);
        if (mb_strlen($v) > $max) { $errors[$k] = 'That is at most ' . $max . ' characters.'; }
        $f[$k] = $v === '' ? null : $v;
    }
}
$issue = $f['issue_date'] ?? ($cur['issue_date'] ?? date('Y-m-d'));
$due = $f['due_date'] ?? ($cur['due_date'] ?? null);
if ($due !== null && $due < $issue) { $errors['due_date'] = 'The due date is on or after the issue date.'; }
$ps = array_key_exists('period_start', $f) ? $f['period_start'] : ($cur['period_start'] ?? null);
$pe = array_key_exists('period_end', $f) ? $f['period_end'] : ($cur['period_end'] ?? null);
if ($ps !== null && $pe !== null && $pe < $ps) { $errors['period_end'] = 'The period ends on or after it starts.'; }
$through = req_date('through', 'The date to pull through', $errors);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
if ($cur === null) {
    $add = ct_yes('add_unbilled', false);
    $out = ct_guard($pdo, static function () use ($pdo, $me, $eng, $f, $add, $through, $pe): array {
        $pdo->beginTransaction();
        $made = create_invoice($pdo, $eng, $f, $me, $add, $through ?? $pe);
        $inv = find_invoice($pdo, $made['id']);
        invoice_log($pdo, 'invoice.draft', $inv, ['after' => ['amount' => $inv['total'], 'currency' => $inv['currency'], 'total' => $inv['total'], 'period_start' => $inv['period_start'], 'period_end' => $inv['period_end'], 'po_number' => $inv['po_number'], 'added_unbilled' => $add]
            + ($made['counts'] === null ? [] : ['counts' => $made['counts']])]);
        $mgr = (int) one_value($pdo, 'SELECT manager_member_id FROM engagements WHERE id = :e', ['e' => $eng]);
        if ($mgr > 0 && $mgr !== $me) {
            queue_billing_notice($pdo, $mgr, 'invoice_drafted', 'invoice', $inv['invoice_id'], ['title' => 'A draft invoice was started for ' . $inv['engagement_number'] . ' (' . fmt_money($inv['total'], $inv['currency']) . ')',
                'body' => 'Open it to check the lines: ' . invoice_link($inv['invoice_id']), 'dedupe' => 'inv-drafted:' . $inv['invoice_id']]);
        }
        $pdo->commit();
        return [$inv, $made['counts']];
    });
    [$inv, $counts] = $out;
    $warn = $counts === null ? null : no_rate_sentence($counts, $inv['engagement_number']);
    if ($counts !== null) {
        ct_flash($warn === null ? 'success' : 'warning', 'Drafted: ' . $counts['time_lines'] . ' time line' . ($counts['time_lines'] === 1 ? '' : 's') . ' (' . $counts['time_entries_billed'] . ' entries), ' . $counts['expense_lines'] . ' expense' . ($counts['expense_lines'] === 1 ? '' : 's') . ', '
            . $counts['usage_lines'] . ' usage charge' . ($counts['usage_lines'] === 1 ? '' : 's') . ', ' . $counts['milestone_lines'] . ' milestone' . ($counts['milestone_lines'] === 1 ? '' : 's') . '.' . ($warn === null ? '' : ' ' . $warn));
    }
    ct_done('Drafted an invoice for ' . $inv['engagement_number'], $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), $counts === null ? 'drafted' : null), 'invoiceChanged',
        ['invoice_id' => $inv['invoice_id'], 'status' => 'draft', 'total' => $inv['total'], 'currency' => $inv['currency'], 'counts' => $counts, 'warning' => $warn]);
}
$changed = ct_guard($pdo, static function () use ($pdo, $me, $cur, $f): array {
    $pdo->beginTransaction();
    $keys = update_invoice($pdo, $cur['invoice_id'], $f, $me);
    $after = find_invoice($pdo, $cur['invoice_id']);
    $diff = ct_diff(array_intersect_key($cur, array_flip($keys)), array_intersect_key($after, array_flip($keys)));
    invoice_log($pdo, 'invoice.update', $after, ['before' => $diff['before'], 'after' => $diff['after'] + ['currency' => $after['currency'], 'amount' => $after['total']]]);
    $pdo->commit();
    return $keys;
});
ct_done('Saved the draft for ' . $cur['engagement_number'], $cur['invoice_id'], ct_land(return_path('/invoices/' . $cur['invoice_id']), 'saved'), 'invoiceChanged', ['invoice_id' => $cur['invoice_id'], 'status' => 'draft', 'changed' => $changed]);
