<?php /** A submitted week awaiting approval, as a card. Data: t (timesheets_pending row), here, tz */ $id = (int) $t['timesheet_id']; ?>
<div class="card h-100" id="week-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold min-w-0 text-truncate" id="week-card-<?= $id ?>-name"><?= hx_link(with_back('/time/weeks/' . $id, $here), e($t['member_name']), 'text-dark') ?></div><span class="badge bg-soft-info text-info"><?= e(week_label($t['week_start'])) ?></span></div>
    <div class="fs-12 mt-1"><span class="fw-semibold" id="week-card-<?= $id ?>-hours"><?= e(fmt_hours($t['total_hours'])) ?></span> <span class="text-muted">· <?= e(days_label($t['billable_hours'])) ?> billable · <?= (int) $t['mine_to_approve'] ?> of <?= (int) $t['submitted_entries'] ?> entries yours to approve</span></div>
    <div class="fs-12 text-muted text-truncate" id="week-card-<?= $id ?>-engagements"><?= e((string) $t['engagements_in']) ?><?= $t['submitted_at'] ? ' · submitted ' . e(format_ts($t['submitted_at'], $tz, 'M j')) : '' ?></div>
    <div class="d-flex gap-1 mt-2">
        <form method="post" action="/time/weeks/approve.php" hx-post="/time/weeks/approve.php" hx-target="#flash" class="flex-fill" hx-confirm="Approve what you manage in <?= e($t['member_name']) ?>'s week?"><?= csrf_field() ?><input type="hidden" name="timesheet" value="<?= $id ?>"><input type="hidden" name="return_to" value="/time/approvals"><button type="submit" class="btn btn-primary btn-touch w-100" id="week-card-<?= $id ?>-approve-btn">Approve</button></form>
        <?= hx_link(with_back('/time/weeks/' . $id, $here), 'Open', 'btn btn-light btn-touch flex-fill', 'id="week-card-' . $id . '-open-btn"') ?>
    </div>
</div></div>
