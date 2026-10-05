<?php
declare(strict_types=1);
/**
 * /invoices/new?engagement=&from=&to= (screen `invoice-add`) and /invoices/{id}/edit (screen `invoice-edit`, the draft's lines editor): invoices.draft AND manage the engagement. The form drafts an invoice for an engagement and a
 * period and pulls the unbilled items (checked); the editor regroups, adds a fixed / adjustment / discount line, sets a tax rate per line, reorders, removes a line or one item (it returns to unbilled), with the database's totals strip.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('invoices.draft');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('invoice');
$taxRates = tax_rates_for_pick($pdo);
if ($id !== null) {
    $i = find_invoice($pdo, $id) ?? refuse(404, 'Invoice not found.');
    require_draft_on($i);
    if ($i['status'] !== 'draft') {
        refuse(422, 'Invoice ' . $i['number'] . ' is ' . $i['status'] . ' and cannot be edited; credit it, or void it.');
    }
    $nte = null;
    $eng = $pdo->prepare('SELECT kind, nte_amount, number, time_grouping FROM mcp_engagements WHERE engagement_id = :e');
    $eng->execute(['e' => $i['engagement_id']]);
    $g = $eng->fetch();
    if ($g && $g['kind'] === 'time_and_materials' && $g['nte_amount'] !== null) {
        $issued = (string) one_value($pdo, "SELECT COALESCE(sum(total), 0) FROM mcp_invoices WHERE engagement_id = :e AND status NOT IN ('draft', 'void')", ['e' => $i['engagement_id']]);
        $nte = ['cap' => $g['nte_amount'], 'issued' => $issued, 'after' => money_add($issued, (string) $i['total']), 'over' => (float) money_add($issued, (string) $i['total']) > (float) $g['nte_amount']];
    }
    log_activity($pdo, 'screen.view', 'invoice', $i['invoice_id'], ['screen' => 'invoice-edit', 'invoice_id' => $i['invoice_id'], 'engagement_id' => $i['engagement_id'], 'client_id' => $i['client_id']]);
    $grouping = (string) ($g['time_grouping'] ?? '') ?: (string) one_value($pdo, 'SELECT default_time_grouping FROM ct_settings WHERE id = 1');
    if (wants_json()) {
        respond_screen(['invoice' => present_invoice($i, true), 'tax_rates' => $taxRates, 'line_kinds' => array_keys(array_intersect_key(LINE_KINDS, array_flip(EDITABLE_LINE_KINDS))), 'time_grouping' => $grouping, 'nte' => $nte]);
    }
    $notices = ['saved' => ['success', 'Saved the draft.'], 'line_saved' => ['success', 'Saved the line.'], 'line_removed' => ['success', 'The line was removed.'], 'item_removed' => ['success', 'The item is unbilled again.']];
    render_screen('Edit ' . $i['engagement_number'] . ' draft', view('invoices/edit.php', ['i' => $i, 'taxRates' => $taxRates, 'nte' => $nte, 'grouping' => $grouping, 'here' => here_url(), 'notice' => ct_page_notice($notices)]),
        ['activeNav' => 'invoices', 'screen' => 'invoice-edit', 'entity' => 'invoice', 'recordId' => (string) $i['invoice_id']]);
    return;
}
$engagements = draftable_engagements($pdo);
$prefill = request_integer('engagement');
$from = request_date('from') ?: null;
$to = request_date('to') ?: null;
$sel = null;
foreach ($engagements as $e) {
    if ($e['engagement_id'] === $prefill) { $sel = $e; }
}
log_screen_view($pdo, 'invoice-add');
if (wants_json()) {
    respond_screen(['engagements' => $engagements, 'prefill' => ['engagement' => $prefill, 'from' => $from, 'to' => $to], 'defaults' => ['issue_date' => date('Y-m-d'), 'terms_days' => (int) one_value($pdo, 'SELECT default_terms_days FROM ct_settings WHERE id = 1')]]);
}
render_screen('Draft an invoice', view('invoices/form.php', ['engagements' => $engagements, 'prefill' => $prefill, 'selected' => $sel, 'from' => $from, 'to' => $to, 'here' => here_url()]), ['activeNav' => 'invoices', 'screen' => 'invoice-add', 'entity' => 'invoice']);
