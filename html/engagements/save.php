<?php
declare(strict_types=1);
/**
 * Actions `engagement_save` / `engagement_update` (by the presence of `engagement`; log `engagement.create` / `engagement.update` — changed fields): engagements.manage; an update
 * needs to manage it. The kind fixes the form (a fixed bid carries no NTE); the manager defaults to the creator, the practice to the creator's primary department, the currency to
 * the client's; `start_now` activates on create; the manager is staffed as principal on create.
 */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
ct_handler_begin();
require_right('engagements.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('engagement') ?? request_integer('engagement_id');
$cur = null;
$seen = null;
if ($id !== null) {
    $seen = engagement_from_request($pdo, $id);
    require_manages($seen);
    $cur = engagement_state($pdo, $id);
    if (in_array($cur['status'], ['closed', 'cancelled'], true)) {
        refuse(422, $cur['number'] . ' is ' . $cur['status'] . '; reopen it to change it.');
    }
}
$settings = find_settings($pdo);
$errors = [];
$clientField = req_has('client') ? 'client' : 'client_id';
$client = ct_ref($pdo, $clientField, $cur['client_id'] ?? null, 'SELECT 1 FROM clients WHERE id = :id AND archived_at IS NULL', 'client', $errors, false);   // any live client: a Manager opens engagements for clients they have none on yet (the view guards the contact details, not the name)
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 160) {
    $errors['name'] = 'Give the engagement a name of up to 160 characters.';
}
$kind = req_has('kind') ? (string) req_val('kind') : (string) ($cur['kind'] ?? '');
if ($kind === 'retainer') {
    $errors['kind'] = 'Retainers are not in this version: make it time-and-materials or fixed bid.';
} elseif (!isset(ENGAGEMENT_KINDS[$kind])) {
    $errors['kind'] = 'The kind is time_and_materials or fixed_bid.';
}
$date = static function (string $k, ?string $keep, bool $required) use (&$errors): ?string {
    if (!req_has($k)) {
        return $keep;
    }
    $d = request_date($k);
    if ($d === false || ($d === null && $required)) {
        $errors[$k] = str_replace('_', ' ', ucfirst($k)) . ' is a date (2026-01-01).';
        return $keep;
    }
    return $d;
};
$start = $date('start_date', $cur['start_date'] ?? date('Y-m-d'), true) ?? date('Y-m-d');
$end = $date('end_date', $cur['end_date'] ?? null, false);
if ($end !== null && $end < $start) {
    $errors['end_date'] = 'The end is on or after the start.';
}
$clientRow = $client === null ? null : $pdo->query('SELECT currency, default_rate_card_id FROM clients WHERE id = ' . $client)->fetch();
$currency = $cur['currency'] ?? null;
if (req_has('currency') && (string) req_val('currency') !== '') {
    $currency = strtoupper(trim((string) req_val('currency')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors['currency'] = 'The currency is a three-letter code (USD).';
    }
}
$currency ??= $clientRow['currency'] ?? $settings['base_currency'];
$po = req_has('po_number') ? ((string) req_val('po_number') === '' ? null : mb_substr((string) req_val('po_number'), 0, 60)) : ($cur['po_number'] ?? null);
$cardField = req_has('rate_card') ? 'rate_card' : 'rate_card_id';
$card = ct_ref($pdo, $cardField, $cur['rate_card_id'] ?? null, 'SELECT 1 FROM rate_cards WHERE id = :id AND archived_at IS NULL', 'rate card', $errors);
$money = static function (string $k, ?string $keep, string $label, bool $positive = false) use (&$errors): ?string {
    if (!req_has($k)) {
        return $keep;
    }
    if ((string) req_val($k) === '') {
        return null;
    }
    $v = parse_money(req_val($k));
    if ($v === null || (float) $v < 0 || ($positive && (float) $v <= 0)) {
        $errors[$k] = $label . ' is an amount' . ($positive ? ' above zero' : ' of zero or more') . '.';
        return $keep;
    }
    return $v;
};
$nte = $money('nte_amount', $cur['nte_amount'] ?? null, 'The not-to-exceed', true);
$budgetHours = null;
if (req_has('budget_hours')) {
    $raw = str_replace(',', '', (string) req_val('budget_hours'));
    if ($raw !== '') {
        if (!is_numeric($raw) || (float) $raw <= 0) {
            $errors['budget_hours'] = 'The budget hours are a number above zero.';
        } else {
            $budgetHours = number_format((float) $raw, 2, '.', '');
        }
    }
} else {
    $budgetHours = $cur['budget_hours'] ?? null;
}
$budgetAmount = $money('budget_amount', $cur['budget_amount'] ?? null, $kind === 'fixed_bid' ? 'The fee' : 'The budget');
$aiBudget = $money('ai_budget_amount', $cur['ai_budget_amount'] ?? null, 'The AI budget');
if ($kind === 'fixed_bid' && $nte !== null) {
    $errors['nte_amount'] = 'A fixed bid carries no not-to-exceed; the fee is the cap.';
}
$policy = $cur['expense_policy'] ?? [];
if (req_has('markup_pct')) {
    $raw = str_replace(['%', ','], '', (string) req_val('markup_pct'));
    if ($raw === '') {
        unset($policy['markup_pct']);
    } elseif (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 500) {
        $errors['markup_pct'] = 'The markup is a percentage from 0 to 500.';
    } else {
        $policy['markup_pct'] = (float) number_format((float) $raw, 2, '.', '');
    }
}
if (req_has('requires_receipt_above')) {
    $raw = str_replace(',', '', (string) req_val('requires_receipt_above'));
    if ($raw === '') {
        unset($policy['requires_receipt_above']);
    } elseif (!is_numeric($raw) || (float) $raw < 0) {
        $errors['requires_receipt_above'] = 'The receipt threshold is an amount of zero or more.';
    } else {
        $policy['requires_receipt_above'] = (float) number_format((float) $raw, 2, '.', '');
    }
}
if (req_has('expenses_billable_default')) {
    $policy['billable_default'] = ct_yes('expenses_billable_default', true);
}
$cadence = req_has('billing_cadence') ? (string) req_val('billing_cadence') : (string) ($cur['billing_cadence'] ?? 'monthly');
if (!isset(BILLING_CADENCES[$cadence])) {
    $errors['billing_cadence'] = 'The cadence is one of: ' . implode(', ', array_keys(BILLING_CADENCES)) . '.';
}
$grouping = $cur['time_grouping'] ?? null;
if (req_has('time_grouping')) {
    $grouping = (string) req_val('time_grouping') === '' ? null : (string) req_val('time_grouping');
    if ($grouping !== null && !isset(TIME_GROUPINGS[$grouping])) {
        $errors['time_grouping'] = 'The grouping is one of: ' . implode(', ', array_keys(TIME_GROUPINGS)) . '.';
    }
}
$share = $cur['share_receipts_with_client'] ?? null;
if (req_has('share_receipts_with_client')) {
    $share = (string) req_val('share_receipts_with_client') === '' ? null : ct_yes('share_receipts_with_client');
}
$deptField = req_has('department') ? 'department' : 'department_id';
$dept = ct_ref($pdo, $deptField, $cur['department_id'] ?? null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'practice', $errors);
if ($cur === null && !req_has($deptField)) {
    $dept = ((int) one_value($pdo, 'SELECT ct_primary_department(:m)', ['m' => $me])) ?: null;
}
$managerField = req_has('manager') ? 'manager' : 'manager_member_id';
$manager = ct_ref($pdo, $managerField, $cur['manager_member_id'] ?? null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL AND member_kind = 'human'", 'manager', $errors);
if ($cur === null && !req_has($managerField)) {
    $manager = $me;
}
$ref = req_has('projects_ref') ? ((string) req_val('projects_ref') === '' ? null : mb_substr((string) req_val('projects_ref'), 0, 80)) : ($cur['projects_ref'] ?? null);
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 4000)) : ($cur['description'] ?? null);
$startNow = $cur === null && ct_yes('start_now', false);
$managerRole = $cur === null ? ct_ref($pdo, 'manager_role', null, 'SELECT 1 FROM consulting_roles WHERE id = :id AND archived_at IS NULL', 'consulting role', $errors) : null;
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['client_id' => $client, 'name' => $name, 'kind' => $kind, 'start_date' => $start, 'end_date' => $end, 'currency' => $currency, 'po_number' => $po, 'rate_card_id' => $card, 'nte_amount' => $nte,
      'budget_hours' => $budgetHours, 'budget_amount' => $budgetAmount, 'ai_budget_amount' => $aiBudget, 'expense_policy' => $policy, 'billing_cadence' => $cadence, 'time_grouping' => $grouping,
      'share_receipts_with_client' => $share, 'department_id' => $dept, 'manager_member_id' => $manager, 'projects_ref' => $ref, 'description' => $description, 'start_now' => $startNow, 'manager_role_id' => $managerRole];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    try {
        $newId = save_engagement($pdo, $id, $f, $me);
    } catch (PDOException $x) {
        if ((string) $x->getCode() === 'P0001') {
            throw new DomainException(db_message($x, 'That engagement could not be saved.'));
        }
        if ((string) $x->getCode() === '23514') {
            throw new DomainException('A fixed bid carries no not-to-exceed; the fee is the cap.');
        }
        throw $x;
    }
    $after = engagement_state($pdo, $newId);
    $keys = ['engagement_id' => $newId, 'client_id' => $after['client_id'], 'department_id' => $after['department_id']];
    if ($cur === null) {
        log_activity($pdo, 'engagement.create', 'engagement', $newId, ['after' => engagement_loggable($after)] + $keys);
    } else {
        $d = ct_diff(engagement_loggable($cur), engagement_loggable($after));
        if ($d['after'] !== []) {
            log_activity($pdo, 'engagement.update', 'engagement', $newId, ['before' => $d['before'], 'after' => $d['after']] + $keys);
        }
    }
    $pdo->commit();
    return $newId;
});
$number = (string) one_value($pdo, 'SELECT number FROM engagements WHERE id = :id', ['id' => $newId]);
ct_done(($id === null ? 'Opened ' : 'Saved ') . $number . ' · ' . $f['name'] . ($f['start_now'] ? ' (active)' : ''), $newId, ct_land(return_path('/engagements/' . $newId), 'saved'), 'engagementChanged', ['engagement_id' => $newId, 'number' => $number]);
