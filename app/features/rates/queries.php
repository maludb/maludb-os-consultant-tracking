<?php
declare(strict_types=1);

/**
 * Rate cards, cost rates and capacity (slice 1). Cards and their rates read through mcp_rate_cards / mcp_rate_card_rates (rates.view);
 * the COST-RATE TABLE reads through mcp_member_cost_rates (cost.view AND ct_sees_all: the admin and Finance; a Manager never) and
 * its handlers are the admin's alone. A log payload about a cost rate carries the member and the dates, never the rate.
 */

function find_rate_cards(PDO $pdo, bool $withArchived = false): array
{
    $rows = $pdo->query('SELECT r.rate_card_id, r.name, r.currency, r.effective_from, r.description, r.archived_at, r.rate_count,
                                (SELECT count(*) FROM engagements e WHERE e.rate_card_id = r.rate_card_id) AS engagement_count,
                                (SELECT count(*) FROM clients c WHERE c.default_rate_card_id = r.rate_card_id AND c.archived_at IS NULL) AS client_count
                           FROM mcp_rate_cards r ' . ($withArchived ? '' : 'WHERE r.archived_at IS NULL ') . 'ORDER BY r.archived_at IS NOT NULL, lower(r.name)')->fetchAll();
    return array_map('rate_card_cast', $rows);
}

function rate_card_cast(array $r): array
{
    foreach (['rate_card_id', 'rate_count', 'engagement_count', 'client_count'] as $k) {
        $r[$k] = (int) ($r[$k] ?? 0);
    }
    return $r;
}

/** One card with a row per LIVE consulting role (rate null where none is set), and the engagements and clients using it. */
function find_rate_card(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.rate_card_id, r.name, r.currency, r.effective_from, r.description, r.archived_at, r.rate_count, r.created_at,
                                (SELECT count(*) FROM engagements e WHERE e.rate_card_id = r.rate_card_id) AS engagement_count,
                                (SELECT count(*) FROM clients c WHERE c.default_rate_card_id = r.rate_card_id AND c.archived_at IS NULL) AS client_count
                           FROM mcp_rate_cards r WHERE r.rate_card_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = rate_card_cast($r);
    $rates = $pdo->prepare('SELECT cr.consulting_role_id, cr.title AS role_title, cr.key AS role_key, x.bill_rate, x.updated_at
                              FROM mcp_consulting_roles cr LEFT JOIN mcp_rate_card_rates x ON x.consulting_role_id = cr.consulting_role_id AND x.rate_card_id = :id
                             WHERE cr.archived_at IS NULL OR x.bill_rate IS NOT NULL ORDER BY cr.sort_order, cr.title');
    $rates->execute(['id' => $id]);
    $r['rates'] = array_map(static function (array $x): array {
        $x['consulting_role_id'] = (int) $x['consulting_role_id'];
        return $x;
    }, $rates->fetchAll());
    $eng = $pdo->prepare('SELECT engagement_id, number, name, client_name, status, currency FROM mcp_engagements WHERE rate_card_id = :id ORDER BY status = \'active\' DESC, engagement_id DESC LIMIT 50');
    $eng->execute(['id' => $id]);
    $r['engagements'] = $eng->fetchAll();
    $cl = $pdo->prepare('SELECT client_id, name FROM mcp_clients WHERE default_rate_card_id = :id AND archived_at IS NULL ORDER BY lower(name)');
    $cl->execute(['id' => $id]);
    $r['clients'] = $cl->fetchAll();
    return $r;
}

function rate_card_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT name, currency, effective_from, description, archived_at FROM rate_cards WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The engagements that fix a card's currency. */
function rate_card_in_use(PDO $pdo, int $id): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM engagements WHERE rate_card_id = :id', ['id' => $id]);
}

/** Every person's cost-rate ranges, through the guarded view: [{member_id, member_name, ranges: [{cost_rate_id, hourly_cost, valid_from, valid_to, note}]}] */
function find_cost_rates(PDO $pdo): array
{
    $people = [];
    foreach ($pdo->query('SELECT cost_rate_id, member_id, member_name, hourly_cost, valid_from, valid_to, note FROM mcp_member_cost_rates ORDER BY lower(member_name), valid_from DESC')->fetchAll() as $r) {
        $m = (int) $r['member_id'];
        $people[$m] ??= ['member_id' => $m, 'member_name' => $r['member_name'], 'ranges' => []];
        $people[$m]['ranges'][] = ['cost_rate_id' => (int) $r['cost_rate_id'], 'hourly_cost' => $r['hourly_cost'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'note' => $r['note']];
    }
    return array_values($people);
}

function cost_rate_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, member_id, hourly_cost, valid_from, valid_to, note FROM member_cost_rates WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['id'] = (int) $r['id'];
    $r['member_id'] = (int) $r['member_id'];
    return $r;
}

/** Every person's capacity ranges (the view admits the caller's own or reports.view). */
function find_capacity(PDO $pdo): array
{
    $people = [];
    foreach ($pdo->query('SELECT capacity_id, member_id, member_name, hours_per_week, valid_from, valid_to FROM mcp_member_capacity ORDER BY lower(member_name), valid_from DESC')->fetchAll() as $r) {
        $m = (int) $r['member_id'];
        $people[$m] ??= ['member_id' => $m, 'member_name' => $r['member_name'], 'ranges' => []];
        $people[$m]['ranges'][] = ['capacity_id' => (int) $r['capacity_id'], 'hours_per_week' => $r['hours_per_week'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to']];
    }
    return array_values($people);
}

function capacity_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, member_id, hours_per_week, valid_from, valid_to FROM member_capacity WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['id'] = (int) $r['id'];
    $r['member_id'] = (int) $r['member_id'];
    return $r;
}
