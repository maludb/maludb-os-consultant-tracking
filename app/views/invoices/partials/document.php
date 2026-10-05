<?php
/**
 * THE invoice document — one partial, two doors (the secure link and the staff preview), and the print page. Data: d (public_invoice_document: firm, invoice, lines with items, payments, credits, waiting_claims), mode ('secure'|'print'|'preview'), token? (secure/print).
 * A client sees: the firm, the client's legal name, the lines, the backing detail (time by day and person with the client-visible notes, expenses with the receipts the engagement shares, usage by provider and period, milestones),
 * payments received, credits applied, the balance. NEVER a cost, a rate card's name, an internal note, a person's rate, another document.
 */
$inv = $d['invoice']; $firm = $d['firm']; $cur = $inv['currency']; $mode = $mode ?? 'secure'; $token = $token ?? '';
$receiptUrl = static fn (int $aid): string => $mode === 'preview' ? '/attachments/' . $aid : '/i/' . $token . '/attachment/' . $aid;
$byKind = ['time' => [], 'expense' => [], 'usage' => [], 'milestone' => []];
foreach ($d['lines'] as $l) { foreach ($l['items'] as $x) { $byKind[$x['kind']][] = $x; } }
$tones = ['draft' => 'secondary', 'issued' => 'primary', 'sent' => 'primary', 'viewed' => 'primary', 'partially_paid' => 'warning', 'paid' => 'success', 'void' => 'dark', 'written_off' => 'danger'];
$words = ['draft' => 'Draft', 'issued' => 'Awaiting payment', 'sent' => 'Awaiting payment', 'viewed' => 'Awaiting payment', 'partially_paid' => 'Partly paid', 'paid' => 'Paid', 'void' => 'Void', 'written_off' => 'Closed'];
?>
<div class="invoice-document" id="secure-invoice" data-invoice="<?= e((string) $inv['number']) ?>">
    <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
        <div id="secure-invoice-firm"><div class="fw-bold fs-16"><?= e($firm['firm_name'] ?? '') ?></div><div class="fs-12 text-muted" style="white-space: pre-line"><?= e($firm['firm_address'] ?? '') ?></div><?php if (!empty($firm['firm_tax_id'])): ?><div class="fs-12 text-muted">Tax id <?= e($firm['firm_tax_id']) ?></div><?php endif; ?></div>
        <div class="text-md-end"><div class="fs-20 fw-bold" id="secure-invoice-number">Invoice <?= e((string) ($inv['number'] ?? 'draft')) ?></div>
            <span class="badge bg-soft-<?= $tones[$inv['status']] ?> text-<?= $tones[$inv['status']] ?>" id="secure-invoice-status"><?= e($words[$inv['status']]) ?></span>
            <?php if ($inv['overdue']): ?><span class="badge bg-soft-danger text-danger" id="secure-invoice-overdue">Past due</span><?php endif; ?></div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6" id="secure-invoice-billto"><div class="fs-12 text-muted">Billed to</div><div class="fw-semibold"><?= e($inv['legal_name'] ?: $inv['client_name']) ?></div><div class="fs-12" style="white-space: pre-line"><?= e((string) $inv['billing_address']) ?></div></div>
        <div class="col-12 col-md-6 fs-12" id="secure-invoice-dates">
            <div><span class="text-muted">For</span> <?= e($inv['engagement_name']) ?></div>
            <div><span class="text-muted">Issued</span> <?= e(format_date($inv['issue_date'])) ?> · <span class="text-muted">Due</span> <strong><?= e(format_date($inv['due_date'])) ?></strong></div>
            <?php if ($inv['period_start'] || $inv['period_end']): ?><div><span class="text-muted">Period</span> <?= e(format_date($inv['period_start'])) ?><?= $inv['period_end'] ? ' – ' . e(format_date($inv['period_end'])) : '' ?></div><?php endif; ?>
            <?php if ($inv['po_number']): ?><div><span class="text-muted">Your PO</span> <span id="secure-invoice-po"><?= e($inv['po_number']) ?></span></div><?php endif; ?>
        </div>
    </div>
    <?php if ($inv['memo']): ?><div class="alert alert-light border fs-12" id="secure-invoice-memo" style="white-space: pre-line"><?= e($inv['memo']) ?></div><?php endif; ?>
    <div class="table-responsive mb-3"><table class="table fs-12 mb-0" id="secure-invoice-lines">
        <thead><tr><th>Description</th><th class="text-end">Quantity</th><th class="text-end">Price</th><th class="text-end">Tax</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($d['lines'] as $l): ?><tr id="secure-line-<?= (int) $l['line_id'] ?>"><td><?= e($l['description']) ?></td><td class="text-end text-nowrap"><?= e(rtrim(rtrim(number_format((float) $l['quantity'], 4, '.', ','), '0'), '.')) ?><?= $l['unit'] ? ' ' . e($l['unit']) : '' ?></td>
            <?= fmt_money_cell($l['unit_price'], $cur) ?><td class="text-end text-nowrap"><?= (float) $l['tax_rate'] > 0 ? e(days_label($l['tax_rate'])) . '%' : '—' ?></td><?= fmt_money_cell($l['line_total'], $cur) ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="row justify-content-end mb-3"><div class="col-12 col-md-5"><table class="table table-sm fs-12 mb-0" id="secure-invoice-totals">
        <tr><td>Subtotal</td><?= fmt_money_cell($inv['subtotal'], $cur) ?></tr><tr><td>Tax</td><?= fmt_money_cell($inv['tax_total'], $cur) ?></tr><tr class="fw-bold"><td>Total</td><?= fmt_money_cell($inv['total'], $cur, 'secure-invoice-total') ?></tr>
        <?php foreach ($d['payments'] as $p): ?><tr class="secure-payment"><td>Paid <?= e(format_date($p['paid_on'])) ?> (<?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?>)</td><?= fmt_money_cell('-' . $p['amount'], $cur) ?></tr><?php endforeach; ?>
        <?php foreach ($d['credits'] as $c): ?><tr class="secure-credit"><td>Credit <?= e($c['number']) ?></td><?= fmt_money_cell('-' . $c['amount'], $cur) ?></tr><?php endforeach; ?>
        <tr class="fw-bold fs-14"><td>Balance due</td><?= fmt_money_cell(in_array($inv['status'], ['void', 'written_off'], true) ? '0' : $inv['balance_due'], $cur, 'secure-invoice-balance') ?></tr>
    </table></div></div>
    <?php if (array_filter($byKind)): ?>
    <h6 class="mt-3 mb-2" id="secure-detail-title">The detail behind the lines</h6>
    <?php if ($byKind['time'] !== []): ?><details class="card mb-2" id="secure-detail-time" <?= $mode === 'print' ? 'open' : '' ?>><summary class="card-header fw-semibold" style="min-height: 44px; cursor: pointer">Time (<?= count($byKind['time']) ?> entries)</summary>
        <div class="table-responsive"><table class="table table-sm fs-12 mb-0"><thead><tr><th>Date</th><th>Who</th><th class="text-end">Hours</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($byKind['time'] as $x): ?><tr class="secure-time-row"><td class="text-nowrap"><?= e(format_date($x['t_date'])) ?></td><td><?= e($x['t_person']) ?></td><td class="text-end"><?= e(fmt_hours($x['t_hours'])) ?></td><td><?= e((string) $x['t_note']) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    <?php if ($byKind['expense'] !== []): ?><details class="card mb-2" id="secure-detail-expenses" <?= $mode === 'print' ? 'open' : '' ?>><summary class="card-header fw-semibold" style="min-height: 44px; cursor: pointer">Expenses (<?= count($byKind['expense']) ?>)</summary>
        <div class="table-responsive"><table class="table table-sm fs-12 mb-0"><thead><tr><th>Date</th><th>What</th><th class="text-end">Amount</th><th>Receipt</th></tr></thead><tbody>
        <?php foreach ($byKind['expense'] as $x): ?><tr class="secure-expense-row"><td class="text-nowrap"><?= e(format_date($x['e_date'])) ?></td><td><?= e((string) $x['e_category']) ?> — <?= e((string) $x['e_description']) ?><?= $x['e_vendor'] ? ' (' . e($x['e_vendor']) . ')' : '' ?></td><?= fmt_money_cell($x['amount'], $cur) ?>
            <td><?php foreach ($x['receipts'] as $r): $img = str_starts_with((string) $r['mime_type'], 'image/'); ?><a href="<?= e($receiptUrl((int) $r['id'])) ?>" target="_blank" rel="noopener" class="secure-receipt d-inline-block me-1" id="secure-receipt-<?= (int) $r['id'] ?>"><?php if ($img): ?><img src="<?= e($receiptUrl((int) $r['id'])) ?>" alt="Receipt" class="rounded border" style="width: 44px; height: 44px; object-fit: cover"><?php else: ?><span class="badge bg-soft-secondary text-secondary"><?= e($r['filename']) ?></span><?php endif; ?></a><?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    <?php if ($byKind['usage'] !== []): ?><details class="card mb-2" id="secure-detail-usage" <?= $mode === 'print' ? 'open' : '' ?>><summary class="card-header fw-semibold" style="min-height: 44px; cursor: pointer">Usage and hosting (<?= count($byKind['usage']) ?>)</summary>
        <div class="table-responsive"><table class="table table-sm fs-12 mb-0"><thead><tr><th>Provider</th><th>Period</th><th>What</th><th class="text-end">Amount</th></tr></thead><tbody>
        <?php foreach ($byKind['usage'] as $x): ?><tr class="secure-usage-row"><td><?= e((string) ($x['u_provider'] ?? '—')) ?></td><td class="text-nowrap"><?= e(format_date($x['u_start'])) ?> – <?= e(format_date($x['u_end'])) ?></td><td><?= e((string) $x['u_description']) ?></td><?= fmt_money_cell($x['amount'], $cur) ?></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    <?php if ($byKind['milestone'] !== []): ?><details class="card mb-2" id="secure-detail-milestones" <?= $mode === 'print' ? 'open' : '' ?>><summary class="card-header fw-semibold" style="min-height: 44px; cursor: pointer">Milestones (<?= count($byKind['milestone']) ?>)</summary>
        <div class="table-responsive"><table class="table table-sm fs-12 mb-0"><tbody>
        <?php foreach ($byKind['milestone'] as $x): ?><tr class="secure-milestone-row"><td><?= e((string) $x['m_name']) ?><?= $x['m_accepted'] ? ' — accepted ' . e(format_date(substr((string) $x['m_accepted'], 0, 10))) : '' ?></td><?= fmt_money_cell($x['amount'], $cur) ?></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
    <?php endif; ?>
    <?php if ($inv['terms_text']): ?><div class="fs-12 text-muted mt-3" id="secure-invoice-terms" style="white-space: pre-line"><?= e($inv['terms_text']) ?></div><?php endif; ?>
    <?php if (!empty($firm['invoice_footer'])): ?><div class="fs-12 text-muted mt-2" id="secure-invoice-footer" style="white-space: pre-line"><?= e($firm['invoice_footer']) ?></div><?php endif; ?>
</div>
