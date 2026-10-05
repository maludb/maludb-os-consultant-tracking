<?php
/**
 * Helpers for the Phase 4 proofs (docs/build-specs/mcp-servers.md). Run through tests/phase4/run.sh: a SCRATCH database (ct_dev3 — never the installed one), the application on :8301, a fake kernel on :8302 (run-facts, K6, K7),
 * and the TWO MCP SERVERS on :8304 (records) and :8305 (activity), plus a third records server on :8310 whose kernel URL is dead (the gate must fail closed). Everything a proof makes is named "SMOKE …".
 * Builds on slice 9's helpers (so 8's … 1's): the cast is the owner (1), Priya (26, Consultant), Marco (27, Manager of Acme and Beta), Bea (28, Billing), Ann (29, Finance), Dana (30, Consultant), the Clerk (40, an agent, billing) and the
 * expert (41, an agent, manager). The world is slice 7's REPORT WORLD plus slice 8's EXPORTS WORLD plus slice 9's HOME WORLD (a pulled, allocated OS month) — their hand-derived figures are quoted in the proofs' comments.
 */
require dirname(__DIR__) . '/phase3/slice9/lib.php';

const REC = 8304;
const ACT = 8305;
const REC_DEAD = 8310;

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl) . '.' . $run;
    return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl);
    return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY'));
}
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function ktoken(string $app = 'consultant_tracking', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}
/** An agent: a mirror row with its roles. capability null = not yet admitted (the first-contact path admits it). */
function make_agent(int $id, string $name, array $roles = ['user'], ?string $cap = 'write'): void
{
    $capSql = $cap === null ? 'NULL' : "'$cap'";
    q("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (:i, 'agent', :n, 'user', 'active', $capSql, CAST(:r AS text[]))
       ON CONFLICT (id) DO UPDATE SET capability = $capSql, status = 'active', roles = CAST(:r AS text[])", ['i' => $id, 'n' => $name, 'r' => '{' . implode(',', $roles) . '}']);
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp', array $extra = []): array
{
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'], $extra);
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp', array $extra = []): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path, $extra);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path, $extra); }
    return [$sess, $st, $init];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/** The full tools/list answer (names, descriptions, annotations, input schemas). */
function mcp_tool_defs(int $port, string $token): array
{
    [$sess] = mcp_open($port, $token);
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess);
    return $a['result']['tools'] ?? [];
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null]. Arguments are wrapped as {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat)
 * and the tools that take none (app_roles, my_timer, my_reimbursements_due).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, array $extra = []): array
{
    [$sess, $st] = mcp_open($port, $token, '/mcp', $extra);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, ['app_roles', 'my_timer', 'my_reimbursements_due'], true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, '/mcp', $extra);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || (is_array($data) && isset($data['error']) && count($data) <= 2), 'text' => $text, 'data' => is_array($data) ? $data : null];
}
/** A tool as a person (their own token): the whole answer, or just the decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** Every key anywhere in a decoded answer. */
function has_key($data, string $key): bool
{
    if (!is_array($data)) { return false; }
    foreach ($data as $k => $v) { if ($k === $key || has_key($v, $key)) { return true; } }
    return false;
}
/** All the values of a key anywhere in a decoded answer. */
function key_values($data, string $key): array
{
    $out = [];
    if (!is_array($data)) { return $out; }
    foreach ($data as $k => $v) { if ($k === $key) { $out[] = $v; } $out = array_merge($out, key_values($v, $key)); }
    return $out;
}
function sum_key($rows, string $key): float { $s = 0.0; foreach ($rows as $r) { $s += (float) ($r[$key] ?? 0); } return round($s, 2); }
function find_row(?array $rows, string $key, $value): ?array { foreach ($rows ?? [] as $r) { if ((string) ($r[$key] ?? '') === (string) $value) { return $r; } } return null; }
/** SQL over the views AS a member (the same rows the server hands that member). */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    return $rows;
}
function val_as(int $member, string $sql, array $args = []) { $r = sql_as($member, $sql, $args); return $r ? array_values($r[0])[0] : null; }
function same_num($a, $b): bool { return abs((float) $a - (float) $b) < 0.005; }

/**
 * The Phase 4 world: slice 9's (the report, exports and home worlds, the pulled OS months) once per database, plus — made through the handlers, named SMOKE p4 —
 *   two allocation rules for the kernel's agent 901 (60 % to Acme, 40 % to Beta at 25 % markup) and 2026-08 allocated: the Researcher's two lines (Anthropic 100.00, OpenAI 30.335) allocated, the Writer's line (50.00) left as the remainder;
 *   a hosting schedule on Acme fallen due yesterday (25.00 a month, at cost); a milestone on Beta (5,000.00) delivered and accepted, unbilled;
 *   Dana's week monday(1) on Acme (6 h on the Monday) SUBMITTED, and a 60.00 expense of Dana's on Acme SUBMITTED without a receipt (the database refuses a submission over the 75.00 threshold without one);
 *   an empty DRAFT invoice of Bea's on Beta;
 *   Beta's AI budget set to 100.00;
 *   a payment claim of 300.00 (transfer, ref SMOKE-P4-WIRE) submitted on Bea's INV-00002; a timer running for Dana on Acme.
 */
