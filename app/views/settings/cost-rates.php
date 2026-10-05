<?php /** People as cards, each with its dated cost-rate ranges (screen `cost-rate-list`). Data: rows, may (write), editing, members, currency, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'cost-rate-list', 'title' => 'Cost rates', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Cost rates', null]]]) ?>
<div class="main-content" id="cost-rate-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="alert alert-warning fs-12" id="cost-rate-list-guard"><i class="feather-lock me-1"></i>What a person costs an hour, in <?= e($currency) ?>. The admin's table: Finance reads it; a manager sees only the margin on their own engagements; the activity log never carries a rate.</div>
    <div class="row g-3">
        <div class="col-lg-7">
            <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="cost-rate-list-empty">No cost rate yet. Without one, a person's margin cannot be worked out.</div></div><?php endif; ?>
            <div class="row g-3" id="cost-rate-list-cards">
                <?php foreach ($rows as $p): $m = $p['member_id']; ?>
                    <div class="col-12 col-md-6"><div class="card h-100" id="cost-rate-<?= $m ?>"><div class="card-body p-3">
                        <div class="fw-bold" id="cost-rate-<?= $m ?>-name"><?= e($p['member_name']) ?></div>
                        <table class="table table-sm mb-0 fs-12 mt-2"><tbody>
                            <?php foreach ($p['ranges'] as $x): ?>
                                <tr id="cost-rate-range-<?= $x['cost_rate_id'] ?>"><td><?= e(format_date($x['valid_from'])) ?> → <?= $x['valid_to'] === null ? '<span class="text-success">open</span>' : e(format_date($x['valid_to'])) ?><?= $x['note'] ? ' <span class="text-muted">· ' . e($x['note']) . '</span>' : '' ?></td>
                                    <td class="text-end text-nowrap fw-semibold" id="cost-rate-range-<?= $x['cost_rate_id'] ?>-cost"><?= e(fmt_money($x['hourly_cost'], $currency)) ?>/h</td>
                                    <?php if ($may['write']): ?><td class="text-end text-nowrap"><?= hx_link('/settings/cost-rates/?edit=' . $x['cost_rate_id'] . '#cost-rate-form', 'Change', 'btn btn-sm btn-light', 'id="cost-rate-range-' . $x['cost_rate_id'] . '-edit-btn"') ?>
                                        <?php if ($x['valid_to'] === null): ?><form method="post" action="/settings/cost-rates/end.php" hx-post="/settings/cost-rates/end.php" hx-target="#flash" class="d-inline" hx-confirm="End this range today?"><?= csrf_field() ?><input type="hidden" name="cost_rate" value="<?= $x['cost_rate_id'] ?>"><input type="hidden" name="valid_to" value="<?= date('Y-m-d') ?>"><button type="submit" class="btn btn-sm btn-light" id="cost-rate-range-<?= $x['cost_rate_id'] ?>-end-btn">End today</button></form><?php endif; ?></td><?php endif; ?></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div></div></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($may['write']): ?>
        <div class="col-lg-5">
            <div class="card" id="cost-rate-form-card"><div class="card-header"><h5 class="card-title mb-0"><?= $editing === null ? 'Add a range' : 'Change a range' ?></h5></div><div class="card-body">
                <form method="post" action="/settings/cost-rates/save.php" hx-post="/settings/cost-rates/save.php" hx-target="#flash" id="cost-rate-form">
                    <?= csrf_field() ?><?php if ($editing !== null): ?><input type="hidden" name="cost_rate" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <label class="form-label fs-12 text-muted" for="cost-rate-form-field-member">Person</label>
                    <select name="member" id="cost-rate-form-field-member" class="form-select btn-touch mb-3" required <?= $editing !== null ? 'disabled' : '' ?>><option value="">Choose</option><?php foreach ($members as $m): if ($m['member_kind'] !== 'human') { continue; } ?><option value="<?= (int) $m['member_id'] ?>" <?= ($editing['member_id'] ?? null) === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select>
                    <label class="form-label fs-12 text-muted" for="cost-rate-form-field-cost">Hourly cost (<?= e($currency) ?>)</label>
                    <input type="text" name="hourly_cost" id="cost-rate-form-field-cost" class="form-control btn-touch mb-3" inputmode="decimal" required value="<?= e($editing === null ? '' : number_format((float) $editing['hourly_cost'], 2, '.', '')) ?>">
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="cost-rate-form-field-from">From</label><input type="date" name="valid_from" id="cost-rate-form-field-from" class="form-control btn-touch" required value="<?= e($editing['valid_from'] ?? date('Y-m-d')) ?>"></div>
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="cost-rate-form-field-to">To (blank: open)</label><input type="date" name="valid_to" id="cost-rate-form-field-to" class="form-control btn-touch" value="<?= e($editing['valid_to'] ?? '') ?>"></div>
                    </div>
                    <label class="form-label fs-12 text-muted" for="cost-rate-form-field-note">Note</label>
                    <input type="text" name="note" id="cost-rate-form-field-note" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($editing['note'] ?? '') ?>" placeholder="Promotion">
                    <div class="fs-12 text-muted mb-3">A new range that starts inside the person's open range ends that range the day before.</div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="cost-rate-form-save-btn"><?= $editing === null ? 'Add' : 'Save' ?></button>
                    <?php if ($editing !== null): ?><?= hx_link('/settings/cost-rates/', 'Cancel', 'btn btn-light btn-touch w-100 mt-2', 'id="cost-rate-form-cancel"') ?><?php endif; ?>
                </form>
            </div></div>
        </div>
        <?php endif; ?>
    </div>
</div>
