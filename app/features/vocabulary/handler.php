<?php
declare(strict_types=1);

/** The shared body of the five vocabulary save/archive/delete handlers: the kind's fields, the diff log, the landing. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** Action `<kind>_save` (by the presence of the kind's param; log `<event>.save` with changed fields): the admin. $fields: name => [max, required] for text; the handler adds the rest. */
function vocabulary_save_handler(string $kind, string $event, string $base, string $singular, callable $readFields): never
{
    ct_handler_begin();
    require_admin();
    $pdo = db();
    [$table, , , , , $param] = VOCABULARY[$kind];
    $id = request_integer($param) ?? request_integer($param . '_id') ?? request_integer($table === 'consulting_roles' ? 'consulting_role_id' : ($table === 'expense_categories' ? 'category_id' : ($table === 'activity_types' ? 'activity_type_id' : ($table === 'usage_providers' ? 'provider_id' : 'tax_rate_id'))));
    $cur = null;
    if ($id !== null) {
        $cur = find_vocabulary_row($pdo, $kind, $id) ?? refuse(404, ucfirst($singular) . ' not found.');
        if ($cur['archived_at'] !== null) {
            refuse(422, ($cur['title'] ?? $cur['label'] ?? $cur['name']) . ' is archived; restore it first.');
        }
    }
    $errors = [];
    $f = $readFields($pdo, $cur, $errors);
    if ($errors !== []) {
        ct_refuse_fields($errors);
    }
    $newId = ct_guard($pdo, static function () use ($pdo, $kind, $event, $id, $cur, $f): int {
        $pdo->beginTransaction();
        $newId = save_vocabulary($pdo, $kind, $id, $f);
        $after = vocabulary_loggable($kind, find_vocabulary_row($pdo, $kind, $newId));
        if ($cur === null) {
            log_activity($pdo, $event . '.save', $kind === 'tax_rate' ? 'tax_rate' : $kind, $newId, ['after' => $after]);
        } else {
            $d = ct_diff(vocabulary_loggable($kind, $cur), $after);
            if ($d['after'] !== []) {
                log_activity($pdo, $event . '.save', $kind === 'tax_rate' ? 'tax_rate' : $kind, $newId, ['before' => $d['before'], 'after' => $d['after']]);
            }
        }
        $pdo->commit();
        return $newId;
    });
    $label = $f['title'] ?? $f['label'] ?? $f['name'];
    ct_done(($id === null ? 'Added the ' : 'Saved the ') . $singular . ' ' . $label, $newId, ct_land(return_path($base), 'saved', 'vocab-row-' . $newId), str_replace('_', '', $kind) . 'Changed', [$param . '_id' => $newId]);
}

/** Action `<kind>_archive` (archived=no restores; log `<event>.archive`), and `delete=yes` for an unused row: the admin. */
function vocabulary_archive_handler(string $kind, string $event, string $base, string $singular): never
{
    ct_handler_begin();
    require_admin();
    $pdo = db();
    [, , , , , $param] = VOCABULARY[$kind];
    $id = request_integer($param) ?? request_integer($param . '_id') ?? refuse(422, 'Say which ' . $singular . '.');
    find_vocabulary_row($pdo, $kind, $id) ?? refuse(404, ucfirst($singular) . ' not found.');
    if (ct_yes('delete', false)) {
        $name = ct_guard($pdo, static function () use ($pdo, $kind, $event, $id): string {
            $pdo->beginTransaction();
            $name = delete_vocabulary($pdo, $kind, $id);
            log_activity($pdo, $event . '.delete', $kind === 'tax_rate' ? 'tax_rate' : $kind, $id, ['before' => ['label' => $name]]);
            $pdo->commit();
            return $name;
        });
        ct_done('Deleted the ' . $singular . ' ' . $name, $id, ct_land(return_path($base), 'deleted'), str_replace('_', '', $kind) . 'Changed');
    }
    $archive = ct_yes('archived', true);
    $was = ct_guard($pdo, static function () use ($pdo, $kind, $event, $id, $archive): array {
        $pdo->beginTransaction();
        $was = archive_vocabulary($pdo, $kind, $id, $archive);
        log_activity($pdo, $event . '.archive', $kind === 'tax_rate' ? 'tax_rate' : $kind, $id, ['before' => ['archived' => !$archive], 'after' => ['archived' => $archive, 'label' => $was['label']]]);
        $pdo->commit();
        return $was;
    });
    ct_done(($archive ? 'Archived the ' : 'Restored the ') . $singular . ' ' . $was['label'], $id, ct_land(return_path($base), $archive ? 'archived' : 'restored', 'vocab-row-' . $id), str_replace('_', '', $kind) . 'Changed');
}

/** The common text/key/sort readers. */
function vocab_text(string $name, ?array $cur, int $max, string $label, array &$errors, bool $required = true): ?string
{
    $v = req_has($name) ? (string) req_val($name) : (string) ($cur[$name] ?? '');
    if ($v === '') {
        if ($required) {
            $errors[$name] = 'Give the ' . $label . '.';
        }
        return null;
    }
    if (mb_strlen($v) > $max) {
        $errors[$name] = ucfirst($label) . ' is up to ' . $max . ' characters.';
    }
    return $v;
}
function vocab_key(string $name, ?array $cur, string $from, array &$errors): string
{
    $k = req_has($name) ? strtolower(trim((string) req_val($name))) : (string) ($cur[$name] ?? '');
    if ($k === '') {
        $k = vocabulary_key_from($from);
    }
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k)) {
        $errors[$name] = 'The key is lowercase letters, digits and underscores, starting with a letter.';
    }
    return $k;
}
function vocab_decimal(string $name, ?array $cur, float $min, float $max, string $label, array &$errors, bool $nullable = true): ?string
{
    if (!req_has($name)) {
        return $cur[$name] ?? null;
    }
    $raw = str_replace(['%', ','], '', (string) req_val($name));
    if ($raw === '') {
        if ($nullable) {
            return null;
        }
        $errors[$name] = ucfirst($label) . ' is required.';
        return $cur[$name] ?? null;
    }
    if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
        $errors[$name] = ucfirst($label) . ' is a number from ' . days_label($min) . ' to ' . days_label($max) . '.';
        return $cur[$name] ?? null;
    }
    return number_format((float) $raw, $name === 'rate' ? 4 : 2, '.', '');
}
