<?php /** One rate card: the rate per consulting role editable in place (screen `rate-card-view`). Data: r, may, here, notice */ $id = $r['rate_card_id']; $live = $r['archived_at'] === null; ?>
<?= view('shared/header.php', ['id' => 'rate-card-view', 'title' => $r['name'], 'crumbs' => [['Home', '/'], ['Rate cards', '/settings/rate-cards/'], [$r['name'], null]], 'back' => back_link() ?? ['/settings/rate-cards/', 'Rate cards'],
    'action' => $may['manage'] && $live ? hx_link(with_back('/settings/rate-cards/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="rate-card-view-edit-btn"') : '']) ?>
<div class="main-content" id="rate-card-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3" id="rate-card-view-rates"><div class="card-header"><h5 class="card-title mb-0">Rates per consulting role (<?= e($r['currency']) ?> an hour)</h5></div>
                <div class="table-responsive"><table class="table mb-0" id="rate-card-view-table"><thead><tr><th>Role</th><th class="text-end">Rate</th><?php if ($may['manage'] && $live): ?><th></th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($r['rates'] as $x): $rid = $x['consulting_role_id']; ?>
                        <tr id="rate-row-<?= $rid ?>">
                            <td class="fw-semibold"><?= e($x['role_title']) ?></td>
                            <?php if ($may['manage'] && $live): ?>
                            <td colspan="2"><form method="post" action="/settings/rate-cards/rate.php" hx-post="/settings/rate-cards/rate.php" hx-target="#flash" class="input-group input-group-sm justify-content-end" id="rate-row-<?= $rid ?>-form" style="max-width: 16rem; margin-left: auto">
                                <?= csrf_field() ?><input type="hidden" name="rate_card" value="<?= $id ?>"><input type="hidden" name="role" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= e('/settings/rate-cards/' . $id) ?>">
                                <input type="text" name="bill_rate" class="form-control text-end" inputmode="decimal" value="<?= e($x['bill_rate'] === null ? '' : number_format((float) $x['bill_rate'], 2, '.', '')) ?>" placeholder="not priced" id="rate-row-<?= $rid ?>-field" aria-label="Rate for <?= e($x['role_title']) ?>">
                                <button type="submit" class="btn btn-light" id="rate-row-<?= $rid ?>-save-btn">Save</button></form></td>
                            <?php else: ?><td class="text-end" id="rate-row-<?= $rid ?>-rate"><?= $x['bill_rate'] === null ? '<span class="text-muted">not priced</span>' : e(fmt_money($x['bill_rate'], $r['currency'])) ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="rate-card-view-facts"><div class="card-body">
                <?php if (!$live): ?><span class="badge bg-soft-secondary text-secondary mb-2" id="rate-card-view-archived">Archived</span><?php endif; ?>
                <dl class="row mb-0 fs-12">
                    <dt class="col-5 text-muted">Currency</dt><dd class="col-7" id="rate-card-view-currency"><?= e($r['currency']) ?><?= $r['engagement_count'] > 0 ? ' <span class="text-muted">(fixed: in use)</span>' : '' ?></dd>
                    <dt class="col-5 text-muted">Effective from</dt><dd class="col-7"><?= e(format_date($r['effective_from'])) ?></dd>
                    <?php if ($r['description']): ?><dt class="col-5 text-muted">Description</dt><dd class="col-7"><?= nl2br(e($r['description'])) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Used by</dt><dd class="col-7" id="rate-card-view-use"><?= $r['engagement_count'] ?> engagement<?= $r['engagement_count'] === 1 ? '' : 's' ?>, <?= $r['client_count'] ?> client<?= $r['client_count'] === 1 ? '' : 's' ?></dd>
                </dl>
                <?php if ($r['engagements'] !== []): ?><div class="mt-2 fs-12" id="rate-card-view-engagements"><?php foreach ($r['engagements'] as $e): ?><div><?= hx_link(with_back('/engagements/' . (int) $e['engagement_id'], $here), e($e['number'] . ' · ' . $e['name'])) ?> <span class="text-muted"><?= e($e['client_name']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($r['clients'] !== []): ?><div class="mt-2 fs-12" id="rate-card-view-clients">Default for <?php foreach ($r['clients'] as $i => $c): ?><?= $i ? ', ' : '' ?><?= hx_link(with_back('/clients/' . (int) $c['client_id'], $here), e($c['name'])) ?><?php endforeach; ?></div><?php endif; ?>
                <?php if ($may['manage']): ?>
                <div class="d-grid gap-2 mt-3">
                    <?= hx_link(with_back('/settings/rate-cards/new?copy_of=' . $id, $here), 'Copy to a new card', 'btn btn-light btn-touch', 'id="rate-card-view-copy-btn"') ?>
                    <form method="post" action="/settings/rate-cards/archive.php" hx-post="/settings/rate-cards/archive.php" hx-target="#flash" <?= $live ? 'hx-confirm="Archive ' . e($r['name']) . '? Engagements using it keep it; new ones cannot pick it."' : '' ?>><?= csrf_field() ?><input type="hidden" name="rate_card" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $live ? 'yes' : 'no' ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="rate-card-view-archive-btn"><?= $live ? 'Archive' : 'Restore' ?></button></form>
                    <?php if ($may['delete'] && $r['engagement_count'] === 0 && $r['client_count'] === 0): ?><form method="post" action="/settings/rate-cards/delete.php" hx-post="/settings/rate-cards/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($r['name']) ?>? Nothing uses it."><?= csrf_field() ?><input type="hidden" name="rate_card" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="rate-card-view-delete-btn">Delete</button></form><?php endif; ?>
                </div>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
