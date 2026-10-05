<?php
/** Proof — who sees (spec "Proof", 4): Priya sees what she is staffed on, not the draft; her card shows the bars, never a cost or the AI amount; Dana sees Acme alone; Ann (Finance) everything with i_manage false; Bea everything with i_manage true; Marco's Overview shows the AI amount; the practice admin rule. */
require __DIR__ . '/lib.php';
$marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $bea = as_member(28); $ann = as_member(29); $owner = as_member(1);
[$acme, $card] = fixture_client();
[$beta, ] = fixture_client('SMOKE Beta', 'BETA');
[$c, $b] = act($marco, '/engagements/save.php', ['client' => $acme, 'name' => 'SMOKE Vis Acme', 'kind' => 'time_and_materials', 'budget_hours' => '100', 'budget_amount' => '20000', 'ai_budget_amount' => '500', 'start_now' => 'yes']);
$acmeEng = (int) $b['record_id'];
[$c, $b] = act($marco, '/engagements/save.php', ['client' => $beta, 'name' => 'SMOKE Vis Beta', 'kind' => 'fixed_bid', 'budget_amount' => '9000', 'start_now' => 'yes']);
$betaEng = (int) $b['record_id'];
[$c, $b] = act($marco, '/engagements/save.php', ['client' => $acme, 'name' => 'SMOKE Vis Draft', 'kind' => 'time_and_materials', 'start_now' => 'no']);
$draft = (int) $b['record_id'];
act($marco, '/engagements/staffing/save.php', ['engagement' => $acmeEng, 'member' => 26, 'role' => role('consultant'), 'valid_from' => '2026-01-01']);
act($marco, '/engagements/staffing/save.php', ['engagement' => $betaEng, 'member' => 26, 'role' => role('consultant'), 'valid_from' => '2026-01-01']);
act($marco, '/engagements/staffing/save.php', ['engagement' => $acmeEng, 'member' => 30, 'role' => role('analyst'), 'valid_from' => '2026-01-01']);

echo "1. The Consultants\n";
[$c, $d] = screen($priya, '/engagements/');
$ids = array_column($d['engagements'], 'engagement_id');
ok($c === 200 && in_array($acmeEng, $ids, true) && in_array($betaEng, $ids, true) && !in_array($draft, $ids, true), 'Priya lists Acme and Beta (staffed), not the draft');
$card = array_values(array_filter($d['engagements'], fn ($e) => $e['engagement_id'] === $acmeEng))[0];
ok($card['i_am_staffed'] === true && $card['i_manage'] === false && $card['budget']['budget_hours'] === '100.00' && $card['budget']['budget_amount'] === '20000.00' && $card['budget']['ai_budget_amount'] === 'set' && $card['budget']['ai_cost'] === null && $card['ai_budget_amount'] === 'set' && $card['rate_card_id'] === null, 'her card: the hours and fee bars; the AI budget only "set", no AI cost, no card');
$body = page($priya, '/engagements/' . $acmeEng)['body'];
ok(str_contains($body, 'id="budget-hours-' . $acmeEng . '"') && str_contains($body, 'AI and hosting budget: set') && !str_contains($body, '500.00') && !str_contains($body, 'id="engagement-view-edit-btn"') && !str_contains($body, 'id="engagement-view-moves"'), 'her page: the bars, "AI and hosting budget: set" without the figure, no Edit, no moves');
ok(page($priya, '/engagements/' . $draft)['code'] === 404 && page($priya, '/engagements/' . $acmeEng . '/edit')['code'] === 403, 'the draft is 404 to her; the edit form 403');
[$c, $d] = screen($dana, '/engagements/');
ok($c === 200 && array_column($d['engagements'], 'engagement_id') === [$acmeEng], 'Dana sees Acme alone');
[, $r] = sign_on(40);   // the Clerk is an agent: the hand-off is for people
ok($r['code'] === 403, 'an agent (the Clerk) cannot sign on to a person\'s screens: 403 — it reads through the MCP (proven in slice 1)');

