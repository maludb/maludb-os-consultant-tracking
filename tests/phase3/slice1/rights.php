<?php
/** Proof — rights (spec "Proof"): who reaches which screen, in the right's sentence; the cost-rate table is the admin's and Finance's; the menu follows. */
require __DIR__ . '/lib.php';
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $owner = as_member(1);

echo "1. The screens by role\n";
$matrix = [   // route => the roles that get 200 (user Priya, manager Marco, billing Bea, finance Ann, admin the owner)
    '/clients/' => 'user manager billing finance admin', '/clients/new' => 'billing admin', '/settings/rate-cards/' => 'manager billing finance admin', '/settings/rate-cards/new' => 'billing admin',
    '/settings/cost-rates/' => 'billing finance admin', '/settings/capacity/' => 'admin', '/settings/roles/' => 'admin', '/settings/activity-types/' => 'admin', '/settings/categories/' => 'admin',
    '/settings/providers/' => 'admin', '/settings/tax-rates/' => 'admin', '/settings/general' => 'admin', '/settings/numbering' => 'admin',
];
$who = ['user' => $priya, 'manager' => $marco, 'billing' => $bea, 'finance' => $ann, 'admin' => $owner];
$wrong = []; $n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $j) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($j, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks answer 200 or 403 as the rights say" . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
ok(str_contains(html_entity_decode(page($priya, '/clients/new')['body'], ENT_QUOTES), 'You may not manage clients.'), 'a Consultant on the client form: "You may not manage clients."');
ok(str_contains(html_entity_decode(page($marco, '/settings/cost-rates/')['body'], ENT_QUOTES), "You may not see the cost-rate table; it is the admin's, Billing's and Finance's."), 'a Manager on the cost rates: refused in words (cost.view alone is not enough — it is the manager\'s own margin, never the table)');
ok(str_contains(html_entity_decode(page($bea, '/settings/roles/')['body'], ENT_QUOTES), 'You may not change the settings.'), 'Billing on the consulting roles: "You may not change the settings."');
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
ok(!in_array('cost-rates', $menu($marco), true) && !in_array('cost-rates', $menu($priya), true) && in_array('cost-rates', $menu($bea), true) && in_array('cost-rates', $menu($ann), true) && in_array('cost-rates', $menu($owner), true), 'the menu hides Cost rates from a Manager and a Consultant; Billing, Finance and the admin see it (the derived right cost.table)');
ok(in_array('rate-cards', $menu($bea), true) && in_array('clients', $menu($priya), true), 'Billing sees Rate cards; a Consultant sees Clients (their engagements\' clients)');

echo "2. The writes by role\n";
$writes = ['/clients/save.php' => ['name' => 'SMOKE x'], '/clients/archive.php' => ['client' => 1], '/clients/contacts/save.php' => ['client' => 1, 'name' => 'x'], '/settings/rate-cards/save.php' => ['name' => 'x', 'currency' => 'USD'],
    '/settings/rate-cards/rate.php' => ['rate_card' => 1, 'role' => 1, 'bill_rate' => '1'], '/settings/cost-rates/save.php' => ['member' => 26, 'hourly_cost' => '1', 'valid_from' => '2026-01-01'], '/settings/capacity/save.php' => ['member' => 26, 'hours_per_week' => '40', 'valid_from' => '2026-01-01'],
    '/settings/roles/save.php' => ['title' => 'x'], '/settings/activity-types/save.php' => ['label' => 'x'], '/settings/categories/save.php' => ['label' => 'x'], '/settings/providers/save.php' => ['name' => 'x'], '/settings/tax-rates/save.php' => ['name' => 'x', 'rate' => '1'],
    '/settings/general.php' => ['firm_name' => 'x'], '/settings/numbering.php' => ['kind' => 'invoice', 'prefix' => 'X-']];
$wrong = [];
foreach ($writes as $path => $form) { [$c, $b] = act($priya, $path, $form); if ($c !== 403) { $wrong[] = "$path $c"; } }
ok($wrong === [], count($writes) . ' writes → 403 for a Consultant' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
$wrong = [];
foreach (['/settings/cost-rates/save.php' => ['member' => 26, 'hourly_cost' => '1', 'valid_from' => '2026-01-01'], '/settings/roles/save.php' => ['title' => 'x'], '/settings/general.php' => ['firm_name' => 'x'], '/clients/delete.php' => ['client' => 1], '/settings/rate-cards/delete.php' => ['rate_card' => 1]] as $path => $form) {
    [$c, $b] = act($bea, $path, $form); if ($c !== 403) { $wrong[] = "$path $c"; }
}
ok($wrong === [], 'Billing may not set a cost rate, change the vocabulary or the settings, nor delete a client or a card (the admin\'s): 403' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
[$c, $b] = act($ann, '/clients/save.php', ['name' => 'SMOKE by Finance']);
ok($c === 403 && msg($b) === 'You may not manage clients.', 'Finance (read) may not add a client: 403 in words');
[$c, $b] = act($marco, '/settings/rate-cards/save.php', ['name' => 'SMOKE by Marco', 'currency' => 'USD']);
ok($c === 403 && msg($b) === 'You may not manage rate cards.', 'a Manager may not add a rate card: 403 "You may not manage rate cards."');
finish();