function p4_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $r = home_world();
    pulled_month();
    expert_member();
    $acme = $r['acme']; $beta = $r['beta'];
    $marco = as_member(27); $dana = as_member(30);
    q('UPDATE engagements SET ai_budget_amount = 100 WHERE id = :e', ['e' => $beta]);          // Beta's AI budget: 100.00 (its cost is the 40 % rule's drafts)
    if ((int) one("SELECT count(*) FROM allocation_rules WHERE name LIKE 'SMOKE p4%'") === 0) {
        foreach ([['SMOKE p4 Researcher 60 to Acme', $acme, '60', []], ['SMOKE p4 Researcher 40 to Beta', $beta, '40', ['pricing' => 'markup', 'markup_pct' => '25']]] as [$n, $e, $pct, $x]) {
            [$c, $b] = act($marco, '/settings/allocation-rules/save.php', ['valid_from' => '2026-01-01', 'name' => $n, 'engagement' => $e, 'agent' => 901, 'share_pct' => $pct] + $x);
            if ($c !== 200) { fwrite(STDERR, "rule failed: " . json_encode($b) . "\n"); }
        }
        [$c, $b] = act($marco, '/usage/os/allocate.php', ['period' => '2026-08']);
        if ($c !== 200) { fwrite(STDERR, "allocate failed: " . json_encode($b) . "\n"); }
        [$c, $b] = act($marco, '/settings/schedules/save.php', ['engagement' => $acme, 'kind' => 'hosting', 'description' => 'SMOKE p4 VM', 'unit_cost' => '25', 'next_date' => plus_days(today(), -1), 'cadence' => 'monthly', 'pricing' => 'cost']);
        if ($c !== 200) { fwrite(STDERR, "schedule failed: " . json_encode($b) . "\n"); }
        [$c, $b] = act($marco, '/engagements/milestones/save.php', ['engagement' => $beta, 'name' => 'SMOKE p4 Phase 1', 'amount' => '5000', 'due_date' => plus_days(today(), 10)]);
        $m = (int) ($b['record_id'] ?? 0);
        act($marco, '/engagements/milestones/deliver.php', ['milestone' => $m]);
        [$c, $b] = act($marco, '/engagements/milestones/accept.php', ['milestone' => $m, 'acceptance_note' => 'SMOKE p4 the client lead signed it off', 'accepted_by' => 27]);
        if ($c !== 200) { fwrite(STDERR, "accept failed: " . json_encode($b) . "\n"); }
        $w3 = monday(1);
        [$c, $b] = log_time($dana, $acme, day($w3, 0), '6', ['note' => 'SMOKE p4 pending week']);
        act($dana, '/time/weeks/submit.php', ['week' => $w3]);
        [$c, $b] = record_expense($dana, $acme, '60.00', ['description' => 'SMOKE p4 no receipt', 'submit' => '1']);
        q("INSERT INTO payment_claims (invoice_id, claimed_on, method, reference, amount, note) VALUES (:i, current_date, 'transfer', 'SMOKE-P4-WIRE', 300.00, 'SMOKE p4 paid by wire')", ['i' => (int) invoice_by_number('INV-00002')['id']]);
        q("INSERT INTO time_timers (member_id, engagement_id, note, started_at) VALUES (30, :e, 'SMOKE p4 timer', now() - interval '90 minutes') ON CONFLICT DO NOTHING", ['e' => $acme]);
    }
    if ((int) one("SELECT count(*) FROM invoices WHERE status = 'draft'") === 0) {
        [$c, $b] = draft(as_member(28), $beta, ['add_unbilled' => 'no', 'period_start' => '2026-10-01', 'period_end' => '2026-10-05']);      // an empty draft on Beta, so that "which engagements hold an open draft" has an answer
        if ($c !== 200) { fwrite(STDERR, "draft failed: " . json_encode($b) . "\n"); }
    }
    return $w = $r;
}

/** The tool surface (docs/consultant-tracking-mcp-tool-surface.md) as the proofs read it: the named tools of each server, sorted; `shares` = the six marked (share). records_search and app_roles are described in prose, so they are added. */
function surface(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $t = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/consultant-tracking-mcp-tool-surface.md');
    $a = strpos($t, '## Records server'); $b = strpos($t, '## Activity server'); $c = strpos($t, '## Coverage');
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $a, $b - $a), $rec);
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $b, $c - $b), $act);
    preg_match_all('/^\| `([a-z_]+)` \*\*\(share/m', substr($t, $a, $b - $a), $sh);
    $records = array_merge($rec[1], ['records_search', 'app_roles']); sort($records);
    $activity = $act[1]; sort($activity);
    $shares = $sh[1]; sort($shares);
    return $s = ['records' => $records, 'activity' => $activity, 'shares' => $shares];
}
/** An agent's grants as maludb-os.json declares them: ['Records MCP' => [...], 'Activity MCP' => [...], 'Actions MCP' => [...]] (tool => []). */
function agent_grants(string $key): array
{
    $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
    foreach ($m['agents'] as $a) { if ($a['key'] === $key) { return array_map(fn ($tools) => array_fill_keys($tools, []), $a['tool_grants']); } }
    return [];
}
