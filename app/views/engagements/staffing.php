<?php /** The staffing tab (screen `engagement-staffing`): a card list; add or change a staffing; end one. Data: e, may (staff, rates), here, editing */
$id = $e['engagement_id']; $cur = null;
foreach ($e['staffing'] as $s) { if ($editing !== null && $s['staffing_id'] === $editing) { $cur = $s; } }
$roles = vocabulary_pick_roles(); ?>
<div class="row g-3" id="engagement-staffing-content">
    <div class="col-lg-7">
        <?php if ($e['staffing'] === []): ?><div class="card"><div class="card-body text-muted" id="staffing-empty">Nobody is staffed yet.</div></div><?php endif; ?>
        <div class="list-group" id="staffing-list">
            <?php foreach ($e['staffing'] as $s): $sid = $s['staffing_id']; ?>
                <div class="list-group-item <?= $s['is_current'] ? '' : 'text-muted' ?>" id="staffing-row-<?= $sid ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0"><span class="fw-semibold" id="staffing-row-<?= $sid ?>-name"><?= e($s['member_name']) ?></span><?= $s['member_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?> <span class="text-muted">· <?= e($s['role_title']) ?></span>
                            <div class="fs-12 text-muted"><?= e(format_date($s['valid_from'])) ?> → <?= $s['valid_to'] === null ? 'open' : e(format_date($s['valid_to'])) ?><?= $may['rates'] && $s['bill_rate_override'] !== null ? ' · override ' . e(fmt_money($s['bill_rate_override'], $e['currency'])) . '/h' : '' ?><?= $s['note'] ? ' · ' . e($s['note']) : '' ?><?= $s['is_current'] ? '' : ' · <span class="badge bg-soft-secondary text-secondary">ended</span>' ?></div></div>
                        <?php if ($may['staff'] && $s['is_current']): ?><div class="d-flex gap-1 flex-shrink-0"><?= hx_link('/engagements/' . $id . '/staffing?staffing=' . $sid . '#staffing-form', 'Change', 'btn btn-sm btn-light', 'id="staffing-row-' . $sid . '-edit-btn"') ?>
                            <form method="post" action="/engagements/staffing/end.php" hx-post="/engagements/staffing/end.php" hx-target="#flash" class="d-inline" hx-confirm="End <?= e($s['member_name']) ?>'s staffing today? Their entries stay; new time from today is refused."><?= csrf_field() ?><input type="hidden" name="staffing" value="<?= $sid ?>"><button type="submit" class="btn btn-sm btn-light" id="staffing-row-<?= $sid ?>-end-btn">End today</button></form></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($may['staff']): ?>
    <div class="col-lg-5">
        <div class="card" id="staffing-form-card"><div class="card-header"><h5 class="card-title mb-0"><?= $cur === null ? 'Staff someone' : 'Change ' . e($cur['member_name']) . '\'s staffing' ?></h5></div><div class="card-body">
            <form method="post" action="/engagements/staffing/save.php" hx-post="/engagements/staffing/save.php" hx-target="#flash" id="staffing-form">
                <?= csrf_field() ?><input type="hidden" name="engagement" value="<?= $id ?>"><?php if ($cur !== null): ?><input type="hidden" name="staffing" value="<?= (int) $cur['staffing_id'] ?>"><?php endif; ?>
                <label class="form-label fs-12 text-muted" id="staffing-form-field-member-label" for="staffing-form-field-member-open">Person or agent</label>
                <?= picker_field(['id' => 'staffing-form-field-member', 'labelledby' => 'staffing-form-field-member-label', 'name' => 'member', 'source' => 'member', 'value' => $cur['member_id'] ?? null, 'required' => true, 'placeholder' => 'Choose', 'disabled' => $cur !== null, 'extra' => $cur !== null ? ' disabled' : '']) ?>
                <label class="form-label fs-12 text-muted" for="staffing-form-field-role">Consulting role (prices the hours)</label>
                <select name="role" id="staffing-form-field-role" class="form-select btn-touch mb-3" required><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= ($cur['consulting_role_id'] ?? null) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="staffing-form-field-from">From</label><input type="date" name="valid_from" id="staffing-form-field-from" class="form-control btn-touch" value="<?= e($cur['valid_from'] ?? date('Y-m-d')) ?>"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="staffing-form-field-to">To (blank: open)</label><input type="date" name="valid_to" id="staffing-form-field-to" class="form-control btn-touch" value="<?= e($cur['valid_to'] ?? '') ?>"></div>
                </div>
                <?php if ($may['rates']): ?><label class="form-label fs-12 text-muted" for="staffing-form-field-override">Rate override, per hour (blank: the role's rate)</label>
                <input type="text" name="bill_rate_override" id="staffing-form-field-override" class="form-control btn-touch mb-3" inputmode="decimal" value="<?= e($cur === null || $cur['bill_rate_override'] === null ? '' : number_format((float) $cur['bill_rate_override'], 2, '.', '')) ?>"><?php endif; ?>
                <label class="form-label fs-12 text-muted" for="staffing-form-field-note">Note</label>
                <input type="text" name="note" id="staffing-form-field-note" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($cur['note'] ?? '') ?>">
                <button type="submit" class="btn btn-primary btn-touch w-100" id="staffing-form-save-btn"><?= $cur === null ? 'Staff' : 'Save' ?></button>
                <?php if ($cur !== null): ?><?= hx_link('/engagements/' . $id . '/staffing', 'Cancel', 'btn btn-light btn-touch w-100 mt-2', 'id="staffing-form-cancel"') ?><?php endif; ?>
            </form>
        </div></div>
    </div>
    <?php endif; ?>
</div>
