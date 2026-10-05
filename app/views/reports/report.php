<?php /** One report (screen `report`). Data: key, def, f (filters), report, scope?, pick, here, may (csv), weekStart? */
$csvUrl = '/reports/csv?' . http_build_query(['report' => $key] + array_filter($f, static fn ($v): bool => $v !== null && $v !== ''));
?>
<?= view('shared/header.php', ['id' => 'report', 'title' => $def['title'], 'crumbs' => [['Home', '/'], ['Reports', '/reports/'], [$def['title'], null]], 'back' => back_link(),
    'action' => $may['csv'] ? '<a href="' . e($csvUrl) . '" class="btn btn-light btn-touch" id="report-csv-btn" download><i class="feather-download me-1"></i>CSV</a>' : '']) ?>
<div class="main-content" id="report-content" data-report="<?= e($key) ?>">
    <?php if ($scope !== null): ?><div class="alert alert-info py-2 fs-12" id="report-scope"><?= e($scope) ?><?= $scope === 'Your engagements' ? ': the engagements you manage.' : '.' ?></div><?php endif; ?>
    <?= view('reports/partials/filters.php', ['key' => $key, 'def' => $def, 'f' => $f, 'pick' => $pick, 'weekStart' => $weekStart]) ?>
    <?= view('reports/partials/report-table.php', ['key' => $key, 'report' => $report, 'here' => $here, 'weekStart' => $weekStart, 'f' => $f]) ?>
</div>
