<?php /** One usage charge (screen `usage-view`). Data: u, may, here, tz, timeline, notice */
$id = (int) $u['usage_charge_id']; $tone = USAGE_TONES[$u['status']]; $cur = $u['currency']; $pc = pricing_chip($u); ?>
<?= view('shared/header.php', ['id' => 'usage-view', 'title' => $u['number'], 'crumbs' => [['Home', '/'], ['AI usage and hosting', '/usage/'], [$u['number'], null]], 'back' => back_link() ?? ['/usage/', 'AI usage'],
    'action' => $may['edit'] ? hx_link(with_back('/usage/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch', 'id="usage-view-edit-btn"') : '']) ?>
<div class="main-content" id="usage-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3" id="usage-money"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="fs-20 fw-bold" id="usage-money-billable"><?= e(fmt_money($u['billable_amount'], $cur)) ?></div><div class="fs-12 text-muted" id="usage-money-cost">Cost <?= e(cost_cell($u)) ?><?= $u['unit_cost'] === null ? '' : ' (' . e(days_label($u['unit_cost'])) . ' a unit)' ?></div></div>
                    <div class="text-end"><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="usage-view-status"><?= e(USAGE_STATUSES[$u['status']]) ?></span>
                        <?php if ($u['billed']): ?> <span class="badge bg-dark" id="usage-view-billed">Billed</span><?php endif; ?>
                        <?php if ($pc !== null): ?> <span class="badge bg-soft-<?= $pc[1] ?> text-<?= $pc[1] ?>" id="usage-view-pricing"><?= e($pc[0]) ?></span><?php endif; ?></div>
                </div>
                <hr class="my-2">
                <div class="fs-12" id="usage-money-pricing"><?= e(USAGE_PRICINGS[$u['pricing']]) ?><?php if ($u['pricing'] === 'markup'): ?> — <?= e(days_label($u['markup_pct'])) ?> % on the cost<?php elseif ($u['pricing'] === 'resale'): ?> — <?= e(days_label($u['resale_unit_price'])) ?> <?= $u['unit'] === 'tokens' ? 'per million tokens' : 'a unit' ?><?php endif; ?><?= $u['billable'] ? '' : ' · not billable to the client' ?></div>
                <hr class="my-2">
                <div class="fs-12"><span class="text-muted">Engagement</span> <?= hx_link(with_back('/engagements/' . (int) $u['engagement_id'], $here), e($u['engagement_number']), '', 'id="usage-view-engagement"') ?> · <span class="text-muted">Kind</span> <?= e(USAGE_KINDS[$u['kind']]) ?><?= $u['provider_name'] ? ' · <span class="text-muted">Provider</span> ' . e($u['provider_name']) : '' ?></div>
                <div class="fs-12"><span class="text-muted">Period</span> <?= e(format_date($u['period_start'])) ?><?= $u['period_end'] !== $u['period_start'] ? ' → ' . e(format_date($u['period_end'])) : '' ?> · <span class="text-muted">Quantity</span> <?= e(fmt_qty($u['quantity'], $u['unit'])) ?></div>
                <div class="fs-12 mt-1" id="usage-view-description"><?= e($u['description']) ?></div>
                <div class="fs-12 text-muted mt-2" id="usage-view-dates"><?php if ($u['approved_at']): ?>Approved <?= e(format_ts($u['approved_at'], $tz)) ?>. <?php endif; ?><?php if ($u['voided_at']): ?>Voided <?= e(format_ts($u['voided_at'], $tz)) ?>. <?php endif; ?></div>
                <?php if ($u['status'] === 'void' && $u['void_reason']): ?><div class="alert alert-warning mt-2 mb-0" id="usage-view-void-reason"><strong>Void:</strong> <?= e($u['void_reason']) ?></div><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="usage-source"><div class="card-header"><h5 class="card-title mb-0">Where it came from</h5></div><div class="card-body fs-12">
                <?php if ($u['source'] === 'manual'): ?><div id="usage-source-manual">Recorded by hand.</div>
                <?php elseif ($u['source'] === 'csv_import'): ?><div id="usage-source-import">From the CSV import <?= $u['import'] === null ? '(since removed)' : hx_link(with_back('/usage/?import=' . (int) $u['import']['import_id'], $here), e($u['import']['file_name'])) ?>.</div>
                <?php elseif ($u['source'] === 'schedule'): ?><div id="usage-source-schedule">Drafted by the schedule <?= $u['schedule'] === null ? '(since removed)' : hx_link(with_back('/settings/schedules/' . (int) $u['schedule']['schedule_id'] . '/edit', $here), e($u['schedule']['description'])) ?>.</div>
                <?php else: ?>
                    <div id="usage-source-statement">From the OS statement of <?= $u['statement'] === null ? e((string) $u['source_ref']) : hx_link(with_back('/usage/os?period=' . rawurlencode($u['statement']['period']), $here), e($u['statement']['period'])) ?>, placed by the allocation rules:</div>
                    <div class="table-responsive mt-2"><table class="table table-sm mb-0 fs-12" id="usage-pieces"><thead><tr><th>Line</th><th>Rule</th><th class="text-end">Piece</th></tr></thead><tbody>
                        <?php foreach ($u['pieces'] as $p): ?><tr id="usage-piece-<?= (int) $p['allocation_id'] ?>"><td><?= e(implode(' · ', array_filter([$p['provider'], $p['model'], $p['agent'], $p['department'], $p['application']]))) ?></td>
                            <td><?= $p['rule_id'] === null ? 'a removed rule' : hx_link(with_back('/settings/allocation-rules/' . (int) $p['rule_id'] . '/edit', $here), e($p['rule_name']) . ' (' . e(days_label($p['share_pct'])) . ' %)') ?></td><td class="text-end text-nowrap"><?= e(fmt_money($p['amount'], $cur)) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </div></div>
            <?= view('shared/attachments.php', ['type' => 'usage_charge', 'id' => $id, 'attachments' => $u['documents'], 'may' => ['attach' => $may['attach'], 'delete' => $may['attach']], 'here' => $here, 'prefix' => 'usage']) ?>
            <?= view('shared/notes.php', ['type' => 'usage_charge', 'id' => $id, 'notes' => $u['notes'], 'may' => ['note' => true, 'admin' => $may['admin']], 'here' => $here, 'tz' => $tz, 'prefix' => 'usage', 'me' => $may['me']]) ?>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="usage-actions"><div class="card-body"><div class="d-grid gap-2">
                <?php if ($may['approve']): ?><form method="post" action="/usage/approve.php" hx-post="/usage/approve.php" hx-target="#flash" hx-confirm="Approve <?= e($u['number']) ?> (<?= e(fmt_money($u['billable_amount'], $cur)) ?>)?"><?= csrf_field() ?><input type="hidden" name="charge" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="usage-view-approve-btn">Approve</button></form><?php endif; ?>
                <?php if ($may['void']): ?><form method="post" action="/usage/void.php" hx-post="/usage/void.php" hx-target="#flash" id="usage-view-void-form"><?= csrf_field() ?><input type="hidden" name="charge" value="<?= $id ?>"><input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is void (kept on the record)" maxlength="500" required id="usage-view-void-reason-field"><button type="submit" class="btn btn-light btn-touch w-100" id="usage-view-void-btn">Void</button></form><?php endif; ?>
                <?php if ($may['delete']): ?><form method="post" action="/usage/delete.php" hx-post="/usage/delete.php" hx-target="#flash" hx-confirm="Remove <?= e($u['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="charge" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="usage-view-delete-btn">Remove the draft</button></form><?php endif; ?>
                <?php if (!$may['approve'] && !$may['void'] && !$may['delete']): ?><div class="fs-12 text-muted" id="usage-view-no-actions">Nothing to do here for now.</div><?php endif; ?>
            </div></div></div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'usage']) ?>
        </div>
    </div>
</div>
