<?php /** My settings (screen `settings`). Data: tab, prefs, refusal, osChannels, recent, notice, may (settings) */ $may = $may ?? ['settings' => false]; ?>
<?= view('shared/header.php', ['id' => 'settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/?tab=notify', 'How I am told', 'btn btn-touch ' . ($tab === 'notify' ? 'btn-primary' : 'btn-light'), 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
        <?php if ($may['settings']): ?><?= hx_link('/settings/general', 'The firm\'s settings', 'btn btn-touch btn-light', 'id="settings-tab-general"') ?><?php endif; ?>
    </div>
    <?= view('settings/partials/prefs.php', ['prefs' => $prefs, 'refusal' => $refusal, 'osChannels' => $osChannels]) ?>
    <?php if (($recent ?? []) !== []): ?>
    <div class="card mt-3" id="prefs-recent"><div class="card-header"><h5 class="card-title mb-0">Sent to you lately</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>What</th><th>By</th><th>Outcome</th></tr></thead><tbody>
        <?php foreach ($recent as $r): ?><tr id="prefs-recent-<?= (int) $r['id'] ?>"><td class="text-nowrap text-muted"><?= e(format_ts($r['created_at'], member_timezone(), 'M j, g:i A')) ?></td><td><?= e($r['subject'] ?? $r['kind']) ?></td><td><?= e($r['channel']) ?></td><td><span class="badge bg-soft-<?= $r['status'] === 'sent' ? 'success text-success' : ($r['status'] === 'queued' ? 'secondary text-secondary' : 'warning text-warning') ?>"><?= e($r['status']) ?><?= $r['status'] === 'skipped' && $r['detail'] ? ' · ' . e($r['detail']) : '' ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php endif; ?>
</div>
