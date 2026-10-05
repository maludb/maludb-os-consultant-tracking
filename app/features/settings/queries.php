<?php
declare(strict_types=1);

/**
 * A person's own settings: how they are told (notification_prefs), their in-app notifications, their access tokens for the
 * two MCP servers (screens `settings`, `notifications`, `tokens`; actions prefs_save, notification_read, token_mint, token_revoke).
 * The firm's settings (settings_save, numbering_save) are slice 1's, in this feature too.
 */

/** Every event a person can be told about, with the sentence the settings screen shows (db/013 notification kinds). */
const NOTICE_KINDS = [
    'timesheet_submitted'    => 'Someone submits a week on an engagement I manage',
    'timesheet_approved'     => 'My week is approved',
    'timesheet_rejected'     => 'My week is rejected',
    'missing_time'           => 'I have not submitted last week',
    'expense_submitted'      => 'Someone submits an expense on an engagement I manage',
    'expense_approved'       => 'My expense is approved',
    'expense_rejected'       => 'My expense is rejected',
    'expense_reimbursed'     => 'My expense is reimbursed',
    'usage_drafted'          => 'A usage charge is drafted on an engagement I manage',
    'budget_alert'           => 'An engagement I manage crosses a budget threshold',
    'invoice_drafted'        => 'An invoice is drafted for an engagement I manage',
    'invoice_viewed'         => 'A client opens an invoice',
    'claim_submitted'        => 'A client says they paid an invoice',
    'invoice_overdue'        => 'An invoice falls overdue',
    'invoice_paid'           => 'An invoice is paid',
    'morning_note'           => 'The Billing Clerk leaves the morning note',
    'ai_statement_allocated' => 'The OS statement is allocated',
    'milestone_delivered'    => 'A milestone is delivered on an engagement I manage',
    'mentioned'              => 'Someone mentions me in a note',
    'agent_drafted'          => 'An agent drafts something for me to check',
];

/** The person's choices, or the table's defaults when they never saved any. */
function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\{[^']*\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'saved' => true];
}

/** Save the choices ($prefs keys present are changed; absent are kept). Answers ['before' => …, 'after' => …] of the changed keys. */
function save_prefs(PDO $pdo, int $memberId, array $prefs): array
{
    $before = find_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($prefs, $before) + $before;
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]))
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                                                        text_kinds = EXCLUDED.text_kinds, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']), 'tk' => $lit($after['text_kinds'])]);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/**
 * What the kernel last said about this person's texts, from our own outbox (this application never sees a phone number):
 * 'no_verified_phone', 'opted_out', 'no_sender' or null when the latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains((string) $r['detail'], $code)) {
            return $code;
        }
    }
    return null;
}

// ---- notifications (the bell) ----------------------------------------------------------------------------------------
function find_my_notifications(PDO $pdo, int $memberId, bool $unreadOnly = false, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, read_at, created_at FROM mcp_notifications'
        . ($unreadOnly ? ' WHERE read_at IS NULL' : '') . ' ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute();
    return $st->fetchAll();
}

/** Mark one of the member's own notifications read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

// ---- tokens -----------------------------------------------------------------------------------------------------------
function find_my_tokens(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens WHERE member_id = :m ORDER BY created_at DESC');
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

function find_my_token(PDO $pdo, int $memberId, int $tokenId): ?array
{
    $st = $pdo->prepare('SELECT id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens WHERE member_id = :m AND id = :id');
    $st->execute(['m' => $memberId, 'id' => $tokenId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mint a token: `mcp_` + 48 hex, shown once; only the hash is stored. Returns id, raw, label, scope. */
function mint_token(PDO $pdo, int $memberId, string $label, string $scope): array
{
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, :s) RETURNING id');
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $scope]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label, 'scope' => $scope];
}

/** Revoke one of the member's own live tokens. False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $tokenId): bool
{
    $st = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND revoked_at IS NULL RETURNING id');
    $st->execute(['id' => $tokenId, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}

// ---- the firm's settings and the numbering (slice 1) --------------------------------------------------------------------------------

const SETTINGS_TEXT = ['firm_name' => 200, 'firm_address' => 500, 'firm_tax_id' => 60, 'invoice_footer' => 1000];
const TIME_GROUPINGS = ['by_person' => 'By person', 'by_role' => 'By consulting role', 'by_activity' => 'By activity', 'by_day' => 'By day', 'single_line' => 'One line'];
const HOURS_INCREMENTS = ['0.05', '0.10', '0.25', '0.50', '1.00'];
const SEQUENCE_KINDS = ['engagement' => 'Engagements', 'invoice' => 'Invoices', 'credit_note' => 'Credit notes', 'expense' => 'Expenses', 'usage_charge' => 'Usage charges'];
const DOW = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

/** The one settings row, typed. */
function find_settings(PDO $pdo): array
{
    $s = $pdo->query('SELECT * FROM ct_settings WHERE id = 1')->fetch();
    foreach (['week_start_dow', 'default_terms_days', 'link_lifetime_days', 'ai_budget_threshold_pct', 'morning_note_member_id', 'missing_time_nudge_dow'] as $k) {
        $s[$k] = $s[$k] === null ? null : (int) $s[$k];
    }
    foreach (['share_receipts_default', 'ai_statement_poll'] as $k) {
        $s[$k] = (bool) $s[$k];
    }
    foreach (['hours_increment', 'max_hours_per_day', 'receipt_threshold', 'default_markup_pct', 'capacity_hours_per_week'] as $k) {
        $s[$k] = number_format((float) $s[$k], 2, '.', '');
    }
    $s['reminder_days'] = pg_int_array($s['reminder_days']);
    $s['budget_thresholds_pct'] = pg_int_array($s['budget_thresholds_pct']);
    unset($s['id']);
    return $s;
}

