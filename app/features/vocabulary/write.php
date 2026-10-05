<?php
declare(strict_types=1);

/** Writes to the five vocabulary tables; one shape. A live key or name is unique (the index); the guard turns 23505 into words. */

function save_vocabulary(PDO $pdo, string $kind, ?int $id, array $f): int
{
    [$table] = VOCABULARY[$kind];
    $keyCol = $kind === 'provider' ? 'provider_key' : ($kind === 'tax_rate' ? null : 'key');
    if ($keyCol !== null) {
        $dupe = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$keyCol} = :k AND archived_at IS NULL AND id IS DISTINCT FROM :id");
        $dupe->execute(['k' => $f[$keyCol], 'id' => $id]);
        if ($dupe->fetchColumn() !== false) {
            throw new DomainException('The key ' . $f[$keyCol] . ' is already in use.');
        }
    }
    if ($kind === 'tax_rate') {
        $dupe = $pdo->prepare('SELECT 1 FROM tax_rates WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
        $dupe->execute(['n' => $f['name'], 'id' => $id]);
        if ($dupe->fetchColumn() !== false) {
            throw new DomainException('A tax rate named ' . $f['name'] . ' already exists.');
        }
        if ($f['is_default']) {
            $pdo->prepare('UPDATE tax_rates SET is_default = false WHERE is_default AND id IS DISTINCT FROM :id')->execute(['id' => $id]);
        }
    }
    $cols = array_keys($f);
    $bind = static fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : (is_array($v) ? json_encode($v) : $v);
    $args = array_combine(array_map(static fn (string $c): string => 'v_' . $c, $cols), array_map($bind, array_values($f)));
    $cast = static fn (string $c): string => $c === 'csv_mapping' ? 'CAST(:v_' . $c . ' AS jsonb)' : ':v_' . $c;
    if ($id === null) {
        $st = $pdo->prepare("INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map($cast, $cols)) . ') RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare("UPDATE {$table} SET " . implode(', ', array_map(static fn (string $c): string => $c . ' = ' . $cast($c), $cols)) . ' WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** Archive or restore. Archiving the default tax rate is refused. */
function archive_vocabulary(PDO $pdo, string $kind, int $id, bool $archive): array
{
    [$table] = VOCABULARY[$kind];
    $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    $label = $r['title'] ?? $r['label'] ?? $r['name'];
    if ($archive && $r['archived_at'] !== null) {
        throw new DomainException($label . ' is already archived.');
    }
    if (!$archive && $r['archived_at'] === null) {
        throw new DomainException($label . ' is not archived.');
    }
    if ($archive && $kind === 'tax_rate' && $r['is_default']) {
        throw new DomainException($label . ' is the default tax rate; make another the default first.');
    }
    $pdo->prepare("UPDATE {$table} SET archived_at = " . ($archive ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
    return ['label' => $label];
}

/** Delete a row nothing names (the vocabulary's Delete button, absent once used). */
function delete_vocabulary(PDO $pdo, string $kind, int $id): string
{
    [$table, , , $useSql, $what] = VOCABULARY[$kind];
    $r = find_vocabulary_row($pdo, $kind, $id) ?? throw new DomainException('Not found.');
    if ($r['in_use'] > 0) {
        throw new DomainException(($r['title'] ?? $r['label'] ?? $r['name']) . ' is in use on ' . $r['in_use'] . ' record' . ($r['in_use'] === 1 ? '' : 's') . '; archive it instead.');
    }
    if ($kind === 'tax_rate' && $r['is_default']) {
        throw new DomainException($r['name'] . ' is the default tax rate; make another the default first.');
    }
    if ($kind === 'role') {
        $cards = (int) one_value($pdo, 'SELECT count(*) FROM rate_card_rates WHERE consulting_role_id = :id', ['id' => $id]);
        if ($cards > 0) {
            throw new DomainException($r['title'] . ' is priced on ' . $cards . ' rate card' . ($cards === 1 ? '' : 's') . '; archive it instead.');
        }
    }
    $pdo->prepare("DELETE FROM {$table} WHERE id = :id")->execute(['id' => $id]);
    return (string) ($r['title'] ?? $r['label'] ?? $r['name']);
}
