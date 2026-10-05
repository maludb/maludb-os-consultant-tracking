<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

/** The screen a notification points at, by the record it names (null when none). */
function notification_record_url(array $n): ?string
{
    $id = $n['record_id'] !== null ? (int) $n['record_id'] : null;
    if ($id === null) {
        return null;
    }
    return match ($n['record_type']) {
        'engagement' => '/engagements/' . $id, 'invoice' => '/invoices/' . $id, 'timesheet' => '/time/weeks/' . $id,
        'expense' => '/expenses/' . $id, 'usage_charge' => '/usage/' . $id, 'client' => '/clients/' . $id, 'ai_statement' => '/usage/os',
        default => null,
    };
}

/** An agent of this application as JSON: what is declared, who was hired, what they did — no run's text, no token. */
function present_agent(array $a): array
{
    $d = $a['last_dispatch'];
    return ['key' => $a['key'], 'declared' => $a['declared'], 'hired' => $a['member'] !== null, 'member' => $a['member'] === null ? null : ['member_id' => $a['member']['member_id'], 'name' => $a['member']['display_name'], 'job_title' => $a['member']['job_title'],
            'status' => $a['member']['status'], 'capability' => $a['member']['capability'], 'roles' => $a['member']['roles']],
            'last_activity' => $a['last_activity'] === null ? null : ['at' => json_ts($a['last_activity']['at']), 'action' => $a['last_activity']['action'], 'source' => $a['last_activity']['source']], 'activity_7d' => $a['activity_7d'],
            'last_dispatch' => $d === null ? null : ['dispatch_id' => (int) $d['agent_dispatch_id'], 'kind' => $d['kind'], 'via' => $d['via'], 'status' => $d['status'], 'attempts' => (int) $d['attempts'], 'at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'])],
            'dispatches' => $a['dispatch_counts'], 'last_note' => $a['note'] === null ? null : ['at' => json_ts($a['note']['created_at']), 'title' => $a['note']['title'], 'for' => $a['note']['for_name']]];
}

function present_share(array $s): array
{
    return ['tool' => $s['tool'], 'description' => $s['description'], 'people' => $s['people'], 'scoped' => $s['scoped'], 'reads' => $s['reads'],
            'last_read' => $s['last'] === null ? null : ['at' => json_ts($s['last']['at']), 'rows' => $s['last']['rows']]];
}

function present_read(array $r): array
{
    return ['provider' => $r['provider'], 'tool' => $r['tool'], 'why' => $r['why'], 'declared' => $r['declared'], 'state' => $r['state'],
            'last' => $r['last'] === null ? null : ['at' => json_ts($r['last']['at']), 'status' => $r['last']['status'], 'code' => $r['last']['code'], 'rows' => $r['last']['rows']]];
}
