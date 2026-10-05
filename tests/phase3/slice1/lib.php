<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/clients-rates-settings.md, "Proof"). Run through tests/phase3/slice1/run.sh: a fresh SCRATCH
 * database, the application on :8301, a fake kernel and a fake MaluDB. Everything a proof makes is named "SMOKE …". Builds on tests/phase2/lib.php.
 * The cast: the owner (1, super-admin), Priya (26, Consultant), Marco (27, Manager), Bea (28, Billing), Ann (29, Finance, external), Dana (30,
 * Consultant), the Billing Clerk (40, an agent, billing).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];

/** A signed-on jar for a fixture member (cached per proof). */
function as_member(int $m): string
{
    static $jars = [];
    if (isset($jars[$m])) { return $jars[$m]; }
    [$j, $r] = sign_on($m);
    if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); }
    return $jars[$m] = $j;
}
/** A write over HTTP as a signed-on person, answered as JSON: [status, decoded body, raw]. The CSRF token is fetched from the shell once per jar. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** A multipart upload as a signed-on person: [status, body]. */
function upload(string $jar, string $path, array $form, string $fileField, string $localPath, string $filename): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HTTPHEADER => JSONH,
        CURLOPT_POSTFIELDS => $form + ['csrf_token' => $tok[$jar], $fileField => new CURLFile($localPath, mime_content_type($localPath) ?: 'application/octet-stream', $filename)]]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($body, true) ?? []];
}
/** A screen as JSON (data only): [status, data, raw]. */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }
function fields(array $body): array { return (array) ($body['error']['fields'] ?? []); }
/** A person's action token (the kernel's shape) and a run token with its relay. */
function person_token(int $m, int $ttl = 300): string { $key = need('ACTION_TOKEN_KEY'); $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, $key); }
function run_token(int $m, int $runId): array { $key = need('ACTION_TOKEN_KEY'); $p = $m . '.' . (time() + 300) . '.' . $runId; $t = $p . '.' . hash_hmac('sha256', 'run:' . $p, $key); return [$t, hash_hmac('sha256', $t, need('ACTIONS_RELAY_KEY'))]; }
/** A write under an action token (no session, no CSRF): [status, body]. */
function act_token(string $path, array $form, array $headers): array { $r = req('POST', $path, ['headers' => array_merge(JSONH, $headers), 'form' => $form]); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; }

/** A client by SQL (named SMOKE …). */
function mkclient(string $name, array $extra = []): int
{
    return (int) one('INSERT INTO clients (name, code, currency, created_by) VALUES (:n, :c, :cur, 1) RETURNING id', ['n' => $name, 'c' => $extra['code'] ?? null, 'cur' => $extra['currency'] ?? null]);
}
/** An engagement by SQL (slice 2 brings the screens): active unless told, on a client, optionally on a rate card, managed by Marco. */
function mkengagement(int $client, string $name = 'SMOKE engagement', string $status = 'active', ?int $rateCard = null): int
{
    return (int) one("INSERT INTO engagements (number, client_id, name, kind, status, currency, rate_card_id, manager_member_id, created_by) VALUES (next_document_number('engagement'), :c, :n, 'time_and_materials', :s, 'USD', :r, 27, 1) RETURNING id",
        ['c' => $client, 'n' => $name, 's' => $status, 'r' => $rateCard]);
}
/** The rate card named, or null. */
function card_id(string $name): ?int { $v = one('SELECT id FROM rate_cards WHERE name = :n', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