echo "2. Finance, Billing, the admin\n";
[$c, $d] = screen($ann, '/engagements/');
ok($c === 200 && count($d['engagements']) >= 3 && in_array($draft, array_column($d['engagements'], 'engagement_id'), true) && array_unique(array_column($d['engagements'], 'i_manage')) === [false], 'Ann (Finance) lists every one, the draft included, with i_manage false');
[$c, $d] = screen($ann, '/engagements/' . $acmeEng);
ok($d['engagement']['ai_budget_amount'] === '500.00' && $d['engagement']['budget']['ai_cost'] !== null && $d['engagement']['role_rates']['card_name'] === 'SMOKE Standard' && $d['may']['manage'] === false, 'she reads the AI amount (cost.view) and the card (rates.view), may not manage');
[$c, $b] = act($ann, '/engagements/hold.php', ['engagement' => $acmeEng]);
ok($c === 403, 'and may not move one: 403');
[$c, $d] = screen($bea, '/engagements/');
ok($c === 200 && array_unique(array_column($d['engagements'], 'i_manage')) === [true], 'Bea (Billing) lists every one with i_manage true');
[$c, $d] = screen($marco, '/engagements/' . $acmeEng);
ok($d['engagement']['ai_budget_amount'] === '500.00' && $d['may']['manage'] === true && str_contains(page($marco, '/engagements/' . $acmeEng)['body'], 'id="budget-ai-' . $acmeEng . '"'), 'Marco\'s Overview shows the AI budget\'s amount (his cost.view) and the AI bar');
[$c, $d] = screen($owner, '/engagements/');
ok($c === 200 && count($d['engagements']) >= 3, 'the super-admin lists every one');

echo "3. The practice admin\n";
kernel_state(function ($s) { $s['incremental'] = incr(['memberships' => [['member_id' => 30, 'department_id' => 7, 'is_admin' => true, 'is_primary' => false, 'joined_at' => '2026-01-01T00:00:00Z', 'left_at' => null]]], '2026-04-01T00:00:00.000000Z'); return $s; });
sync();
ok((bool) one("SELECT is_admin FROM department_members WHERE member_id = 30 AND department_id = 7"), 'the feed makes Dana an admin of AI Strategy');
[$c, $d] = screen($dana, '/engagements/');
ok(in_array($draft, array_column($d['engagements'], 'engagement_id'), true) && in_array($betaEng, array_column($d['engagements'], 'engagement_id'), true), 'as the practice\'s admin she now sees its engagements, the draft included');
[$c, $d] = screen($dana, '/engagements/' . $betaEng);
ok($d['engagement']['i_manage'] === false, 'but manages none: she holds no engagements.manage (a Consultant)');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 30, 'role' => 'manager', 'roles' => ['manager', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-04-02T00:00:00.000000Z'); return $s; });
sync();
[$c, $d] = screen($dana, '/engagements/' . $betaEng);
ok($d['engagement']['i_manage'] === true, 'given the Manager role, she manages the practice\'s engagements');
[$c, $b] = act($dana, '/engagements/staffing/save.php', ['engagement' => $betaEng, 'member' => 30, 'role' => role('analyst')]);
ok($c === 200, 'and may staff them');
kernel_state(function ($s) { $s['incremental'] = incr(['memberships' => [['member_id' => 30, 'department_id' => 7, 'is_admin' => false, 'is_primary' => false, 'joined_at' => '2026-01-01T00:00:00Z', 'left_at' => '2026-04-03T00:00:00Z']], 'access' => [['member_id' => 30, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-04-03T00:00:00.000000Z'); return $s; });
sync();
kernel_state(function ($s) { unset($s['incremental']); return $s; });
[$c, $d] = screen($dana, '/engagements/');
ok(!in_array($draft, array_column($d['engagements'], 'engagement_id'), true), 'back to a Consultant out of the practice: the draft is gone again');

echo "4. The client page and the home\n";
[$c, $d] = screen($priya, '/clients/' . $acme);
ok($c === 200 && in_array($acmeEng, array_column($d['engagements'], 'engagement_id'), true) && !in_array($draft, array_column($d['engagements'], 'engagement_id'), true), 'Acme\'s page lists Priya the engagement she is on, not the draft');
[$c, $d] = screen($marco, '/');
ok($c === 200 && is_array($d['budget_alerts']) && $d['budget_alerts']['active'] >= 2 && $d['budget_alerts']['open'] === [], 'Marco\'s home: the Budget alerts region with the active count and no open alert');
[$c, $d] = screen($priya, '/');
ok($c === 200 && $d['budget_alerts'] === null, 'Priya\'s home: no Budget alerts region');
ok(str_contains(page($marco, '/')['body'], 'id="home-budget-alerts"'), 'the region renders for a Manager');
finish();
