<?php /** One charge as a table row (mode row) or a card (mode card). Data: u (a usage charge row), here, mode */
$id = (int) $u['usage_charge_id']; $mode = $mode ?? 'row'; $p = 'charge-' . $mode . '-' . $id; $tone = USAGE_TONES[$u['status']]; $pc = pricing_chip($u); $src = USAGE_SOURCE_ICONS[$u['source']] ?? '';
$chips = '<span class="badge bg-soft-' . $tone . ' text-' . $tone . '" id="' . $p . '-status">' . e(USAGE_STATUSES[$u['status']]) . '</span>'
    . ($pc === null ? '' : ' <span class="badge bg-soft-' . $pc[1] . ' text-' . $pc[1] . '" id="' . $p . '-pricing">' . e($pc[0]) . '</span>')
    . ($src === '' ? '' : ' <span class="badge bg-soft-secondary text-secondary" id="' . $p . '-source" title="' . e(USAGE_SOURCES[$u['source']]) . '"><i class="' . $src . ' me-1"></i>' . ($u['source'] === 'kernel_ledger' ? 'OS' : '') . '</span>')
    . ($u['billed'] ? ' <span class="badge bg-dark" id="' . $p . '-billed">Billed</span>' : '') . (!$u['billable'] ? ' <span class="badge bg-soft-secondary text-secondary">Not billable</span>' : ''); ?>
<?php if ($mode === 'row'): ?>
<tr id="charge-row-<?= $id ?>">
    <td class="text-nowrap fw-semibold"><?= hx_link(with_back('/usage/' . $id, $here), e($u['number']), 'text-dark') ?></td>
    <td><?= hx_link(with_back('/engagements/' . (int) $u['engagement_id'], $here), e($u['engagement_number'])) ?></td>
    <td class="text-nowrap fs-12"><?= e(format_date($u['period_start'])) ?><?= $u['period_end'] !== $u['period_start'] ? ' → ' . e(format_date($u['period_end'])) : '' ?></td>
    <td class="fs-12"><?= e(USAGE_KINDS[$u['kind']]) ?><?= $u['provider_name'] ? ' · ' . e($u['provider_name']) : '' ?><div class="text-muted text-truncate" style="max-width: 22rem"><?= e($u['description']) ?></div></td>
    <td class="text-end text-nowrap fs-12"><?= e(fmt_qty($u['quantity'], $u['unit'])) ?></td>
    <td class="text-end text-nowrap" id="charge-row-<?= $id ?>-cost"><?= e(cost_cell($u)) ?></td>
    <td class="text-end text-nowrap fw-bold" id="charge-row-<?= $id ?>-amount"><?= e(fmt_money($u['billable_amount'], $u['currency'])) ?></td>
    <td><?= $chips ?></td>
</tr>
<?php else: ?>
<div class="card h-100" id="charge-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <div class="fw-bold text-truncate"><?= hx_link(with_back('/usage/' . $id, $here), e($u['number']), 'text-dark') ?></div>
            <div class="fs-12 text-muted text-truncate"><?= e(USAGE_KINDS[$u['kind']]) ?><?= $u['provider_name'] ? ' · ' . e($u['provider_name']) : '' ?> · <?= e(format_date($u['period_start'])) ?></div>
            <div class="fs-12 text-truncate"><?= hx_link(with_back('/engagements/' . (int) $u['engagement_id'], $here), e($u['engagement_number'])) ?></div>
        </div>
        <div class="text-end flex-shrink-0"><div class="fw-bold" id="charge-card-<?= $id ?>-amount"><?= e(fmt_money($u['billable_amount'], $u['currency'])) ?></div><div class="fs-12 text-muted" id="charge-card-<?= $id ?>-cost">cost <?= e(cost_cell($u)) ?></div></div>
    </div>
    <div class="fs-12 mt-1 text-truncate"><?= e($u['description']) ?> · <?= e(fmt_qty($u['quantity'], $u['unit'])) ?></div>
    <div class="d-flex flex-wrap gap-1 mt-2"><?= $chips ?></div>
</div></div>
<?php endif; ?>
