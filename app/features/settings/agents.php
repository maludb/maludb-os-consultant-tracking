<?php
declare(strict_types=1);
/**
 * The Agents and Connections screens (slice 9, docs/build-specs/home-admin-tokens.md). Read-only. The kernel hires, grants and runs the agents and approves the connections between applications; this application SHOWS what it holds —
 * the agents `maludb-os.json` declares, the mirror's agent members (the directory feed's facts: hired, active, the roles they hold here), what they did here (the activity log, the dispatches, the Clerk's notes) and what the kernel last
 * answered when a share was read (the `share.read` rows). The kernel has no endpoint that lists an application's agents or connections, so nothing here asks it.
 */

/** maludb-os.json decoded (the declared agents, shares and reads). */
function os_manifest(): array
{
    static $m = null;
    return $m ??= (json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true) ?: []);
}

/** "0 7 * * *" in words when it is a daily time; the expression itself otherwise. */
function cron_words(string $cron): string
{
    return preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/', trim($cron), $m) === 1 ? sprintf('daily at %02d:%02d', (int) $m[2], (int) $m[1]) : $cron;
}

/** The OS's own pages (where agents are hired, granted and connections approved): the launcher's host with `app.` turned into `os.`, or null when it cannot be told. */
function os_pages_url(): ?string
{
    $u = parse_url((string) env('OS_LAUNCHER_URL', ''));
    if (!is_array($u) || ($u['host'] ?? '') === '') {
        return null;
    }
    $host = preg_replace('/^app\./', 'os.', (string) $u['host']);
    return ($u['scheme'] ?? 'https') . '://' . $host . '/';
}

/**
 * The agents of this application: one entry per agent `maludb-os.json` declares (matched to the mirror's agent members by the roles it holds here; hired = a member matched) and one per mirror agent that matches no declaration.
 * Each: key, declared (name, job description, access, roles, the duty, tool/skill counts), member (id, name, job title, roles, status) or null, last_activity (at, action, source) or null, activity_7d, last_dispatch or null,
 * dispatches (counts by status), last_note (the Billing Clerk's, the duty's output) or null.
 */
function agents_here(PDO $pdo): array
{
    $members = $pdo->query("SELECT member_id, display_name, job_title, status, capability, roles FROM mcp_members WHERE member_kind = 'agent' AND capability IS NOT NULL ORDER BY member_id")->fetchAll();
    $out = [];
    $used = [];
    $detail = static function (PDO $pdo, array $m): array {
        $id = (int) $m['member_id'];
        $roles = array_values(array_filter(explode(',', trim((string) $m['roles'], '{}'))));
        $a = $pdo->prepare('SELECT occurred_at, action, source, entity_type FROM mcp_activity_log WHERE actor_member_id = :m ORDER BY activity_id DESC LIMIT 1');
        $a->execute(['m' => $id]);
        $act = $a->fetch() ?: null;
        $n = $pdo->prepare("SELECT count(*) FROM mcp_activity_log WHERE actor_member_id = :m AND occurred_at > now() - interval '7 days'");
        $n->execute(['m' => $id]);
        $d = $pdo->prepare('SELECT agent_dispatch_id, kind, via, status, attempts, reply_excerpt, created_at, answered_at FROM mcp_agent_dispatches WHERE agent_member_id = :m ORDER BY agent_dispatch_id DESC LIMIT 1');
        $d->execute(['m' => $id]);
        $dc = $pdo->prepare('SELECT status, count(*) AS n FROM mcp_agent_dispatches WHERE agent_member_id = :m GROUP BY status');
        $dc->execute(['m' => $id]);
        $counts = [];
        foreach ($dc->fetchAll() as $r) { $counts[$r['status']] = (int) $r['n']; }
        $note = $pdo->prepare("SELECT n.created_at, n.title, mm.display_name AS for_name FROM notifications n JOIN members mm ON mm.id = n.member_id WHERE n.kind = 'morning_note' ORDER BY n.id DESC LIMIT 1");
        $note->execute();
        return ['member' => ['member_id' => $id, 'display_name' => $m['display_name'], 'job_title' => $m['job_title'], 'status' => $m['status'], 'capability' => $m['capability'], 'roles' => $roles],
                'last_activity' => $act === null ? null : ['at' => $act['occurred_at'], 'action' => $act['action'], 'source' => $act['source'], 'entity_type' => $act['entity_type']],
                'activity_7d' => (int) $n->fetchColumn(), 'last_dispatch' => $d->fetch() ?: null, 'dispatch_counts' => $counts, 'note' => $note->fetch() ?: null];
    };
    foreach ((array) (os_manifest()['agents'] ?? []) as $decl) {
        $want = (array) ($decl['roles'] ?? []);
        $match = null;
        foreach ($members as $m) {
            $have = array_values(array_filter(explode(',', trim((string) $m['roles'], '{}'))));
            if (!isset($used[$m['member_id']]) && $want !== [] && array_intersect($want, $have) !== []) { $match = $m; break; }
        }
        $grants = 0;
        foreach ((array) ($decl['tool_grants'] ?? []) as $tools) { $grants += count((array) $tools); }
        $row = ['key' => (string) $decl['key'], 'declared' => ['job_description' => (string) ($decl['job_description'] ?? ''), 'access' => (string) ($decl['access_capability'] ?? ''), 'roles' => $want,
                'duty' => isset($decl['duty']) ? ['name' => (string) $decl['duty']['name'], 'schedule' => cron_words((string) $decl['duty']['schedule_cron'])] : null, 'tools' => $grants, 'skills' => count((array) ($decl['skills'] ?? []))],
                'member' => null, 'last_activity' => null, 'activity_7d' => 0, 'last_dispatch' => null, 'dispatch_counts' => [], 'note' => null];
        if ($match !== null) {
            $used[$match['member_id']] = true;
            $d = $detail($pdo, $match);
            $row = array_merge($row, $d);
            if (!isset($decl['duty'])) { $row['note'] = null; }          // only a duty leaves a morning note
        }
        $out[] = $row;
    }
    foreach ($members as $m) {
        if (isset($used[$m['member_id']])) { continue; }
        $out[] = array_merge(['key' => null, 'declared' => null], $detail($pdo, $m), ['note' => null]);
    }
    return $out;
}

