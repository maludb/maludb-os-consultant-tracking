<?php /** The filter row of a report (id report-filters). Data: key, def, f, pick, weekStart? */
$ownOnly = !has_right('reports.view');   // a Consultant reads her own utilization: no one else to pick
$has = static fn (string $n): bool => in_array($n, $def['filters'], true) && !($ownOnly && in_array($n, ['member', 'department'], true));
$sel = static function (string $name, string $label, array $opts, string $valueKey, string $textKey, $current, string $any = 'Any') use ($key): string {
    $h = '<div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="report-f-' . $name . '">' . e($label) . '</label><select name="' . $name . '" id="report-f-' . $name . '" class="form-select btn-touch"><option value="">' . e($any) . '</option>';
    foreach ($opts as $o) {
        $h .= '<option value="' . e((string) $o[$valueKey]) . '"' . ((string) $current === (string) $o[$valueKey] ? ' selected' : '') . '>' . e((string) $o[$textKey]) . '</option>';
    }
    return $h . '</select></div>';
};
$engs = array_map(static fn (array $e): array => ['engagement_id' => $e['engagement_id'], 'label' => $e['number'] . ' ' . $e['name']], $pick['engagements']);
?>
<form method="get" action="/reports/<?= e($key) ?>" class="card mb-3" id="report-filters"><div class="card-body p-3">
    <?php if ($def['period']): ?>
        <div class="d-flex flex-wrap gap-2 mb-2" id="report-presets">
            <?php foreach (REPORT_PRESETS as $p => $label): ?><a href="/reports/<?= e($key) ?>?preset=<?= e($p) ?>" class="btn btn-sm <?= ($f['preset'] ?? '') === $p ? 'btn-primary' : 'btn-light' ?>" id="report-preset-<?= e($p) ?>"><?= e($label) ?></a><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="row g-2 align-items-end">
        <?php if ($def['period']): ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-from">From</label><input type="date" name="from" id="report-f-from" class="form-control btn-touch" value="<?= e((string) $f['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-to">To</label><input type="date" name="to" id="report-f-to" class="form-control btn-touch" value="<?= e((string) $f['to']) ?>"></div>
        <?php endif; ?>
        <?php if ($has('as_of')): ?><div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-as_of">As of</label><input type="date" name="as_of" id="report-f-as_of" class="form-control btn-touch" value="<?= e((string) $f['as_of']) ?>"></div><?php endif; ?>
        <?php if ($has('week')): ?><div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-week">Week of</label><input type="date" name="week" id="report-f-week" class="form-control btn-touch" value="<?= e((string) ($f['week'] ?? $weekStart ?? '')) ?>"></div><?php endif; ?>
        <?php if ($has('by') && $key === 'revenue'): ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-by">Group by</label><select name="by" id="report-f-by" class="form-select btn-touch"><?php foreach (REVENUE_GROUPS as $v => $l): ?><option value="<?= e($v) ?>" <?= ($f['by'] ?? 'client') === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php elseif ($has('by')): ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-by">Group by</label><select name="by" id="report-f-by" class="form-select btn-touch"><option value="">Person</option><option value="practice" <?= ($f['by'] ?? '') === 'practice' ? 'selected' : '' ?>>Practice</option></select></div>
        <?php endif; ?>
        <?php if ($has('status')): $opts = $key === 'expenses' ? EXPENSE_REPORT_STATUSES : BUDGET_STATUSES; ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-f-status">Status</label><select name="status" id="report-f-status" class="form-select btn-touch"><?php foreach ($opts as $v => $l): ?><option value="<?= e($v) ?>" <?= ($f['status'] ?? '') === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?= $has('engagement') ? $sel('engagement', 'Engagement', $engs, 'engagement_id', 'label', $f['engagement'] ?? '') : '' ?>
        <?= $has('client') ? $sel('client', 'Client', $pick['clients'], 'client_id', 'name', $f['client'] ?? '') : '' ?>
        <?= $has('member') ? $sel('member', 'Person', $pick['members'], 'member_id', 'display_name', $f['member'] ?? '') : '' ?>
        <?= $has('manager') ? $sel('manager', 'Manager', $pick['members'], 'member_id', 'display_name', $f['manager'] ?? '') : '' ?>
        <?= $has('department') ? $sel('department', 'Practice', $pick['departments'], 'department_id', 'name', $f['department'] ?? '') : '' ?>
        <?= $has('category') ? $sel('category', 'Category', $pick['categories'], 'category_id', 'label', $f['category'] ?? '') : '' ?>
        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="report-show-btn">Show</button></div>
    </div>
</div></form>
