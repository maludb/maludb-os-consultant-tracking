<?php /** Add or change an allocation rule (screens `allocation-rule-add`, `allocation-rule-edit`). Data: cur, engagements, names, prefill, here */
$id = $cur['rule_id'] ?? null; $screen = $cur === null ? 'allocation-rule-add' : 'allocation-rule-edit'; $selected = $cur['engagement_id'] ?? $prefill; $back = ['/settings/allocation-rules/', 'the rules'];
$pick = static function (string $kind, ?int $value, string $field, array $names, string $label): string {
    $h = '<label class="form-label fs-12 text-muted" for="rule-form-field-' . $kind . '">' . e($label) . '</label><div class="mb-3">';
    if ($names[$kind] !== []) {
        $h .= '<select name="' . $kind . '" id="rule-form-field-' . $kind . '" class="form-select btn-touch"><option value="">Any</option>';
        foreach ($names[$kind] as $k => $n) { $h .= '<option value="' . (int) $k . '"' . ($value === (int) $k ? ' selected' : '') . '>' . e($n . ' (#' . $k . ')') . '</option>'; }
        if ($value !== null && !isset($names[$kind][$value])) { $h .= '<option value="' . $value . '" selected>#' . $value . '</option>'; }
        return $h . '</select></div>';
    }
    return $h . '<input type="text" name="' . $kind . '" id="rule-form-field-' . $kind . '" class="form-control btn-touch" inputmode="numeric" placeholder="the kernel\'s number (optional)" value="' . e((string) $value) . '"></div>';
}; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add an allocation rule' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Allocation rules', '/settings/allocation-rules/'], [$cur === null ? 'Add' : 'Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($engagements === []): ?><div class="alert alert-info" id="rule-form-none">You manage no live engagement to place usage on.</div><?php endif; ?>
    <form method="post" action="/settings/allocation-rules/save.php" hx-post="/settings/allocation-rules/save.php" hx-target="#flash" id="rule-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="rule" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="rule-form-field-name">Name</label>
            <input type="text" name="name" id="rule-form-field-name" class="form-control btn-touch mb-3" maxlength="200" required placeholder="Research agent to Acme" value="<?= e($cur['name'] ?? '') ?>">
            <div class="fs-12 text-muted mb-2">Which lines of the OS statement this rule takes a share of. Name at least one; every one you name must match.</div>
            <?= $pick('agent', $cur['kernel_agent_id'] ?? null, 'agent', $names, 'Agent') ?>
            <?= $pick('department', $cur['kernel_department_id'] ?? null, 'department', $names, 'Department') ?>
            <?= $pick('application', $cur['kernel_application_id'] ?? null, 'application', $names, 'Application') ?>
            <label class="form-label fs-12 text-muted" for="rule-form-field-engagement">Engagement that gets the share</label>
            <select name="engagement" id="rule-form-field-engagement" class="form-select btn-touch mb-3" required><?php if ($engagements === []): ?><option value="">None</option><?php endif; ?><?php foreach ($engagements as $g): ?><option value="<?= (int) $g['engagement_id'] ?>" <?= $selected === (int) $g['engagement_id'] ? 'selected' : '' ?>><?= e($g['number'] . ' · ' . $g['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="rule-form-field-share">Share of each matching line, %</label>
            <input type="text" name="share_pct" id="rule-form-field-share" class="form-control btn-touch mb-3" inputmode="decimal" required value="<?= e($cur['share_pct'] ?? '100') ?>">
            <?= view('usage/partials/pricing.php', ['cur' => $cur, 'prefix' => 'rule-form']) ?>
            <div class="row g-2 mb-3"><div class="col-6"><label class="form-label fs-12 text-muted" for="rule-form-field-from">Applies from</label><input type="date" name="valid_from" id="rule-form-field-from" class="form-control btn-touch" required value="<?= e($cur['valid_from'] ?? date('Y-m-01')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="rule-form-field-to">until (optional)</label><input type="date" name="valid_to" id="rule-form-field-to" class="form-control btn-touch" value="<?= e((string) ($cur['valid_to'] ?? '')) ?>"></div></div>
            <input type="hidden" name="active" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="rule-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="rule-form-field-active" <?= $cur === null || $cur['active'] ? 'checked' : '' ?>>Active</label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="rule-form-save-btn" <?= $engagements === [] ? 'disabled' : '' ?>>Save the rule</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="rule-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null): ?><form method="post" action="/settings/allocation-rules/delete.php" hx-post="/settings/allocation-rules/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Remove the rule <?= e($cur['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="rule" value="<?= (int) $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="rule-form-delete-btn">Remove the rule</button></form><?php endif; ?>
</div>
