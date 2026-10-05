<?php
declare(strict_types=1);

/** The JSON shapes of usage, rules, schedules and the statement (whitelists over the views' rows — never a raw row). The cost is whatever the view let the caller read: null when hidden. */
function present_usage_charge(array $u): array
{
    return ['usage_charge_id' => $u['usage_charge_id'], 'number' => $u['number'], 'engagement' => ['engagement_id' => $u['engagement_id'], 'number' => $u['engagement_number']],
            'period_start' => $u['period_start'], 'period_end' => $u['period_end'], 'kind' => $u['kind'], 'provider' => $u['provider_id'] === null ? null : ['provider_id' => $u['provider_id'], 'name' => $u['provider_name']],
            'description' => $u['description'], 'quantity' => $u['quantity'], 'unit' => $u['unit'], 'unit_cost' => $u['unit_cost'], 'cost_amount' => $u['cost_amount'], 'pricing' => $u['pricing'],
            'markup_pct' => $u['markup_pct'], 'resale_unit_price' => $u['resale_unit_price'], 'billable_amount' => $u['billable_amount'], 'billable' => $u['billable'], 'currency' => $u['currency'],
            'source' => $u['source'], 'import_id' => $u['import_id'], 'statement_id' => $u['statement_id'], 'schedule_id' => $u['schedule_id'], 'source_ref' => $u['source_ref'], 'status' => $u['status'],
            'approved_at' => json_ts($u['approved_at']), 'approved_by' => $u['approved_by'], 'voided_at' => json_ts($u['voided_at']), 'voided_by' => $u['voided_by'], 'void_reason' => $u['void_reason'], 'billed' => $u['billed']];
}

function present_usage_totals(array $t): array
{
    return ['currency' => $t['currency'], 'charges' => $t['charges'], 'billable_amount' => $t['billable_amount'], 'cost_amount' => $t['cost_amount']];
}

function present_usage_import(array $i): array
{
    return ['import_id' => $i['import_id'], 'provider' => ['provider_id' => $i['provider_id'], 'name' => $i['provider_name']], 'file_name' => $i['file_name'], 'row_count' => $i['row_count'], 'inserted' => $i['inserted'],
            'skipped' => $i['skipped'], 'detail' => $i['detail'], 'created_at' => json_ts($i['created_at'])];
}

function present_csv_plan_item(array $p): array
{
    return ['period_start' => $p['period_start'], 'period_end' => $p['period_end'], 'quantity' => $p['quantity'], 'cost' => $p['cost'], 'description' => $p['description'], 'rows' => $p['rows'], 'state' => $p['state'], 'reason' => $p['reason']];
}

function present_allocation_rule(array $r): array
{
    return ['rule_id' => $r['rule_id'], 'name' => $r['name'], 'kernel_agent_id' => $r['kernel_agent_id'], 'kernel_department_id' => $r['kernel_department_id'], 'kernel_application_id' => $r['kernel_application_id'],
            'engagement' => ['engagement_id' => $r['engagement_id'], 'number' => $r['engagement_number']], 'share_pct' => $r['share_pct'], 'pricing' => $r['pricing'], 'markup_pct' => $r['markup_pct'],
            'resale_unit_price' => $r['resale_unit_price'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'active' => $r['active'], 'last' => $r['last'] ?? null];
}

function present_schedule(array $r): array
{
    return ['schedule_id' => $r['schedule_id'], 'engagement' => ['engagement_id' => $r['engagement_id'], 'number' => $r['engagement_number']], 'kind' => $r['kind'],
            'provider' => $r['provider_id'] === null ? null : ['provider_id' => $r['provider_id'], 'name' => $r['provider_name']], 'description' => $r['description'], 'quantity' => $r['quantity'], 'unit' => $r['unit'],
            'unit_cost' => $r['unit_cost'], 'pricing' => $r['pricing'], 'markup_pct' => $r['markup_pct'], 'resale_unit_price' => $r['resale_unit_price'], 'cadence' => $r['cadence'], 'every_n' => $r['every_n'],
            'next_date' => $r['next_date'], 'end_date' => $r['end_date'], 'last_run_at' => json_ts($r['last_run_at']), 'run_count' => $r['run_count'], 'active' => $r['active'], 'due' => $r['due']];
}

function present_statement(array $s): array
{
    return ['statement_id' => $s['ai_statement_id'], 'period' => $s['period'], 'period_start' => $s['period_start'], 'period_end' => $s['period_end'], 'kernel_status' => $s['kernel_status'], 'status' => $s['status'],
            'pulled_at' => json_ts($s['pulled_at']), 'allocated_at' => json_ts($s['allocated_at']), 'late_calls' => $s['late_calls'], 'statement_amount' => $s['statement_amount'], 'allocated_amount' => $s['allocated_amount'],
            'remainder' => $s['remainder'], 'lines' => $s['lines'], 'charges' => $s['charges'], 'document_hash' => $s['document_hash']];
}

function present_statement_line(array $l): array
{
    return ['line_id' => $l['line_id'], 'line_no' => $l['line_no'], 'provider' => $l['provider'], 'model' => $l['model'], 'agent' => $l['agent'], 'kernel_agent_id' => $l['kernel_agent_id'], 'department' => $l['department'],
            'kernel_department_id' => $l['kernel_department_id'], 'application' => $l['application'], 'kernel_application_id' => $l['kernel_application_id'], 'calls' => $l['calls'], 'tokens' => $l['tokens'],
            'amount' => $l['amount'], 'currency' => $l['currency'], 'allocated_amount' => $l['allocated_amount'], 'remainder' => $l['remainder'], 'note' => $l['note']];
}

// ---- the words screens use ----------------------------------------------------------------------------------------------------------------------------------------------

/** A quantity for a screen: whole when whole (2,000,000), else up to four decimals, with the unit's name. */
function fmt_qty(string|int|float|null $q, string $unit): string
{
    $n = (float) $q;
    $s = abs($n - round($n)) < 0.00005 ? number_format($n, 0, '.', ',') : rtrim(rtrim(number_format($n, 4, '.', ','), '0'), '.');
    return $s . ' ' . (USAGE_UNITS[$unit] ?? $unit);
}

/** The pricing chip's words: none for cost, "+20 %" for a markup, "15 /M" for a resale price per million tokens (per unit otherwise). [label, tone] or null. */
function pricing_chip(array $u): ?array
{
    return match ($u['pricing']) {
        'markup' => ['+' . days_label($u['markup_pct']) . ' %', 'info'],
        'resale' => [days_label($u['resale_unit_price']) . ($u['unit'] === 'tokens' ? ' /M' : ' /unit'), 'primary'],
        default => null,
    };
}

/** The money line of a cost: the amount, or an em dash when the view hides it from the caller. */
function cost_cell(array $u): string
{
    return $u['cost_amount'] === null ? '—' : fmt_money($u['cost_amount'], $u['currency']);
}
