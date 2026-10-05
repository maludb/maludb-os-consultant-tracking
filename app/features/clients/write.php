<?php
declare(strict_types=1);

/** Writes to clients and client_contacts. A refusal is a DomainException in words; a duplicate name or code is the index's (23505) and the guard's sentence. */

/** Add ($id null) or change a client. $fields: name, legal_name, code, email, phone, billing_address, tax_id, terms_days, currency, po_required, default_rate_card_id, notes. */
function save_client(PDO $pdo, ?int $id, array $f, int $by): int
{
    $dupe = $pdo->prepare('SELECT name FROM clients WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if (($found = $dupe->fetchColumn()) !== false) {
        throw new DomainException('A client named ' . $found . ' already exists.');
    }
    if ($f['code'] !== null) {
        $dupe = $pdo->prepare('SELECT name FROM clients WHERE code = :c AND archived_at IS NULL AND id IS DISTINCT FROM :id');
        $dupe->execute(['c' => $f['code'], 'id' => $id]);
        if (($found = $dupe->fetchColumn()) !== false) {
            throw new DomainException('The code ' . $f['code'] . ' already belongs to ' . $found . '.');
        }
    }
    $args = ['n' => $f['name'], 'ln' => $f['legal_name'], 'c' => $f['code'], 'e' => $f['email'], 'p' => $f['phone'], 'a' => $f['billing_address'], 't' => $f['tax_id'],
             'td' => $f['terms_days'], 'cur' => $f['currency'], 'po' => $f['po_required'] ? 't' : 'f', 'rc' => $f['default_rate_card_id'], 'no' => $f['notes']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO clients (name, legal_name, code, email, phone, billing_address, tax_id, terms_days, currency, po_required, default_rate_card_id, notes, created_by)
                             VALUES (:n, :ln, :c, :e, :p, :a, :t, :td, :cur, :po, :rc, :no, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE clients SET name = :n, legal_name = :ln, code = :c, email = :e, phone = :p, billing_address = :a, tax_id = :t, terms_days = :td, currency = :cur,
                          po_required = :po, default_rate_card_id = :rc, notes = :no WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** Archive ($archive true) or restore a client. Archiving with an active engagement is refused in words. */
function archive_client(PDO $pdo, int $id, bool $archive, int $by): void
{
    $st = $pdo->prepare('SELECT name, archived_at, (SELECT count(*) FROM engagements e WHERE e.client_id = c.id AND e.status = \'active\') AS active FROM clients c WHERE id = :id');
    $st->execute(['id' => $id]);
    $c = $st->fetch();
    if ($c === false) {
        throw new DomainException('Not found.');
    }
    if ($archive) {
        if ($c['archived_at'] !== null) {
            throw new DomainException($c['name'] . ' is already archived.');
        }
        if ((int) $c['active'] > 0) {
            throw new DomainException($c['name'] . ' has ' . (int) $c['active'] . ' active engagement' . ((int) $c['active'] === 1 ? '' : 's') . '; close them first.');
        }
        $pdo->prepare('UPDATE clients SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
        return;
    }
    if ($c['archived_at'] === null) {
        throw new DomainException($c['name'] . ' is not archived.');
    }
    $live = $pdo->prepare('SELECT name FROM clients WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id <> :id');
    $live->execute(['n' => $c['name'], 'id' => $id]);
    if ($live->fetchColumn() !== false) {
        throw new DomainException('A live client named ' . $c['name'] . ' exists now; rename one first.');
    }
    $pdo->prepare('UPDATE clients SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
}

/** Delete a client that no engagement ever named (contacts, notes and attachments go with it). */
function delete_client(PDO $pdo, int $id, int $by): string
{
    $st = $pdo->prepare('SELECT name, (SELECT count(*) FROM engagements e WHERE e.client_id = c.id) AS engagements FROM clients c WHERE id = :id');
    $st->execute(['id' => $id]);
    $c = $st->fetch();
    if ($c === false) {
        throw new DomainException('Not found.');
    }
    if ((int) $c['engagements'] > 0) {
        throw new DomainException($c['name'] . ' has ' . (int) $c['engagements'] . ' engagement' . ((int) $c['engagements'] === 1 ? '' : 's') . '; a client with history is archived, never deleted.');
    }
    $pdo->prepare("DELETE FROM notes WHERE record_type = 'client' AND record_id = :id")->execute(['id' => $id]);
    foreach ($pdo->query("SELECT id FROM attachments WHERE record_type = 'client' AND record_id = " . $id)->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        delete_attachment($pdo, (int) $aid);
    }
    $pdo->prepare('DELETE FROM clients WHERE id = :id')->execute(['id' => $id]);
    return (string) $c['name'];
}

/** Add ($id null) or change a contact. $f: name, email, phone, role, receives_invoices, is_primary. Making one primary unsets the old primary. */
function save_client_contact(PDO $pdo, ?int $id, int $clientId, array $f, int $by): int
{
    if ($f['is_primary']) {
        $pdo->prepare('UPDATE client_contacts SET is_primary = false WHERE client_id = :c AND is_primary AND id IS DISTINCT FROM :id')->execute(['c' => $clientId, 'id' => $id]);
    }
    $args = ['n' => $f['name'], 'e' => $f['email'], 'p' => $f['phone'], 'r' => $f['role'], 'ri' => $f['receives_invoices'] ? 't' : 'f', 'ip' => $f['is_primary'] ? 't' : 'f'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO client_contacts (client_id, name, email, phone, role, receives_invoices, is_primary) VALUES (:c, :n, :e, :p, :r, :ri, :ip) RETURNING id');
        $st->execute($args + ['c' => $clientId]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE client_contacts SET name = :n, email = :e, phone = :p, role = :r, receives_invoices = :ri, is_primary = :ip WHERE id = :id AND client_id = :c')->execute($args + ['id' => $id, 'c' => $clientId]);
    return $id;
}

/** Remove a contact (archived: an invoice already sent to them keeps its address). */
function remove_client_contact(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT client_id, name, role, receives_invoices, is_primary FROM client_contacts WHERE id = :id AND archived_at IS NULL');
    $st->execute(['id' => $id]);
    $k = $st->fetch();
    if ($k === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('UPDATE client_contacts SET archived_at = now(), is_primary = false, receives_invoices = false WHERE id = :id')->execute(['id' => $id]);
    return ['client_id' => (int) $k['client_id'], 'name' => $k['name'], 'role' => $k['role'], 'receives_invoices' => (bool) $k['receives_invoices'], 'is_primary' => (bool) $k['is_primary']];
}

/** The contact's base row (the handler's "a field left out stays"). */
function client_contact_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT client_id, name, email, phone, role, receives_invoices, is_primary FROM client_contacts WHERE id = :id AND archived_at IS NULL');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['client_id'] = (int) $r['client_id'];
    $r['receives_invoices'] = (bool) $r['receives_invoices'];
    $r['is_primary'] = (bool) $r['is_primary'];
    return $r;
}
