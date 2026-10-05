<?php
/** The client's secure invoice page (screen `invoice-secure`, no session, 375 px first). Data: d (the document), token, csrf, thanks (bool), claimError (string|null), claimForm (array of the typed values) */
$inv = $d['invoice']; $cur = $inv['currency']; $open = in_array($inv['status'], ['issued', 'sent', 'viewed', 'partially_paid'], true);
$claimForm = $claimForm ?? []; $v = static fn (string $k, string $default = ''): string => e((string) ($claimForm[$k] ?? $default));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    <title>Invoice <?= e((string) $inv['number']) ?> · <?= e((string) ($d['firm']['firm_name'] ?? '')) ?></title>
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css"><link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css"><link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css"><link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
    <style>body { background: #f4f6fa; } .invoice-document { background: #fff; border-radius: 8px; padding: 16px; } summary { list-style: revert; } .btn-touch { min-height: 44px; }</style>
</head>
<body>
<main class="container py-3" style="max-width: 860px" id="secure-page">
    <?php if (!empty($thanks)): ?><?= view('public/claimed.php') ?><?php endif; ?>
    <div class="d-flex justify-content-end gap-2 mb-2 no-print"><a href="/i/<?= e($token) ?>/print" target="_blank" rel="noopener" class="btn btn-light btn-touch" id="secure-print-btn"><i class="feather-printer me-1"></i>Print or save as PDF</a></div>
    <?= view('invoices/partials/document.php', ['d' => $d, 'mode' => 'secure', 'token' => $token]) ?>
    <?php if ($open): ?>
    <div class="card mt-3 no-print" id="claim-form-card"><div class="card-header"><h5 class="card-title mb-0">Already paid?</h5></div><div class="card-body">
        <p class="fs-12 text-muted">Tell us when and how you paid — we will confirm when it shows. This does not record a payment by itself.</p>
        <?php if (!empty($claimError)): ?><div class="alert alert-danger fs-12" id="claim-error"><?= e($claimError) ?></div><?php endif; ?>
        <?php if ((int) $d['waiting_claims'] > 0 && empty($thanks)): ?><div class="alert alert-info fs-12" id="claim-waiting">We have your note of payment and will confirm it when it shows.</div><?php endif; ?>
        <form method="post" action="/i/<?= e($token) ?>/claim" id="claim-form" class="row g-2">
            <?= csrf_field() ?>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="claim-field-date">I paid on</label><input type="date" name="claimed_on" id="claim-field-date" class="form-control btn-touch" value="<?= $v('claimed_on', date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="claim-field-method">By</label><select name="method" id="claim-field-method" class="form-select btn-touch"><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($claimForm['method'] ?? 'transfer') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-7"><label class="form-label fs-12 text-muted" for="claim-field-reference">Reference (optional)</label><input type="text" name="reference" id="claim-field-reference" class="form-control btn-touch" maxlength="120" value="<?= $v('reference') ?>"></div>
            <div class="col-5"><label class="form-label fs-12 text-muted" for="claim-field-amount">Amount (<?= e($cur) ?>)</label><input type="text" inputmode="decimal" name="amount" id="claim-field-amount" class="form-control btn-touch" value="<?= $v('amount') ?>" placeholder="<?= e(number_format((float) $inv['balance_due'], 2, '.', '')) ?>"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="claim-field-note">A note (optional, 200 characters)</label><input type="text" name="note" id="claim-field-note" class="form-control btn-touch" maxlength="200" value="<?= $v('note') ?>"></div>
            <div class="col-12 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="claim-submit-btn" style="min-height: 44px">I paid this</button></div>
        </form>
    </div></div>
    <?php endif; ?>
</main>
</body>
</html>
