<?php
declare(strict_types=1);

/** Writes to engagements, staffing, role rates, milestones and alerts. The guards are the referee; a refusal is a DomainException in words. */

/**
 * Add ($id null) or change an engagement. $f: client_id, name, kind, start_date, end_date, currency, po_number, rate_card_id, nte_amount, budget_hours, budget_amount,
 * ai_budget_amount, expense_policy (array), billing_cadence, time_grouping, share_receipts_with_client, department_id, manager_member_id, projects_ref, description,
 * start_now (create only). On create the manager is staffed as `principal` (a decision taken here: a manager logs time too) unless $f['manager_role_id'] says otherwise.
 */
function save_engagement(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['c' => $f['client_id'], 'n' => $f['name'], 'k' => $f['kind'], 'sd' => $f['start_date'], 'ed' => $f['end_date'], 'cur' => $f['currency'], 'po' => $f['po_number'], 'rc' => $f['rate_card_id'],
             'nte' => $f['nte_amount'], 'bh' => $f['budget_hours'], 'ba' => $f['budget_amount'], 'ai' => $f['ai_budget_amount'], 'ep' => json_encode($f['expense_policy'] ?: new stdClass()), 'cad' => $f['billing_cadence'],
             'tg' => $f['time_grouping'], 'sr' => $f['share_receipts_with_client'] === null ? null : ($f['share_receipts_with_client'] ? 't' : 'f'), 'd' => $f['department_id'], 'm' => $f['manager_member_id'],
             'pr' => $f['projects_ref'], 'desc' => $f['description']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO engagements (client_id, name, kind, status, start_date, end_date, currency, po_number, rate_card_id, nte_amount, budget_hours, budget_amount, ai_budget_amount, expense_policy,
                                                      billing_cadence, time_grouping, share_receipts_with_client, department_id, manager_member_id, projects_ref, description, created_by)
                             VALUES (:c, :n, :k, :s, :sd, :ed, :cur, :po, :rc, :nte, :bh, :ba, :ai, CAST(:ep AS jsonb), :cad, :tg, :sr, :d, :m, :pr, :desc, :by) RETURNING id');
        $st->execute($args + ['s' => !empty($f['start_now']) ? 'active' : 'draft', 'by' => $by]);
        $id = (int) $st->fetchColumn();
        if ($f['manager_member_id'] !== null) {
            $role = $f['manager_role_id'] ?? (int) one_value($pdo, "SELECT id FROM consulting_roles WHERE key = 'principal' AND archived_at IS NULL") ?: (int) one_value($pdo, 'SELECT id FROM consulting_roles WHERE archived_at IS NULL ORDER BY sort_order LIMIT 1');
            if ($role) {
                $pdo->prepare('INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from, created_by) VALUES (:e, :m, :r, :f, :by)')
                    ->execute(['e' => $id, 'm' => $f['manager_member_id'], 'r' => $role, 'f' => $f['start_date'], 'by' => $by]);
            }
        }
        return $id;
    }
    $pdo->prepare('UPDATE engagements SET client_id = :c, name = :n, kind = :k, start_date = :sd, end_date = :ed, currency = :cur, po_number = :po, rate_card_id = :rc, nte_amount = :nte, budget_hours = :bh,
                          budget_amount = :ba, ai_budget_amount = :ai, expense_policy = CAST(:ep AS jsonb), billing_cadence = :cad, time_grouping = :tg, share_receipts_with_client = :sr, department_id = :d,
                          manager_member_id = :m, projects_ref = :pr, description = :desc WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** A status move (activate/hold/close/reopen/cancel): the guard decides what is legal. Answers [before, after]. */
function move_engagement(PDO $pdo, int $id, string $to, ?string $reason, int $by): array
{
    $cur = engagement_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] === $to) {
        throw new DomainException($cur['number'] . ' is already ' . str_replace('_', ' ', $to) . '.');
    }
    $words = ['draft' => 'A draft', 'active' => 'An active', 'on_hold' => 'An on-hold', 'closed' => 'A closed', 'cancelled' => 'A cancelled'];
    try {
        $pdo->prepare('UPDATE engagements SET status = :s, closed_by = CASE WHEN :s2 = \'closed\' THEN :by ELSE closed_by END WHERE id = :id')->execute(['s' => $to, 's2' => $to, 'by' => $by, 'id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, ($words[$cur['status']] ?? 'This') . ' engagement cannot become ' . str_replace('_', ' ', $to) . '.'));
        }
        throw $e;
    }
    return [$cur['status'], $to];
}

/** Delete a draft (the trigger refuses anything else). */
function delete_engagement(PDO $pdo, int $id, int $by): string
{
    $cur = engagement_state($pdo, $id) ?? throw new DomainException('Not found.');
    try {
        $pdo->prepare('DELETE FROM engagements WHERE id = :id')->execute(['id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'Only a draft engagement is deleted.'));
        }
        throw $e;
    }
    return (string) $cur['number'];
}

/** Staff ($id null) or change a staffing. The EXCLUDE refusal is translated with the person's name. */
function save_staffing(PDO $pdo, ?int $id, int $engagementId, int $memberId, int $roleId, string $from, ?string $to, ?string $override, ?string $note, int $by): int
{
    $name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $memberId]);       // read before the write: after a refused INSERT the transaction is aborted
    $num = (string) one_value($pdo, 'SELECT number FROM engagements WHERE id = :e', ['e' => $engagementId]);
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from, valid_to, bill_rate_override, note, created_by) VALUES (:e, :m, :r, :f, :t, :o, :n, :by) RETURNING id');
            $st->execute(['e' => $engagementId, 'm' => $memberId, 'r' => $roleId, 'f' => $from, 't' => $to, 'o' => $override, 'n' => $note, 'by' => $by]);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE engagement_members SET consulting_role_id = :r, valid_from = :f, valid_to = :t, bill_rate_override = :o, note = :n WHERE id = :id AND engagement_id = :e')
            ->execute(['r' => $roleId, 'f' => $from, 't' => $to, 'o' => $override, 'n' => $note, 'id' => $id, 'e' => $engagementId]);
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23P01') {
            throw new DomainException($name . ' is already staffed on ' . $num . ' over those dates.');
        }
        throw $e;
    }
}

