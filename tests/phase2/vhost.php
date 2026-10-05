<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-consultant_tracking.conf). Meaningful under CT_APP=apache tests/phase2/run.sh,
 * where a real Apache serves the template rendered as the installer renders it; under php -S the same checks run against
 * tests/dev_router.php, which mirrors the rewrites. Nothing outside html/ is reachable, /api is blocked but for health, the public
 * door is rewritten but answers 404 until its slice.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/engagements/')['code'] === 200 && page($j, '/engagements')['code'] === 200, '/engagements/ and /engagements both reach engagements/index.php');
ok(page($j, '/time/entries/new')['code'] === 200, '/time/entries/new reaches time/entries/form.php (the placeholder)');
ok(page($j, '/usage/os')['code'] === 200 && page($j, '/usage/os/')['code'] === 200, '/usage/os and /usage/os/ reach usage/os/index.php');
ok(page($j, '/billing/unbilled')['code'] === 200 && page($j, '/reports/ar-aging')['code'] === 200, '/billing/unbilled and /reports/ar-aging resolve to their own files');
ok(page($j, '/settings/general')['code'] === 403 && page($j, '/settings/agents')['code'] === 403, '/settings/general and /settings/agents resolve (403 for a Manager, by the right)');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
ok(req('GET', '/i/' . str_repeat('ab', 24))['code'] === 404 && req('GET', '/i/' . str_repeat('ab', 24) . '/claim')['code'] === 404, 'the public door /i/{token} is rewritten and answers 404 until slice 6 builds it');
ok(page($j, '/reports/utilization')['code'] === 200, '/reports/{report} is rewritten to reports/report.php (slice 7; the placeholder answers)');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'consultant_tracking', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1, '/manifest.webmanifest is served as JSON (' . (preg_match('/^Content-Type: (.+)$/mi', $m['headers'], $t) ? trim($t[1]) : '?') . ')');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
