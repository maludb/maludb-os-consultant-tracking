<?php /** People as cards, each with its dated capacity ranges (screen `capacity-list`). Data: rows, editing, members, default, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'capacity-list', 'title' => 'Capacity', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Capacity', null]]]) ?>
<div class="main-content" id="capacity-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="fs-12 text-muted mb-2" id="capacity-list-default">Where no range is set, a person's capacity is the settings' default: <?= e(days_label($default)) ?> hours a week.</div>
            <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="capacity-list-empty">No range yet — everyone is at the default.</div></div><?php endif; ?>
            <div class="row g-3" id="capacity-list-cards">
                <?php foreach ($rows as $p): $m = $p['member_id']; ?>
                    <div class="col-12 col-md-6"><div class="card h-100" id="capacity-<?= $m ?>"><div class="card-body p-3">
                        <div class="fw-bold" id="capacity-<?= $m ?>-name"><?= e($p['member_name']) ?></div>
                        <table class="table table-sm mb-0 fs-12 mt-2"><tbody>
                            <?php foreach ($p['ranges'] as $x): ?>
                                <tr id="capacity-range-<?= $x['capacity_id'] ?>"><td><?= e(format_date($x['valid_from'])) ?> → <?= $x['valid_to'] === null ? '<span class="text-success">open</span>' : e(format_date($x['valid_to'])) ?></td>
                                    <td class="text-end text-nowrap fw-semibold" id="capacity-range-<?= $x['capacity_id'] ?>-hours"><?= e(days_label($x['hours_per_week'])) ?> h/wk</td>
                                    <td class="text-end"><?= hx_link('/settings/capacity/?edit=' . $x['capacity_id'] . '#capacity-form', 'Change', 'btn btn-sm btn-light', 'id="capacity-range-' . $x['capacity_id'] . '-edit-btn"') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div></div></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card" id="capacity-form-card"><div class="card-header"><h5 class="card-title mb-0"><?= $editing === null ? 'Add a range' : 'Change a range' ?></h5></div><div class="card-body">
                <form method="post" action="/settings/capacity/save.php" hx-post="/settings/capacity/save.php" hx-target="#flash" id="capacity-form">
                    <?= csrf_field() ?><?php if ($editing !== null): ?><input type="hidden" name="capacity" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <label class="form-label fs-12 text-muted" for="capacity-form-field-member">Person</label>
                    <select name="member" id="capacity-form-field-member" class="form-select btn-touch mb-3" required <?= $editing !== null ? 'disabled' : '' ?>><option value="">Choose</option><?php foreach ($members as $m): if ($m['member_kind'] !== 'human') { continue; } ?><option value="<?= (int) $m['member_id'] ?>" <?= ($editing['member_id'] ?? null) === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select>
                    <label class="form-label fs-12 text-muted" for="capacity-form-field-hours">Hours a week</label>
                    <input type="number" name="hours_per_week" id="capacity-form-field-hours" class="form-control btn-touch mb-3" min="0" max="168" step="0.5" required value="<?= e($editing === null ? days_label($default) : days_label($editing['hours_per_week'])) ?>">
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="capacity-form-field-from">From</label><input type="date" name="valid_from" id="capacity-form-field-from" class="form-control btn-touch" required value="<?= e($editing['valid_from'] ?? date('Y-m-d')) ?>"></div>
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="capacity-form-field-to">To (blank: open)</label><input type="date" name="valid_to" id="capacity-form-field-to" class="form-control btn-touch" value="<?= e($editing['valid_to'] ?? '') ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="capacity-form-save-btn"><?= $editing === null ? 'Add' : 'Save' ?></button>
                    <?php if ($editing !== null): ?><?= hx_link('/settings/capacity/', 'Cancel', 'btn btn-light btn-touch w-100 mt-2', 'id="capacity-form-cancel"') ?><?php endif; ?>
                </form>
            </div></div>
        </div>
    </div>
</div>
