<?php
declare(strict_types=1);
/** /billing/due — what awaits an invoice (screen `due-to-draft`): the engagements whose billing date has passed with work to bill, the accepted milestones not yet invoiced, and the engagements that already have an open draft. invoices.draft; `invoices_due_to_draft()`. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_any_right('invoices.draft|billing.write');
require_human();
$pdo = db();
$asOf = request_date('as_of') ?: null;
$r = invoices_due_to_draft($pdo, $asOf);
log_screen_view($pdo, 'due-to-draft');
if (wants_json()) {
    respond_screen(['as_of' => $asOf ?? date('Y-m-d'), 'due' => array_map(static fn (array $e): array => ['engagement_id' => $e['engagement_id'], 'number' => $e['number'], 'name' => $e['name'], 'client' => $e['client_name'], 'billing_cadence' => $e['billing_cadence'],
        'next_billing_date' => $e['next_billing_date'], 'currency' => $e['currency'], 'unbilled' => $e['wip']['total_amount']], $r['due']),
        'milestones' => array_map(static fn (array $m): array => ['milestone_id' => $m['milestone_id'], 'engagement_id' => $m['engagement_id'], 'engagement' => $m['engagement_number'], 'name' => $m['name'], 'amount' => $m['amount'], 'currency' => $m['currency'], 'accepted_at' => json_ts($m['accepted_at'])], $r['milestones']),
        'drafts' => array_map(static fn (array $d): array => ['invoice_id' => $d['invoice_id'], 'engagement_id' => $d['engagement_id'], 'engagement' => $d['engagement_number'], 'total' => $d['total'], 'currency' => $d['currency']], $r['drafts'])]);
}
render_screen('Due to draft', view('billing/due.php', ['r' => $r, 'here' => here_url(), 'may' => ['draft' => has_right('invoices.draft')]]), ['activeNav' => 'unbilled', 'screen' => 'due-to-draft', 'entity' => 'engagement']);
