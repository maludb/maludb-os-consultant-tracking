<?php
declare(strict_types=1);

/**
 * Writes (slice 5): a draft charge to the base table (the guard in db/011 is the referee: the number, the money from quantity × unit cost and the pricing, the lock once approved),
 * the database VERBS for every status move, the rules and schedules as plain rows, the notices this slice queues. PHP decides nothing twice: a refusal is the database's own sentence.
 */

/** A verb or write: the row it answers, or its RAISE as a DomainException. */
function usage_verb(PDO $pdo, string $sql, array $args): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: [];
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That could not be done.'));
        }
        throw $e;
    }
}

/** The files stored by this request (full paths), so a refused save can take them away again. */
function usage_stored_files(?string $add = null, bool $clear = false): array
{
    static $files = [];
    if ($add !== null) {
        $files[] = $add;
    }
    $out = $files;
    if ($clear) {
        $files = [];
    }
    return $out;
}

function usage_discard_stored_files(): void
{
    foreach (usage_stored_files(null, true) as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/**
 * Add ($id null) or change a draft charge. $f: engagement_id, kind, provider_id, description, period_start, period_end, quantity, unit, unit_cost, pricing, markup_pct, resale_unit_price, billable.
 * $file: the provider's invoice (one $_FILES entry), stored in the same transaction. Set the unit cost, never the total: the guard recomputes the cost from quantity × unit cost.
 */
function save_usage_charge(PDO $pdo, ?int $id, array $f, int $by, ?array $file): int
{
    try {
        $args = ['e' => $f['engagement_id'], 'k' => $f['kind'], 'p' => $f['provider_id'], 'ds' => $f['description'], 'ps' => $f['period_start'], 'pe' => $f['period_end'], 'q' => $f['quantity'], 'u' => $f['unit'],
                 'uc' => $f['unit_cost'], 'pr' => $f['pricing'], 'mk' => $f['markup_pct'], 'rs' => $f['resale_unit_price'], 'b' => $f['billable'] ? 't' : 'f'];
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO usage_charges (engagement_id, kind, provider_id, description, period_start, period_end, quantity, unit, unit_cost, pricing, markup_pct, resale_unit_price, billable, currency, source, created_by)
                                 VALUES (:e, :k, :p, :ds, :ps, :pe, :q, :u, :uc, :pr, :mk, :rs, :b, \'USD\', \'manual\', :by) RETURNING id');
            $st->execute($args + ['by' => $by]);
            $id = (int) $st->fetchColumn();
        } else {
            $pdo->prepare('UPDATE usage_charges SET engagement_id = :e, kind = :k, provider_id = :p, description = :ds, period_start = :ps, period_end = :pe, quantity = :q, unit = :u, unit_cost = :uc, pricing = :pr,
                                  markup_pct = :mk, resale_unit_price = :rs, billable = :b WHERE id = :id')->execute($args + ['id' => $id]);
        }
        if ($file !== null) {
            $aid = store_attachment($pdo, 'usage_charge', $id, $file, $by, false);
            usage_stored_files(APP_ROOT . '/storage/' . (string) one_value($pdo, 'SELECT storage_path FROM attachments WHERE id = :id', ['id' => $aid]));
        }
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That charge could not be saved.'));
        }
        throw $e;
    }
}

/** Delete a draft charge (the trigger refuses anything else), then its documents and notes. A charge the OS statement made is voided, never deleted: its pieces return to the remainder through the void. */
function delete_usage_charge(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT number, engagement_id, billable_amount, currency, status, source FROM usage_charges WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    if ($r['source'] === 'kernel_ledger') {
        throw new DomainException('Usage charge ' . $r['number'] . ' came from the OS statement; void it (its pieces return to the remainder) or allocate the month again.');
    }
    try {
        $pdo->prepare('DELETE FROM usage_charges WHERE id = :id')->execute(['id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That charge is not deleted.'));
        }
        throw $e;
    }
    foreach ($pdo->query("SELECT id FROM attachments WHERE record_type = 'usage_charge' AND record_id = " . $id)->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        delete_attachment($pdo, (int) $aid);
    }
    $pdo->prepare("DELETE FROM notes WHERE record_type = 'usage_charge' AND record_id = :id")->execute(['id' => $id]);
    return ['number' => $r['number'], 'engagement_id' => (int) $r['engagement_id'], 'amount' => $r['billable_amount'], 'currency' => trim((string) $r['currency']), 'status' => $r['status']];
}

