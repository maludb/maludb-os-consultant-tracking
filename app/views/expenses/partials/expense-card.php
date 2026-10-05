<?php /** One expense as a card. Data: x (find_expenses row), here, showMember? (a manager's list), approve? (the approvals variant: receipt thumbnail, Approve / Reject) */
$id = (int) $x['expense_id']; $tone = EXPENSE_TONES[$x['status']]; $cur = trim((string) $x['currency']); $showMember = $showMember ?? false; $approve = $approve ?? false;
$img = $x['receipt_id'] !== null && str_starts_with((string) $x['receipt_mime'], 'image/'); ?>
<div class="card h-100" id="expense-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <div class="fw-bold text-truncate" id="expense-card-<?= $id ?>-number"><?= hx_link(with_back('/expenses/' . $id, $here), e($x['number']), 'text-dark') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(format_date($x['expense_date'])) ?> · <?= e($x['category_label']) ?><?= $x['vendor_name'] ? ' · ' . e($x['vendor_name']) : '' ?></div>
            <div class="fs-12 text-truncate"><?= hx_link(with_back('/engagements/' . (int) $x['engagement_id'], $here), e($x['engagement_number']), '', 'id="expense-card-' . $id . '-engagement"') ?><?= $showMember ? ' <span class="text-muted">· ' . e($x['member_name']) . '</span>' : '' ?></div>
        </div>
        <div class="text-end flex-shrink-0">
            <?php if ($approve && $x['receipt_id'] !== null): ?><a href="/attachments/<?= (int) $x['receipt_id'] ?>" target="_blank" rel="noopener" class="d-block mb-1" id="expense-card-<?= $id ?>-receipt-thumb"><?php if ($img): ?><img src="/attachments/<?= (int) $x['receipt_id'] ?>" alt="Receipt of <?= e($x['number']) ?>" class="rounded border" style="width: 56px; height: 56px; object-fit: cover"><?php else: ?><span class="avatar-text avatar-lg rounded"><i class="feather-file-text"></i></span><?php endif; ?></a><?php endif; ?>
            <div class="fw-bold" id="expense-card-<?= $id ?>-amount"><?= e(fmt_money($x['amount'], $cur)) ?></div>
        </div>
    </div>
    <div class="fs-12 mt-1 text-truncate" id="expense-card-<?= $id ?>-description"><?= e($x['description']) ?></div>
    <div class="d-flex flex-wrap gap-1 mt-2" id="expense-card-<?= $id ?>-chips">
        <span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="expense-card-<?= $id ?>-status"><?= e(EXPENSE_STATUSES[$x['status']]) ?></span>
        <?php if ($x['has_receipt']): ?><span class="badge bg-soft-success text-success" id="receipt-<?= $id ?>"><i class="feather-paperclip me-1"></i>Receipt</span>
        <?php elseif ($x['receipt_needed']): ?><span class="badge bg-soft-danger text-danger" id="receipt-<?= $id ?>" title="A receipt is required at or above <?= e(fmt_money($x['receipt_threshold'], $cur, false)) ?>"><i class="feather-paperclip me-1"></i>Receipt needed</span><?php endif; ?>
        <?php if ($x['paid_with'] === 'company_card'): ?><span class="badge bg-soft-secondary text-secondary" title="Paid with the company card"><i class="feather-credit-card me-1"></i>Company card</span><?php endif; ?>
        <?php if ($x['billed']): ?><span class="badge bg-dark" id="expense-card-<?= $id ?>-billed">Billed</span><?php endif; ?>
        <?php if ($x['reimbursed_at'] !== null): ?><span class="badge bg-soft-success text-success" id="expense-card-<?= $id ?>-reimbursed">Reimbursed</span><?php endif; ?>
    </div>
    <?php if ($x['status'] === 'rejected' && $x['rejection_reason']): ?><div class="fs-12 text-warning mt-1">Sent back: <?= e($x['rejection_reason']) ?></div><?php endif; ?>
    <?php if ($approve): ?>
    <div class="mt-2">
        <form method="post" action="/expenses/approve.php" hx-post="/expenses/approve.php" hx-target="#flash" hx-confirm="Approve <?= e($x['number']) ?> (<?= e(fmt_money($x['amount'], $cur)) ?>)?" class="mb-2"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><input type="hidden" name="return_to" value="/expenses/approvals"><button type="submit" class="btn btn-primary btn-touch w-100" id="expense-card-<?= $id ?>-approve-btn">Approve</button></form>
        <form method="post" action="/expenses/reject.php" hx-post="/expenses/reject.php" hx-target="#flash" class="d-flex gap-1" id="expense-card-<?= $id ?>-reject-form"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><input type="hidden" name="return_to" value="/expenses/approvals"><input type="text" name="reason" class="form-control btn-touch" placeholder="Why (the person reads it)" maxlength="1000" required id="expense-card-<?= $id ?>-reject-reason"><button type="submit" class="btn btn-light btn-touch" id="expense-card-<?= $id ?>-reject-btn">Reject</button></form>
    </div>
    <?php endif; ?>
</div></div>
