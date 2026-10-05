<?php
/** Proof — rate cards (spec "Proof", 2): the Standard card with five rates editable in place; a copy carries the rates; the currency fixed once an engagement uses it; archive and delete rules. */
require __DIR__ . '/lib.php';
$bea = as_member(28); $marco = as_member(27); $owner = as_member(1); $priya = as_member(26);

echo "1. A card and its rates\n";
$since = last_activity_id();
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['name' => 'SMOKE Standard 2026', 'currency' => 'usd', 'effective_from' => '2026-01-01', 'description' => 'The list']);
$card = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $card > 0 && ($b['location'] ?? '') === '/settings/rate-cards/' . $card . '?notice=saved', "rate_card_save: 200, record_id $card, lands on the card");
ok(q('SELECT currency, effective_from FROM rate_cards WHERE id = :c', ['c' => $card])[0] === ['currency' => 'USD', 'effective_from' => '2026-01-01'] && count(activity('rate_card.save', $since)) === 1, 'USD uppercased, the date kept; rate_card.save logged');
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['name' => 'smoke standard 2026', 'currency' => 'EUR']);
ok($c === 422 && msg($b) === 'A rate card named SMOKE Standard 2026 already exists.', 'a duplicate name: 422 in words');
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['name' => 'SMOKE Bad', 'currency' => 'dollars']);
ok($c === 422 && isset(fields($b)['currency']), 'a bad currency: 422 (field currency)');
[$c, $d] = screen($bea, '/settings/rate-cards/' . $card);
$roles = $d['rate_card']['rates'];
ok($c === 200 && count($roles) === 5 && $roles[0]['role_title'] === 'Principal' && $roles[0]['bill_rate'] === null && $d['rate_card']['rate_count'] === 0, 'the card lists one row per live consulting role (5), none priced yet');
$since = last_activity_id();
$prices = ['principal' => '300', 'senior_consultant' => '225', 'consultant' => '175', 'analyst' => '125', 'project_manager' => '200'];
foreach ($prices as $key => $rate) {
    $rid = (int) one('SELECT id FROM consulting_roles WHERE key = :k', ['k' => $key]);
    [$c, $b] = act($bea, '/settings/rate-cards/rate.php', ['rate_card' => $card, 'role' => $rid, 'bill_rate' => $rate]);
    if ($c !== 200) { echo "  rate $key: $c " . msg($b) . "\n"; }
}
[$c, $d] = screen($bea, '/settings/rate-cards/' . $card);
ok($d['rate_card']['rate_count'] === 5 && $d['rate_card']['rates'][2]['bill_rate'] === '175.00' && count(activity('rate_card.rate', $since)) === 5, 'five rates set in place (Consultant 175.00); rate_card.rate logged each');
$log = activity('rate_card.rate', $since);
ok(json_decode((string) $log[0]['before'], true)['bill_rate'] === null && json_decode((string) $log[0]['after'], true) === ['role_id' => (int) one("SELECT id FROM consulting_roles WHERE key = 'principal'"), 'bill_rate' => '300.00'], 'the log carries the role and the rate before (none) and after (300.00)');
$rid = (int) one("SELECT id FROM consulting_roles WHERE key = 'analyst'");
[$c, $b] = act($bea, '/settings/rate-cards/rate.php', ['rate_card' => $card, 'role' => $rid, 'bill_rate' => '']);
ok($c === 200 && in_array(one('SELECT bill_rate FROM rate_card_rates WHERE rate_card_id = :c AND consulting_role_id = :r', ['c' => $card, 'r' => $rid]), [null, false], true) && str_starts_with((string) $b['did'], 'Cleared the rate for Analyst'), 'an empty rate clears the role\'s price');
[$c, $b] = act($bea, '/settings/rate-cards/rate.php', ['rate_card' => $card, 'role' => $rid, 'bill_rate' => '-5']);
ok($c === 422 && isset(fields($b)['bill_rate']), 'a negative rate: 422');
[$c, $b] = act($bea, '/settings/rate-cards/rate.php', ['rate_card' => $card, 'role' => $rid, 'bill_rate' => '125']);
ok($c === 200, 'priced again');
$r = page($bea, '/settings/rate-cards/' . $card);
ok($r['code'] === 200 && str_contains($r['body'], 'id="rate-row-' . $rid . '-form"') && str_contains($r['body'], 'id="rate-card-view-copy-btn"'), 'Billing\'s page has the in-place forms and "Copy to a new card"');
$r = page($marco, '/settings/rate-cards/' . $card);
ok($r['code'] === 200 && !str_contains($r['body'], 'id="rate-row-' . $rid . '-form"') && str_contains($r['body'], '125.00 USD'), 'a Manager reads the card (rates.view) without the forms');
ok(page($priya, '/settings/rate-cards/' . $card)['code'] === 403, 'a Consultant does not see bill rates: 403');

