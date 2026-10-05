<?php /** A month's lines as a table on a desktop and cards on a phone. Data: lines, currency? */ ?>
<div class="card mb-3 d-none d-md-block" id="statement-lines-table"><div class="table-responsive"><table class="table table-hover mb-0 fs-12">
    <thead><tr><th>Provider</th><th>Model</th><th>Agent</th><th>Department</th><th>Application</th><th class="text-end">Tokens</th><th class="text-end">Amount</th><th class="text-end">Allocated</th><th class="text-end">Remainder</th></tr></thead>
    <tbody><?php foreach ($lines as $l): $id = (int) $l['line_id']; ?><tr id="statement-line-<?= $id ?>"><td><?= e($l['provider']) ?></td><td><?= e((string) $l['model']) ?></td><td><?= e((string) $l['agent']) ?></td><td><?= e((string) $l['department']) ?></td><td><?= e((string) $l['application']) ?></td>
        <td class="text-end"><?= e(number_format($l['tokens'])) ?></td><td class="text-end text-nowrap"><?= e(fmt_money($l['amount'], $l['currency'])) ?></td><td class="text-end text-nowrap" id="statement-line-<?= $id ?>-allocated"><?= e(fmt_money($l['allocated_amount'], $l['currency'])) ?></td>
        <td class="text-end text-nowrap<?= (float) $l['remainder'] > 0 ? ' text-warning fw-semibold' : '' ?>" id="statement-line-<?= $id ?>-remainder"><?= e(fmt_money($l['remainder'], $l['currency'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div></div>
<div class="row g-3 d-md-none" id="statement-lines-cards"><?php foreach ($lines as $l): $id = (int) $l['line_id']; ?>
    <div class="col-12"><div class="card" id="statement-line-card-<?= $id ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2"><div class="min-w-0"><div class="fw-semibold text-truncate"><?= e($l['provider']) ?><?= $l['model'] ? ' · ' . e($l['model']) : '' ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(implode(' · ', array_filter([$l['agent'], $l['department'], $l['application']]))) ?></div></div>
            <div class="text-end flex-shrink-0 fw-bold"><?= e(fmt_money($l['amount'], $l['currency'])) ?></div></div>
        <div class="fs-12 mt-1"><?= e(number_format($l['tokens'])) ?> tokens · allocated <?= e(fmt_money($l['allocated_amount'], $l['currency'])) ?> · <span class="<?= (float) $l['remainder'] > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">remainder <?= e(fmt_money($l['remainder'], $l['currency'])) ?></span></div>
    </div></div></div><?php endforeach; ?></div>
