<?php
/**
 * Helpers for the slice 6 proofs (docs/build-specs/invoicing.md, "Proof"). Builds on slice 5's (so 4's, 3's, 2's and 1's). The cast: the owner (1), Priya (26, Consultant), Marco (27, the manager of Acme and Beta), Bea (28, Billing),
 * Ann (29, Finance — reads, never writes), Dana (30, Consultant), the Clerk (40, an agent with Billing). The world is slice 3's (Acme T&M, Beta fixed bid, the Standard card: Consultant 180, Analyst 125) and `billing_world()` puts
 * approved work on Acme THROUGH THE HANDLERS: time (Priya 4 + 3 h, Dana 5 + 2 h — two lines by person), 4 h by the owner in a role the card does not price (no rate), two expenses (100.00 with a receipt, 40.00 without), three usage charges
 * (50.00 + 4.00 + 30.00) — 1,260.00 + 875.00 + 140.00 + 84.00 = 2,359.00 unbilled.
 */
require dirname(__DIR__) . '/slice5/lib.php';

/** Money as the database prints it. */
function inv(int $id): array { return q('SELECT * FROM invoices WHERE id = :id', ['id' => $id])[0] ?? []; }
function cn(int $id): array { return q('SELECT * FROM credit_notes WHERE id = :id', ['id' => $id])[0] ?? []; }
function lines_of(int $id): array { return q('SELECT * FROM invoice_lines WHERE invoice_id = :i ORDER BY sort_order, id', ['i' => $id]); }
function items_of(int $id): int { return (int) one('SELECT count(*) FROM invoice_line_items WHERE invoice_id = :i', ['i' => $id]); }
/** The "approved and unbilled" total of an engagement as ct_unbilled answers it (as a super-admin so the visibility is whole). */
function wip(int $engagement): string
{
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    return (string) (one('SELECT COALESCE(sum(total_amount), 0) FROM ct_unbilled(current_date, :e, NULL)', ['e' => $engagement]) ?: '0');
}
/** A draft invoice over HTTP as a person: [status, body]. */
function draft(string $jar, int $engagement, array $extra = []): array
{
    return act($jar, '/invoices/save.php', array_merge(['engagement' => $engagement, 'add_unbilled' => 'yes'], $extra));
}
/** The outbox rows of a kind for a record. */
function outbox(string $kind, ?int $recordId = null): array
{
    return q('SELECT * FROM notification_outbox WHERE kind = :k' . ($recordId === null ? '' : ' AND record_id = :r') . ' ORDER BY id', ['k' => $kind] + ($recordId === null ? [] : ['r' => $recordId]));
}
/** The raw token out of an outbox body (the link at its foot), or ''. */
function token_in(string $body): string { return preg_match('#/i/([a-f0-9]{48})#', $body, $m) ? $m[1] : ''; }
/** A page as a visitor with no session (a jar of its own, so the CSRF token and the cookie are the anonymous session's). */
function visitor(string $path, string $jar = '/dev/null'): array { return req('GET', $path, ['jar' => $jar]); }
/** A client contact who receives invoices (SQL; the contact screens are slice 1's). */
function bill_contact(int $client, string $email, string $name = 'SMOKE AP'): void
{
    q('INSERT INTO client_contacts (client_id, name, email, receives_invoices, is_primary) VALUES (:c, :n, :e, true, false)', ['c' => $client, 'n' => $name, 'e' => $email]);
}

/**
 * The slice-6 world, made once per database; returns ['acme', 'beta', 'client', 'priya_entries', 'expenses', 'usage'].
 */
