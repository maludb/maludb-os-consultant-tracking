<?php /** The firm's settings (screen `settings-general`). Data: s, members, notice */ ?>
<?= view('shared/header.php', ['id' => 'settings-general', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Settings', null]],
    'action' => hx_link('/settings/numbering', '<i class="feather-hash me-1"></i>Numbering', 'btn btn-light btn-touch', 'id="settings-general-numbering-link"')]) ?>
<div class="main-content" id="settings-general-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/settings/general.php" hx-post="/settings/general.php" hx-target="#flash" id="settings-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The firm (on the invoice)</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="settings-form-field-name">Name</label>
            <input type="text" name="firm_name" id="settings-form-field-name" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($s['firm_name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="settings-form-field-address">Address</label>
            <textarea name="firm_address" id="settings-form-field-address" class="form-control mb-3" rows="2" maxlength="500"><?= e($s['firm_address'] ?? '') ?></textarea>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-taxid">Tax id</label><input type="text" name="firm_tax_id" id="settings-form-field-taxid" class="form-control btn-touch" maxlength="60" value="<?= e($s['firm_tax_id'] ?? '') ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-currency">Base currency (costs are kept in it)</label><input type="text" name="base_currency" id="settings-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" pattern="[A-Za-z]{3}" value="<?= e($s['base_currency']) ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-footer">Invoice footer</label>
            <textarea name="invoice_footer" id="settings-form-field-footer" class="form-control" rows="2" maxlength="1000"><?= e($s['invoice_footer'] ?? '') ?></textarea>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Time</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-timezone">Time zone</label><select name="timezone" id="settings-form-field-timezone" class="form-select btn-touch"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option value="<?= e($z) ?>" <?= $s['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="settings-form-field-weekstart">The week starts on</label><select name="week_start_dow" id="settings-form-field-weekstart" class="form-select btn-touch"><?php foreach (DOW as $n => $day): ?><option value="<?= $n ?>" <?= $s['week_start_dow'] === $n ? 'selected' : '' ?>><?= e($day) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-increment">Hours increment</label><select name="hours_increment" id="settings-form-field-increment" class="form-select btn-touch"><?php foreach (HOURS_INCREMENTS as $i): ?><option value="<?= e($i) ?>" <?= $s['hours_increment'] === $i ? 'selected' : '' ?>><?= e(days_label($i)) ?> h</option><?php endforeach; ?></select></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-maxhours">Hours a day at most</label><input type="number" name="max_hours_per_day" id="settings-form-field-maxhours" class="form-control btn-touch" min="1" max="24" step="0.5" value="<?= e(days_label($s['max_hours_per_day'])) ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-capacity">Default capacity (h/week)</label><input type="number" name="capacity_hours_per_week" id="settings-form-field-capacity" class="form-control btn-touch" min="1" max="168" step="0.5" value="<?= e(days_label($s['capacity_hours_per_week'])) ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-nudge">Missing-time nudge on</label>
            <select name="missing_time_nudge_dow" id="settings-form-field-nudge" class="form-select btn-touch"><?php foreach (DOW as $n => $day): ?><option value="<?= $n ?>" <?= $s['missing_time_nudge_dow'] === $n ? 'selected' : '' ?>><?= e($day) ?></option><?php endforeach; ?></select>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Expenses and usage</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-receipt">Receipt required at or above</label><input type="text" name="receipt_threshold" id="settings-form-field-receipt" class="form-control btn-touch" inputmode="decimal" value="<?= e($s['receipt_threshold']) ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-markup">Default markup %</label><input type="text" name="default_markup_pct" id="settings-form-field-markup" class="form-control btn-touch" inputmode="decimal" value="<?= e(days_label($s['default_markup_pct'])) ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-aipct">AI budget alert at %</label><input type="number" name="ai_budget_threshold_pct" id="settings-form-field-aipct" class="form-control btn-touch" min="1" max="100" value="<?= $s['ai_budget_threshold_pct'] ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-thresholds">Budget alert thresholds, % (comma-separated)</label>
            <input type="text" name="budget_thresholds_pct" id="settings-form-field-thresholds" class="form-control btn-touch mb-3" value="<?= e(implode(', ', $s['budget_thresholds_pct'])) ?>" placeholder="50, 80, 100">
            <input type="hidden" name="ai_statement_poll" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="settings-form-field-aipoll"><input type="checkbox" class="form-check-input mt-0" name="ai_statement_poll" value="yes" id="settings-form-field-aipoll" <?= $s['ai_statement_poll'] ? 'checked' : '' ?>>Pull the OS's AI statement each closed month</label>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Billing</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-terms">Default terms (days)</label><input type="number" name="default_terms_days" id="settings-form-field-terms" class="form-control btn-touch" min="0" max="365" value="<?= $s['default_terms_days'] ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-link">The link's life (days)</label><input type="number" name="link_lifetime_days" id="settings-form-field-link" class="form-control btn-touch" min="1" max="3650" value="<?= $s['link_lifetime_days'] ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-grouping">Time on an invoice</label><select name="default_time_grouping" id="settings-form-field-grouping" class="form-select btn-touch"><?php foreach (TIME_GROUPINGS as $k => $label): ?><option value="<?= e($k) ?>" <?= $s['default_time_grouping'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-reminders">Reminder days past due (comma-separated)</label>
            <input type="text" name="reminder_days" id="settings-form-field-reminders" class="form-control btn-touch mb-3" value="<?= e(implode(', ', $s['reminder_days'])) ?>" placeholder="7, 21, 45">
            <input type="hidden" name="share_receipts_default" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="settings-form-field-share"><input type="checkbox" class="form-check-input mt-0" name="share_receipts_default" value="yes" id="settings-form-field-share" <?= $s['share_receipts_default'] ? 'checked' : '' ?>>Share receipts with the client on the secure link (a new engagement's default)</label>
            <label class="form-label fs-12 text-muted" for="settings-form-field-note">Who receives the Billing Clerk's morning note</label>
            <select name="morning_note_member" id="settings-form-field-note" class="form-select btn-touch"><option value="">Nobody</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= $s['morning_note_member_id'] === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?> (<?= $m['member_kind'] === 'agent' ? 'agent' : 'person' ?>)</option><?php endforeach; ?></select>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="settings-form-save-btn">Save</button>
    </form>
</div>
