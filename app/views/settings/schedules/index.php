<?php /** The hosting schedules (screen `schedule-list`). Data: rows, engagement, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'schedule-list', 'title' => 'Hosting schedules', 'crumbs' => [['Home', '/'], ['AI usage and hosting', '/usage/'], ['Hosting schedules', null]],
    'action' => hx_link(with_back('/settings/schedules/new' . ($engagement === null ? '' : '?engagement=' . $engagement), $here), '<i class="feather-plus me-1"></i>Add a schedule', 'btn btn-primary btn-touch', 'id="schedule-list-add-btn"')]) ?>
<div class="main-content" id="schedule-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">A schedule drafts a charge for the period it covers each time it falls due (a client's VM, a monthly hosting fee). The worker runs the due ones every morning; Run now does it by hand.</div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="schedule-list-empty">No schedule yet.</div></div><?php endif; ?>
    <div class="row g-3" id="schedule-list-cards">
        <?php foreach ($rows as $r): $id = (int) $r['schedule_id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="schedule-row-<?= $id ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><div class="fw-bold text-truncate"><?= hx_link(with_back('/settings/schedules/' . $id . '/edit', $here), e($r['description']), 'text-dark') ?></div>
                <div class="fs-12 text-truncate"><?= hx_link(with_back('/engagements/' . (int) $r['engagement_id'], $here), e($r['engagement_number'])) ?> · <?= e(USAGE_KINDS[$r['kind']]) ?><?= $r['provider_name'] ? ' · ' . e($r['provider_name']) : '' ?></div></div>
                <div class="text-end flex-shrink-0"><div class="fw-bold" id="schedule-row-<?= $id ?>-amount"><?= $r['unit_cost'] === null ? '—' : e(number_format((float) $r['quantity'] * (float) $r['unit_cost'], 2)) ?></div>
                    <span class="badge bg-soft-<?= $r['active'] ? 'success text-success' : 'secondary text-secondary' ?>" id="schedule-row-<?= $id ?>-active"><?= $r['active'] ? 'Active' : 'Done' ?></span></div></div>
            <div class="fs-12 mt-1" id="schedule-row-<?= $id ?>-cadence"><?= e(CADENCES[$r['cadence']]) ?><?= $r['every_n'] > 1 ? ', every ' . (int) $r['every_n'] : '' ?> · next <strong id="schedule-row-<?= $id ?>-next"><?= e(format_date($r['next_date'])) ?></strong><?= $r['due'] ? ' <span class="badge bg-soft-warning text-warning" id="schedule-row-' . $id . '-due">due</span>' : '' ?></div>
            <div class="fs-12 text-muted" id="schedule-row-<?= $id ?>-last"><?= $r['last_run_at'] ? 'Last ran ' . e(format_date(substr((string) $r['last_run_at'], 0, 10))) . ' · ' . (int) $r['run_count'] . ' run' . ($r['run_count'] === 1 ? '' : 's') : 'Never ran' ?><?= $r['end_date'] ? ' · ends ' . e(format_date($r['end_date'])) : '' ?></div>
            <div class="d-flex gap-2 mt-2"><?php if ($r['due']): ?><form method="post" action="/settings/schedules/run.php" hx-post="/settings/schedules/run.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="schedule" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="schedule-row-<?= $id ?>-run-btn">Run now</button></form><?php endif; ?>
                <?= hx_link(with_back('/settings/schedules/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch' . ($r['due'] ? '' : ' flex-grow-1'), 'id="schedule-row-' . $id . '-edit-btn"') ?>
                <form method="post" action="/settings/schedules/delete.php" hx-post="/settings/schedules/delete.php" hx-target="#flash" hx-confirm="Remove the schedule <?= e($r['description']) ?>?"><?= csrf_field() ?><input type="hidden" name="schedule" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="schedule-row-<?= $id ?>-delete-btn">Remove</button></form></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