/** Save the keys given; answers ['before' => …, 'after' => …] of what changed (nothing changed: both empty). */
function save_settings(PDO $pdo, array $fields, int $by): array
{
    $before = find_settings($pdo);
    $after = array_intersect_key($fields, $before) + $before;
    $norm = static function (array $s): array {
        foreach (['hours_increment', 'max_hours_per_day', 'receipt_threshold', 'default_markup_pct', 'capacity_hours_per_week'] as $k) {
            $s[$k] = number_format((float) $s[$k], 2, '.', '');
        }
        unset($s['updated_at']);
        return $s;
    };
    $d = ct_diff($norm($before), $norm($after));
    if ($d['after'] === []) {
        return $d;
    }
    $sets = [];
    $args = [];
    foreach ($d['after'] as $k => $v) {
        $sets[] = $k . ' = ' . (in_array($k, ['reminder_days', 'budget_thresholds_pct'], true) ? 'CAST(:' . $k . ' AS integer[])' : ':' . $k);
        $args[$k] = is_bool($v) ? ($v ? 't' : 'f') : (is_array($v) ? pg_array_literal($v) : $v);
    }
    $pdo->prepare('UPDATE ct_settings SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($args);
    return $d;
}

function present_settings(array $s): array
{
    return $s + ['updated_at' => json_ts($s['updated_at'] ?? null)];
}

/** The five sequences, with a preview of the next number and the highest number issued (the floor the next value may not go below). */
function find_numbering(PDO $pdo): array
{
    $rows = $pdo->query('SELECT kind, prefix, next_value, padding FROM document_sequences ORDER BY array_position(ARRAY[\'engagement\', \'invoice\', \'credit_note\', \'expense\', \'usage_charge\'], kind)')->fetchAll();
    $issued = [
        'engagement' => (int) one_value($pdo, "SELECT max(substring(number FROM '[0-9]+$')::bigint) FROM engagements WHERE number ~ '[0-9]+$'"),
        'invoice' => (int) one_value($pdo, "SELECT max(substring(number FROM '[0-9]+$')::bigint) FROM invoices WHERE number IS NOT NULL AND number ~ '[0-9]+$'"),
        'credit_note' => (int) one_value($pdo, "SELECT max(substring(number FROM '[0-9]+$')::bigint) FROM credit_notes WHERE number IS NOT NULL AND number ~ '[0-9]+$'"),
        'expense' => (int) one_value($pdo, "SELECT max(substring(number FROM '[0-9]+$')::bigint) FROM expenses WHERE number ~ '[0-9]+$'"),
        'usage_charge' => (int) one_value($pdo, "SELECT max(substring(number FROM '[0-9]+$')::bigint) FROM usage_charges WHERE number ~ '[0-9]+$'"),
    ];
    return array_map(static fn (array $r): array => ['kind' => $r['kind'], 'label' => SEQUENCE_KINDS[$r['kind']] ?? $r['kind'], 'prefix' => $r['prefix'], 'next_value' => (int) $r['next_value'], 'padding' => (int) $r['padding'],
        'preview' => $r['prefix'] . str_pad((string) $r['next_value'], (int) $r['padding'], '0', STR_PAD_LEFT), 'highest_issued' => $issued[$r['kind']] ?? 0], $rows);
}

/** Change a sequence's prefix, next value or padding. The next value never goes below the highest issued + 1. Answers the diff. */
function save_numbering(PDO $pdo, string $kind, array $fields, int $by): array
{
    $rows = array_column(find_numbering($pdo), null, 'kind');
    $cur = $rows[$kind] ?? throw new DomainException('Not found.');
    $before = ['prefix' => $cur['prefix'], 'next_value' => $cur['next_value'], 'padding' => $cur['padding']];
    $after = array_intersect_key($fields, $before) + $before;
    if ($after['next_value'] <= $cur['highest_issued']) {
        throw new DomainException('The next number cannot go below ' . ($cur['highest_issued'] + 1) . ': ' . $cur['prefix'] . str_pad((string) $cur['highest_issued'], $cur['padding'], '0', STR_PAD_LEFT) . ' is already issued.');
    }
    $d = ct_diff($before, $after);
    if ($d['after'] !== []) {
        $pdo->prepare('UPDATE document_sequences SET prefix = :p, next_value = :n, padding = :pad, updated_at = now() WHERE kind = :k')->execute(['p' => $after['prefix'], 'n' => $after['next_value'], 'pad' => $after['padding'], 'k' => $kind]);
    }
    return $d;
}
