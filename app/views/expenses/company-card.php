<?php /** Approved company-card spend by month and category (screen `company-card`). Data: rows, from, to, here */
$months = []; foreach ($rows as $r) { $months[$r['month']][] = $r; } $totals = []; foreach ($rows as $r) { $totals[$r['currency']] = ($totals[$r['currency']] ?? 0) + (float) $r['amount']; } ?>
<?= view('shared/header.php', ['id' => 'company-card', 'title' => 'Company card', 'crumbs' => [['Home', '/'], ['Reimbursements', '/expenses/reimbursements'], ['Company card', null]]]) ?>
<div class="main-content" id="company-card-content">
    <form method="get" action="/expenses/company-card" class="card mb-3" id="company-card-filters"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="company-card-from">From</label><input type="date" name="from" id="company-card-from" class="form-control btn-touch" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="company-card-to">To</label><input type="date" name="to" id="company-card-to" class="form-control btn-touch" value="<?= e($to) ?>"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="company-card-apply">Apply</button></div>
    </div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="company-card-empty">No approved company-card expense in this period.</div></div><?php endif; ?>
    <?php foreach ($months as $month => $list): ?>
    <div class="card mb-3" id="company-card-month-<?= e($month) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e((new DateTimeImmutable($month . '-01'))->format('F Y')) ?></h5></div>
        <div class="table-responsive"><table class="table table-sm mb-0" id="company-card-table-<?= e($month) ?>"><thead><tr><th>Category</th><th class="text-end">Count</th><th class="text-end">Tax inside</th><th class="text-end">Amount</th></tr></thead><tbody>
        <?php foreach ($list as $r): ?><tr id="company-card-row-<?= e($month) ?>-<?= (int) $r['category_id'] ?>-<?= e($r['currency']) ?>"><td><?= e($r['category']) ?></td><td class="text-end"><?= (int) $r['count'] ?></td><?= fmt_money_cell($r['tax_amount'], $r['currency']) ?><?= fmt_money_cell($r['amount'], $r['currency']) ?></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endforeach; ?>
    <?php if ($totals !== []): ?><div class="fw-bold text-end" id="company-card-total">Total <?= e(implode(' + ', array_map(static fn (string $c, float $a): string => fmt_money($a, (string) $c), array_keys($totals), array_values($totals)))) ?></div><?php endif; ?>
</div>
