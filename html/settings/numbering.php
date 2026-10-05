<?php
declare(strict_types=1);
/** GET /settings/numbering — prefixes and next numbers (screen `numbering`). POST = action `numbering_save` per kind (log `numbering.save`; the next value never goes below the highest issued): settings.manage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_right('settings.manage');
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    $rows = find_numbering($pdo);
    log_screen_view($pdo, 'numbering');
    if (wants_json()) {
        respond_screen(['numbering' => $rows]);
    }
    render_screen('Numbering', view('settings/numbering.php', ['rows' => $rows, 'notice' => ct_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the numbering.'], 'same' => ['info', 'Nothing changed.']])]),
        ['activeNav' => 'general', 'screen' => 'numbering', 'entity' => 'settings']);
    exit;
}
ct_handler_begin();
require_admin();
$me = (int) current_member_id();
$kind = (string) req_val('kind');
if (!isset(SEQUENCE_KINDS[$kind])) {
    ct_refuse_fields(['kind' => 'The kind is one of: ' . implode(', ', array_keys(SEQUENCE_KINDS)) . '.']);
}
$errors = [];
$f = [];
if (req_has('prefix')) {
    $f['prefix'] = strtoupper((string) req_val('prefix'));
    if (!preg_match('/^[A-Z][A-Z0-9]{0,7}-?$/', $f['prefix'])) {
        $errors['prefix'] = 'The prefix is capital letters and digits, up to 8, starting with a letter, with an optional dash.';
    }
}
$next = ct_int('next_value', null, 1, PHP_INT_MAX, 'The next number', $errors);
if ($next !== null) {
    $f['next_value'] = $next;
}
$pad = ct_int('padding', null, 1, 12, 'The padding', $errors);
if ($pad !== null) {
    $f['padding'] = $pad;
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$d = ct_guard($pdo, static function () use ($pdo, $me, $kind, $f): array {
    $pdo->beginTransaction();
    $d = save_numbering($pdo, $kind, $f, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'numbering.save', 'document_sequence', null, ['before' => ['kind' => $kind] + $d['before'], 'after' => ['kind' => $kind] + $d['after']]);
    }
    $pdo->commit();
    return $d;
});
$row = array_values(array_filter(find_numbering($pdo), static fn (array $r): bool => $r['kind'] === $kind))[0];
ct_done($d['after'] === [] ? 'Nothing changed' : 'Saved the numbering of ' . strtolower(SEQUENCE_KINDS[$kind]) . ' (next ' . $row['preview'] . ')', null, ct_land(return_path('/settings/numbering'), $d['after'] === [] ? 'same' : 'saved', 'numbering-row-' . $kind), 'settingsChanged', ['kind' => $kind, 'preview' => $row['preview'], 'changed' => array_keys($d['after'])]);
