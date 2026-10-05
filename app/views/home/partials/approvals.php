<?php /** The home's Approvals waiting region: weeks (slice 3), expenses (4), usage charges (5). Data: a = [weeks, expenses, usage] */ ?>
<div class="card h-100" id="home-approvals"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start"><div class="fw-bold">Approvals waiting</div><?= hx_link('/time/approvals', 'Time approvals', 'fs-12', 'id="home-approvals-link"') ?></div>
    <?php if ($a['weeks'] === []): ?><div class="fs-12 text-muted" id="home-approvals-weeks-empty">No week awaits you.</div><?php endif; ?>
    <?php foreach ($a['weeks'] as $t): ?><div class="fs-12 d-flex justify-content-between" id="home-approval-week-<?= (int) $t['timesheet_id'] ?>"><span><?= hx_link(with_back('/time/weeks/' . (int) $t['timesheet_id'], '/'), e($t['member_name'])) ?> <span class="text-muted"><?= e(week_label($t['week_start'])) ?></span></span><span class="fw-semibold"><?= e(fmt_hours($t['total_hours'])) ?></span></div><?php endforeach; ?>
    <?php if ($a['expenses'] === []): ?><div class="fs-12 text-muted mt-1" id="home-approvals-expenses-empty">No expense awaits you.</div><?php endif; ?>
    <?php foreach (($a['expenses'] ?? []) as $x): ?><div class="fs-12 d-flex justify-content-between" id="home-approval-expense-<?= (int) $x['expense_id'] ?>"><span><?= hx_link(with_back('/expenses/' . (int) $x['expense_id'], '/'), e($x['number'])) ?> <span class="text-muted"><?= e($x['member_name']) ?> · <?= e($x['category_label']) ?></span></span><span class="fw-semibold"><?= e(fmt_money($x['amount'], trim((string) $x['currency']))) ?></span></div><?php endforeach; ?>
    <?php if (($a['expenses'] ?? []) !== []): ?><div class="fs-12 mt-1"><?= hx_link('/expenses/approvals', 'Expense approvals', '', 'id="home-approvals-expenses-link"') ?></div><?php endif; ?>
    <?php if ($a['usage'] === null): ?><div class="fs-12 text-muted" id="home-approvals-usage-coming">Usage charges to approve appear here (slice 5).</div><?php endif; ?>
</div></div>
