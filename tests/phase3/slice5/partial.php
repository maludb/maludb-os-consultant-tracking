<?php
/** Proof — _partial=1 (the agents' door): under an action token a charge's save with only the fields that change keeps the rest — the engagement, kind, provider, description, period, unit, unit cost, pricing, markup, resale price, billable; the database recomputes the money; another person's token cannot reach the charge. */
require __DIR__ . '/lib.php';
[$acme, $beta] = usage_world();
$marcoTok = ['X-Action-Token: ' . person_token(27)];
$priyaTok = ['X-Action-Token: ' . person_token(26)];
[$c, $b] = act_token('/usage/save.php', ['engagement' => $acme, 'kind' => 'model_usage', 'provider' => provider('anthropic'), 'description' => 'SMOKE partial tokens', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'quantity' => '2000000',
    'unit' => 'tokens', 'unit_cost' => '0.000003', 'pricing' => 'resale', 'resale_unit_price' => '15', 'billable' => 'no'], $marcoTok);
$u = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $u > 0, 'usage_charge_draft under a person\'s action token (no session, no CSRF)');
$x = usage_row($u);
ok($x['billable_amount'] === '30.00' && $x['billable'] === false, 'a resale charge, not billable');
[$c, $b] = act_token('/usage/save.php', ['charge' => $u, 'quantity' => '3000000', '_partial' => '1'], $marcoTok);
$x = usage_row($u);
ok($c === 200 && $x['quantity'] === '3000000.0000' && $x['cost_amount'] === '9.00' && $x['billable_amount'] === '45.00' && $x['unit_cost'] === '0.000003' && $x['resale_unit_price'] === '15.000000' && $x['pricing'] === 'resale' && $x['unit'] === 'tokens' && $x['kind'] === 'model_usage'
    && $x['description'] === 'SMOKE partial tokens' && $x['period_start'] === '2026-09-01' && $x['period_end'] === '2026-09-30' && (int) $x['engagement_id'] === $acme && (int) $x['provider_id'] === provider('anthropic') && $x['billable'] === false,
    '_partial=1 with the quantity only keeps the engagement, kind, provider, description, period, unit, unit cost, pricing, resale price and billable; the database recomputes cost 9.00 and billable 45.00');
$since = last_activity_id();
[$c, $b] = act_token('/usage/save.php', ['charge' => $u, 'description' => 'SMOKE partial renamed', '_partial' => '1'], $marcoTok);
$log = activity('usage_charge.draft', $since);
ok($c === 200 && usage_row($u)['description'] === 'SMOKE partial renamed' && usage_row($u)['billable_amount'] === '45.00' && count($log) === 1, 'the description alone: money unchanged, one log row');
[$c, $b] = act_token('/usage/save.php', ['charge' => $u, 'pricing' => 'markup', 'markup_pct' => '50', '_partial' => '1'], $marcoTok);
$x = usage_row($u);
ok($c === 200 && $x['pricing'] === 'markup' && $x['markup_pct'] === '50.00' && $x['billable_amount'] === '13.50' && $x['resale_unit_price'] === '15.000000', 'pricing → markup 50 %: billable 13.50 (the old resale price is kept, unused)');
[$c, $b] = act_token('/usage/save.php', ['charge' => $u, 'billable' => 'yes', '_partial' => '1'], $marcoTok);
ok($c === 200 && usage_row($u)['billable'] === true, 'billable alone');
[$c, $b] = act_token('/usage/save.php', ['charge' => $u, 'quantity' => '1', '_partial' => '1'], $priyaTok);
ok($c === 403, 'Priya\'s token (no usage.write): 403');
[$c, $b] = act_token('/usage/save.php', ['charge' => $u, 'quantity' => '1', '_partial' => '1'], ['X-Action-Token: ' . person_token(28)]);
ok($c === 200, 'Bea\'s token (Billing manages every engagement): 200');
[$c, $b] = act_token('/usage/save.php', ['charge' => 99999999, 'quantity' => '1', '_partial' => '1'], $marcoTok);
ok($c === 404, 'a charge that is not there: 404');
finish();
