<?php /** The receipt large (screen `expense-view`). Data: x (find_expense row with receipts) */ $id = (int) $x['expense_id']; $r = $x['receipts'][0] ?? null; ?>
<div class="card mb-3" id="receipt-large-<?= $id ?>"><div class="card-header"><h5 class="card-title mb-0">Receipt</h5></div>
    <div class="card-body text-center">
        <?php if ($r === null): ?>
            <div class="text-muted" id="receipt-<?= $id ?>-none"><?= $x['receipt_needed'] ? '<span class="text-danger fw-semibold">A receipt is required at or above ' . e(fmt_money($x['receipt_threshold'], trim((string) $x['currency']), false)) . ' to submit.</span>' : 'No receipt attached.' ?></div>
        <?php elseif (str_starts_with($r['mime_type'], 'image/')): ?>
            <a href="/attachments/<?= (int) $r['attachment_id'] ?>" target="_blank" rel="noopener"><img src="/attachments/<?= (int) $r['attachment_id'] ?>" alt="Receipt <?= e($r['filename']) ?>" class="img-fluid rounded border" id="receipt-<?= $id ?>-image"></a>
        <?php else: ?>
            <a href="/attachments/<?= (int) $r['attachment_id'] ?>" target="_blank" rel="noopener" class="btn btn-light btn-touch" id="receipt-<?= $id ?>-file"><i class="feather-file-text me-1"></i><?= e($r['filename']) ?></a>
        <?php endif; ?>
        <?php if (count($x['receipts']) > 1): ?><div class="fs-12 text-muted mt-2"><?= count($x['receipts']) - 1 ?> more in the documents below.</div><?php endif; ?>
    </div></div>
