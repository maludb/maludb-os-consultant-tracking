<?php
declare(strict_types=1);

function present_rate_card(array $r): array
{
    $out = ['rate_card_id' => $r['rate_card_id'], 'name' => $r['name'], 'currency' => $r['currency'], 'effective_from' => $r['effective_from'], 'description' => $r['description'],
            'archived' => $r['archived_at'] !== null, 'rate_count' => $r['rate_count'], 'engagement_count' => $r['engagement_count'], 'client_count' => $r['client_count']];
    if (isset($r['rates'])) {
        $out['rates'] = array_map(static fn (array $x): array => ['consulting_role_id' => $x['consulting_role_id'], 'role_title' => $x['role_title'], 'role_key' => $x['role_key'], 'bill_rate' => $x['bill_rate']], $r['rates']);
        $out['engagements'] = array_map(static fn (array $e): array => ['engagement_id' => (int) $e['engagement_id'], 'number' => $e['number'], 'name' => $e['name'], 'client_name' => $e['client_name'], 'status' => $e['status']], $r['engagements']);
        $out['clients'] = array_map(static fn (array $c): array => ['client_id' => (int) $c['client_id'], 'name' => $c['name']], $r['clients']);
    }
    return $out;
}

/** A person's cost-rate ranges — the JSON of the admin's screen (the view already refused everyone else). */
function present_cost_rates(array $p): array
{
    return ['member_id' => $p['member_id'], 'member_name' => $p['member_name'], 'ranges' => $p['ranges']];
}

function present_capacity(array $p): array
{
    return ['member_id' => $p['member_id'], 'member_name' => $p['member_name'], 'ranges' => $p['ranges']];
}
