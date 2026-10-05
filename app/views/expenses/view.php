<?php /** One expense (screen `expense-view`). Data: x, may, here, tz, timeline, me, notice */
$id = (int) $x['expense_id']; $tone = EXPENSE_TONES[$x['status']]; $cur = trim((string) $x['currency']); ?>
<?= view('shared/header.php', ['id' => 'expense-view', 'title' => $x['number'], 'crumbs' => [['Home', '/'], ['My expenses', '/expenses/'], [$x['number'], null]], 'back' => back_link() ?? ['/expenses/', 'my expenses'],
    'action' => $may['edit'] ? hx_link(with_back('/expenses/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch', 'id="expense-view-edit-btn"') : '']) ?>
<div class="main-content" id="expense-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <?= view('expenses/partials/receipt.php', ['x' => $x]) ?>
            <div class="card mb-3" id="expense-money"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="fs-20 fw-bold" id="expense-money-amount"><?= e(fmt_money($x['amount'], $cur)) ?></div><div class="fs-12 text-muted" id="expense-money-tax">of which tax <?= e(fmt_money($x['tax_amount'], $cur)) ?></div></div>
                    <div class="text-end"><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="expense-view-status"><?= e(EXPENSE_STATUSES[$x['status']]) ?></span>
                        <?php if ($x['billed']): ?> <span class="badge bg-dark" id="expense-view-billed">Billed</span><?php endif; ?>
                        <?php if ($x['reimbursed_at'] !== null): ?> <span class="badge bg-soft-success text-success" id="expense-view-reimbursed">Reimbursed</span><?php endif; ?></div>
                </div>
                <hr class="my-2">
                <div class="fs-12" id="expense-money-billable"><?php if ($x['billable']): ?>Billable at <?= e(days_label($x['markup_pct'])) ?>% markup → <strong><?= e(fmt_money($x['billable_amount'], $cur)) ?></strong><?php else: ?>Not billable to the client.<?php endif; ?></div>
                <div class="fs-12 text-muted" id="expense-money-paid"><?= e(PAID_WITH[$x['paid_with']]) ?><?= $x['reimbursable'] ? ' — reimbursable' : ' — nothing to reimburse' ?></div>
                <hr class="my-2">
                <div class="fs-12"><span class="text-muted">Engagement</span> <?= hx_link(with_back('/engagements/' . (int) $x['engagement_id'], $here), e($x['engagement_number']), '', 'id="expense-view-engagement"') ?></div>
                <div class="fs-12"><span class="text-muted">Person</span> <span id="expense-view-member"><?= e($x['member_name']) ?></span> · <span class="text-muted">Date</span> <?= e(format_date($x['expense_date'])) ?> · <span class="text-muted">Category</span> <?= e($x['category_label']) ?><?= $x['vendor_name'] ? ' · <span class="text-muted">Vendor</span> ' . e($x['vendor_name']) : '' ?></div>
                <div class="fs-12 mt-1" id="expense-view-description"><?= e($x['description']) ?></div>
                <div class="fs-12 text-muted mt-2" id="expense-view-dates">
                    <?php if ($x['submitted_at']): ?>Submitted <?= e(format_ts($x['submitted_at'], $tz)) ?>. <?php endif; ?>
                    <?php if ($x['approved_at']): ?>Approved <?= e(format_ts($x['approved_at'], $tz)) ?>. <?php endif; ?>
                    <?php if ($x['rejected_at']): ?>Sent back <?= e(format_ts($x['rejected_at'], $tz)) ?>. <?php endif; ?>
                    <?php if ($x['reimbursed_at']): ?>Reimbursed <?= e(format_ts($x['reimbursed_at'], $tz)) ?><?= $x['reimbursed_ref'] ? ' (' . e($x['reimbursed_ref']) . ')' : '' ?>.<?php endif; ?>
                </div>
                <?php if ($x['status'] === 'rejected' && $x['rejection_reason']): ?><div class="alert alert-warning mt-2 mb-0" id="expense-view-rejection"><strong>Sent back:</strong> <?= e($x['rejection_reason']) ?></div><?php endif; ?>
            </div></div>
            <?= view('shared/attachments.php', ['type' => 'expense', 'id' => $id, 'attachments' => $x['receipts'], 'may' => ['attach' => $may['attach'], 'delete' => $may['attach']], 'here' => $here, 'prefix' => 'expense']) ?>
            <?= view('shared/notes.php', ['type' => 'expense', 'id' => $id, 'notes' => $x['notes'], 'may' => ['note' => true, 'admin' => $may['admin']], 'here' => $here, 'tz' => $tz, 'prefix' => 'expense', 'me' => $me]) ?>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="expense-actions"><div class="card-body"><div class="d-grid gap-2">
                <?php if ($may['submit']): ?><form method="post" action="/expenses/submit.php" hx-post="/expenses/submit.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="expense-view-submit-btn">Submit</button></form><?php endif; ?>
                <?php if ($may['approve']): ?><form method="post" action="/expenses/approve.php" hx-post="/expenses/approve.php" hx-target="#flash" hx-confirm="Approve <?= e($x['number']) ?> (<?= e(fmt_money($x['amount'], $cur)) ?>)?"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="expense-view-approve-btn">Approve</button></form><?php endif; ?>
                <?php if ($may['reject']): ?><form method="post" action="/expenses/reject.php" hx-post="/expenses/reject.php" hx-target="#flash" id="expense-view-reject-form"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="What must change (the person reads it)" maxlength="1000" required id="expense-view-reject-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="expense-view-reject-btn">Send back</button></form><?php endif; ?>
                <?php if ($may['reimburse']): ?><form method="post" action="/expenses/reimburse.php" hx-post="/expenses/reimburse.php" hx-target="#flash" hx-confirm="Mark <?= e($x['number']) ?> reimbursed?" id="expense-view-reimburse-form"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><input type="hidden" name="return_to" value="/expenses/<?= $id ?>"><input type="text" name="reference" class="form-control btn-touch mb-2" placeholder="Pay run or payment reference" maxlength="200" id="expense-view-reimburse-reference"><button type="submit" class="btn btn-primary btn-touch w-100" id="expense-view-reimburse-btn">Mark reimbursed</button></form><?php endif; ?>
                <?php if ($may['delete']): ?><form method="post" action="/expenses/delete.php" hx-post="/expenses/delete.php" hx-target="#flash" hx-confirm="Remove <?= e($x['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="expense-view-delete-btn">Remove</button></form><?php endif; ?>
                <?php if (!array_filter($may, static fn ($v, $k) => $v === true && in_array($k, ['submit', 'approve', 'reject', 'reimburse', 'delete'], true), ARRAY_FILTER_USE_BOTH)): ?><div class="fs-12 text-muted" id="expense-view-no-actions">Nothing to do here for now.</div><?php endif; ?>
            </div></div></div>
            <?= view('expenses/partials/timeline.php', ['rows' => $timeline, 'tz' => $tz]) ?>
        </div>
    </div>
</div>
