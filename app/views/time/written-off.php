<?php /** The hours written off with reasons (screen `written-off`). Data: rows, filters, here, tz, rates */ ?>
<?= view('shared/header.php', ['id' => 'written-off', 'title' => 'Written off', 'crumbs' => [['Home', '/'], ['Time approvals', '/time/approvals'], ['Written off', null]]]) ?>
<div class="main-content" id="written-off-content">
    <form method="get" action="/time/written-off" hx-get="/time/written-off" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="written-off-filters"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="written-off-filter-from">From</label><input type="date" name="from" id="written-off-filter-from" class="form-control btn-touch" value="<?= e($filters['from'] ?? '') ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="written-off-filter-to">To</label><input type="date" name="to" id="written-off-filter-to" class="form-control btn-touch" value="<?= e($filters['to'] ?? '') ?>"></div>
        <div class="col-12 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div>
    </div></form>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0" id="written-off-table">
        <thead><tr><th>Date</th><th>Person</th><th>Engagement</th><th class="text-end">Hours</th><?php if ($rates): ?><th class="text-end">Value</th><?php endif; ?><th>Reason</th></tr></thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="6" class="text-muted py-3" id="written-off-empty">Nothing written off.</td></tr><?php endif; ?>
            <?php foreach ($rows as $e): ?><tr id="written-off-<?= (int) $e['entry_id'] ?>"><td class="text-nowrap"><?= e(format_date($e['entry_date'])) ?></td><td><?= e($e['member_name']) ?></td><td><?= e($e['engagement_number']) ?> <span class="text-muted fs-12"><?= e($e['engagement_name']) ?></span></td><td class="text-end"><?= e(fmt_hours($e['hours'])) ?></td><?php if ($rates): ?><td class="text-end text-nowrap"><?= $e['bill_rate'] === null ? '—' : e(fmt_money((float) $e['hours'] * (float) $e['bill_rate'], $e['currency'])) ?></td><?php endif; ?><td class="fs-12"><?= e((string) $e['written_off_reason']) ?> <span class="text-muted">· <?= e(format_ts($e['written_off_at'], $tz, 'M j')) ?></span></td></tr><?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