/** End a staffing on a date (new time from that date is refused; the entries stay). */
function end_staffing(PDO $pdo, int $id, string $to, int $by): array
{
    $cur = staffing_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['valid_to'] !== null && $cur['valid_to'] <= date('Y-m-d')) {
        throw new DomainException('That staffing already ended on ' . format_date($cur['valid_to']) . '.');
    }
    if ($to <= $cur['valid_from']) {
        throw new DomainException('The end is after the start (' . format_date($cur['valid_from']) . ').');
    }
    try {
        $pdo->prepare('UPDATE engagement_members SET valid_to = :t WHERE id = :id')->execute(['t' => $to, 'id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23P01') {
            throw new DomainException('That end runs into their next staffing.');
        }
        throw $e;
    }
    return $cur;
}

/** Set (or, with null, remove) a role's rate on this engagement. Answers the rate before. */
function save_role_rate(PDO $pdo, int $engagementId, int $roleId, ?string $rate, int $by): ?string
{
    $before = one_value($pdo, 'SELECT bill_rate FROM engagement_role_rates WHERE engagement_id = :e AND consulting_role_id = :r', ['e' => $engagementId, 'r' => $roleId]);
    if ($rate === null) {
        $pdo->prepare('DELETE FROM engagement_role_rates WHERE engagement_id = :e AND consulting_role_id = :r')->execute(['e' => $engagementId, 'r' => $roleId]);
    } else {
        $pdo->prepare('INSERT INTO engagement_role_rates (engagement_id, consulting_role_id, bill_rate) VALUES (:e, :r, :b) ON CONFLICT (engagement_id, consulting_role_id) DO UPDATE SET bill_rate = EXCLUDED.bill_rate')
            ->execute(['e' => $engagementId, 'r' => $roleId, 'b' => $rate]);
    }
    return $before === null ? null : (string) $before;
}

