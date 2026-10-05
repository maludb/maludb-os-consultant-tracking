<?php /** The kernel's statement (screen `os-statement`). Data: months, open (a month row, or ['period','missing'], or null), lines, summary, charges, here, tz, notice, rules */
$isOpen = $open !== null && !isset($open['missing']); ?>
<?= view('shared/header.php', ['id' => 'os-statement', 'title' => 'The OS statement', 'crumbs' => [['Home', '/'], ['AI usage and hosting', '/usage/'], ['The OS statement', null]], 'back' => back_link() ?? ['/usage/', 'AI usage'],
    'action' => '<form method="post" action="/usage/os/pull.php" hx-post="/usage/os/pull.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<button type="submit" class="btn btn-primary btn-touch" id="os-pull-btn"><i class="feather-download-cloud me-1"></i>Pull from the OS</button></form>']) ?>
<div class="main-content" id="os-statement-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">The OS keeps a monthly statement of what its agents spent on models, by provider, agent, department and application. A closed month is pulled, kept as the OS sent it, and placed on engagements by your <?= hx_link(with_back('/settings/allocation-rules/', $here), 'allocation rules', '', 'id="os-statement-rules-link"') ?> as draft charges; what no rule places is the remainder, shown and never billed.</div>
    <?php if ($months === []): ?><div class="card"><div class="card-body text-muted" id="os-statement-empty">No month has been pulled yet. Pull from the OS to see the closed months.</div></div><?php endif; ?>
    <?php if ($months !== []): ?>
    <div class="card mb-3 d-none d-md-block" id="os-months-table"><div class="table-responsive"><table class="table table-hover mb-0 fs-12">
        <thead><tr><th>Month</th><th>In the OS</th><th>Here</th><th>Pulled</th><th class="text-end">Statement</th><th class="text-end">Allocated</th><th class="text-end">Remainder</th></tr></thead>
        <tbody><?php foreach ($months as $s): $sid = (int) $s['ai_statement_id']; ?><tr id="statement-<?= $sid ?>"><td class="fw-semibold"><?= hx_link('/usage/os?period=' . rawurlencode($s['period']), e($s['period']), 'text-dark', 'id="statement-' . $sid . '-open"') ?></td>
            <td><?php if ($s['kernel_status'] === 'open'): ?><span class="badge bg-soft-warning text-warning" id="statement-<?= $sid ?>-kernel">open in the OS</span><?php else: ?><span class="badge bg-soft-secondary text-secondary" id="statement-<?= $sid ?>-kernel">closed</span><?php endif; ?></td>
            <td><?php if ($s['status'] === 'allocated'): ?><span class="badge bg-soft-success text-success" id="statement-<?= $sid ?>-status">allocated</span><?php else: ?><span class="badge bg-soft-info text-info" id="statement-<?= $sid ?>-status">pulled</span><?php endif; ?></td>
            <td class="text-nowrap"><?= e(format_ts($s['pulled_at'], $tz)) ?></td><td class="text-end text-nowrap"><?= e($s['statement_amount']) ?></td><td class="text-end text-nowrap"><?= e($s['allocated_amount']) ?></td>
            <td class="text-end text-nowrap<?= (float) $s['remainder'] > 0 && $s['status'] === 'allocated' ? ' text-warning fw-semibold' : '' ?>" id="statement-<?= $sid ?>-remainder"><?= e($s['remainder']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="row g-3 d-md-none mb-3" id="os-months-cards"><?php foreach ($months as $s): $sid = (int) $s['ai_statement_id']; ?>
        <div class="col-12"><div class="card" id="statement-card-<?= $sid ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center gap-2"><div class="fw-bold"><?= hx_link('/usage/os?period=' . rawurlencode($s['period']), e($s['period']), 'text-dark') ?></div>
                <div><?php if ($s['kernel_status'] === 'open'): ?><span class="badge bg-soft-warning text-warning">open in the OS</span><?php else: ?><span class="badge bg-soft-secondary text-secondary">closed</span><?php endif; ?>
                    <span class="badge bg-soft-<?= $s['status'] === 'allocated' ? 'success text-success' : 'info text-info' ?>"><?= e($s['status']) ?></span></div></div>
            <div class="fs-12 mt-1">Statement <?= e($s['statement_amount']) ?> · allocated <?= e($s['allocated_amount']) ?> · <span class="<?= (float) $s['remainder'] > 0 && $s['status'] === 'allocated' ? 'text-warning fw-semibold' : 'text-muted' ?>">remainder <?= e($s['remainder']) ?></span></div>
        </div></div></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($open !== null && !$isOpen): ?><div class="alert alert-info" id="os-month-missing">This application has not pulled <?= e($open['period']) ?> yet. Pull from the OS.</div><?php endif; ?>
    <?php if ($isOpen): $sid = (int) $open['ai_statement_id']; $closed = $open['kernel_status'] === 'closed'; ?>
    <div class="card mb-3" id="os-month"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><h5 class="card-title mb-0"><?= e($open['period']) ?> — <?= (int) $open['lines'] ?> lines</h5>
        <?php if ($closed): ?>
            <form method="post" action="/usage/os/allocate.php" hx-post="/usage/os/allocate.php" hx-target="#flash" hx-confirm="<?= $open['status'] === 'allocated' ? 'Allocate ' . e($open['period']) . ' again? Approved charges are kept, the drafts are remade.' : 'Allocate ' . e($open['period']) . ' by the rules?' ?>"><?= csrf_field() ?><input type="hidden" name="statement" value="<?= $sid ?>"><button type="submit" class="btn btn-primary btn-touch" id="os-allocate-btn"><?= $open['status'] === 'allocated' ? 'Re-allocate' : 'Allocate' ?></button></form>
        <?php endif; ?></div>
        <div class="card-body fs-12">
            <?php if (!$closed): ?><div class="alert alert-warning mb-2" id="os-month-open">Not yet closed in the OS: its figures can still change, so it cannot be allocated. Pull it again after a super-admin closes it.</div><?php endif; ?>
            <?php if ($closed && $rules === 0): ?><div class="alert alert-info mb-2" id="os-month-norules">No allocation rule is active, so nothing would be placed. <?= hx_link(with_back('/settings/allocation-rules/new', $here), 'Add a rule', 'alert-link') ?>.</div><?php endif; ?>
            <?php foreach ($summary as $r): ?><div class="d-flex flex-wrap justify-content-between gap-2" id="os-summary-<?= e($r['provider']) ?>-<?= e($r['currency']) ?>"><span class="fw-semibold"><?= e($r['provider']) ?> <?= e($r['currency']) ?></span><span>statement <?= e(fmt_money($r['statement_amount'], $r['currency'])) ?> · allocated <?= e(fmt_money($r['allocated_amount'], $r['currency'])) ?> · remainder <strong class="<?= (float) $r['remainder'] > 0 && $open['status'] === 'allocated' ? 'text-warning' : '' ?>"><?= e(fmt_money($r['remainder'], $r['currency'])) ?></strong></span></div><?php endforeach; ?>
            <?php if ($open['late_calls'] > 0): ?><div class="text-muted mt-1"><?= (int) $open['late_calls'] ?> late call<?= (int) $open['late_calls'] === 1 ? '' : 's' ?> in this month, flagged by the OS.</div><?php endif; ?>
        </div></div>
    <?= view('usage/partials/statement-lines.php', ['lines' => $lines]) ?>
    <div class="card mb-3" id="os-charges"><div class="card-header"><h5 class="card-title mb-0">The charges it made</h5></div><div class="list-group list-group-flush">
        <?php if ($charges === []): ?><div class="list-group-item text-muted fs-12" id="os-charges-empty">None yet<?= $closed ? ' — allocate the month' : '' ?>.</div><?php endif; ?>
        <?php foreach ($charges as $c): ?><div class="list-group-item fs-12 d-flex justify-content-between gap-2" id="os-charge-<?= (int) $c['usage_charge_id'] ?>"><span><?= hx_link(with_back('/usage/' . (int) $c['usage_charge_id'], $here), e($c['number'])) ?> <span class="text-muted"><?= e($c['engagement_number']) ?> · <?= e((string) $c['provider_name']) ?> · <?= e(USAGE_STATUSES[$c['status']]) ?></span></span><span class="fw-semibold"><?= e(fmt_money($c['billable_amount'], $c['currency'])) ?></span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
