<?php
/**
 * Proof: the directory sync (bin/directory_sync.php) against the fake kernel — the full pass applies the fixture, the incremental pass applies
 * nothing and moves the cursor, a role change reaches members.roles in one pass, an access row with capability null ends the member's sessions,
 * a suspended member's sessions end, --from-file, and a failing kernel is reported (sso-shell.md, "Proof"). Run after gates.php.
 */
require __DIR__ . '/lib.php';
$state = fn () => q('SELECT * FROM directory_sync_state WHERE id = 1')[0];
$L = 'https://app.example.invalid/launcher?app=consultant_tracking';

echo "1. The full pass\n";
ok((int) one('SELECT count(*) FROM members WHERE capability IS NOT NULL AND id IN (1, 26, 27, 28, 29, 30, 40)') === 7 && (int) one('SELECT count(*) FROM departments') === 4, 'the run.sh full pass admitted the seven fixture members and the four departments (practices)');
ok(q('SELECT roles FROM members WHERE id = 28')[0]['roles'] === '{billing,user}' && q('SELECT roles FROM members WHERE id = 40')[0]['roles'] === '{billing,user}' && q('SELECT roles FROM members WHERE id = 29')[0]['roles'] === '{finance}', 'access[] roles landed (Bea and the Clerk billing,user; Ann finance alone — an external reads, never logs time)');
$st = $state();
ok($st['full_at'] !== null && $st['next_cursor'] !== null && $st['last_error'] === null, 'the sync state records the full pass (full_at, a cursor, no error)');
$out = sync('--full');
ok(str_contains($out, '(full)') && (int) one('SELECT count(*) FROM department_members WHERE left_at IS NULL') === 7, "running --full again changes nothing: $out");
ok(count(activity('directory.sync', 0)) >= 1 && activity('directory.sync', 0)[0]['source'] === 'cron', 'directory.sync is logged (source cron)');

echo "2. The incremental pass applies nothing and advances the cursor\n";
kernel_state(function ($s) { $s['feed']['next'] = '2026-03-01T00:00:00.000000Z'; unset($s['incremental']); return $s; });
$before = $state()['next_cursor'];
$out = sync();
ok(str_contains($out, 'applied 0 members, 0 departments, 0 memberships, 0 holdings') && !str_contains($out, '(full)'), "no change: $out");
ok($state()['next_cursor'] !== $before && str_starts_with($state()['next_cursor'], '2026-03-01'), 'the cursor moved to the kernel\'s next (' . $state()['next_cursor'] . ')');

echo "3. A role change reaches members.roles in one pass\n";
[$jm, ] = sign_on(27);
ok(page($jm, '/credit-notes/')['code'] === 403 && page($jm, '/invoices/')['code'] === 200, 'Marco (manager) may not see Credit notes — Invoices he may (invoices.draft)');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 27, 'role' => 'billing', 'roles' => ['billing', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-02T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 holdings') && q('SELECT roles FROM members WHERE id = 27')[0]['roles'] === '{billing,user}', "one holding applied, roles now billing,user: $out");
ok(page($jm, '/credit-notes/')['code'] === 200 && badge_of($jm) === 'Billing', 'his open session sees Credit notes at once and the badge reads Billing — no new sign-on needed');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 27, 'role' => 'manager', 'roles' => ['manager', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-03T00:00:00.000000Z'); return $s; });
sync();
ok(page($jm, '/credit-notes/')['code'] === 403 && q('SELECT roles FROM members WHERE id = 27')[0]['roles'] === '{manager,user}', 'and back to manager: Credit notes 403 again');

echo "4. Access withdrawn ends the member's sessions in the same pass\n";
[$jp, ] = sign_on(26);
ok(page($jp, '/')['code'] === 200, 'Priya is signed in');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => null, 'roles' => [], 'capability' => null, 'scopes' => []]]], '2026-03-04T00:00:00.000000Z'); return $s; });
sync();
$r = page($jp, '/');
ok($r['code'] === 302 && $r['location'] === $L && (int) one('SELECT count(*) FROM member_sessions WHERE member_id = 26 AND ended_at IS NULL') === 0, 'her next request goes to the launcher; no live session left');
ok(q("SELECT ended_by FROM member_sessions WHERE member_id = 26 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', "ended_by = 'directory'");
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 26, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-05T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT capability FROM members WHERE id = 26')[0]['capability'] === 'write', 'granted again for the later proofs');

echo "5. A suspended member's sessions end in the same pass\n";
[$jl, ] = sign_on(28);
ok(page($jl, '/')['code'] === 200, 'Bea is signed in');
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 28, 'member_kind' => 'human', 'display_name' => 'SMOKE Bea', 'email' => 'bea@example.invalid', 'business_role' => 'user',
    'is_external' => false, 'status' => 'suspended', 'updated_at' => '2026-03-06T00:00:00Z', 'departments' => []]]], '2026-03-06T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 members'), "the member row applied: $out");
ok((int) one('SELECT count(*) FROM member_sessions WHERE member_id = 28 AND ended_at IS NULL') === 0 && q("SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', 'in that pass: her sessions ended (ended_by directory)');
ok(page($jl, '/')['code'] === 302 && q('SELECT status FROM members WHERE id = 28')[0]['status'] === 'inactive', 'her next request goes to the launcher and the mirror says inactive');
$r = req('GET', handoff(28, ['claims' => ['status' => 'suspended'] + fixture()['claims']['28']]), ['jar' => jar()]);
ok($r['code'] === 403, 'a hand-off for her is refused too');

echo "6. A fixture (--from-file) and a kernel that fails\n";
$out = sync('--from-file ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/dev_directory.json'));
ok(str_contains($out, '(full)') && str_contains($out, '7 members') && str_contains($out, '7 holdings') && q('SELECT status FROM members WHERE id = 28')[0]['status'] === 'active', "--from-file applies the fixture's feed (Bea active again): $out");
$out = (string) shell_exec('OS_INTERNAL_URL=http://127.0.0.1:1 php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/directory_sync.php') . ' 2>&1; echo "exit=$?"');
ok(str_contains($out, 'the kernel did not answer') && str_contains($out, 'exit=1'), 'an unreachable kernel: the run says so and exits 1');
ok(($state()['last_error'] ?? '') !== '' && $state()['last_error'] !== null, 'and directory_sync_state.last_error holds the reason (' . $state()['last_error'] . ')');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['directory']['error'] ?? '') !== '', 'health reports it: directory.error');
kernel_state(function ($s) { $s['incremental'] = incr([], '2026-03-07T00:00:00.000000Z'); return $s; });
sync();
ok($state()['last_error'] === null && str_starts_with($state()['next_cursor'], '2026-03-07'), 'the next good pass clears the error and moves the cursor');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
