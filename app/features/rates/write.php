<?php
declare(strict_types=1);

/** Writes to rate cards, their rates, cost rates and capacity. */

/** Add ($id null) or change a card; $copyOf copies another card's rates onto a new one. $f: name, currency, effective_from, description. */
function save_rate_card(PDO $pdo, ?int $id, array $f, ?int $copyOf, int $by): int
{
    $dupe = $pdo->prepare('SELECT name FROM rate_cards WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if (($found = $dupe->fetchColumn()) !== false) {
        throw new DomainException('A rate card named ' . $found . ' already exists.');
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO rate_cards (name, currency, effective_from, description, created_by) VALUES (:n, :c, :e, :d, :by) RETURNING id');
        $st->execute(['n' => $f['name'], 'c' => $f['currency'], 'e' => $f['effective_from'], 'd' => $f['description'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
        if ($copyOf !== null) {
            $pdo->prepare('INSERT INTO rate_card_rates (rate_card_id, consulting_role_id, bill_rate) SELECT :new, consulting_role_id, bill_rate FROM rate_card_rates WHERE rate_card_id = :old')->execute(['new' => $id, 'old' => $copyOf]);
        }
        return $id;
    }
    $cur = rate_card_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($f['currency'] !== $cur['currency'] && ($n = rate_card_in_use($pdo, $id)) > 0) {
        throw new DomainException('The currency is fixed: ' . $n . ' engagement' . ($n === 1 ? ' uses' : 's use') . ' this card. Copy it to a new card instead.');
    }
    $pdo->prepare('UPDATE rate_cards SET name = :n, currency = :c, effective_from = :e, description = :d WHERE id = :id')
        ->execute(['n' => $f['name'], 'c' => $f['currency'], 'e' => $f['effective_from'], 'd' => $f['description'], 'id' => $id]);
    return $id;
}

/** Set (or, with null, clear) the rate of a role on a card. Answers the rate before. */
function save_rate_card_rate(PDO $pdo, int $cardId, int $roleId, ?string $rate, int $by): ?string
{
    $before = one_value($pdo, 'SELECT bill_rate FROM rate_card_rates WHERE rate_card_id = :c AND consulting_role_id = :r', ['c' => $cardId, 'r' => $roleId]);
    if ($rate === null) {
        $pdo->prepare('DELETE FROM rate_card_rates WHERE rate_card_id = :c AND consulting_role_id = :r')->execute(['c' => $cardId, 'r' => $roleId]);
    } else {
        $pdo->prepare('INSERT INTO rate_card_rates (rate_card_id, consulting_role_id, bill_rate) VALUES (:c, :r, :b) ON CONFLICT (rate_card_id, consulting_role_id) DO UPDATE SET bill_rate = EXCLUDED.bill_rate')
            ->execute(['c' => $cardId, 'r' => $roleId, 'b' => $rate]);
    }
    return $before === null ? null : (string) $before;
}

function archive_rate_card(PDO $pdo, int $id, bool $archive, int $by): void
{
    $cur = rate_card_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($archive && $cur['archived_at'] !== null) {
        throw new DomainException($cur['name'] . ' is already archived.');
    }
    if (!$archive && $cur['archived_at'] === null) {
        throw new DomainException($cur['name'] . ' is not archived.');
    }
    $pdo->prepare('UPDATE rate_cards SET archived_at = ' . ($archive ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

/** Delete a card no engagement and no client uses. */
function delete_rate_card(PDO $pdo, int $id, int $by): string
{
    $cur = rate_card_state($pdo, $id) ?? throw new DomainException('Not found.');
    $eng = rate_card_in_use($pdo, $id);
    $cl = (int) one_value($pdo, 'SELECT count(*) FROM clients WHERE default_rate_card_id = :id', ['id' => $id]);
    if ($eng > 0 || $cl > 0) {
        throw new DomainException($cur['name'] . ' is used by ' . ($eng > 0 ? $eng . ' engagement' . ($eng === 1 ? '' : 's') : '') . ($eng > 0 && $cl > 0 ? ' and ' : '') . ($cl > 0 ? $cl . ' client' . ($cl === 1 ? '' : 's') : '') . '; archive it instead.');
    }
    $pdo->prepare('DELETE FROM rate_cards WHERE id = :id')->execute(['id' => $id]);
    return (string) $cur['name'];
}

/**
 * Add ($id null) or change a person's cost-rate range. A new range that starts inside the person's OPEN range ends that range the day
 * before, in the same transaction; any other overlap is the EXCLUDE's refusal, shown in words. Answers [id, ended_id|null].
 */
function save_cost_rate(PDO $pdo, ?int $id, int $memberId, string $cost, string $from, ?string $to, ?string $note, int $by): array
{
    $ended = null;
    if ($id === null) {
        $open = $pdo->prepare('SELECT id, valid_from FROM member_cost_rates WHERE member_id = :m AND valid_to IS NULL AND valid_from < :f ORDER BY valid_from DESC LIMIT 1');
        $open->execute(['m' => $memberId, 'f' => $from]);
        if (($o = $open->fetch()) !== false) {
            $pdo->prepare('UPDATE member_cost_rates SET valid_to = :t WHERE id = :id')->execute(['t' => $from, 'id' => (int) $o['id']]);
            $ended = (int) $o['id'];
        }
    }
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO member_cost_rates (member_id, hourly_cost, valid_from, valid_to, note, created_by) VALUES (:m, :c, :f, :t, :n, :by) RETURNING id');
            $st->execute(['m' => $memberId, 'c' => $cost, 'f' => $from, 't' => $to, 'n' => $note, 'by' => $by]);
            $id = (int) $st->fetchColumn();
        } else {
            $pdo->prepare('UPDATE member_cost_rates SET hourly_cost = :c, valid_from = :f, valid_to = :t, note = :n WHERE id = :id AND member_id = :m')
                ->execute(['c' => $cost, 'f' => $from, 't' => $to, 'n' => $note, 'id' => $id, 'm' => $memberId]);
        }
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23P01') {
            throw new DomainException('That range overlaps another of theirs; end the other range first.');
        }
        throw $e;
    }
    return [$id, $ended];
}

function end_cost_rate(PDO $pdo, int $id, string $to, int $by): array
{
    $cur = cost_rate_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($to <= $cur['valid_from']) {
        throw new DomainException('The end is after the start (' . format_date($cur['valid_from']) . ').');
    }
    try {
        $pdo->prepare('UPDATE member_cost_rates SET valid_to = :t WHERE id = :id')->execute(['t' => $to, 'id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23P01') {
            throw new DomainException('That end runs into their next range.');
        }
        throw $e;
    }
    return $cur;
}

/** The same shape for capacity. Answers [id, ended_id|null]. */
function save_capacity(PDO $pdo, ?int $id, int $memberId, string $hours, string $from, ?string $to, int $by): array
{
    $ended = null;
    if ($id === null) {
        $open = $pdo->prepare('SELECT id FROM member_capacity WHERE member_id = :m AND valid_to IS NULL AND valid_from < :f ORDER BY valid_from DESC LIMIT 1');
        $open->execute(['m' => $memberId, 'f' => $from]);
        if (($o = $open->fetch()) !== false) {
            $pdo->prepare('UPDATE member_capacity SET valid_to = :t WHERE id = :id')->execute(['t' => $from, 'id' => (int) $o['id']]);
            $ended = (int) $o['id'];
        }
    }
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO member_capacity (member_id, hours_per_week, valid_from, valid_to) VALUES (:m, :h, :f, :t) RETURNING id');
            $st->execute(['m' => $memberId, 'h' => $hours, 'f' => $from, 't' => $to]);
            $id = (int) $st->fetchColumn();
        } else {
            $pdo->prepare('UPDATE member_capacity SET hours_per_week = :h, valid_from = :f, valid_to = :t WHERE id = :id AND member_id = :m')->execute(['h' => $hours, 'f' => $from, 't' => $to, 'id' => $id, 'm' => $memberId]);
        }
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23P01') {
            throw new DomainException('That range overlaps another of theirs; end the other range first.');
        }
        throw $e;
    }
    return [$id, $ended];
}
