<?php /** The week as a grid (the desktop's shape): engagements down, days across, a row total and a day total — the same data as the day list. Data: shape, here, editable, addBase */ $days = array_values($shape['days']); ?>
<div class="card d-none d-lg-block mb-3" id="timesheet-grid"><div class="table-responsive"><table class="table table-sm mb-0 text-center">
    <thead><tr><th class="text-start">Engagement</th><?php foreach ($days as $d): ?><th class="<?= $d['day']['today'] ? 'text-primary' : ($d['day']['weekend'] ? 'text-muted' : '') ?>"><?= e($d['day']['label']) ?></th><?php endforeach; ?><th>Total</th></tr></thead>
    <tbody>
        <?php if ($shape['rows'] === []): ?><tr><td colspan="9" class="text-muted py-3" id="timesheet-grid-empty">Nothing logged this week.</td></tr><?php endif; ?>
        <?php foreach ($shape['rows'] as $r): ?>
            <tr id="grid-row-<?= (int) $r['engagement_id'] ?>"><td class="text-start fw-semibold"><?= e($r['number']) ?> <span class="text-muted fw-normal"><?= e($r['name']) ?></span></td>
            <?php foreach ($days as $d): $h = $shape['grid'][$r['engagement_id']][$d['day']['date']] ?? 0.0; ?><td id="grid-cell-<?= (int) $r['engagement_id'] ?>-<?= e($d['day']['date']) ?>"><?= $h > 0 ? ($editable ? hx_link(with_back($addBase . $d['day']['date'] . '&engagement=' . (int) $r['engagement_id'], $here), e(days_label($h))) : e(days_label($h))) : ($editable ? hx_link(with_back($addBase . $d['day']['date'] . '&engagement=' . (int) $r['engagement_id'], $here), '·', 'text-muted') : '<span class="text-muted">·</span>') ?></td><?php endforeach; ?>
            <td class="fw-semibold" id="grid-row-<?= (int) $r['engagement_id'] ?>-total"><?= e(days_label($r['total'])) ?></td></tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="fw-semibold"><td class="text-start">Day total</td><?php foreach ($days as $d): ?><td id="grid-day-<?= e($d['day']['date']) ?>-total"><?= $d['total'] > 0 ? e(days_label($d['total'])) : '' ?></td><?php endforeach; ?><td id="grid-week-total"><?= e(days_label(array_sum(array_map(fn ($d) => $d['total'], $days)))) ?></td></tr></tfoot>
</table></div></div>