function billing_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    [$acme, $beta] = time_world();
    $client = (int) one('SELECT client_id FROM engagements WHERE id = :e', ['e' => $acme]);
    $marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $owner = as_member(1);
    if ((int) one("SELECT count(*) FROM expenses WHERE description = 'SMOKE bill hotel'") === 0) {
        q("INSERT INTO consulting_roles (key, title, sort_order) VALUES ('smoke_unpriced', 'SMOKE Unpriced', 99) ON CONFLICT DO NOTHING");
        act($marco, '/engagements/staffing/save.php', ['engagement' => $acme, 'member' => 1, 'role' => role('smoke_unpriced'), 'valid_from' => '2026-01-05']);
        $w0 = monday(3);
        foreach ([[$priya, 26, [[0, '4'], [1, '3']]], [$dana, 30, [[0, '5'], [1, '2']]], [$owner, 1, [[2, '4']]]] as [$jar, $m, $days]) {
            foreach ($days as [$d, $h]) { [$c, $b] = log_time($jar, $acme, day($w0, $d), $h, ['note' => 'SMOKE work ' . $m . ' day ' . $d]); if ($c !== 200) { fwrite(STDERR, "log_time failed: " . json_encode($b) . "\n"); } }
            [$c, $b] = act($jar, '/time/weeks/submit.php', ['week' => $w0]);
            if ($c !== 200) { fwrite(STDERR, "submit failed for $m: " . json_encode($b) . "\n"); }
            [$c, $b] = act($marco, '/time/weeks/approve.php', ['timesheet' => week_id($m, $w0)]);
            if ($c !== 200) { fwrite(STDERR, "approve failed for $m: " . json_encode($b) . "\n"); }
        }
        // two expenses: the hotel with its receipt (shared with the client by the settings' default), a taxi under the threshold
        $jpg = jpeg_file('hotel.jpg');
        [$c, $b] = upload($priya, '/expenses/save.php', ['engagement' => $acme, 'date' => day($w0, 1), 'category' => category('meals'), 'amount' => '100.00', 'description' => 'SMOKE bill hotel', 'submit' => '1'], 'file', $jpg, 'hotel.jpg');
        $x1 = (int) ($b['record_id'] ?? 0);
        [$c, $b] = record_expense($priya, $acme, '40.00', ['description' => 'SMOKE bill taxi', 'date' => day($w0, 2), 'submit' => '1']);
        $x2 = (int) ($b['record_id'] ?? 0);
        foreach ([$x1, $x2] as $x) { [$c, $b] = act($marco, '/expenses/approve.php', ['expense' => $x]); if ($c !== 200) { fwrite(STDERR, "expense approve failed: " . json_encode($b) . "\n"); } }
        // three usage charges
        $u = [];
        foreach ([['hosting', 'SMOKE bill VM', '1', 'months', '50', null], ['model_usage', 'SMOKE bill tokens', '1000000', 'tokens', '0.000004', provider('anthropic')], ['hosting', 'SMOKE bill DB', '1', 'months', '30', null]] as [$kind, $desc, $qty, $unit, $cost, $prov]) {
            [$c, $b] = act($marco, '/usage/save.php', ['engagement' => $acme, 'kind' => $kind, 'description' => $desc, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'quantity' => $qty, 'unit' => $unit, 'unit_cost' => $cost] + ($prov === null ? [] : ['provider' => $prov]));
            $uid = (int) ($b['record_id'] ?? 0);
            [$c, $b] = act($marco, '/usage/approve.php', ['charge' => $uid]);
            if ($c !== 200) { fwrite(STDERR, "usage approve failed: " . json_encode($b) . "\n"); }
            $u[] = $uid;
        }
    }
    $x = array_column(q("SELECT id FROM expenses WHERE description IN ('SMOKE bill hotel', 'SMOKE bill taxi') ORDER BY id"), 'id');
    $u = array_column(q("SELECT id FROM usage_charges WHERE description LIKE 'SMOKE bill %' ORDER BY id"), 'id');
    return $w = ['acme' => $acme, 'beta' => $beta, 'client' => $client, 'expenses' => array_map('intval', $x), 'usage' => array_map('intval', $u)];
}

/** The open draft for Acme (the one draft.php leaves, with every approved item on it), or a new one. */
function acme_draft(string $jar): int
{
    $w = billing_world();
    $id = one("SELECT id FROM invoices WHERE engagement_id = :e AND status = 'draft' ORDER BY id DESC LIMIT 1", ['e' => $w['acme']]);
    if ($id !== false && $id !== null) { return (int) $id; }
    [$c, $b] = draft($jar, $w['acme'], ['period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
    return (int) $b['record_id'];
}
/** Run SQL that the database must refuse and answer its sentence (or '' when it was allowed). The transaction is left clean. */
function refused(string $sql, array $args = []): string
{
    try { $st = pdo()->prepare($sql); $st->execute($args); return ''; }
    catch (PDOException $e) { return trim((string) (preg_split('/\R|CONTEXT:/', substr($e->getMessage(), (int) strpos($e->getMessage(), 'ERROR:') + 6))[0] ?? '')); }
}
