<?php
declare(strict_types=1);

/** The prelude of a usage handler: the feature's files; the charge a request names (seen → else 404); the log with the engagement's key on every row; the notices' words. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/statement.php';
require_once dirname(__DIR__) . '/documents/write.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/engagements/queries.php';
require_once dirname(__DIR__) . '/engagements/write.php';

/** The charge the request names, through the view: 404 when unseen. */
function usage_from_request(PDO $pdo): array
{
    $id = usage_id_from_request() ?? refuse(422, 'Say which usage charge.');
    return find_usage_charge($pdo, $id) ?? refuse(404, 'Usage charge not found.');
}

/** log_activity with the charge as the entity and the engagement's key. */
function usage_log(PDO $pdo, string $action, int $chargeId, ?int $engagementId, array $opts = []): void
{
    log_activity($pdo, $action, 'usage_charge', $chargeId, $opts + ['engagement_id' => $engagementId]);
}

/** The money of a charge row, for a log payload: number, amount (the billable amount), currency, cost_amount, never anything of a person's pay. */
function usage_money(array $u): array
{
    return ['number' => $u['number'], 'amount' => $u['billable_amount'], 'currency' => trim((string) $u['currency']), 'cost_amount' => $u['cost_amount'], 'billable_amount' => $u['billable_amount']];
}

/** "USG-000001 (216.00 USD)" for a sentence. */
function usage_label(array $u): string
{
    return $u['number'] . ' (' . fmt_money($u['billable_amount'], trim((string) $u['currency'])) . ')';
}

function usage_link(int $id): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/usage/' . $id;
}

/** The engagement a charge, rule or schedule names: seen (else 404), managed (else 403). Answers the engagement's row from the view. */
function usage_engagement_gate(PDO $pdo, int $engagementId): array
{
    require_can_see_engagement($engagementId);
    $e = find_engagement($pdo, $engagementId) ?? refuse(404, 'Engagement not found.');
    if (!$e['i_manage']) {
        refuse(403, 'You may not manage ' . $e['number'] . '; its manager, the practice\'s admin or Billing may.');
    }
    return $e;
}

/** The manager of an engagement (to tell), never the person who acted. */
function usage_manager_of(PDO $pdo, int $engagementId, int $except): ?int
{
    $m = one_value($pdo, 'SELECT manager_member_id FROM engagements WHERE id = :e', ['e' => $engagementId]);
    return $m !== null && (int) $m !== $except ? (int) $m : null;
}

/** Tell the engagement's manager that a charge was drafted (by an import, a schedule or a person other than them). */
function notify_usage_drafted(PDO $pdo, array $u, int $actor, string $how): void
{
    $mgr = usage_manager_of($pdo, (int) $u['engagement_id'], $actor);
    if ($mgr !== null) {
        queue_usage_notice($pdo, $mgr, 'usage_drafted', 'usage_charge', (int) $u['usage_charge_id'], ['title' => 'A usage charge ' . usage_label($u) . ' was drafted ' . $how, 'body' => 'Open it to approve or void: ' . usage_link((int) $u['usage_charge_id']),
            'dedupe' => 'usage-drafted:' . $u['usage_charge_id']]);
    }
}

/** A decimal field: a number of at least $min (and at most $max) as a plain string, or null when blank and $nullable; adds to $errors. $keep when left out. */
function ct_decimal(string $name, ?string $keep, float $min, float $max, string $label, array &$errors, bool $nullable = false, int $scale = 6): ?string
{
    if (!req_has($name)) {
        return $keep;
    }
    $raw = str_replace([',', ' '], ['', ''], (string) req_val($name));
    if ($raw === '') {
        if (!$nullable) {
            $errors[$name] = $label . ' is required.';
        }
        return $nullable ? null : $keep;
    }
    if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
        $errors[$name] = $label . ' is a number from ' . rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.') . ' to ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.';
        return $keep;
    }
    return number_format((float) $raw, $scale, '.', '');
}

/** A date field: YYYY-MM-DD; null when blank and $nullable; adds to $errors; $keep when left out. */
function ct_date(string $name, ?string $keep, string $label, array &$errors, bool $nullable = false): ?string
{
    if (!req_has($name)) {
        return $keep;
    }
    $v = (string) req_val($name);
    if ($v === '') {
        if (!$nullable) {
            $errors[$name] = $label . ' is a day (2026-03-02).';
        }
        return $nullable ? null : $keep;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) {
        $errors[$name] = $label . ' is a day (2026-03-02).';
        return $keep;
    }
    return $v;
}

/** The pricing fields shared by a charge, a rule and a schedule: pricing, markup_pct, resale_unit_price. Answers [pricing, markup, resale]. */
function ct_pricing_fields(?array $cur, array &$errors): array
{
    $pricing = $cur['pricing'] ?? 'cost';
    if (req_has('pricing')) {
        $pricing = (string) req_val('pricing');
        if (!isset(USAGE_PRICINGS[$pricing])) {
            $errors['pricing'] = 'Pricing is cost, markup or resale.';
            $pricing = $cur['pricing'] ?? 'cost';
        }
    }
    $markup = ct_decimal('markup_pct', $cur['markup_pct'] ?? '0.00', 0, 500, 'The markup', $errors, true, 2) ?? '0.00';
    $resale = ct_decimal('resale_unit_price', $cur['resale_unit_price'] ?? null, 0, 1000000, 'The resale price', $errors, true, 6);
    if ($pricing === 'resale' && $resale === null && !isset($errors['resale_unit_price'])) {
        $errors['resale_unit_price'] = 'A resale price per unit (per million tokens for tokens) is needed when the pricing is resale.';
    }
    if ($pricing !== 'resale') {
        $resale = $resale ?? null;
    }
    return [$pricing, $markup, $resale];
}

/** A kernel id field (agent, department, application): a whole number or null. Two names are read (the form's short one and the column's). */
function ct_kernel_id(string $short, string $column, ?int $keep, array &$errors, string $label): ?int
{
    $name = req_has($short) ? $short : $column;
    if (!req_has($name)) {
        return $keep;
    }
    $v = (string) req_val($name);
    if ($v === '' || $v === '0') {
        return null;
    }
    if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1) {
        $errors[$name] = $label . ' is the kernel\'s number for it.';
        return $keep;
    }
    return (int) $v;
}
