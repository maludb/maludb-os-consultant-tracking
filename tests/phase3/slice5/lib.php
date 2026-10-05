<?php
/**
 * Helpers for the slice 5 proofs (docs/build-specs/usage-and-os-statement.md, "Proof"). Builds on slice 4's (so slice 3's, 2's and 1's). The world is slice 3's: Acme T&M (ENG) with Priya, Marco (its manager) and Dana
 * staffed, a Beta fixed bid managed by Marco. The cast: the owner (1), Priya (26), Marco (27, the manager), Bea (28, Billing), Ann (29, Finance), Dana (30), the Clerk (40, an agent with Billing).
 * The fake kernel serves the OS statement from its state file: `ledger_fixture()` writes two months — 2026-08 closed, 2026-09 open — as os.ledger-period/1 documents.
 */
require dirname(__DIR__) . '/slice4/lib.php';

/** The firm's two usage engagements: [Acme, Beta], both managed by Marco, USD. */
function usage_world(): array
{
    return time_world();
}
function usage_row(int $id): array { return q('SELECT * FROM usage_charges WHERE id = :id', ['id' => $id])[0] ?? []; }
function provider(string $key): int { return (int) one('SELECT id FROM usage_providers WHERE provider_key = :k', ['k' => $key]); }
/** Draft a charge over HTTP as a person: [status, body]. $extra overrides the hosting defaults. */
function draft_charge(string $jar, int $engagement, array $extra = []): array
{
    return act($jar, '/usage/save.php', array_merge(['engagement' => $engagement, 'kind' => 'hosting', 'description' => 'SMOKE hosting', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'quantity' => '1', 'unit' => 'months',
        'unit_cost' => '180'], $extra));
}

/** An os.ledger-period/1 line. */
function ledger_line(string $provider, ?int $agentId, ?string $agent, ?int $deptId, ?string $dept, string $amount, array $o = []): array
{
    return $o + ['provider' => $provider, 'model_id' => 11, 'model' => 'claude-test', 'department_id' => $deptId, 'department' => $dept, 'agent_id' => $agentId, 'agent' => $agent, 'application_id' => $o['application_id'] ?? null,
        'application' => $o['application'] ?? null, 'calls' => 12, 'late_calls' => 0, 'input_tokens' => 800000, 'output_tokens' => 200000, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'amount' => $amount, 'currency' => 'USD', 'note' => null];
}
/** An os.ledger-period/1 document. */
function ledger_doc(string $period, string $status, array $lines): array
{
    $start = $period . '-01';
    return ['schema' => 'os.ledger-period/1', 'business' => 'SMOKE Firm', 'period' => $period, 'period_start' => $start, 'period_end' => date('Y-m-t', strtotime($start)), 'status' => $status, 'closed_at' => $status === 'closed' ? $period . '-28T00:00:00Z' : null,
        'rolled_up_at' => $period . '-28T00:00:00Z', 'currencies' => ['USD'], 'exchange' => 'none — each line in the currency the provider billed; nothing is converted', 'amount_scale' => 4, 'generated_at' => '2026-10-01T00:00:00Z',
        'lines' => $lines, 'totals' => [['currency' => 'USD', 'amount' => number_format(array_sum(array_map(static fn (array $l): float => (float) $l['amount'], $lines)), 4, '.', '')]]];
}
/**
 * The fake kernel's statement: 2026-08 (closed) — the Researcher (agent 901, AI Strategy 7) on Anthropic 100.0000 and on OpenAI 30.3350, the Writer (agent 902, Content 8) on Anthropic 50.0000;
 * 2026-09 (open) — the Researcher on Anthropic 12.5000. Returns the documents by period.
 */
function ledger_fixture(): array
{
    $docs = ['2026-08' => ledger_doc('2026-08', 'closed', [
            ledger_line('anthropic', 901, 'Researcher', 7, 'AI Strategy', '100.0000', ['application_id' => 3, 'application' => 'Projects']),
            ledger_line('openai', 901, 'Researcher', 7, 'AI Strategy', '30.3350'),
            ledger_line('anthropic', 902, 'Writer', 8, 'Content', '50.0000'),
        ]),
        '2026-09' => ledger_doc('2026-09', 'open', [ledger_line('anthropic', 901, 'Researcher', 7, 'AI Strategy', '12.5000')])];
    set_ledger($docs);
    return $docs;
}
/** Put documents (and their list) in the fake kernel's state. */
function set_ledger(array $docs, ?string $mode = null): void
{
    kernel_state(function (array $s) use ($docs, $mode) {
        $s['ledger'] = ['docs' => $docs, 'periods' => array_values(array_map(static fn (array $d): array => ['period' => $d['period'], 'period_start' => $d['period_start'], 'period_end' => $d['period_end'], 'status' => $d['status'],
            'closed_at' => $d['closed_at'], 'statement_lines' => count($d['lines']), 'ledger_calls' => 0], array_reverse($docs)))] + ($mode === null ? [] : ['mode' => $mode]);
        return $s;
    });
}
function clear_ledger(): void { kernel_state(function (array $s) { unset($s['ledger']); return $s; }); }
function statement_row(string $period): array { return q('SELECT * FROM ai_statements WHERE period = :p', ['p' => $period])[0] ?? []; }
