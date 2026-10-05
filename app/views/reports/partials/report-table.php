<?php /** The rows of a report as a table with a totals footer (ids report-table, report-totals, report-empty). Data: key, report, here, weekStart?, f */
$cols = $report['columns'];
$tone = static fn (?string $pct): string => $pct === null ? 'secondary' : ((float) $pct >= 100 ? 'danger' : ((float) $pct >= 80 ? 'warning' : ((float) $pct >= 50 ? 'info' : 'success')));
$cell = static function (array $c, array $row) use ($here, $tone): string {
    $text = report_cell($c, $row);
    switch ($c['type']) {
        case 'text':
            $url = isset($c['link'], $row[$c['link']]) ? (string) $row[$c['link']] : '';
            return '<td>' . ($url !== '' ? hx_link(with_back($url, $here), e($text)) : e($text)) . '</td>';
        case 'money':
            $v = $row[$c['key']] ?? null;
            return $v === null ? '<td class="text-end text-muted">—</td>' : fmt_money_cell($v, (string) ($row['currency'] ?? ct_base_currency()));
        case 'pct':
            $s = report_status((string) ($c['status'] ?? ''), $row[$c['key']] ?? null);
            return '<td class="text-end text-nowrap">' . ($s !== '' ? '<span class="badge bg-soft-' . $s . ' text-' . $s . '">' . e($text) . '</span>' : e($text)) . '</td>';
        case 'budget':
            $pct = $row[$c['pct']] ?? null;
            return '<td class="text-nowrap fs-12">' . e($text) . ($pct !== null ? '<div class="progress mt-1" style="height: 5px" role="progressbar" aria-valuenow="' . e((string) min(100, (float) $pct)) . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-' . $tone($pct) . '" style="width: ' . min(100, (float) $pct) . '%"></div></div>' : '') . '</td>';
        case 'date':
            return '<td class="text-nowrap">' . e($text === '' ? '—' : $text) . '</td>';
        default:
            return '<td class="text-end text-nowrap">' . e($text) . '</td>';
    }
};
$nudge = $key === 'missing-time';
?>
<?php if ($report['rows'] === []): ?><div class="card"><div class="card-body text-muted" id="report-empty">Nothing to show for these filters.</div></div><?php else: ?>
<div class="card"><div class="table-responsive"><table class="table mb-0 fs-12" id="report-table">
    <thead><tr><?php foreach ($cols as $c): ?><th class="<?= $c['type'] === 'text' ? '' : 'text-end' ?>"><?= e($c['label']) ?></th><?php endforeach; ?><?php if ($nudge): ?><th></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($report['rows'] as $i => $row): ?>
        <tr id="report-row-<?= $i ?>" class="<?= !empty($row['is_subtotal']) ? 'fw-bold table-light' : '' ?>"><?php foreach ($cols as $c): echo $cell($c, $row); endforeach; ?>
            <?php if ($nudge): ?><td class="text-end"><?= hx_link(with_back('/time/missing?week=' . rawurlencode((string) $row['week_start']), $here), 'Nudge') ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody>
    <?php if ($report['totals'] !== []): $multi = count($report['totals']) > 1; ?>
    <tfoot><?php foreach ($report['totals'] as $t): ?><tr class="fw-bold" id="report-totals<?= $multi ? '-' . e($t['currency']) : '' ?>"><?php foreach ($cols as $n => $c): ?><?= $n === 0 ? '<td>Total' . ($multi ? ' ' . e($t['currency']) : '') . '</td>' : $cell($c, $t) ?><?php endforeach; ?></tr><?php endforeach; ?></tfoot>
    <?php endif; ?>
</table></div></div>
<?php endif; ?>
