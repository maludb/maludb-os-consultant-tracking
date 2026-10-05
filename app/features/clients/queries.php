<?php
declare(strict_types=1);

/**
 * Clients and their contacts (slice 1): reads through mcp_clients / mcp_client_contacts (contact details only with clients.manage or
 * billing.write — the view decides, never this code), writes to the base tables. The database is the referee for names and codes.
 */

const CLIENT_SELECT = 'SELECT c.client_id, c.name, c.legal_name, c.code, c.email, c.phone, c.billing_address, c.tax_id, c.terms_days, c.currency, c.po_required,
       c.default_rate_card_id, r.name AS rate_card_name, c.member_id, c.notes, c.archived_at, c.created_at, c.updated_at, c.active_engagements,
       (SELECT count(*) FROM engagements e WHERE e.client_id = c.client_id) AS engagement_count,
       (SELECT count(*) FROM client_contacts k WHERE k.client_id = c.client_id AND k.archived_at IS NULL) AS contact_count,
       (SELECT COALESCE(sum(i.balance_due), 0) FROM invoices i WHERE i.client_id = c.client_id AND i.status IN (\'issued\', \'sent\', \'partially_paid\', \'overdue\')) AS open_balance
  FROM mcp_clients c LEFT JOIN rate_cards r ON r.id = c.default_rate_card_id';

function client_cast(array $c): array
{
    foreach (['client_id', 'terms_days', 'default_rate_card_id', 'member_id', 'active_engagements', 'engagement_count', 'contact_count'] as $k) {
        $c[$k] = $c[$k] === null ? null : (int) $c[$k];
    }
    $c['po_required'] = (bool) $c['po_required'];
    $c['open_balance'] = number_format((float) ($c['open_balance'] ?? 0), 2, '.', '');
    return $c;
}

/** The clients the caller may see. $filters: q (name, code or legal name), archived (true lists the archived ones instead). */
function find_clients(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [!empty($filters['archived']) ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL'];
    $args = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(c.name ILIKE :q OR c.legal_name ILIKE :q OR c.code ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare(CLIENT_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY lower(c.name), c.client_id LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map('client_cast', $st->fetchAll());
}

/** One client the caller may see, archived or not, with its live contacts. */
function find_client(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(CLIENT_SELECT . ' WHERE c.client_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $c = client_cast($r);
    $c['contacts'] = find_client_contacts($pdo, $id);
    return $c;
}

function find_client_contacts(PDO $pdo, int $clientId): array
{
    $st = $pdo->prepare('SELECT contact_id, client_id, name, email, phone, role, receives_invoices, is_primary FROM mcp_client_contacts WHERE client_id = :c AND archived_at IS NULL ORDER BY is_primary DESC, lower(name)');
    $st->execute(['c' => $clientId]);
    return array_map(static function (array $k): array {
        $k['contact_id'] = (int) $k['contact_id'];
        $k['client_id'] = (int) $k['client_id'];
        $k['receives_invoices'] = (bool) $k['receives_invoices'];
        $k['is_primary'] = (bool) $k['is_primary'];
        return $k;
    }, $st->fetchAll());
}

/** The base row's state (the handler's "a field left out stays"), or null. */
function client_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT name, legal_name, code, email, phone, billing_address, tax_id, terms_days, currency, po_required, default_rate_card_id, notes, archived_at FROM clients WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['terms_days'] = $r['terms_days'] === null ? null : (int) $r['terms_days'];
    $r['default_rate_card_id'] = $r['default_rate_card_id'] === null ? null : (int) $r['default_rate_card_id'];
    $r['po_required'] = (bool) $r['po_required'];
    return $r;
}

/** What a client's log payload may carry: never email, phone, address or tax id. */
function client_loggable(array $s): array
{
    return ['name' => $s['name'], 'code' => $s['code'], 'terms_days' => $s['terms_days'], 'currency' => $s['currency'], 'po_required' => $s['po_required'],
            'rate_card_id' => $s['default_rate_card_id'], 'has_notes' => ($s['notes'] ?? null) !== null && $s['notes'] !== ''];
}

/** The rate cards a client may name as its default: live ones. [{rate_card_id, name, currency}] */
function rate_cards_for_pick(PDO $pdo): array
{
    return $pdo->query('SELECT id AS rate_card_id, name, currency FROM rate_cards WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();
}

/** A client's engagements as the view admits them (slice 2 builds the screens; the counts stand now). */
function find_client_engagements(PDO $pdo, int $clientId): array
{
    $st = $pdo->prepare('SELECT engagement_id, number, name, kind, status, start_date, end_date, currency, manager_name FROM mcp_engagements WHERE client_id = :c ORDER BY status = \'active\' DESC, start_date DESC NULLS LAST, engagement_id DESC');
    $st->execute(['c' => $clientId]);
    return $st->fetchAll();
}
