<?php /** AI usage and hosting: the charges (screen `usage-list`). Data: rows, totals, filters, engagements, import, here, tz, notice, may */
$f = $filters; $q = static fn (array $over = []): string => '/usage/?' . http_build_query(array_filter($over + ['engagement' => $f['engagement_id'], 'from' => $f['from'], 'to' => $f['to'], 'kind' => $f['kind'], 'source' => $f['source'], 'status' => $f['status']], static fn ($v) => $v !== null && $v !== '')); ?>
<?= view('shared/header.php', ['id' => 'usage-list', 'title' => 'AI usage and hosting', 'crumbs' => [['Home', '/'], ['AI usage and hosting', null]],
    'action' => hx_link(with_back('/usage/new' . ($f['engagement_id'] === null ? '' : '?engagement=' . $f['engagement_id']), $here), '<i class="feather-plus me-1"></i>Record a charge', 'btn btn-primary btn-touch', 'id="usage-list-add-btn"')]) ?>
<div class="main-content" id="usage-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 mb-3" id="usage-list-links">
        <?= hx_link(with_back('/usage/import', $here), '<i class="feather-upload me-1"></i>Import a CSV', 'btn btn-light btn-touch', 'id="usage-list-import-btn"') ?>
        <?= hx_link(with_back('/usage/os', $here), '<i class="feather-activity me-1"></i>The OS statement', 'btn btn-light btn-touch', 'id="usage-list-os-btn"') ?>
        <?= hx_link(with_back('/settings/schedules/', $here), '<i class="feather-refresh-cw me-1"></i>Hosting schedules', 'btn btn-light btn-touch', 'id="usage-list-schedules-btn"') ?>
        <?= hx_link(with_back('/settings/allocation-rules/', $here), '<i class="feather-git-merge me-1"></i>Allocation rules', 'btn btn-light btn-touch', 'id="usage-list-rules-btn"') ?>
    </div>
    <?php if ($import !== null): ?>
    <div class="alert alert-info fs-12 d-flex flex-wrap justify-content-between align-items-center gap-2" id="usage-list-import">
        <span>From the import of <strong><?= e($import['file_name']) ?></strong> — <?= (int) $import['inserted'] ?> charge<?= (int) $import['inserted'] === 1 ? '' : 's' ?> made, <?= (int) $import['skipped'] ?> duplicate<?= (int) $import['skipped'] === 1 ? '' : 's' ?> skipped.<?= $import['detail'] ? ' ' . e($import['detail']) : '' ?></span>
        <form method="post" action="/usage/import-delete.php" hx-post="/usage/import-delete.php" hx-target="#flash" hx-confirm="Remove the draft charges of this import?"><?= csrf_field() ?><input type="hidden" name="import" value="<?= (int) $import['import_id'] ?>"><button type="submit" class="btn btn-light btn-touch" id="usage-list-import-delete-btn">Remove its drafts</button></form>
    </div>
    <?php endif; ?>
    <form method="get" action="/usage/" class="card mb-3" id="usage-filter-form"><div class="card-body"><div class="row g-2 align-items-end">
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="usage-filter-engagement">Engagement</label><select name="engagement" id="usage-filter-engagement" class="form-select btn-touch"><option value="">All</option><?php foreach ($engagements as $g): ?><option value="<?= (int) $g['engagement_id'] ?>" <?= $f['engagement_id'] === (int) $g['engagement_id'] ? 'selected' : '' ?>><?= e($g['number'] . ' · ' . $g['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-filter-from">From</label><input type="date" name="from" id="usage-filter-from" class="form-control btn-touch" value="<?= e((string) $f['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="usage-filter-to">To</label><input type="date" name="to" id="usage-filter-to" class="form-control btn-touch" value="<?= e((string) $f['to']) ?>"></div>
        <div class="col-4 col-md-1"><label class="form-label fs-12 text-muted" for="usage-filter-kind">Kind</label><select name="kind" id="usage-filter-kind" class="form-select btn-touch"><option value="">All</option><?php foreach (USAGE_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><label class="form-label fs-12 text-muted" for="usage-filter-source">Source</label><select name="source" id="usage-filter-source" class="form-select btn-touch"><option value="">All</option><?php foreach (USAGE_SOURCES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-1"><label class="form-label fs-12 text-muted" for="usage-filter-status">Status</label><select name="status" id="usage-filter-status" class="form-select btn-touch"><option value="">All</option><?php foreach (USAGE_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-1 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="usage-filter-btn">Filter</button></div>
    </div></div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="usage-list-empty">No usage charge here yet. Record one, import a provider's CSV, run a hosting schedule or allocate the OS statement.</div></div><?php endif; ?>
    <?php if ($rows !== []): ?>
    <div class="card mb-3 d-none d-md-block" id="usage-list-table"><div class="table-responsive"><table class="table table-hover mb-0 fs-12">
        <thead><tr><th>Number</th><th>Engagement</th><th>Period</th><th>What</th><th class="text-end">Quantity</th><th class="text-end">Cost</th><th class="text-end">Billable</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $u): ?><?= view('usage/partials/charge-row.php', ['u' => $u, 'here' => $here, 'mode' => 'row']) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="row g-3 d-md-none" id="usage-list-cards"><?php foreach ($rows as $u): ?><div class="col-12"><?= view('usage/partials/charge-row.php', ['u' => $u, 'here' => $here, 'mode' => 'card']) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($totals !== []): ?>
    <div class="card mt-3" id="usage-list-totals"><div class="card-body p-3">
        <?php foreach ($totals as $t): ?><div class="d-flex flex-wrap justify-content-between gap-2 fs-12" id="usage-total-<?= e($t['currency']) ?>"><span class="fw-semibold"><?= (int) $t['charges'] ?> charge<?= $t['charges'] === 1 ? '' : 's' ?> (void left out)</span><span>Cost <strong><?= e($t['cost_amount'] === null ? '—' : fmt_money($t['cost_amount'], $t['currency'])) ?></strong> · Billable <strong><?= e(fmt_money($t['billable_amount'], $t['currency'])) ?></strong></span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
