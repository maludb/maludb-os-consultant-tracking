<?php
/**
 * Proof — tokens for every member (spec "Proof", the admin's /settings/tokens/ made whole): every person — a Consultant, a Manager, Billing, Finance, the administrator — mints a token of their own: `mcp_` + 48 hex, shown once (the response of the mint and the next page, never again), only the
 * SHA-256 stored; their page lists their own (label, scope, created, last used, revoked) and never another's; the list in JSON never carries a value or a hash; the token resolves to its member through `mcp_resolve_token` (and stamps last used) and stops resolving when revoked; a
 * revoke of someone else's token is 404; an empty label or a scope that does not exist is refused in words; an agent cannot mint (people only); the log carries the label and the scope, never the token.
 */
require __DIR__ . '/lib.php';
home_world();
$jars = [1 => as_member(1), 26 => as_member(26), 27 => as_member(27), 28 => as_member(28), 29 => as_member(29), 30 => as_member(30)];
$names = [1 => 'the administrator', 26 => 'Priya (Consultant)', 27 => 'Marco (Manager)', 28 => 'Bea (Billing)', 29 => 'Ann (Finance)', 30 => 'Dana (Consultant)'];
$minted = [];
function resolve(string $raw, string $scope = 'mcp'): array { return q('SELECT * FROM mcp_resolve_token(:h, :s)', ['h' => hash('sha256', $raw), 's' => $scope]); }

echo "1. Everyone mints one\n";
foreach ($jars as $m => $j) {
    $since = last_activity_id();
    [$c, $b] = act($j, '/settings/tokens/mint.php', ['label' => 'SMOKE s9 ' . $m, 'scope' => 'mcp']);
    $raw = (string) ($b['token'] ?? '');
    $minted[$m] = ['id' => (int) ($b['record_id'] ?? 0), 'raw' => $raw];
    $row = q('SELECT * FROM mcp_access_tokens WHERE id = :i', ['i' => $minted[$m]['id']])[0] ?? [];
    ok($c === 200 && preg_match('/^mcp_[a-f0-9]{48}$/', $raw) === 1 && (int) $row['member_id'] === $m && $row['token_hash'] === hash('sha256', $raw) && !str_contains(json_encode($row), $raw), "{$names[$m]}: minted — mcp_ + 48 hex, the database holds the SHA-256 of it, not the token");
    $l = activity('token.mint', $since)[0] ?? [];
    ok(str_contains((string) ($l['after'] ?? ''), 'SMOKE s9 ' . $m) && !str_contains(json_encode($l), $raw) && (int) ($l['actor_member_id'] ?? 0) === $m, "{$names[$m]}: token.mint logged with the label and the scope, never the token");
}

echo "2. Resolving, and the page\n";
foreach ($minted as $m => $t) {
    $r = resolve($t['raw']);
    ok(count($r) === 1 && (int) $r[0]['member_id'] === $m && $r[0]['member_kind'] === 'human', "{$names[$m]}: the token resolves to member $m");
}
ok(resolve($minted[26]['raw'], 'api') === [] && resolve('mcp_' . str_repeat('0', 48)) === [], 'a token does not resolve in another scope; a made-up one does not resolve at all');
ok(q('SELECT last_used_at FROM mcp_access_tokens WHERE id = :i', ['i' => $minted[26]['id']])[0]['last_used_at'] !== null, 'resolving stamped "last used"');
[$c, $d] = screen($jars[26], '/settings/tokens/');
$labels = array_column($d['tokens'], 'label');
ok($c === 200 && array_keys($d) === ['tokens', 'records_url', 'activity_url'] && in_array('SMOKE s9 26', $labels, true) && !in_array('SMOKE s9 27', $labels, true) && !in_array('SMOKE s9 1', $labels, true), 'Priya\'s list (JSON): her own token, not Marco\'s and not the administrator\'s');
ok(array_keys($d['tokens'][0]) === ['token_id', 'label', 'scope', 'created_at', 'last_used_at', 'expires_at', 'revoked'] && !str_contains(json_encode($d), $minted[26]['raw']) && !str_contains(json_encode($d), hash('sha256', $minted[26]['raw'])), 'a listed token is {token_id, label, scope, created_at, last_used_at, expires_at, revoked} — no value, no hash');
$r = page($jars[26], '/settings/tokens/');
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE s9 26') && !str_contains($r['body'], 'SMOKE s9 27') && !str_contains($r['body'], $minted[26]['raw']) && str_contains($r['body'], 'id="tokens-url-records"') && str_contains($r['body'], '/mcp/records') && str_contains($r['body'], '/mcp/activity'), 'the page: her token by label, not Marco\'s; the value is not on it; the two MCP URLs to connect to');
foreach ($jars as $m => $j) {
    ok(page($j, '/settings/tokens/')['code'] === 200, "{$names[$m]}: the page opens");
}

