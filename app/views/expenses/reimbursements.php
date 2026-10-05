<?php /** What each person is owed (screen `reimbursements`). Data: groups, member, here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'reimbursements', 'title' => 'Reimbursements', 'crumbs' => [['Home', '/'], ['Reimbursements', null]]]) ?>
<div class="main-content" id="reimbursements-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($groups === []): ?><div class="card"><div class="card-body text-muted" id="reimbursements-empty">Nobody is owed anything. Approved expenses paid personally appear here until they are reimbursed.</div></div><?php endif; ?>
    <?php foreach ($groups as $g): $mid = (int) $g['member_id']; ?>
    <div class="card mb-3" id="reimbursements-<?= $mid ?>"><div class="card-header d-flex justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= e($g['member_name']) ?></h5>
        <span class="fw-bold" id="reimbursements-<?= $mid ?>-total"><?= e(implode(' + ', array_map(static fn (string $c, string $a): string => fmt_money($a, $c), array_keys($g['totals']), array_values($g['totals'])))) ?></span>
    </div>
    <form method="post" action="/expenses/reimburse.php" hx-post="/expenses/reimburse.php" hx-target="#flash" hx-confirm="Mark the ticked expenses of <?= e($g['member_name']) ?> reimbursed?" id="reimbursements-<?= $mid ?>-form"><?= csrf_field() ?><input type="hidden" name="return_to" value="/expenses/reimbursements">
        <div class="list-group list-group-flush">
        <?php foreach ($g['expenses'] as $x): $eid = (int) $x['expense_id']; ?>
            <label class="list-group-item d-flex align-items-center gap-2" for="reimbursements-<?= $mid ?>-x-<?= $eid ?>" id="reimbursements-row-<?= $eid ?>">
                <input type="checkbox" class="form-check-input mt-0 flex-shrink-0" name="expenses[]" value="<?= $eid ?>" id="reimbursements-<?= $mid ?>-x-<?= $eid ?>" checked>
                <span class="min-w-0 flex-grow-1"><span class="fw-semibold"><?= e($x['number']) ?></span> <span class="text-muted fs-12"><?= e(format_date($x['expense_date'])) ?> · <?= e($x['description']) ?></span></span>
                <span class="fw-semibold text-nowrap"><?= e(fmt_money($x['amount'], trim((string) $x['currency']))) ?></span>
            </label>
        <?php endforeach; ?>
        </div>
        <div class="card-body border-top d-flex gap-2">
            <input type="text" name="reference" class="form-control btn-touch" placeholder="Pay run or payment reference" maxlength="200" id="reimbursements-<?= $mid ?>-reference">
            <button type="submit" class="btn btn-primary btn-touch flex-shrink-0" id="reimbursements-<?= $mid ?>-pay-btn">Mark reimbursed</button>
        </div>
    </form></div>
    <?php endforeach; ?>
</div>
