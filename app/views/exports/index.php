<?php /** The downloads (screen `exports`). Data: p (['from', 'to']), asOf */
$link = static fn (string $export, string $format): string => '/exports/download.php?' . http_build_query(['export' => $export, 'format' => $format]
    + (EXPORTS[$export]['period'] === 'as_of' ? ['to' => $asOf] : ['from' => $p['from'], 'to' => $p['to']]));
?>
<?= view('shared/header.php', ['id' => 'exports', 'title' => 'Exports', 'crumbs' => [['Home', '/'], ['Reports', '/reports/'], ['Exports', null]]]) ?>
<div class="main-content" id="exports-content">
    <form method="get" action="/exports" class="card mb-3" id="exports-filters"><div class="card-body p-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="exports-f-from">From</label><input type="date" name="from" id="exports-f-from" class="form-control btn-touch" value="<?= e((string) $p['from']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="exports-f-to">To (and "as of")</label><input type="date" name="to" id="exports-f-to" class="form-control btn-touch" value="<?= e((string) $p['to']) ?>"></div>
            <div class="col-12 col-md-3 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="exports-show-btn">Use this period</button></div>
        </div>
        <div class="fs-12 text-muted mt-2" id="exports-hint">Amounts carry their own currency; nothing is converted. A period is at most 24 months. Reimbursements and work in progress are "as of" the last day.</div>
    </div></form>
    <div class="row g-3" id="exports-cards">
        <?php foreach (EXPORTS as $key => $x): ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="export-card-<?= e($key) ?>"><div class="card-body p-3 d-flex flex-column">
                <div class="d-flex align-items-start gap-3 mb-2">
                    <span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="<?= e($x['icon']) ?>"></i></span>
                    <div class="min-w-0"><div class="fw-bold"><?= e($x['title']) ?></div><div class="fs-12 text-muted"><?= e($x['blurb']) ?></div>
                        <div class="fs-11 text-muted mt-1"><?= e($x['schema']) ?><?= $x['period'] === 'as_of' ? ' · as of ' . e(format_date($asOf)) : '' ?></div></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-auto pt-2">
                    <a href="<?= e($link($key, 'csv')) ?>" class="btn btn-light btn-touch" id="export-<?= e($key) ?>-csv-btn" download><i class="feather-download me-1"></i>CSV</a>
                    <a href="<?= e($link($key, 'json')) ?>" class="btn btn-light btn-touch" id="export-<?= e($key) ?>-json-btn" download><i class="feather-download me-1"></i>JSON</a>
                </div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
