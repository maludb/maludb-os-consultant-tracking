<?php /** My expenses as cards (screen `expense-list`). Data: rows, counts, status, member, who, owed, engagement, engName, here, tz, notice */
$q = static fn (string $s): string => '/expenses/?' . http_build_query(array_filter(['status' => $s, 'engagement' => $engagement, 'member' => $who === null ? null : $member], static fn ($v) => $v !== null && $v !== '')); ?>
<?= view('shared/header.php', ['id' => 'expense-list', 'title' => $who === null ? 'My expenses' : $who . '\'s expenses', 'crumbs' => $who === null ? [['Home', '/'], ['My expenses', null]] : [['Home', '/'], ['Expense approvals', '/expenses/approvals'], [$who, null]],
    'action' => hx_link(with_back('/expenses/new' . ($who === null ? '' : '?member=' . $member), $here), '<i class="feather-camera me-1"></i>Add expense', 'btn btn-primary btn-touch', 'id="expense-list-add-btn"')]) ?>
<div class="main-content" id="expense-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($owed !== []): ?><div class="alert alert-info fs-12" id="expense-list-owed"><?= $who === null ? 'You are owed' : e($who) . ' is owed' ?> <strong><?= e(implode(' + ', array_map(static fn (string $c, string $a): string => fmt_money($a, $c), array_keys($owed), array_values($owed)))) ?></strong> — approved, paid personally, not yet reimbursed.</div><?php endif; ?>
    <div class="d-flex gap-1 mb-3 overflow-auto" id="expense-list-tabs">
        <?= hx_link($q(''), 'All <span class="badge bg-soft-secondary text-secondary">' . (int) $counts[''] . '</span>', 'btn btn-touch ' . ($status === '' ? 'btn-primary' : 'btn-light'), 'id="expense-tab-all"') ?>
        <?php foreach (EXPENSE_STATUSES as $k => $label): ?><?= hx_link($q($k), e($label) . ' <span class="badge bg-soft-secondary text-secondary">' . (int) $counts[$k] . '</span>', 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="expense-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($engName !== null): ?><div class="fs-12 mb-2" id="expense-list-engagement">On <?= e($engName) ?> · <?= hx_link($q($status), 'every engagement') ?></div><?php endif; ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="expense-list-empty"><?= $status === '' ? 'No expense yet. Snap a receipt and record the first one.' : 'None ' . e(strtolower(EXPENSE_STATUSES[$status])) . '.' ?></div></div><?php endif; ?>
    <div class="row g-3" id="expense-list-cards">
        <?php foreach ($rows as $x): ?><div class="col-12 col-md-6 col-xl-4"><?= view('expenses/partials/expense-card.php', ['x' => $x, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>
