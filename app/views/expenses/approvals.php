<?php /** The submitted expenses awaiting me (screen `expense-approvals`). Data: rows, engagement, missing, here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'expense-approvals', 'title' => 'Expense approvals', 'crumbs' => [['Home', '/'], ['Expense approvals', null]],
    'action' => hx_link($missing ? '/expenses/approvals' : '/expenses/approvals?missing_receipt=1', $missing ? 'Show all' : 'Missing receipt only', 'btn btn-light btn-touch', 'id="expense-approvals-filter-link"')]) ?>
<div class="main-content" id="expense-approvals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="approvals-empty">Nothing awaits you. An expense appears here once someone submits it on an engagement you manage.</div></div><?php endif; ?>
    <div class="row g-3" id="approvals-list">
        <?php foreach ($rows as $x): ?><div class="col-12 col-md-6 col-xl-4"><?= view('expenses/partials/expense-card.php', ['x' => $x, 'here' => $here, 'showMember' => true, 'approve' => true]) ?></div><?php endforeach; ?>
    </div>
</div>
