<?php /** The secure link's state — never the token. Data: i, may (rotate), tz */ $l = $i['link']; ?>
<div class="card mb-3" id="link-state"><div class="card-header"><h5 class="card-title mb-0">The client's link</h5></div><div class="card-body">
<?php if ($l === null): ?><div class="text-muted fs-12" id="link-state-none"><?= $i['status'] === 'draft' ? 'The link is made when the invoice is issued.' : 'No link was made.' ?></div>
<?php else: $tone = ['live' => 'success', 'rotated' => 'secondary', 'expired' => 'dark'][$l['state']]; ?>
    <div class="d-flex flex-wrap align-items-center gap-2"><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="link-state-badge"><?= e(ucfirst($l['state'])) ?></span>
        <span class="fs-12 text-muted" id="link-state-detail">Opened <?= (int) $l['view_count'] ?> time<?= (int) $l['view_count'] === 1 ? '' : 's' ?><?= $l['last_used_at'] ? ', last ' . e(format_ts($l['last_used_at'], $tz)) : '' ?><?= $l['expires_at'] ? ' · expires ' . e(format_ts($l['expires_at'], $tz)) : '' ?></span></div>
    <div class="fs-12 text-muted mt-1">The link itself is not kept — only a hash. Sending the invoice or a reminder makes a fresh one; the old one stops working.</div>
    <?php if ($may['rotate']): ?><form method="post" action="/invoices/secure-link.php" hx-post="/invoices/secure-link.php" hx-target="#flash" class="mt-2" hx-confirm="Rotate the link? The one the client has stops working."><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= (int) $i['invoice_id'] ?>"><button type="submit" class="btn btn-light btn-touch" id="link-state-rotate-btn">Rotate the link</button></form><?php endif; ?>
<?php endif; ?>
</div></div>