/** Add ($id null) or change a milestone. $f: name, description, amount, due_date, sort_order. */
function save_milestone(PDO $pdo, ?int $id, int $engagementId, array $f, int $by): int
{
    try {
        if ($id === null) {
            $st = $pdo->prepare('INSERT INTO milestones (engagement_id, name, description, amount, due_date, sort_order, created_by) VALUES (:e, :n, :d, :a, :due, :so, :by) RETURNING id');
            $st->execute(['e' => $engagementId, 'n' => $f['name'], 'd' => $f['description'], 'a' => $f['amount'], 'due' => $f['due_date'], 'so' => $f['sort_order'], 'by' => $by]);
            return (int) $st->fetchColumn();
        }
        $pdo->prepare('UPDATE milestones SET name = :n, description = :d, amount = :a, due_date = :due, sort_order = :so WHERE id = :id AND engagement_id = :e')
            ->execute(['n' => $f['name'], 'd' => $f['description'], 'a' => $f['amount'], 'due' => $f['due_date'], 'so' => $f['sort_order'], 'id' => $id, 'e' => $engagementId]);
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'That milestone cannot change.'));
        }
        throw $e;
    }
}

/** A milestone's move (deliver/accept/cancel); $fields: accepted_by, acceptance_note, cancel_reason. Answers [before, after]. */
function move_milestone(PDO $pdo, int $id, string $to, array $fields, int $by): array
{
    $cur = milestone_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] === $to) {
        throw new DomainException('Milestone "' . $cur['name'] . '" is already ' . $to . '.');
    }
    try {
        $pdo->prepare('UPDATE milestones SET status = :s, accepted_by = COALESCE(:ab, accepted_by), acceptance_note = COALESCE(:an, acceptance_note), cancel_reason = COALESCE(:cr, cancel_reason) WHERE id = :id')
            ->execute(['s' => $to, 'ab' => $fields['accepted_by'] ?? null, 'an' => $fields['acceptance_note'] ?? null, 'cr' => $fields['cancel_reason'] ?? null, 'id' => $id]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === 'P0001') {
            throw new DomainException(db_message($e, 'Milestone "' . $cur['name'] . '" cannot become ' . $to . '.'));
        }
        throw $e;
    }
    return [$cur['status'], $to];
}

function milestone_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, engagement_id, name, description, amount, due_date, status, sort_order, accepted_by, acceptance_note, cancel_reason FROM milestones WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['id', 'engagement_id', 'sort_order'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['accepted_by'] = $r['accepted_by'] === null ? null : (int) $r['accepted_by'];
    return $r;
}

/** Delete a planned milestone that was never accepted. */
function delete_milestone(PDO $pdo, int $id, int $by): string
{
    $cur = milestone_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($cur['status'] !== 'planned') {
        throw new DomainException('Milestone "' . $cur['name'] . '" is ' . $cur['status'] . '; only a planned one is deleted (cancel it instead).');
    }
    foreach ($pdo->query("SELECT id FROM attachments WHERE record_type = 'milestone' AND record_id = " . $id)->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        delete_attachment($pdo, (int) $aid);
    }
    $pdo->prepare("DELETE FROM notes WHERE record_type = 'milestone' AND record_id = :id")->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM milestones WHERE id = :id')->execute(['id' => $id]);
    return (string) $cur['name'];
}

/** ct_budget_alerts_check() for one engagement or every active one the caller manages. Answers [engagement_id => new alerts]. */
function check_budget_alerts(PDO $pdo, ?int $engagementId): array
{
    $ids = $engagementId !== null ? [$engagementId] : array_map('intval', $pdo->query("SELECT engagement_id FROM mcp_engagements WHERE status = 'active' AND i_manage")->fetchAll(PDO::FETCH_COLUMN));
    $out = [];
    $st = $pdo->prepare('SELECT ct_budget_alerts_check(:e)');
    foreach ($ids as $id) {
        $st->execute(['e' => $id]);
        $out[$id] = (int) $st->fetchColumn();
    }
    return $out;
}

function acknowledge_alert(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT engagement_id, kind, threshold_pct, acknowledged_at FROM budget_alerts WHERE id = :id');
    $st->execute(['id' => $id]);
    $a = $st->fetch();
    if ($a === false) {
        throw new DomainException('Not found.');
    }
    if ($a['acknowledged_at'] !== null) {
        throw new DomainException('That alert is already acknowledged.');
    }
    $pdo->prepare('UPDATE budget_alerts SET acknowledged_by = :by, acknowledged_at = now() WHERE id = :id')->execute(['by' => $by, 'id' => $id]);
    return ['engagement_id' => (int) $a['engagement_id'], 'kind' => $a['kind'], 'threshold_pct' => (int) $a['threshold_pct']];
}