echo "2. A copy carries the rates\n";
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['name' => 'SMOKE Standard 2027', 'currency' => 'USD', 'effective_from' => '2027-01-01', 'copy_of' => $card]);
$copy = (int) $b['record_id'];
ok($c === 200 && (int) one('SELECT count(*) FROM rate_card_rates WHERE rate_card_id = :c', ['c' => $copy]) === 5 && (string) one('SELECT bill_rate FROM rate_card_rates WHERE rate_card_id = :c AND consulting_role_id = :r', ['c' => $copy, 'r' => $rid]) === '125.00', 'the copy carries the five rates');
ok(json_decode((string) activity('rate_card.save', 0)[count(activity('rate_card.save', 0)) - 1]['after'], true)['copy_of'] === $card, 'its log says copy_of');
ok(page($bea, '/settings/rate-cards/new?copy_of=' . $card)['code'] === 200 && str_contains(page($bea, '/settings/rate-cards/new?copy_of=' . $card)['body'], 'id="rate-card-form-copy"'), 'the form from "Copy to a new card" says what it copies');

echo "3. The currency fixed once an engagement uses it\n";
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['rate_card' => $card, 'currency' => 'EUR']);
ok($c === 200 && (string) one('SELECT currency FROM rate_cards WHERE id = :c', ['c' => $card]) === 'EUR', 'unused: the currency changes freely (EUR)');
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['rate_card' => $card, 'currency' => 'USD']);
$client = mkclient('SMOKE Rates client');
$eng = mkengagement($client, 'SMOKE on the card', 'active', $card);
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['rate_card' => $card, 'currency' => 'EUR']);
ok($c === 422 && msg($b) === 'The currency is fixed: 1 engagement uses this card. Copy it to a new card instead.', 'in use: 422 "The currency is fixed: 1 engagement uses this card. Copy it to a new card instead."');
[$c, $b] = act($bea, '/settings/rate-cards/save.php', ['rate_card' => $card, 'name' => 'SMOKE Standard 2026 (renamed)']);
ok($c === 200 && (string) one('SELECT name FROM rate_cards WHERE id = :c', ['c' => $card]) === 'SMOKE Standard 2026 (renamed)', 'the name still changes');
[$c, $d] = screen($bea, '/settings/rate-cards/' . $card);
ok($d['rate_card']['engagement_count'] === 1 && $d['rate_card']['engagements'][0]['engagement_id'] === $eng, 'the card lists the engagement using it');
$r = page($bea, '/settings/rate-cards/' . $card . '/edit');
ok(str_contains($r['body'], 'readonly') && str_contains($r['body'], 'fixed: 1 engagement uses it'), 'the edit form shows the currency read-only and says why');

echo "4. Archive and delete\n";
[$c, $b] = act($owner, '/settings/rate-cards/delete.php', ['rate_card' => $card]);
ok($c === 422 && msg($b) === 'SMOKE Standard 2026 (renamed) is used by 1 engagement; archive it instead.', 'deleting a card in use: 422 in words');
q('UPDATE clients SET default_rate_card_id = :c WHERE id = :cl', ['c' => $copy, 'cl' => $client]);
[$c, $b] = act($owner, '/settings/rate-cards/delete.php', ['rate_card' => $copy]);
ok($c === 422 && msg($b) === 'SMOKE Standard 2027 is used by 1 client; archive it instead.', 'a card that is a client\'s default: 422 naming the client count');
[$c, $b] = act($bea, '/settings/rate-cards/delete.php', ['rate_card' => $copy]);
ok($c === 403, 'delete is the admin\'s: 403 for Billing');
$since = last_activity_id();
[$c, $b] = act($bea, '/settings/rate-cards/archive.php', ['rate_card' => $card]);
ok($c === 200 && one('SELECT archived_at FROM rate_cards WHERE id = :c', ['c' => $card]) !== null && count(activity('rate_card.archive', $since)) === 1 && ($b['location'] ?? '') === '/settings/rate-cards/?notice=archived', 'archived, logged, lands on the list');
[$c, $d] = screen($bea, '/settings/rate-cards/');
ok(!in_array($card, array_column($d['rate_cards'], 'rate_card_id'), true), 'gone from the live list');
[$c, $d] = screen($bea, '/settings/rate-cards/?include_archived=1');
ok(in_array($card, array_column($d['rate_cards'], 'rate_card_id'), true), 'shown with include_archived');
[$c, $b] = act($bea, '/settings/rate-cards/rate.php', ['rate_card' => $card, 'role' => $rid, 'bill_rate' => '1']);
ok($c === 422 && str_contains(msg($b), 'is archived; restore it first'), 'an archived card\'s rates cannot change');
[$c, $b] = act($bea, '/settings/rate-cards/archive.php', ['rate_card' => $card, 'archived' => 'no']);
ok($c === 200 && one('SELECT archived_at FROM rate_cards WHERE id = :c', ['c' => $card]) === null, 'restored');
q('UPDATE clients SET default_rate_card_id = NULL WHERE id = :cl', ['cl' => $client]);
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/rate-cards/delete.php', ['rate_card' => $copy]);
ok($c === 200 && (int) one('SELECT count(*) FROM rate_cards WHERE id = :c', ['c' => $copy]) === 0 && count(activity('rate_card.delete', $since)) === 1, 'an unused card: deleted by the admin, logged (its rates cascade)');
finish();