/**
 * What the application shares and reads, with what the kernel last answered. `shares`: the six `shares[]` (tool, description, people/scoped flags, the last `share.read` row `direction = in`: when, rows) — a share the kernel's token has not
 * called yet has no last read. `reads`: the `reads[]` (gl.invoice_receipts) and hr.reimbursements_paid (the worker also makes it; slice 8) with the last outcome (status, code, rows, when) and what that says of the connection
 * (`approved` when the kernel answered 200, `not_approved` on 403, `unreachable` on no answer, `failed` otherwise, `unknown` before any read).
 */
function connections_summary(PDO $pdo): array
{
    $m = os_manifest();
    $last = static function (PDO $pdo, string $direction, string $tool, ?string $provider): ?array {
        $st = $pdo->prepare("SELECT occurred_at, after FROM mcp_activity_log WHERE action = 'share.read' AND after->>'direction' = :d AND after->>'tool' = :t" . ($provider === null ? '' : " AND after->>'provider' = :p") . ' ORDER BY activity_id DESC LIMIT 1');
        $st->execute(['d' => $direction, 't' => $tool] + ($provider === null ? [] : ['p' => $provider]));
        $r = $st->fetch();
        if ($r === false) { return null; }
        $a = json_decode((string) $r['after'], true) ?: [];
        return ['at' => $r['occurred_at'], 'rows' => isset($a['rows']) ? (int) $a['rows'] : null, 'status' => isset($a['status']) ? (int) $a['status'] : null, 'code' => $a['code'] ?? null];
    };
    $count = static function (PDO $pdo, string $tool): int {
        $st = $pdo->prepare("SELECT count(*) FROM mcp_activity_log WHERE action = 'share.read' AND after->>'direction' = 'in' AND after->>'tool' = :t");
        $st->execute(['t' => $tool]);
        return (int) $st->fetchColumn();
    };
    $shares = [];
    foreach ((array) ($m['shares'] ?? []) as $s) {
        $shares[] = ['tool' => (string) $s['tool'], 'description' => (string) ($s['description'] ?? ''), 'people' => !empty($s['people']), 'scoped' => !empty($s['scoped']),
                     'last' => $last($pdo, 'in', (string) $s['tool'], null), 'reads' => $count($pdo, (string) $s['tool'])];
    }
    $declared = [];
    foreach ((array) ($m['reads'] ?? []) as $r) { $declared[$r['app'] . '.' . $r['tool']] = (string) ($r['why'] ?? ''); }
    $declared += ['hr.reimbursements_paid' => 'Mark an approved personal-card expense reimbursed when a pay run paid it (H2 — owed by HR; not declared in maludb-os.json until HR ships the share)'];
    $reads = [];
    foreach ($declared as $key => $why) {
        [$app, $tool] = explode('.', $key, 2);
        $l = $last($pdo, 'out', $tool, $app);
        $state = $l === null ? 'unknown' : ($l['status'] === 200 ? 'approved' : ($l['status'] === 0 ? 'unreachable' : ($l['status'] === 403 ? 'not_approved' : 'failed')));
        $reads[] = ['provider' => $app, 'tool' => $tool, 'why' => $why, 'declared' => isset(array_column((array) ($m['reads'] ?? []), 'tool', 'tool')[$tool]), 'last' => $l, 'state' => $state];
    }
    return ['shares' => $shares, 'reads' => $reads];
}
