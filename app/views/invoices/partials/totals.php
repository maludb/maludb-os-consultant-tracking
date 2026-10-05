<?php /** The totals strip — the database's figures (ct_invoice_recalculate). Data: i, cur */ $cur = $i['currency']; ?>
<div class="card mb-3" id="totals-strip"><div class="card-body p-3"><div class="row g-2 text-center text-md-start">
    <div class="col-6 col-md"><div class="fs-12 text-muted">Subtotal</div><div class="fw-semibold" id="totals-subtotal"><?= e(fmt_money($i['subtotal'], $cur)) ?></div></div>
    <div class="col-6 col-md"><div class="fs-12 text-muted">Tax</div><div class="fw-semibold" id="totals-tax"><?= e(fmt_money($i['tax_total'], $cur)) ?></div></div>
    <div class="col-6 col-md"><div class="fs-12 text-muted">Total</div><div class="fw-bold" id="totals-total"><?= e(fmt_money($i['total'], $cur)) ?></div></div>
    <?php if ($i['status'] !== 'draft'): ?>
    <div class="col-6 col-md"><div class="fs-12 text-muted">Paid</div><div class="fw-semibold" id="totals-paid"><?= e(fmt_money($i['amount_paid'], $cur)) ?></div></div>
    <div class="col-6 col-md"><div class="fs-12 text-muted">Credited</div><div class="fw-semibold" id="totals-credited"><?= e(fmt_money($i['amount_credited'], $cur)) ?></div></div>
    <div class="col-6 col-md"><div class="fs-12 text-muted">Owed</div><div class="fw-bold<?= $i['overdue'] ? ' text-danger' : '' ?>" id="totals-balance"><?= e(fmt_money($i['status'] === 'void' ? '0' : $i['balance_due'], $cur)) ?></div></div>
    <?php endif; ?>
</div></div></div>
