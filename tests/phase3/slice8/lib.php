<?php
/**
 * Helpers for the slice 8 proofs (docs/build-specs/worker-doors-shares.md, "Proof"). Builds on slice 7's (so 6's … 1's): the REPORT WORLD (slice 7's lib) is the data — Acme's INV-00001 (2,479.00, issued, UNPAID), Bea's INV-00002 (300.00),
 * 620.00 unbilled on Acme (hosting 60.00, Priya's 3 h = 540.00, a 20.00 taxi). The worker is run as a PROCESS (`php bin/worker.php --steps=…`) with CT_WORKER_NOW as the clock; the fakes — kernel :8302 (its K6 SMS and K7 read states and
 * their request logs), MaluMail :8306 (its request log) — are on the proof's environment. Never a real provider.
 */
require dirname(__DIR__) . '/slice7/lib.php';

/** The fakes' files (the servers' state dir). */
function state_dir(): string { return dirname(need('FAKE_KERNEL_STATE')); }
function mail_log_file(): string { return need('FAKE_MALUMAIL_LOG'); }
function sms_log_file(): string { return need('FAKE_KERNEL_STATE') . '.sms'; }
function reads_log_file(): string { return need('FAKE_KERNEL_STATE') . '.reads'; }
/** The lines of a JSON-lines log, decoded. */
function log_lines(string $file): array { return is_file($file) ? array_map(static fn (string $l): array => json_decode($l, true) ?: [], array_values(array_filter(explode("\n", (string) file_get_contents($file))))) : []; }
function mail_log(): array { return log_lines(mail_log_file()); }
function sms_log(): array { return log_lines(sms_log_file()); }
function reads_log(): array { return log_lines(reads_log_file()); }
/** Empty the fakes' request logs (and the kernel's SMS/read states). */
function reset_fakes(): void
{
    foreach ([mail_log_file(), sms_log_file(), reads_log_file()] as $f) { file_put_contents($f, ''); }
    kernel_state(function (array $s) { unset($s['sms'], $s['reads']); return $s; });
}
function set_sms(array $sms): void { kernel_state(function (array $s) use ($sms) { $s['sms'] = $sms; return $s; }); }
function set_reads(array $reads): void { kernel_state(function (array $s) use ($reads) { $s['reads'] = $reads; return $s; }); }

/**
 * Run the worker: [exit code, the report decoded (the JSON line), raw output]. $steps names steps (they run whether due or not), '' runs by rhythm; $now is the clock (ISO 8601) the steps select by.
 */
function worker(string $steps = '', ?string $now = null, string $extra = '', string $envs = ''): array
{
    $env = ($now === null ? 'env -u CT_WORKER_NOW' : 'env CT_WORKER_NOW=' . escapeshellarg($now)) . ($envs === '' ? '' : ' ' . $envs);
    $cmd = $env . ' php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ($steps === '' ? '' : ' --steps=' . $steps) . ' ' . $extra . ' 2>&1';
    $out = [];
    exec($cmd, $out, $code);
    $line = '';
    foreach ($out as $l) { if (str_starts_with(ltrim($l), '{')) { $line = $l; } }
    return [$code, json_decode($line, true) ?? [], implode("\n", $out)];
}
/** One step's counts from a worker run. */
function step_counts(array $report, string $step): array { return $report['results'][$step] ?? []; }
/** Set a clock one hour ahead of the real one (the outbox's backoff in minutes is always over). */
function hours_from_now(int $h): string { return gmdate('Y-m-d\TH:i:s\Z', time() + 3600 * $h); }

/** The outbox rows of a kind (optionally for a member or a record), oldest first. */
function outbox_of(string $kind, ?int $member = null, ?int $record = null): array
{
    return q('SELECT * FROM notification_outbox WHERE kind = :k' . ($member === null ? '' : ' AND member_id = ' . (int) $member) . ($record === null ? '' : ' AND record_id = ' . (int) $record) . ' ORDER BY id', ['k' => $kind]);
}
/** Mark everything queued as handled by the proof (so a later send step touches only what the proof makes). */
function drain_outbox(): void { q("UPDATE notification_outbox SET status = 'skipped', detail = 'proof-drained' WHERE status = 'queued'"); }
/** Today in the firm's zone (UTC in the proofs). */
function today(): string { return gmdate('Y-m-d'); }
function plus_days(string $date, int $n): string { return (new DateTimeImmutable($date))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d'); }
/** Rows of the log for an action since an id. */
function log_since(string $action, int $since): array { return activity($action, $since); }
/** The invoice by number. */
function invoice_by_number(string $n): array { return q('SELECT * FROM invoices WHERE number = :n', ['n' => $n])[0] ?? []; }

/**
 * The additions the exports and the shares proofs share, made once per database (idempotent): CN-… of 250.00 against INV-00001 issued by Bea; three COMPANY-CARD expenses on Acme (meals 60.00 billable with 5.00 tax, meals 25.00 not billable, travel 70.00),
 * all approved; 45.00 of approved billable expense on Beta (its WIP). Answers ['cn' => the credit note's number].
 */
function exports_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $x = report_world(); $acme = $x['acme']; $beta = (int) billing_world()['beta'];
    $bea = as_member(28); $priya = as_member(26); $marco = as_member(27);
    if ((int) one("SELECT count(*) FROM expenses WHERE description = 'SMOKE exports beta wip'") === 0) {
        [$c, $b] = act($bea, '/credit-notes/save.php', ['invoice' => (int) invoice_by_number('INV-00001')['id'], 'reason' => 'SMOKE exports goodwill']);
        $cn = (int) $b['record_id'];
        act($bea, '/credit-notes/lines/save.php', ['credit_note' => $cn, 'description' => 'SMOKE exports credit', 'unit_price' => '250']);
        act($bea, '/credit-notes/issue.php', ['credit_note' => $cn]);
        foreach ([['60.00', 'meals', 'yes', '5.00'], ['25.00', 'meals', 'no', '0'], ['70.00', 'travel', 'yes', '0']] as [$amt, $cat, $bill, $tax]) {
            [$c, $b] = record_expense($priya, $acme, $amt, ['description' => 'SMOKE exports card ' . $amt, 'paid_with' => 'company_card', 'category' => category($cat), 'billable' => $bill, 'tax_amount' => $tax, 'submit' => '1']);
            [$c, $b2] = act($marco, '/expenses/approve.php', ['expense' => (int) $b['record_id']]);
            if ($c !== 200) { fwrite(STDERR, 'approve failed ' . json_encode($b2) . "\n"); }
        }
        [$c, $b] = record_expense($priya, $beta, '45.00', ['description' => 'SMOKE exports beta wip', 'submit' => '1']);
        act($marco, '/expenses/approve.php', ['expense' => (int) $b['record_id']]);
    }
    return $w = ['cn' => (string) one("SELECT number FROM credit_notes WHERE reason = 'SMOKE exports goodwill'")];
}
