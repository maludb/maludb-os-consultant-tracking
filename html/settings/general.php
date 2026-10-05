<?php
declare(strict_types=1);
/**
 * GET /settings/general — the firm's settings (screen `settings-general`). POST = action `settings_save` (log `settings.save`: before/after of the changed keys only;
 * nothing changed logs nothing): settings.manage.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_right('settings.manage');
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    $s = find_settings($pdo);
    log_screen_view($pdo, 'settings-general');
    if (wants_json()) {
        respond_screen(['settings' => present_settings($s), 'groupings' => TIME_GROUPINGS, 'increments' => HOURS_INCREMENTS]);
    }
    render_screen('Settings', view('settings/general.php', ['s' => $s, 'members' => members_for_pick($pdo), 'notice' => ct_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the settings.'], 'same' => ['info', 'Nothing changed.']])]),
        ['activeNav' => 'general', 'screen' => 'settings-general', 'entity' => 'settings']);
    exit;
}
ct_handler_begin();
$me = (int) current_member_id();
$cur = find_settings($pdo);
$errors = [];
$f = [];
foreach (SETTINGS_TEXT as $k => $max) {
    if (req_has($k)) {
        $v = (string) req_val($k);
        $f[$k] = $v === '' ? null : mb_substr($v, 0, $max);
    }
}
if (req_has('base_currency')) {
    $f['base_currency'] = strtoupper((string) req_val('base_currency'));
    if (!preg_match('/^[A-Z]{3}$/', $f['base_currency'])) {
        $errors['base_currency'] = 'The base currency is a three-letter code (USD).';
    } elseif ($f['base_currency'] !== $cur['base_currency'] && (int) one_value($pdo, 'SELECT count(*) FROM member_cost_rates') > 0) {
        $errors['base_currency'] = 'The base currency is fixed once a cost rate is kept in it.';
    }
}
if (req_has('timezone')) {
    $f['timezone'] = (string) req_val('timezone');
    if (!in_array($f['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors['timezone'] = 'The time zone is an IANA name like America/New_York.';
    }
}
$f['week_start_dow'] = ct_int('week_start_dow', $cur['week_start_dow'], 0, 6, 'The week start', $errors);
$f['missing_time_nudge_dow'] = ct_int('missing_time_nudge_dow', $cur['missing_time_nudge_dow'], 0, 6, 'The nudge day', $errors);
if (req_has('hours_increment')) {
    $inc = number_format((float) req_val('hours_increment'), 2, '.', '');
    if (!in_array($inc, HOURS_INCREMENTS, true)) {
        $errors['hours_increment'] = 'The increment is one of ' . implode(', ', HOURS_INCREMENTS) . ' hours.';
    } else {
        $f['hours_increment'] = $inc;
    }
}
$decimal = static function (string $k, float $min, float $max, string $label) use (&$f, &$errors): void {
    if (!req_has($k)) {
        return;
    }
    $raw = str_replace([',', '%'], '', (string) req_val($k));
    if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
        $errors[$k] = $label . ' is a number from ' . days_label($min) . ' to ' . days_label($max) . '.';
    } else {
        $f[$k] = number_format((float) $raw, 2, '.', '');
    }
};
$decimal('max_hours_per_day', 1, 24, 'Hours a day at most');
$decimal('receipt_threshold', 0, 1000000000, 'The receipt threshold');
$decimal('default_markup_pct', 0, 500, 'The default markup');
$decimal('capacity_hours_per_week', 1, 168, 'The default capacity');
$f['default_terms_days'] = ct_int('default_terms_days', $cur['default_terms_days'], 0, 365, 'Terms', $errors);
$f['link_lifetime_days'] = ct_int('link_lifetime_days', $cur['link_lifetime_days'], 1, 3650, "The link's life", $errors);
$f['ai_budget_threshold_pct'] = ct_int('ai_budget_threshold_pct', $cur['ai_budget_threshold_pct'], 1, 100, 'The AI budget threshold', $errors);
if (req_has('default_time_grouping')) {
    $f['default_time_grouping'] = (string) req_val('default_time_grouping');
    if (!isset(TIME_GROUPINGS[$f['default_time_grouping']])) {
        $errors['default_time_grouping'] = 'The grouping is one of: ' . implode(', ', array_keys(TIME_GROUPINGS)) . '.';
    }
}
foreach (['reminder_days' => [1, 365, 'Reminder days'], 'budget_thresholds_pct' => [1, 1000, 'Budget thresholds']] as $k => [$min, $max, $label]) {
    $list = request_list($k);
    if ($list !== null) {
        $bad = array_filter($list, static fn (string $d): bool => filter_var($d, FILTER_VALIDATE_INT) === false || (int) $d < $min || (int) $d > $max);
        if ($bad !== []) {
            $errors[$k] = $label . ' are whole numbers from ' . $min . ' to ' . $max . '.';
        } else {
            $vals = array_values(array_unique(array_map('intval', $list)));
            sort($vals);
            $f[$k] = $vals;
        }
    }
}
$f['share_receipts_default'] = ct_yes('share_receipts_default', $cur['share_receipts_default']);
$f['ai_statement_poll'] = ct_yes('ai_statement_poll', $cur['ai_statement_poll']);
$noteField = req_has('morning_note_member') ? 'morning_note_member' : (req_has('morning_note_member_id') ? 'morning_note_member_id' : null);
if ($noteField !== null) {
    $f['morning_note_member_id'] = ct_ref($pdo, $noteField, $cur['morning_note_member_id'], "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors);
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$d = ct_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $d = save_settings($pdo, $f, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'settings.save', 'settings', 1, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
ct_done($d['after'] === [] ? 'Nothing changed' : 'Saved the settings (' . implode(', ', array_keys($d['after'])) . ')', 1, ct_land(return_path('/settings/general'), $d['after'] === [] ? 'same' : 'saved'), 'settingsChanged', ['changed' => array_keys($d['after'])]);
