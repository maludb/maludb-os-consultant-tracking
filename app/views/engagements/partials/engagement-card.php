<?php /** One engagement as a card. Data: e (with budget), here, may (ai) */ $id = (int) $e['engagement_id'];
$statusTone = ['draft' => 'secondary', 'active' => 'success', 'on_hold' => 'warning', 'closed' => 'dark', 'cancelled' => 'danger'][$e['status']] ?? 'secondary'; ?>
<div class="card h-100" id="engagement-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><div class="fw-bold text-truncate" id="engagement-card-<?= $id ?>-name"><?= hx_link(with_back('/engagements/' . $id, $here), e($e['number'] . ' · ' . $e['name']), 'text-dark') ?></div>
            <div class="fs-12 text-muted text-truncate" id="engagement-card-<?= $id ?>-client"><?= hx_link(with_back('/clients/' . (int) $e['client_id'], $here), e($e['client_name']), 'text-muted') ?><?= $e['manager_name'] ? ' · ' . e($e['manager_name']) : '' ?></div></div>
        <div class="text-end flex-shrink-0"><span class="badge bg-soft-<?= $statusTone ?> text-<?= $statusTone ?>" id="engagement-card-<?= $id ?>-status"><?= e(ENGAGEMENT_STATUSES[$e['status']] ?? $e['status']) ?></span>
            <span class="badge bg-soft-<?= $e['kind'] === 'fixed_bid' ? 'primary text-primary' : 'info text-info' ?>" id="engagement-card-<?= $id ?>-kind"><?= $e['kind'] === 'fixed_bid' ? 'Fixed' : 'T&amp;M' ?></span></div>
    </div>
    <div class="fs-12 text-muted mt-1"><?= e(format_date($e['start_date'])) ?><?= $e['end_date'] ? ' → ' . e(format_date($e['end_date'])) : '' ?><?= $e['i_manage'] ? ' · <span class="badge bg-soft-secondary text-secondary">I manage</span>' : '' ?><?= $e['i_am_staffed'] ? ' · <span class="badge bg-soft-secondary text-secondary">Staffed</span>' : '' ?><?= (int) $e['open_alerts'] > 0 ? ' · <span class="badge bg-soft-danger text-danger">' . (int) $e['open_alerts'] . ' alert' . ((int) $e['open_alerts'] === 1 ? '' : 's') . '</span>' : '' ?></div>
    <div class="mt-2"><?= view('engagements/partials/budget-bars.php', ['e' => $e, 'may' => $may, 'compact' => true]) ?></div>
    <div class="mt-2"><?= hx_link(with_back('/engagements/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="engagement-card-' . $id . '-open-btn"') ?></div>
</div></div>
