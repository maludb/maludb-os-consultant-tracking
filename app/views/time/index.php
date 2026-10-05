<?php /** My week (screen `timesheet`). Data: t, ws, member, who, shape, entries, timer, loggable, settings, status, may, here, tz, activities, notice */
$label = week_label($ws); $prev = (new DateTimeImmutable($ws))->modify('-7 days')->format('Y-m-d'); $next = (new DateTimeImmutable($ws))->modify('+7 days')->format('Y-m-d');
$q = static fn (string $w): string => '/time/?week=' . $w . ($who !== null ? '&member=' . $member : '');
$tone = ['open' => 'secondary', 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'warning'][$status]; $addBase = '/time/entries/new' . ($who !== null ? '?member=' . $member . '&date=' : '?date=');
$editable = in_array($status, ['open', 'rejected'], true) && $may['edit']; $total = $t['total_hours'] ?? '0'; $billable = $t['billable_hours'] ?? '0'; ?>
<?= view('shared/header.php', ['id' => 'timesheet', 'title' => $who === null ? 'My timesheet' : $who . '\'s week', 'crumbs' => $who === null ? [['Home', '/'], ['My timesheet', null]] : [['Home', '/'], ['Time approvals', '/time/approvals'], [$who, null]],
    'action' => $editable ? hx_link(with_back($addBase . (array_key_exists(date('Y-m-d'), $shape['days']) ? date('Y-m-d') : $ws), $here), '<i class="feather-plus me-1"></i>Log time', 'btn btn-primary btn-touch', 'id="timesheet-log-btn"') : '']) ?>
<div class="main-content" id="timesheet-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="week-header"><div class="card-body py-2 d-flex justify-content-between align-items-center gap-2">
        <?= hx_link($q($prev), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="week-prev" aria-label="Previous week"') ?>
        <div class="text-center min-w-0"><div class="fw-bold" id="week-label"><?= e($label) ?></div><div class="fs-12"><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="week-status"><?= e(WEEK_STATUSES[$status]) ?></span> <span class="text-muted" id="week-totals"><?= e(fmt_hours($total)) ?> · <?= e(days_label($billable)) ?> billable</span></div></div>
        <?= hx_link($q($next), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="week-next" aria-label="Next week"') ?>
    </div></div>
    <?php if ($status === 'rejected' && $t !== null): ?><div class="alert alert-warning" id="week-rejection"><strong>Sent back:</strong> <?= e((string) $t['rejection_reason']) ?></div><?php endif; ?>
    <?php if ($who === null): ?><?= view('time/partials/timer-bar.php', ['timer' => $timer, 'loggable' => $loggable, 'activities' => $activities, 'here' => $here, 'returnTo' => '/time/?week=' . $ws]) ?><?php endif; ?>
    <?php if ($who === null && $loggable === [] && $entries === []): ?><div class="alert alert-info fs-12" id="timesheet-unstaffed">You are staffed on no active engagement today. Ask your manager to staff you; then your engagements appear here.</div><?php endif; ?>
    <?= view('time/partials/timesheet-grid.php', ['shape' => $shape, 'here' => $here, 'editable' => $editable, 'addBase' => $addBase]) ?>
    <?= view('time/partials/day-list.php', ['shape' => $shape, 'editable' => $editable, 'here' => $here, 'addBase' => $addBase, 'showRates' => false, 'manager' => $who !== null]) ?>
    <div class="d-flex flex-wrap gap-2 mt-3" id="week-footer">
        <?php if ($may['submit']): ?><form method="post" action="/time/weeks/submit.php" hx-post="/time/weeks/submit.php" hx-target="#flash" hx-confirm="Submit the <?= e(strtolower($label)) ?> (<?= e(fmt_hours($total)) ?>)<?= $who !== null ? ' for ' . e($who) : '' ?>?"><?= csrf_field() ?><input type="hidden" name="week" value="<?= e($ws) ?>"><input type="hidden" name="member" value="<?= (int) $member ?>"><button type="submit" class="btn btn-primary btn-touch" id="week-submit-btn"><?= $who === null ? 'Submit the week' : 'Submit for ' . e($who) ?></button></form><?php endif; ?>
        <?php if ($may['reopen'] && $t !== null): ?><form method="post" action="/time/weeks/reopen.php" hx-post="/time/weeks/reopen.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="timesheet" value="<?= (int) $t['timesheet_id'] ?>"><button type="submit" class="btn btn-light btn-touch" id="week-reopen-btn">Reopen</button></form><?php endif; ?>
        <?php if ($may['approve'] && $t !== null): ?><?= hx_link(with_back('/time/weeks/' . (int) $t['timesheet_id'], $here), 'Review as a manager', 'btn btn-light btn-touch', 'id="week-review-link"') ?><?php endif; ?>
        <?php if ($status === 'submitted'): ?><span class="fs-12 text-muted align-self-center" id="week-waiting">Awaiting approval<?= $t !== null && $t['submitted_at'] ? ' since ' . e(format_ts($t['submitted_at'], $tz, 'M j')) : '' ?>.</span><?php endif; ?>
        <?php if ($status === 'approved'): ?><span class="fs-12 text-muted align-self-center" id="week-approved">Approved<?= $t !== null && $t['approved_at'] ? ' ' . e(format_ts($t['approved_at'], $tz, 'M j')) : '' ?>.</span><?php endif; ?>
    </div>
</div>
