<?php /** One engagement with tabs (screen `engagement-view`). Data: e, tab, attachments, notes, timeline, may, here, me, notice */
$id = $e['engagement_id']; $tz = member_timezone(); $base = '/engagements/' . $id;
$statusTone = ['draft' => 'secondary', 'active' => 'success', 'on_hold' => 'warning', 'closed' => 'dark', 'cancelled' => 'danger'][$e['status']] ?? 'secondary';
$tabs = ['overview' => 'Overview', 'staffing' => 'Staffing', 'rates' => 'Rates', 'milestones' => 'Milestones', 'time' => 'Time', 'expenses' => 'Expenses', 'usage' => 'Usage', 'invoices' => 'Invoices', 'timeline' => 'Timeline'];
if (!$may['rates']) { unset($tabs['rates']); }
$tabUrl = static fn (string $t): string => $t === 'overview' ? $base : (in_array($t, ['staffing', 'rates', 'milestones'], true) ? $base . '/' . $t : $base . '?tab=' . $t);
$moveForm = static function (string $verb, string $label, string $cls = 'btn-light', bool $reason = false, string $confirm = '') use ($id): string {
    return '<form method="post" action="/engagements/' . $verb . '.php" hx-post="/engagements/' . $verb . '.php" hx-target="#flash" class="d-inline-block" id="engagement-view-' . $verb . '-form"' . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="engagement" value="' . $id . '">'
        . ($reason ? '<input type="text" name="reason" class="form-control form-control-sm d-inline-block w-auto me-1" placeholder="Reason" maxlength="500" id="engagement-view-' . $verb . '-reason">' : '') . '<button type="submit" class="btn ' . $cls . ' btn-touch" id="engagement-view-' . $verb . '-btn">' . e($label) . '</button></form>';
}; ?>
<?= view('shared/header.php', ['id' => 'engagement-view', 'title' => $e['number'] . ' · ' . $e['name'], 'crumbs' => [['Home', '/'], ['Engagements', '/engagements/'], [$e['number'], null]], 'back' => back_link() ?? ['/engagements/', 'Engagements'],
    'action' => $may['manage'] && !in_array($e['status'], ['closed', 'cancelled'], true) ? hx_link(with_back($base . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="engagement-view-edit-btn"') : '']) ?>
<div class="main-content" id="engagement-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="engagement-tabs">
        <?php foreach ($tabs as $t => $label): ?><?= hx_link($tabUrl($t), e($label), 'btn btn-touch ' . ($tab === $t ? 'btn-primary' : 'btn-light'), 'id="engagement-tab-' . $t . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'overview'): ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="engagement-view-facts"><div class="card-body">
                <div class="mb-2"><span class="badge bg-soft-<?= $statusTone ?> text-<?= $statusTone ?>" id="engagement-view-status"><?= e(ENGAGEMENT_STATUSES[$e['status']]) ?></span> <span class="badge bg-soft-<?= $e['kind'] === 'fixed_bid' ? 'primary text-primary' : 'info text-info' ?>" id="engagement-view-kind"><?= $e['kind'] === 'fixed_bid' ? 'Fixed bid' : 'Time and materials' ?></span><?= $e['po_number'] ? ' <span class="badge bg-soft-secondary text-secondary">PO ' . e($e['po_number']) . '</span>' : '' ?></div>
                <dl class="row mb-0 fs-12">
                    <dt class="col-5 text-muted">Client</dt><dd class="col-7" id="engagement-view-client"><?= hx_link(with_back('/clients/' . $e['client_id'], $here), e($e['client_name'])) ?></dd>
                    <dt class="col-5 text-muted">Manager</dt><dd class="col-7" id="engagement-view-manager"><?= e($e['manager_name'] ?? 'Nobody yet') ?></dd>
                    <dt class="col-5 text-muted">Practice</dt><dd class="col-7"><?= e($e['department_name'] ?? 'None') ?></dd>
                    <dt class="col-5 text-muted">Dates</dt><dd class="col-7"><?= e(format_date($e['start_date'])) ?><?= $e['end_date'] ? ' → ' . e(format_date($e['end_date'])) : ' → open' ?></dd>
                    <dt class="col-5 text-muted">Currency</dt><dd class="col-7"><?= e($e['currency']) ?></dd>
                    <dt class="col-5 text-muted">Billing</dt><dd class="col-7" id="engagement-view-billing"><?= e(BILLING_CADENCES[$e['billing_cadence']] ?? $e['billing_cadence']) ?><?= $e['next_billing_date'] ? ' · next ' . e(format_date($e['next_billing_date'])) : '' ?></dd>
                    <?php if ($may['rates']): ?><dt class="col-5 text-muted">Rate card</dt><dd class="col-7" id="engagement-view-card"><?= $e['role_rates']['card_name'] === null ? 'None' : hx_link(with_back('/settings/rate-cards/' . (int) $e['role_rates']['card_id'], $here), e($e['role_rates']['card_name'])) . ($e['rate_card_id'] === null ? ' <span class="text-muted">(the client\'s default)</span>' : '') ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Expenses</dt><dd class="col-7"><?= isset($e['expense_policy']['markup_pct']) ? e(days_label($e['expense_policy']['markup_pct'])) . '% markup' : 'The settings\' markup' ?><?= isset($e['expense_policy']['requires_receipt_above']) ? ' · receipt above ' . e(fmt_money($e['expense_policy']['requires_receipt_above'], $e['currency'], false)) : '' ?></dd>
                    <?php if ($e['projects_ref']): ?><dt class="col-5 text-muted">Projects</dt><dd class="col-7"><?= e($e['projects_ref']) ?></dd><?php endif; ?>
                    <?php if ($e['description']): ?><dt class="col-5 text-muted">Scope</dt><dd class="col-7" id="engagement-view-description"><?= nl2br(e($e['description'])) ?></dd><?php endif; ?>
                </dl>
                <?php if ($may['manage']): ?>
                <div class="d-flex flex-wrap gap-2 mt-3" id="engagement-view-moves">
                    <?php if ($e['status'] === 'draft'): ?><?= $moveForm('activate', 'Activate', 'btn-primary') ?><?= $moveForm('cancel', 'Cancel it', 'btn-light text-danger', true, 'Cancel ' . $e['number'] . '? It stays so.') ?><?= $moveForm('delete', 'Delete the draft', 'btn-light text-danger', false, 'Delete the draft ' . $e['number'] . '?') ?><?php endif; ?>
                    <?php if ($e['status'] === 'active'): ?><?= $moveForm('hold', 'Put on hold', 'btn-light', true) ?><?= $moveForm('close', 'Close', 'btn-light', true, 'Close ' . $e['number'] . '? Time can no longer be logged; it can be reopened.') ?><?= $moveForm('cancel', 'Cancel it', 'btn-light text-danger', true, 'Cancel ' . $e['number'] . '? It stays so.') ?><?php endif; ?>
                    <?php if ($e['status'] === 'on_hold'): ?><?= $moveForm('activate', 'Resume', 'btn-primary') ?><?= $moveForm('close', 'Close', 'btn-light', true, 'Close ' . $e['number'] . '?') ?><?php endif; ?>
                    <?php if ($e['status'] === 'closed'): ?><?= $moveForm('reopen', 'Reopen', 'btn-light', true) ?><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($may['log']): ?><div class="mt-3"><?= hx_link(with_back('/time/entries/new?engagement=' . $id, $here), '<i class="feather-clock me-1"></i>Log time', 'btn btn-light btn-touch w-100', 'id="engagement-view-log-btn"') ?></div><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="engagement-view-budgets"><div class="card-header"><h5 class="card-title mb-0">Budgets</h5><?= $may['manage'] ? '<form method="post" action="/usage/budgets/check.php" hx-post="/usage/budgets/check.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="engagement" value="' . $id . '"><button type="submit" class="btn btn-link btn-sm p-0 fs-12" id="engagement-view-check-btn">Check now</button></form>' : '' ?></div>
                <div class="card-body" id="budget-bars"><?= view('engagements/partials/budget-bars.php', ['e' => $e, 'may' => $may]) ?></div>
            </div>
            <?php if ($e['alerts'] !== []): ?>
            <div class="card mb-3" id="engagement-view-alerts"><div class="card-header"><h5 class="card-title mb-0">Open alerts</h5></div><div class="list-group list-group-flush">
                <?php foreach ($e['alerts'] as $a): ?><div class="list-group-item d-flex justify-content-between align-items-center fs-12" id="alert-<?= $a['alert_id'] ?>"><span><span class="badge bg-soft-warning text-warning"><?= e($a['kind']) ?> at <?= (int) $a['threshold_pct'] ?>%</span> <?= $a['kind'] === 'ai' && !$may['ai'] ? '' : e(days_label($a['value_at'])) . ' of ' . e(days_label($a['budget_at'])) ?> <span class="text-muted">· <?= e(format_ts($a['written_at'], $tz, 'M j')) ?></span></span>
                    <?php if ($may['manage']): ?><form method="post" action="/usage/budgets/acknowledge.php" hx-post="/usage/budgets/acknowledge.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="alert" value="<?= $a['alert_id'] ?>"><input type="hidden" name="return_to" value="<?= e($base) ?>"><button type="submit" class="btn btn-sm btn-light" id="alert-<?= $a['alert_id'] ?>-ack-btn">Acknowledge</button></form><?php endif; ?></div><?php endforeach; ?>
            </div></div>
            <?php endif; ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="engagement-view-staffing"><div class="card-header"><h5 class="card-title mb-0">Staffed</h5><?= hx_link($base . '/staffing', 'All', 'fs-12', 'id="engagement-view-staffing-all"') ?></div>
                <?php $current = array_values(array_filter($e['staffing'], fn ($s) => $s['is_current'])); if ($current === []): ?><div class="card-body text-muted" id="engagement-view-staffing-empty">Nobody yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($current as $s): ?><div class="list-group-item d-flex justify-content-between fs-12" id="engagement-view-staffed-<?= $s['staffing_id'] ?>"><span><?= e($s['member_name']) ?><?= $s['member_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?> <span class="text-muted">· <?= e($s['role_title']) ?></span></span><span class="text-muted">since <?= e(format_date($s['valid_from'])) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <div class="card mb-3" id="engagement-view-milestones"><div class="card-header"><h5 class="card-title mb-0">Milestones</h5><?= hx_link($base . '/milestones', 'All', 'fs-12', 'id="engagement-view-milestones-all"') ?></div>
                <?php if ($e['milestones'] === []): ?><div class="card-body text-muted" id="engagement-view-milestones-empty">None yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($e['milestones'] as $m): $mt = ['planned' => 'secondary', 'delivered' => 'info', 'accepted' => 'success', 'invoiced' => 'dark', 'cancelled' => 'danger'][$m['status']]; ?><div class="list-group-item d-flex justify-content-between fs-12" id="engagement-view-milestone-<?= $m['milestone_id'] ?>"><span><?= e($m['name']) ?><?= $m['due_date'] ? ' <span class="text-muted">· due ' . e(format_date($m['due_date'])) . '</span>' : '' ?></span><span><?= (float) $m['amount'] > 0 ? e(fmt_money($m['amount'], $e['currency'])) . ' ' : '' ?><span class="badge bg-soft-<?= $mt ?> text-<?= $mt ?>"><?= e(MILESTONE_STATUSES[$m['status']]) ?></span></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?= view('shared/attachments.php', ['type' => 'engagement', 'id' => $id, 'attachments' => $attachments, 'may' => ['attach' => $may['attach'], 'delete' => $may['manage']], 'here' => $here, 'prefix' => 'engagement']) ?>
            <?= view('shared/notes.php', ['type' => 'engagement', 'id' => $id, 'notes' => $notes, 'may' => ['note' => true, 'admin' => $may['admin']], 'here' => $here, 'tz' => $tz, 'prefix' => 'engagement', 'me' => $me]) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'engagement']) ?>
        </div>
    </div>
    <?php elseif ($tab === 'staffing'): ?><?= view('engagements/staffing.php', ['e' => $e, 'may' => $may, 'here' => $here, 'editing' => request_integer('staffing')]) ?>
    <?php elseif ($tab === 'rates'): ?><?= view('engagements/rates.php', ['e' => $e, 'may' => $may, 'here' => $here]) ?>
    <?php elseif ($tab === 'milestones'): ?><?= view('engagements/milestones.php', ['e' => $e, 'may' => $may, 'here' => $here, 'editing' => request_integer('milestone'), 'accepting' => request_integer('accept'), 'tz' => $tz]) ?>
    <?php elseif ($tab === 'timeline'): ?><?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'engagement']) ?>
    <?php else: ?>
    <div class="card" id="engagement-tab-<?= e($tab) ?>-coming"><div class="card-body text-muted"><?= ['time' => 'The hours logged on this engagement, by week and person, appear here (slice 3).', 'expenses' => 'The expenses charged to this engagement appear here (slice 4).', 'usage' => 'The hosting and model-usage charges on this engagement appear here (slice 5).', 'invoices' => 'The invoices of this engagement, with what is unbilled, appear here (slice 6).'][$tab] ?></div></div>
    <?php endif; ?>
</div>
