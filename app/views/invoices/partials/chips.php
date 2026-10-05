<?php /** An invoice's chips: status (viewed with an eye), overdue, waiting claims. Data: i (an invoice row), id (prefix, e.g. invoice-card-12) */
$tone = INVOICE_TONES[$i['status']]; $id = $id ?? 'invoice-' . (int) $i['invoice_id']; ?>
<span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="<?= e($id) ?>-status"><?php if ($i['status'] === 'viewed'): ?><i class="feather-eye me-1"></i><?php endif; ?><?= e(INVOICE_STATUSES[$i['status']]) ?></span>
<?php if ($i['overdue']): ?><span class="badge bg-soft-danger text-danger" id="<?= e($id) ?>-overdue">Overdue <?= (int) $i['days_past_due'] ?> d</span><?php endif; ?>
<?php if ((int) $i['open_claims'] > 0): ?><span class="badge bg-soft-warning text-warning" id="<?= e($id) ?>-claims"><?= (int) $i['open_claims'] ?> claim<?= (int) $i['open_claims'] === 1 ? '' : 's' ?></span><?php endif; ?>
