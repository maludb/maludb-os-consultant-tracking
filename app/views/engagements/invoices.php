<?php /** The engagement's Invoices tab (slice 6). Data: e, invoices (null = not for this person), wip (the engagement's ct_unbilled row or null), here, may */
$id = (int) $e['engagement_id']; $cur = $e['currency']; ?>
<?php if ($invoices === null): ?><div class="card" id="engagement-tab-invoices-none"><div class="card-body text-muted">Invoices are the engagement manager's and Billing's.</div></div>
<?php else: ?>
<div id="engagement-tab-invoices">
    <div class="card mb-3" id="engagement-invoices-unbilled"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><div class="fw-semibold">Unbilled</div><div class="fs-12 text-muted" id="engagement-invoices-unbilled-text"><?= $wip === null ? 'Nothing approved is waiting to be billed.' : e(fmt_money($wip['total_amount'], $cur)) . ' approved and not yet billed' . ((float) $wip['time_no_rate_hours'] > 0 ? ' — ' . e(fmt_hours($wip['time_no_rate_hours'])) . ' with no rate' : '') ?></div></div>
        <div class="d-flex gap-2"><?php if ($wip !== null): ?><?= hx_link(with_back('/billing/unbilled/' . $id, $here), 'The items', 'btn btn-light btn-touch', 'id="engagement-invoices-items-btn"') ?><?php endif; ?>
            <?php if ($may['manage'] && has_right('invoices.draft')): ?><?= hx_link(with_back('/invoices/new?engagement=' . $id, $here), 'Draft invoice', 'btn btn-primary btn-touch', 'id="engagement-invoices-draft-btn"') ?><?php endif; ?></div>
    </div></div>
    <?php if ($invoices === []): ?><div class="card"><div class="card-body text-muted" id="engagement-invoices-empty">No invoice yet.</div></div><?php endif; ?>
    <?php if ($invoices !== []): ?><div class="row g-3" id="engagement-invoices-cards"><?php foreach ($invoices as $i): ?><div class="col-12 col-md-6 col-xl-4"><?= view('invoices/partials/invoice-row.php', ['i' => $i, 'here' => $here, 'mode' => 'card']) ?></div><?php endforeach; ?></div><?php endif; ?>
</div>
<?php endif; ?>
