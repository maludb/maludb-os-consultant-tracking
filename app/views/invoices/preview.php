<?php /** The client's view, previewed (screen `invoice-preview`). Data: i, d, here */ $id = (int) $i['invoice_id']; ?>
<?= view('shared/header.php', ['id' => 'invoice-preview', 'title' => 'What the client sees', 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], [$i['number'] ?? 'Draft', '/invoices/' . $id], ['Preview', null]], 'back' => back_link() ?? ['/invoices/' . $id, 'the invoice']]) ?>
<div class="main-content" id="invoice-preview-content">
    <div class="alert alert-info fs-12" id="invoice-preview-banner">This is the page the client opens from the link<?= $i['status'] === 'draft' ? ' once the invoice is issued' : '' ?> — nothing here is a cost, a rate card or an internal note.</div>
    <div class="card"><div class="card-body"><?= view('invoices/partials/document.php', ['d' => $d, 'mode' => 'preview']) ?></div></div>
</div>
