<?php /** The Billing Clerk's latest morning note on Billing's home (region `home-clerk-note`). Data: note (title, body, created_at, for_name), tz */ ?>
<div class="card mb-3" id="home-clerk-note">
    <div class="card-header d-flex align-items-center gap-2"><span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="feather-cpu"></i></span>
        <div class="min-w-0"><div class="fw-semibold" id="home-clerk-note-title"><?= e($note['title']) ?></div>
            <div class="fs-11 text-muted">Left for <?= e($note['for_name']) ?> · <?= e(format_ts($note['created_at'], $tz, 'M j, g:i A')) ?></div></div></div>
    <div class="card-body"><div class="fs-13" id="home-clerk-note-body" style="white-space: pre-wrap;"><?= e($note['body']) ?></div></div>
</div>
