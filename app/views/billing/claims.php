<?php /** What clients said (screen `claims`). Data: rows, status, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'claims', 'title' => 'Payment claims', 'crumbs' => [['Home', '/'], ['Payment claims', null]]]) ?>
<div class="main-content" id="claims-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex gap-1 mb-3 overflow-auto" id="claims-tabs"><?php foreach (['submitted' => 'Waiting', 'confirmed' => 'Confirmed', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l): ?><?= hx_link('/billing/claims?status=' . $k, e($l), 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="claims-tab-' . $k . '"') ?><?php endforeach; ?></div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="claims-empty"><?= $status === 'submitted' ? 'Nothing waits for you. A claim appears here when a client says on their link that they paid.' : 'None.' ?></div></div><?php endif; ?>
    <div class="row g-3" id="claims-list">
    <?php foreach ($rows as $k): $kid = (int) $k['claim_id']; $cur = $k['currency']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="claim-<?= $kid ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><div class="fw-bold text-truncate"><?= hx_link(with_back('/invoices/' . (int) $k['invoice_id'], $here), e((string) $k['invoice_number']), 'text-dark', 'id="claim-' . $kid . '-invoice"') ?> <span class="fw-normal text-muted">· <?= e($k['client_name']) ?></span></div>
                <div class="fs-12 text-muted">Said paid <?= e(format_date($k['claimed_on'])) ?> · <?= e(PAYMENT_METHODS[$k['method']] ?? $k['method']) ?><?= $k['reference'] ? ' · ' . e($k['reference']) : '' ?></div></div>
                <div class="text-end"><div class="fw-bold" id="claim-<?= $kid ?>-amount-said"><?= $k['amount'] !== null ? e(fmt_money($k['amount'], $cur)) : '—' ?></div><span class="badge bg-soft-<?= ['submitted' => 'warning', 'confirmed' => 'success', 'dismissed' => 'secondary'][$k['status']] ?> text-<?= ['submitted' => 'warning', 'confirmed' => 'success', 'dismissed' => 'secondary'][$k['status']] ?>"><?= e(CLAIM_STATUSES[$k['status']]) ?></span></div></div>
            <div class="fs-12 mt-1">Invoice owes <?= e(fmt_money($k['balance_due'], $cur)) ?></div>
            <?php if ($k['note']): ?><div class="fs-12 mt-1 text-muted">“<?= e($k['note']) ?>”</div><?php endif; ?>
            <?php if ($k['status'] === 'submitted'): ?><div class="mt-2"><?= view('billing/partials/claim-actions.php', ['k' => $k, 'cur' => $cur, 'here' => '/billing/claims', 'balance' => $k['balance_due']]) ?></div><?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