function approve_usage_charge(PDO $pdo, int $id, int $by): array
{
    return usage_verb($pdo, 'SELECT * FROM ct_usage_charge_approve(:u, :by)', ['u' => $id, 'by' => $by]);
}

function void_usage_charge(PDO $pdo, int $id, int $by, string $reason): array
{
    return usage_verb($pdo, 'SELECT * FROM ct_usage_charge_void(:u, :by, :r)', ['u' => $id, 'by' => $by, 'r' => $reason]);
}

// ---- allocation rules --------------------------------------------------------------------------------------------------------------------------------------------------

/** Add or change a rule. $f: name, kernel_agent_id, kernel_department_id, kernel_application_id, engagement_id, share_pct, pricing, markup_pct, resale_unit_price, valid_from, valid_to, active. Answers the id. */
function save_allocation_rule(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['n' => $f['name'], 'ag' => $f['kernel_agent_id'], 'de' => $f['kernel_department_id'], 'ap' => $f['kernel_application_id'], 'e' => $f['engagement_id'], 'sh' => $f['share_pct'], 'pr' => $f['pricing'],
             'mk' => $f['markup_pct'], 'rs' => $f['resale_unit_price'], 'vf' => $f['valid_from'], 'vt' => $f['valid_to'], 'ac' => $f['active'] ? 't' : 'f'];
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO allocation_rules (name, kernel_agent_id, kernel_department_id, kernel_application_id, engagement_id, share_pct, pricing, markup_pct, resale_unit_price, valid_from, valid_to, active, created_by)
                                 VALUES (:n, :ag, :de, :ap, :e, :sh, :pr, :mk, :rs, :vf, :vt, :ac, :by) RETURNING id');
            $st->execute($args + ['by' => $by]);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE allocation_rules SET name = :n, kernel_agent_id = :ag, kernel_department_id = :de, kernel_application_id = :ap, engagement_id = :e, share_pct = :sh, pricing = :pr, markup_pct = :mk,
                              resale_unit_price = :rs, valid_from = :vf, valid_to = :vt, active = :ac WHERE id = :id')->execute($args + ['id' => $id]);
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That rule could not be saved.'));
        }
        if ((string) $e->getCode() === '23514') {                          // a CHECK: the sentence is ours, the rule is the database's
            throw new DomainException('A rule names an agent, a department or an application, an end date after its start, and a resale price when it resells.');
        }
        throw $e;
    }
}

/** Delete a rule (the pieces it placed keep their charges; the allocation rows lose the rule). Answers its facts for the log. */
function delete_allocation_rule(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT name, engagement_id, share_pct FROM allocation_rules WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM allocation_rules WHERE id = :id')->execute(['id' => $id]);
    return ['name' => $r['name'], 'engagement_id' => (int) $r['engagement_id'], 'share_pct' => $r['share_pct']];
}

// ---- hosting schedules -------------------------------------------------------------------------------------------------------------------------------------------------

