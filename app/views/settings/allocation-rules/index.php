<?php /** The allocation rules (screen `allocation-rule-list`). Data: rules, names, engagement, here, notice */
$nm = static function (array $r, array $names): string {
    $bits = [];
    foreach (['agent' => ['kernel_agent_id', 'Agent'], 'department' => ['kernel_department_id', 'Department'], 'application' => ['kernel_application_id', 'Application']] as $kind => [$col, $label]) {
        if ($r[$col] !== null) { $bits[] = $label . ' ' . ($names[$kind][$r[$col]] ?? '#' . $r[$col]); }
    }
    return implode(' · ', $bits);
}; ?>
<?= view('shared/header.php', ['id' => 'allocation-rule-list', 'title' => 'Allocation rules', 'crumbs' => [['Home', '/'], ['The OS statement', '/usage/os'], ['Allocation rules', null]],
    'action' => hx_link(with_back('/settings/allocation-rules/new' . ($engagement === null ? '' : '?engagement=' . $engagement), $here), '<i class="feather-plus me-1"></i>Add a rule', 'btn btn-primary btn-touch', 'id="allocation-rule-list-add-btn"')]) ?>
<div class="main-content" id="allocation-rule-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">A rule places a share of the OS's model spend on an engagement: it names a kernel agent, department or application (any of them, all must match), the share, how the charge is priced, and the months it applies to. The shares of the rules a line matches add up to at most 100 %; the rest is the remainder.</div>
    <?php if ($rules === []): ?><div class="card"><div class="card-body text-muted" id="allocation-rule-list-empty">No rule yet. Without one a closed month allocates nothing.</div></div><?php endif; ?>
    <div class="row g-3" id="allocation-rule-list-cards">
        <?php foreach ($rules as $r): $id = (int) $r['rule_id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="rule-row-<?= $id ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><div class="fw-bold text-truncate"><?= hx_link(with_back('/settings/allocation-rules/' . $id . '/edit', $here), e($r['name']), 'text-dark') ?></div>
                <div class="fs-12 text-muted" id="rule-row-<?= $id ?>-matches"><?= e($nm($r, $names)) ?></div></div>
                <div class="text-end flex-shrink-0"><div class="fw-bold" id="rule-row-<?= $id ?>-share"><?= e(days_label($r['share_pct'])) ?> %</div><span class="badge bg-soft-<?= $r['active'] ? 'success text-success' : 'secondary text-secondary' ?>" id="rule-row-<?= $id ?>-active"><?= $r['active'] ? 'Active' : 'Off' ?></span></div></div>
            <div class="fs-12 mt-1"><?= hx_link(with_back('/engagements/' . (int) $r['engagement_id'], $here), e($r['engagement_number'])) ?> · <?= e(USAGE_PRICINGS[$r['pricing']]) ?><?= $r['pricing'] === 'markup' ? ' ' . e(days_label($r['markup_pct'])) . ' %' : ($r['pricing'] === 'resale' ? ' ' . e(days_label($r['resale_unit_price'])) . ' /M' : '') ?></div>
            <div class="fs-12 text-muted"><?= e(format_date($r['valid_from'])) ?> → <?= $r['valid_to'] ? e(format_date($r['valid_to'])) : 'no end' ?></div>
            <?php if ($r['last'] !== null): ?><div class="fs-12 mt-1" id="rule-row-<?= $id ?>-last">Placed <strong><?= e($r['last']['amount']) ?></strong> of <?= e($r['last']['period']) ?></div><?php endif; ?>
            <div class="d-flex gap-2 mt-2"><?= hx_link(with_back('/settings/allocation-rules/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch flex-grow-1', 'id="rule-row-' . $id . '-edit-btn"') ?>
                <form method="post" action="/settings/allocation-rules/delete.php" hx-post="/settings/allocation-rules/delete.php" hx-target="#flash" hx-confirm="Remove the rule <?= e($r['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="rule" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="rule-row-<?= $id ?>-delete-btn">Remove</button></form></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
