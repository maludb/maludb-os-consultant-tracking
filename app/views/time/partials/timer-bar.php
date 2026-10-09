<?php /** The timer bar: the running timer with elapsed and Stop, or Start on an engagement. Data: timer, loggable, activities, here, returnTo */ ?>
<div class="card mb-3" id="timer-bar"><div class="card-body py-2">
    <?php if ($timer !== null): ?>
    <div class="d-flex justify-content-between align-items-center gap-2">
        <div class="min-w-0"><i class="feather-clock text-primary me-1"></i><span class="fw-semibold" id="timer-bar-engagement"><?= e($timer['engagement_number']) ?></span> <span class="text-muted text-truncate"><?= e($timer['engagement_name']) ?></span>
            <div class="fs-12 text-muted">since <?= e(format_ts($timer['started_at'], member_timezone(), 'g:i A')) ?> · <span id="timer-bar-elapsed" data-started="<?= e($timer['started_at']) ?>"><?= e(fmt_hours($timer['elapsed_hours'])) ?></span></div></div>
        <form method="post" action="/time/timer/stop.php" hx-post="/time/timer/stop.php" hx-target="#flash" class="flex-shrink-0"><?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><button type="submit" class="btn btn-danger btn-touch" id="timer-bar-stop-btn"><i class="feather-square me-1"></i>Stop</button></form>
    </div>
    <script>(function () { var el = document.getElementById('timer-bar-elapsed'); if (!el) return; var t0 = Date.parse(el.dataset.started); if (!t0) return; var tick = function () { var m = Math.max(0, Math.floor((Date.now() - t0) / 60000)); el.textContent = Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2) + ' elapsed'; }; tick(); setInterval(tick, 30000); })();</script>
    <?php elseif ($loggable !== []): ?>
    <form method="post" action="/time/timer/start.php" hx-post="/time/timer/start.php" hx-target="#flash" class="row g-2 align-items-center" id="timer-bar-form">
        <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <div class="col-7 col-md-5"><?= picker_field(['id' => 'timer-bar-field-engagement', 'name' => 'engagement', 'source' => 'engagement_loggable', 'value' => $loggable[0]['engagement_id'], 'label' => $loggable[0]['number'] . ' · ' . $loggable[0]['name'], 'required' => true, 'params' => ['member_id' => (int) current_member_id(), 'on' => (int) date('Ymd')]]) ?></div>
        <div class="col-5 col-md-3 d-none d-md-block"><select name="activity" class="form-select btn-touch" id="timer-bar-field-activity" aria-label="Activity"><option value="">Activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-5 col-md-4"><button type="submit" class="btn btn-primary btn-touch w-100" id="timer-bar-start-btn"><i class="feather-play me-1"></i>Start timer</button></div>
    </form>
    <?php else: ?><div class="fs-12 text-muted" id="timer-bar-none">No timer: you are staffed on no active engagement today. Ask your manager to staff you.</div><?php endif; ?>
</div></div>