echo "3. Shown once\n";
$j = $jars[28];
$r1 = req('POST', '/settings/tokens/mint.php', ['jar' => $j, 'form' => ['label' => 'SMOKE s9 shown once', 'scope' => 'api', 'csrf_token' => csrf_of(req('GET', '/', ['jar' => $j])['body'])]]);
$p1 = page($j, '/settings/tokens/'); $p2 = page($j, '/settings/tokens/');
ok(in_array($r1['code'], [302, 303], true) && preg_match('/id="tokens-minted-value"[^>]*>(mcp_[a-f0-9]{48})</', $p1['body']) === 1, 'a browser mint lands on the tokens page, which shows the value once');
ok(!preg_match('/mcp_[a-f0-9]{48}/', $p2['body']), 'the next time the page is opened there is no token value on it at all');

echo "4. Revoke, and whose it is\n";
[$c, $b] = act($jars[27], '/settings/tokens/revoke.php', ['token' => $minted[26]['id']]);
ok($c === 404 && count(resolve($minted[26]['raw'])) === 1, 'Marco revoking Priya\'s token: 404, and it still works');
$since = last_activity_id();
[$c, $b] = act($jars[26], '/settings/tokens/revoke.php', ['token' => $minted[26]['id']]);
ok($c === 200 && resolve($minted[26]['raw']) === [] && count(activity('token.revoke', $since)) === 1 && !str_contains(json_encode(activity('token.revoke', $since)), $minted[26]['raw']), 'Priya revoking her own: the token stops resolving; token.revoke logged without the value');
[$c, $b] = act($jars[26], '/settings/tokens/revoke.php', ['token' => $minted[26]['id']]);
ok($c === 404, 'revoking it again: 404');
[$c, $d] = screen($jars[26], '/settings/tokens/');
ok(array_values(array_filter($d['tokens'], fn ($t) => $t['label'] === 'SMOKE s9 26'))[0]['revoked'] === true, 'the list says it is revoked');
q("UPDATE mcp_access_tokens SET expires_at = now() - interval '1 minute' WHERE id = :i", ['i' => $minted[30]['id']]);
ok(resolve($minted[30]['raw']) === [], 'an expired token does not resolve');
q("UPDATE members SET status = 'inactive' WHERE id = 29");
ok(resolve($minted[29]['raw']) === [], 'a member who is no longer active (the directory says so): their token does not resolve');
q("UPDATE members SET status = 'active' WHERE id = 29");

echo "5. Refusals\n";
[$c, $b] = act($jars[28], '/settings/tokens/mint.php', ['label' => '   ', 'scope' => 'mcp']);
ok($c === 422 && str_contains(msg($b), 'A label is required'), 'an empty label: 422 in words');
[$c, $b] = act($jars[28], '/settings/tokens/mint.php', ['label' => 'SMOKE s9 bad', 'scope' => 'admin']);
ok($c === 422 && msg($b) === 'The scope is mcp or api.', 'a scope that does not exist: 422 "The scope is mcp or api."');
[$c, $b] = act_token('/settings/tokens/mint.php', ['label' => 'SMOKE s9 agent', 'scope' => 'mcp'], ['X-Action-Token: ' . person_token(40)]);
ok(in_array($c, [403, 401], true) && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = 'SMOKE s9 agent'") === 0, 'an agent cannot mint (people only): ' . $c);
$r = req('GET', '/settings/tokens/', ['jar' => '/dev/null']);
ok(in_array($r['code'], [302, 303, 401], true), 'no session: sent to sign in');
finish();
