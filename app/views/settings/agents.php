<?php /** The agents that work here (screen `agents`). Data: rows (agents_here), os (the OS's pages or null), tz */
$status = ['answered' => 'success', 'sent' => 'info', 'awaiting_approval' => 'warning', 'refused' => 'danger', 'failed' => 'danger'];
$source = static fn (?string $s): string => $s === null ? '' : ' · ' . $s; ?>
<?= view('shared/header.php', ['id' => 'agents', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Agents', null]]]) ?>
<div class="main-content" id="agents-content">
    <div class="alert alert-light border fs-12" id="agents-note">The agents that work here are hired, granted their tools and scheduled by the Business OS, not by this application — Consultant Tracking only shows what they did here.
        <?= $os !== null ? 'Hire, grant and schedule them at <a href="' . e($os) . '" id="agents-os-link" rel="noopener">' . e((string) parse_url($os, PHP_URL_HOST)) . '</a>.' : 'Hire, grant and schedule them on the OS (os.&lt;your domain&gt;).' ?>
        Ask the expert a question from the command bar on any page.</div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="agents-empty">No agent is declared or known here yet.</div></div><?php endif; ?>
    <div class="row g-3" id="agents-cards">
        <?php foreach ($rows as $i => $a): $m = $a['member']; $d = $a['declared']; $key = $a['key'] ?? ('member-' . ($m['member_id'] ?? $i)); ?>
        <div class="col-12 col-lg-6"><div class="card h-100" id="agent-<?= e($key) ?>"><div class="card-body p-3">
            <div class="d-flex align-items-start gap-2 mb-2"><span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="feather-cpu"></i></span>
                <div class="min-w-0"><div class="fw-bold" id="agent-<?= e($key) ?>-name"><?= e($m['display_name'] ?? ucfirst((string) $a['key'])) ?></div>
                    <div class="fs-12 text-muted"><?= e($m['job_title'] ?? ($a['key'] === 'expert' ? 'The consulting expert' : 'Declared by this application')) ?></div></div>
                <span class="ms-auto badge <?= $m === null ? 'bg-soft-warning text-warning' : 'bg-soft-success text-success' ?>" id="agent-<?= e($key) ?>-status"><?= $m === null ? 'Not hired here yet' : 'Hired · ' . e($m['status']) ?></span></div>
            <dl class="row fs-12 mb-0 g-1">
                <?php if ($m !== null): ?><dt class="col-5 text-muted">Roles here</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-roles"><?= e(implode(', ', $m['roles']) ?: 'none') ?> <span class="text-muted">(<?= e($m['capability']) ?>)</span></dd><?php endif; ?>
                <?php if ($d !== null): ?><dt class="col-5 text-muted">Declared roles</dt><dd class="col-7 mb-0"><?= e(implode(', ', $d['roles'])) ?></dd>
                    <dt class="col-5 text-muted">Tools and skills</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-grants"><?= (int) $d['tools'] ?> tool grants · <?= (int) $d['skills'] ?> skills</dd>
                    <?php if ($d['duty'] !== null): ?><dt class="col-5 text-muted">Duty</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-duty"><?= e($d['duty']['name']) ?> — <?= e($d['duty']['schedule']) ?></dd><?php endif; ?><?php endif; ?>
                <?php if ($m !== null): ?>
                <dt class="col-5 text-muted">Last activity here</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-last"><?= $a['last_activity'] === null ? '<span class="text-muted">none yet</span>' : e(format_ts($a['last_activity']['at'], $tz, 'M j, g:i A')) . ' · <code>' . e($a['last_activity']['action']) . '</code>' . e($source($a['last_activity']['source'])) ?></dd>
                <dt class="col-5 text-muted">Actions, last 7 days</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-count"><?= (int) $a['activity_7d'] ?></dd>
                <dt class="col-5 text-muted">Last dispatch</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-dispatch"><?php $x = $a['last_dispatch']; if ($x === null): ?><span class="text-muted">none yet</span><?php else: ?>
                    <?= e(format_ts($x['created_at'], $tz, 'M j, g:i A')) ?> · <?= e(str_replace('_', ' ', $x['kind'])) ?> <span class="badge bg-soft-<?= $status[$x['status']] ?? 'secondary' ?> text-<?= $status[$x['status']] ?? 'secondary' ?>"><?= e(str_replace('_', ' ', $x['status'])) ?></span><?= (int) $x['attempts'] > 1 ? ' <span class="text-muted">(' . (int) $x['attempts'] . ' attempts)</span>' : '' ?><?php endif; ?></dd>
                <?php if ($a['note'] !== null): ?><dt class="col-5 text-muted">Last morning note</dt><dd class="col-7 mb-0" id="agent-<?= e($key) ?>-note"><?= e($a['note']['title']) ?> <span class="text-muted">· for <?= e($a['note']['for_name']) ?>, <?= e(format_ts($a['note']['created_at'], $tz, 'M j, g:i A')) ?></span></dd><?php endif; ?>
                <?php endif; ?>
            </dl>
            <?php if ($m === null): ?><div class="fs-12 text-muted mt-2" id="agent-<?= e($key) ?>-unhired">The kernel hires it when this application is installed with its agents; until then nothing here is drafted or answered by it.</div><?php endif; ?>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
