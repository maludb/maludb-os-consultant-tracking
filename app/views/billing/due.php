<?php /** What awaits an invoice (screen `due-to-draft`). Data: r (due, milestones, drafts), here, may */ ?>
<?= view('shared/header.php', ['id' => 'due-to-draft', 'title' => 'Due to draft', 'crumbs' => [['Home', '/'], ['Unbilled work', '/billing/unbilled'], ['Due to draft', null]], 'back' => back_link() ?? ['/billing/unbilled', 'unbilled work']]) ?>
<div class="main-content" id="due-to-draft-content">
    <h6 class="mb-2">Billing date passed, work to bill</h6>
    <?php if ($r['due'] === []): ?><div class="card mb-3"><div class="card-body text-muted" id="due-empty">No engagement is due by its cadence with work to bill.</div></div><?php endif; ?>
    <div class="row g-3 mb-3" id="due-engagements"><?php foreach ($r['due'] as $e): $eid = $e['engagement_id']; $cur = $e['currency']; ?><div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="due-card-<?= $eid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2"><div class="fw-bold text-truncate"><?= hx_link(with_back('/billing/unbilled/' . $eid, $here), e($e['number'] . ' · ' . $e['name']), 'text-dark') ?></div><div class="fw-bold"><?= e(fmt_money($e['wip']['total_amount'], $cur)) ?></div></div>
        <div class="fs-12 text-muted"><?= e($e['client_name']) ?> · <?= e(BILLING_CADENCES[$e['billing_cadence']] ?? $e['billing_cadence']) ?> · was due <?= e(format_date($e['next_billing_date'])) ?></div>
        <?= $may['draft'] ? '<div class="mt-2">' . hx_link(with_back('/invoices/new?engagement=' . $eid, $here), 'Draft invoice', 'btn btn-primary btn-touch', 'id="due-card-' . $eid . '-draft-btn"') . '</div>' : '' ?></div></div></div><?php endforeach; ?></div>
    <h6 class="mb-2">Accepted milestones not invoiced</h6>
    <?php if ($r['milestones'] === []): ?><div class="card mb-3"><div class="card-body text-muted" id="due-milestones-empty">No accepted milestone awaits an invoice.</div></div><?php endif; ?>
    <div class="row g-3 mb-3" id="due-milestones"><?php foreach ($r['milestones'] as $m): $mid = $m['milestone_id']; ?><div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="due-milestone-<?= $mid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2"><div class="fw-bold text-truncate"><?= e($m['name']) ?></div><div class="fw-bold"><?= e(fmt_money($m['amount'], $m['currency'])) ?></div></div>
        <div class="fs-12 text-muted"><?= hx_link(with_back('/engagements/' . $m['engagement_id'] . '/milestones', $here), e($m['engagement_number'])) ?> · <?= e($m['client_name']) ?> · accepted <?= e(format_date(substr((string) $m['accepted_at'], 0, 10))) ?></div>
        <?= $may['draft'] ? '<div class="mt-2">' . hx_link(with_back('/invoices/new?engagement=' . $m['engagement_id'], $here), 'Invoice this milestone', 'btn btn-primary btn-touch', 'id="due-milestone-' . $mid . '-draft-btn"') . '</div>' : '' ?></div></div></div><?php endforeach; ?></div>
    <h6 class="mb-2">Drafts already open</h6>
    <?php if ($r['drafts'] === []): ?><div class="card"><div class="card-body text-muted" id="due-drafts-empty">No open draft.</div></div><?php endif; ?>
    <div class="row g-3" id="due-drafts"><?php foreach ($r['drafts'] as $d): ?><div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="due-draft-<?= $d['invoice_id'] ?>"><div class="card-body p-3 d-flex justify-content-between gap-2"><div><div class="fw-bold"><?= hx_link(with_back('/invoices/' . $d['invoice_id'], $here), 'Draft for ' . e($d['engagement_number']), 'text-dark') ?></div><div class="fs-12 text-muted"><?= e($d['client_name']) ?> · started <?= e(format_date(substr((string) $d['created_at'], 0, 10))) ?></div></div><div class="fw-bold"><?= e(fmt_money($d['total'], $d['currency'])) ?></div></div></div></div><?php endforeach; ?></div>
</div>
