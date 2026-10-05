<?php /** What is shared and what is read (screen `connections`). Data: c (connections_summary), os, tz */
$state = ['approved' => ['success', 'Connection approved — the kernel answered'], 'not_approved' => ['warning', 'Not approved yet — the kernel refused (no connection)'], 'unreachable' => ['danger', 'The kernel did not answer'], 'failed' => ['danger', 'The provider failed'], 'unknown' => ['secondary', 'Not read yet']]; ?>
<?= view('shared/header.php', ['id' => 'connections', 'title' => 'Connections', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Connections', null]]]) ?>
<div class="main-content" id="connections-content">
    <div class="alert alert-light border fs-12" id="connections-note">A connection between two applications is approved by a super-admin in the Business OS, never here — this page shows what the kernel last answered.
        <?= $os !== null ? 'Approve one at <a href="' . e($os) . '" id="connections-os-link" rel="noopener">' . e((string) parse_url($os, PHP_URL_HOST)) . '</a>.' : 'Approve one on the OS (os.&lt;your domain&gt;).' ?></div>
    <h5 class="mb-2">What we read</h5>
    <div class="row g-3 mb-4" id="connections-reads">
        <?php foreach ($c['reads'] as $r): [$cls, $words] = $state[$r['state']]; $id = $r['provider'] . '-' . $r['tool']; ?>
        <div class="col-12 col-lg-6"><div class="card h-100" id="read-<?= e($id) ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><code><?= e($r['provider'] . '.' . $r['tool']) ?></code></div><span class="badge bg-soft-<?= $cls ?> text-<?= $cls ?>" id="read-<?= e($id) ?>-state"><?= e($words) ?></span></div>
            <div class="fs-12 text-muted mt-1"><?= e($r['why']) ?></div>
            <div class="fs-12 mt-2" id="read-<?= e($id) ?>-last"><?php $l = $r['last']; if ($l === null): ?><span class="text-muted">The worker has not read it yet.</span><?php else: ?>
                Last read <?= e(format_ts($l['at'], $tz, 'M j, g:i A')) ?> · <?= $l['status'] === 200 ? (int) $l['rows'] . ' row(s)' : e($l['code'] !== null ? (string) $l['code'] : 'no answer') ?><?php endif; ?></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
    <h5 class="mb-2">What the others read of ours</h5>
    <div class="row g-3" id="connections-shares">
        <?php foreach ($c['shares'] as $s): ?>
        <div class="col-12 col-lg-6"><div class="card h-100" id="share-<?= e($s['tool']) ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><code><?= e($s['tool']) ?></code></div>
                <?= $s['people'] ? '<span class="badge bg-soft-info text-info">names people</span>' : '' ?></div>
            <div class="fs-12 text-muted mt-1"><?= e($s['description']) ?></div>
            <div class="fs-12 mt-2" id="share-<?= e($s['tool']) ?>-last"><?php $l = $s['last']; if ($l === null): ?><span class="text-muted">Not read by another application yet.</span><?php else: ?>
                Last read <?= e(format_ts($l['at'], $tz, 'M j, g:i A')) ?> · <?= (int) $l['rows'] ?> row(s) · <?= (int) $s['reads'] ?> read(s) in all<?php endif; ?></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