/** Add or change a schedule. $f: engagement_id, kind, provider_id, description, quantity, unit, unit_cost, pricing, markup_pct, resale_unit_price, cadence, every_n, next_date, end_date, active. Answers the id. */
function save_schedule(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['e' => $f['engagement_id'], 'k' => $f['kind'], 'p' => $f['provider_id'], 'ds' => $f['description'], 'q' => $f['quantity'], 'u' => $f['unit'], 'uc' => $f['unit_cost'], 'pr' => $f['pricing'], 'mk' => $f['markup_pct'],
             'rs' => $f['resale_unit_price'], 'c' => $f['cadence'], 'n' => $f['every_n'], 'nd' => $f['next_date'], 'ed' => $f['end_date'], 'ac' => $f['active'] ? 't' : 'f'];
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO recurring_charges (engagement_id, kind, provider_id, description, quantity, unit, unit_cost, pricing, markup_pct, resale_unit_price, cadence, every_n, next_date, end_date, active, created_by)
                                 VALUES (:e, :k, :p, :ds, :q, :u, :uc, :pr, :mk, :rs, :c, :n, :nd, :ed, :ac, :by) RETURNING id');
            $st->execute($args + ['by' => $by]);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE recurring_charges SET engagement_id = :e, kind = :k, provider_id = :p, description = :ds, quantity = :q, unit = :u, unit_cost = :uc, pricing = :pr, markup_pct = :mk, resale_unit_price = :rs,
                              cadence = :c, every_n = :n, next_date = :nd, end_date = :ed, active = :ac WHERE id = :id')->execute($args + ['id' => $id]);
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That schedule could not be saved.'));
        }
        if ((string) $e->getCode() === '23514') {
            throw new DomainException('A schedule needs a quantity above zero, an end date on or after the next date, and a resale price when it resells.');
        }
        throw $e;
    }
}

/** Run a due schedule: one draft charge for the period it covers and the next date (the verb). Answers the charge's row. */
function run_schedule(PDO $pdo, int $id, ?int $by): array
{
    return usage_verb($pdo, 'SELECT * FROM ct_recurring_charge_run(:s, :by)', ['s' => $id, 'by' => $by]);   // the final run before an end date deactivates the schedule (db/016)
}

function delete_schedule(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT description, engagement_id, run_count FROM recurring_charges WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM recurring_charges WHERE id = :id')->execute(['id' => $id]);
    return ['description' => $r['description'], 'engagement_id' => (int) $r['engagement_id'], 'run_count' => (int) $r['run_count']];
}

// ---- the notices -------------------------------------------------------------------------------------------------------------------------------------------------------

/**
 * Queue a notice about a usage charge or a statement (the sibling of queue_expense_notice; $recordType is 'usage_charge', 'ai_statement' or 'engagement'): the in-app row, and an outbox row per channel
 * the person chose; an agent gets the in-app row only; a dedupe key (per channel) makes a second queue of the same key a no-op; an eval run queues nothing. Slice 8 sends. Answers how many rows were made.
 */
function queue_usage_notice(PDO $pdo, int $memberId, string $kind, string $recordType, int $recordId, array $data): int
{
    if ($memberId < 1 || (($_SERVER['HTTP_X_EVAL_RUN'] ?? '') === '1')) {
        return 0;
    }
    $m = $pdo->prepare('SELECT member_kind, status, capability FROM members WHERE id = :m');
    $m->execute(['m' => $memberId]);
    $member = $m->fetch();
    if ($member === false || $member['status'] !== 'active' || $member['capability'] === null) {
        return 0;
    }
    $title = (string) ($data['title'] ?? $kind);
    $body = (string) ($data['body'] ?? '');
    $dedupe = $data['dedupe'] ?? null;
    if ($dedupe !== null && (int) one_value($pdo, 'SELECT count(*) FROM notification_outbox WHERE dedupe_key LIKE :d', ['d' => $dedupe . ':m' . $memberId . ':%']) > 0) {
        return 0;
    }
    $pdo->prepare('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, :t, :r, :ti, :b)')
        ->execute(['m' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'ti' => mb_substr($title, 0, 300), 'b' => mb_substr($body, 0, 2000)]);
    $n = 1;
    if ($member['member_kind'] === 'agent') {
        return $n;
    }
    $prefs = find_prefs($pdo, $memberId);
    $ins = $pdo->prepare('INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body) VALUES (:c, :m, :k, :t, :r, :d, :s, :b)');
    if ($prefs['email_enabled'] && in_array($kind, $prefs['kinds'], true)) {
        $ins->execute(['c' => 'email', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':email', 's' => $title, 'b' => $body]);
        $n++;
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'm' => $memberId, 'k' => $kind, 't' => $recordType, 'r' => $recordId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':text', 's' => $title, 'b' => mb_substr($body, 0, 300)]);
        $n++;
    }
    return $n;
}
