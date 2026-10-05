<?php /** One week as its manager sees it (screen `timesheet-view`). Data: t, shape, entries, may, here, tz, timeline, me, notice */
$id = (int) $t['timesheet_id']; $tone = ['open' => 'secondary', 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'warning'][$t['status']]; $label = week_label($t['week_start']);
$billValue = array_sum(array_map(fn ($e) => (float) ($e['bill_value'] ?? (($e['will_apply']['bill_rate'] ?? null) !== null ? (float) $e['hours'] * (float) $e['will_apply']['bill_rate'] : 0)), array_filter($entries, fn ($e) => $e['billable'] && $e['status'] !== 'rejected' && $e['status'] !== 'written_off'))); ?>
<?= view('shared/header.php', ['id' => 'timesheet-view', 'title' => $t['member_name'] . ' · ' . $label, 'crumbs' => [['Home', '/'], ['Time approvals', '/time/approvals'], [$t['member_name'], null]], 'back' => back_link() ?? ['/time/approvals', 'Time approvals']]) ?>
<div class="main-content" id="timesheet-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3" id="week-header"><div class="card-body py-2 d-flex justify-content-between align-items-center">
                <div><span class="fw-bold"><?= e($label) ?></span> <span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="week-status"><?= e(WEEK_STATUSES[$t['status']]) ?></span></div>
                <div class="fs-12 text-muted" id="week-totals"><?= e(fmt_hours($t['total_hours'])) ?> · <?= e(days_label($t['billable_hours'])) ?> billable<?= $may['rates'] && $billValue > 0 ? ' · ' . e(fmt_money($billValue, $entries[0]['currency'] ?? ct_base_currency())) : '' ?></div>
            </div></div>
            <?php if ($t['status'] === 'rejected'): ?><div class="alert alert-warning" id="week-rejection"><strong>Sent back:</strong> <?= e((string) $t['rejection_reason']) ?></div><?php endif; ?>
            <?= view('time/partials/day-list.php', ['shape' => $shape, 'editable' => false, 'here' => $here, 'addBase' => '', 'showRates' => $may['rates'], 'showCost' => $may['cost'], 'manager' => true]) ?>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="week-actions"><div class="card-body">
                <?php if ($t['status'] === 'submitted'): ?><div class="fs-12 text-muted mb-2">Submitted <?= e(format_ts($t['submitted_at'], $tz)) ?>.<?php if ($others > 0): ?> <span id="week-others"><?= (int) $others ?> entr<?= (int) $others === 1 ? 'y' : 'ies' ?> on engagements you do not manage await their manager.</span><?php endif; ?></div><?php endif; ?>
                <?php if ($t['status'] === 'approved'): ?><div class="fs-12 text-muted mb-2">Approved <?= e(format_ts($t['approved_at'], $tz)) ?>.</div><?php endif; ?>
                <div class="d-grid gap-2">
                    <?php if ($may['approve']): ?><form method="post" action="/time/weeks/approve.php" hx-post="/time/weeks/approve.php" hx-target="#flash" hx-confirm="Approve what you manage in <?= e($t['member_name']) ?>'s <?= e(strtolower($label)) ?>? The rates are fixed on each entry."><?= csrf_field() ?><input type="hidden" name="timesheet" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="week-approve-btn">Approve</button></form><?php endif; ?>
                    <?php if ($may['reject']): ?><form method="post" action="/time/weeks/reject.php" hx-post="/time/weeks/reject.php" hx-target="#flash" id="week-reject-form"><?= csrf_field() ?><input type="hidden" name="timesheet" value="<?= $id ?>"><input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="What must change (the person reads it)" maxlength="1000" required id="week-reject-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="week-reject-btn">Send back</button></form><?php endif; ?>
                    <?php if ($may['reopen']): ?><form method="post" action="/time/weeks/reopen.php" hx-post="/time/weeks/reopen.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="timesheet" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e('/time/weeks/' . $id) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="week-reopen-btn">Reopen</button></form><?php endif; ?>
                    <?= hx_link(with_back('/time/?week=' . $t['week_start'] . ($may['own'] ? '' : '&member=' . (int) $t['member_id']), $here), $may['own'] ? 'Open my week' : 'Open as their week', 'btn btn-light btn-touch w-100', 'id="week-open-link"') ?>
                </div>
            </div></div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'week']) ?>
        </div>
    </div>
</div>
