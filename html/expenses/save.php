<?php
declare(strict_types=1);
/**
 * Action `expense_save` (by the presence of `expense`; log `expense.save`: number, amount, currency, category_id, paid_with, billable, markup_pct, recorded_by; `expense.attach` when a receipt came): expenses.write;
 * for another member → expenses.approve and manage of the engagement. The receipt (`file`, multipart) is stored in the same transaction; `submit=1` ("Save and submit") calls the submit verb after — all or nothing,
 * so a refused submit saves nothing. A Consultant's `markup_pct` is ignored (the database's policy stands) and the log says so; a manager's is kept. The database refuses in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
ct_handler_begin();
require_right('expenses.write');
$pdo = db();
$me = (int) current_member_id();
$id = expense_id_from_request();
$cur = null;
if ($id !== null) {
    $cur = find_expense($pdo, $id) ?? refuse(404, 'Expense not found.');
}
$member = $cur['member_id'] ?? (request_integer('member') ?? request_integer('member_id') ?? $me);
$errors = [];
$engField = req_has('engagement') ? 'engagement' : 'engagement_id';
$engagement = ct_ref($pdo, $engField, $cur['engagement_id'] ?? null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
$manages = $engagement !== null && has_right('expenses.approve') && can_manage_engagement($engagement);
if ($member !== $me && !$manages) {
    refuse(403, 'You may not record expenses for that person on that engagement.');
}
if ($cur !== null && $cur['member_id'] !== $me && !may_act_on_expense($cur, $me)) {
    refuse(403, 'You may not change that expense.');
}
$date = $cur['expense_date'] ?? null;
if (req_has('date') || req_has('expense_date') || $cur === null) {
    $d = request_date(req_has('date') ? 'date' : 'expense_date');
    if ($d === false || $d === null) {
        $errors['date'] = 'The date is a day (2026-03-02).';
    } else {
        $date = $d;
    }
}
$catField = req_has('category') ? 'category' : 'category_id';
$category = ct_ref($pdo, $catField, $cur['category_id'] ?? null, 'SELECT 1 FROM expense_categories WHERE id = :id AND archived_at IS NULL', 'category', $errors, false);
$amount = $cur['amount'] ?? null;
if (req_has('amount') || $cur === null) {
    $a = parse_money(req_val('amount'));
    if ($a === null || (float) $a <= 0 || (float) $a > 999999999) {
        $errors['amount'] = 'The amount is a number above zero (120.00), tax included.';
    } else {
        $amount = $a;
    }
}
$tax = $cur['tax_amount'] ?? '0.00';
if (req_has('tax_amount')) {
    $t = (string) req_val('tax_amount') === '' ? '0.00' : parse_money(req_val('tax_amount'));
    if ($t === null || (float) $t < 0) {
        $errors['tax_amount'] = 'The tax inside the amount is a number, zero or more.';
    } else {
        $tax = $t;
    }
}
if (!isset($errors['amount']) && !isset($errors['tax_amount']) && $amount !== null && (float) $tax > (float) $amount) {
    $errors['tax_amount'] = 'The tax is inside the amount, so it cannot be more than the amount.';
}
$description = $cur['description'] ?? null;
if (req_has('description') || $cur === null) {
    $description = trim((string) req_val('description'));
    if ($description === '') {
        $errors['description'] = 'Say what it was for (a few words).';
    }
    $description = mb_substr($description, 0, 500);
}
$vendor = req_has('vendor_name') ? ((string) req_val('vendor_name') === '' ? null : mb_substr((string) req_val('vendor_name'), 0, 200)) : ($cur['vendor_name'] ?? null);
$paid = $cur['paid_with'] ?? 'personal';
if (req_has('paid_with')) {
    $paid = (string) req_val('paid_with');
    if (!isset(PAID_WITH[$paid])) {
        $errors['paid_with'] = 'Paid with is personal or company_card.';
    }
}
$billable = $cur['billable'] ?? null;
if (req_has('billable')) {
    $billable = ct_yes('billable');
} elseif ($billable === null) {
    $billable = $category === null ? true : (bool) one_value($pdo, 'SELECT billable_default FROM expense_categories WHERE id = :id', ['id' => $category]);
}
$markup = null;
$markupIgnored = null;
if (req_has('markup_pct') && (string) req_val('markup_pct') !== '') {
    $raw = str_replace(',', '.', (string) req_val('markup_pct'));
    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 500) {
        $errors['markup_pct'] = 'The markup is a percentage from 0 to 500.';
    } elseif ($manages || is_ct_admin()) {
        $markup = number_format((float) $raw, 2, '.', '');
    } else {                                                          // a Consultant's markup is the database's policy: what they send is ignored (a forged value is noted)
        $policy = $cur !== null ? (float) $cur['markup_pct'] : ($category !== null && $engagement !== null ? (float) policy_markup($pdo, $category, $engagement) : 0.0);
        if (abs((float) $raw - $policy) > 0.004) {
            $markupIgnored = number_format((float) $raw, 2, '.', '');
        }
    }
}
$file = null;
if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['file'];
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['engagement_id' => $engagement, 'expense_date' => $date, 'category_id' => $category, 'amount' => $amount, 'tax_amount' => $tax, 'vendor_name' => $vendor, 'description' => $description, 'paid_with' => $paid, 'billable' => $billable, 'markup_pct' => $markup];
$submit = ct_yes('submit', false);
$loggable = static fn (array $x): array => ['engagement_id' => (int) $x['engagement_id'], 'expense_date' => $x['expense_date'], 'category_id' => (int) $x['category_id'], 'amount' => $x['amount'], 'tax_amount' => $x['tax_amount'], 'paid_with' => $x['paid_with'],
    'billable' => (bool) $x['billable'], 'markup_pct' => $x['markup_pct'], 'has_vendor' => $x['vendor_name'] !== null && $x['vendor_name'] !== ''];
$out = ct_guard($pdo, static function () use ($pdo, $me, $member, $id, $cur, $f, $file, $submit, $markupIgnored, $loggable): array {
    $pdo->beginTransaction();
    try {
        $newId = save_expense($pdo, $id, $f, $member, $me, $file);
        $row = $pdo->prepare('SELECT id AS expense_id, number, member_id, engagement_id, expense_date, category_id, vendor_name, amount, tax_amount, currency, paid_with, billable, markup_pct, billable_amount, status, submitted_at FROM expenses WHERE id = :id');
        $row->execute(['id' => $newId]);
        $after = $row->fetch();
        foreach (['expense_id', 'member_id', 'engagement_id', 'category_id'] as $k) {
            $after[$k] = (int) $after[$k];
        }
        $after['billable'] = (bool) $after['billable'];
        $money = ['number' => $after['number'], 'amount' => $after['amount'], 'currency' => trim((string) $after['currency']), 'member_id' => $member, 'recorded_by' => $me];
        $extra = $markupIgnored === null ? [] : ['markup_ignored' => $markupIgnored];
        if ($cur === null) {
            expense_log($pdo, 'expense.save', $newId, $after['engagement_id'], ['after' => $loggable($after) + $money + $extra]);
        } else {
            $d = ct_diff($loggable($cur), $loggable($after));
            if ($d['after'] !== [] || $extra !== [] || $cur['description'] !== $f['description']) {
                expense_log($pdo, 'expense.save', $newId, $after['engagement_id'], ['before' => $d['before'], 'after' => $d['after'] + $money + $extra]);
            }
        }
        if ($file !== null) {
            $att = $pdo->prepare("SELECT filename, byte_size, mime_type, client_visible FROM attachments WHERE record_type = 'expense' AND record_id = :id ORDER BY id DESC LIMIT 1");
            $att->execute(['id' => $newId]);
            $a = $att->fetch();
            expense_log($pdo, 'expense.attach', $newId, $after['engagement_id'], ['after' => ['number' => $after['number'], 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'mime_type' => $a['mime_type'], 'client_visible' => (bool) $a['client_visible']]]);
        }
        $status = $after['status'];
        if ($submit) {
            $x = ['expense_id' => $newId, 'engagement_id' => $after['engagement_id'], 'member_id' => $member, 'status' => $after['status']];
            $status = do_submit_expense($pdo, $x, $me, ['status' => $after['status']])['status'];
        }
        $pdo->commit();
        expense_stored_files(null, true);
        return [$newId, $after, $status];
    } catch (Throwable $t) {
        expense_discard_stored_files();
        throw $t;
    }
});
[$newId, $after, $status] = $out;
$num = $after['number'];
$label = $num . ' (' . fmt_money($after['amount'], trim((string) $after['currency'])) . ')';
ct_done(($submit ? 'Recorded and submitted ' : ($id === null ? 'Recorded ' : 'Changed ')) . $label, $newId, ct_land(return_path('/expenses/' . $newId), $submit ? 'submitted' : 'saved'), 'expenseChanged',
    ['expense_id' => $newId, 'number' => $num, 'status' => $status, 'engagement_id' => $after['engagement_id']]);
