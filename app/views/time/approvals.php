<?php /** The submitted weeks awaiting me (screen `time-approvals`). Data: rows, member, here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'time-approvals', 'title' => 'Time approvals', 'crumbs' => [['Home', '/'], ['Time approvals', null]],
    'action' => hx_link('/time/missing', 'Missing time', 'btn btn-light btn-touch', 'id="time-approvals-missing-link"')]) ?>
<div class="main-content" id="time-approvals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="approvals-empty">Nothing awaits you. A week appears here once someone submits hours on an engagement you manage.</div></div><?php endif; ?>
    <?php $byPerson = []; foreach ($rows as $t) { $byPerson[$t['member_id']][] = $t; } ?>
    <div id="approvals-list">
    <?php foreach ($byPerson as $mid => $weeks): ?>
        <div class="d-flex justify-content-between align-items-center mb-2 mt-3"><div class="fw-bold" id="approvals-person-<?= (int) $mid ?>"><?= e($weeks[0]['member_name']) ?> <span class="text-muted fw-normal fs-12">· <?= count($weeks) ?> week<?= count($weeks) === 1 ? '' : 's' ?></span></div>
            <?php if (count($weeks) > 1): ?><form method="post" action="/time/weeks/approve.php" hx-post="/time/weeks/approve.php" hx-target="#flash" hx-confirm="Approve every submitted week of <?= e($weeks[0]['member_name']) ?> that you manage?"><?= csrf_field() ?><input type="hidden" name="timesheet" value="<?= (int) $weeks[0]['timesheet_id'] ?>"><input type="hidden" name="return_to" value="/time/approvals?member=<?= (int) $mid ?>"><button type="submit" class="btn btn-sm btn-light" id="approvals-person-<?= (int) $mid ?>-approve-all">Approve the oldest</button></form><?php endif; ?></div>
        <div class="row g-3"><?php foreach ($weeks as $t): ?><div class="col-12 col-md-6 col-xl-4"><?= view('time/partials/week-card.php', ['t' => $t, 'here' => $here, 'tz' => $tz]) ?></div><?php endforeach; ?></div>
    <?php endforeach; ?>
    </div>
</div>
