<?php /** Add, copy or change a rate card (screens `rate-card-add`, `rate-card-edit`). Data: cur, copyOf, settings, here */
$id = $cur['rate_card_id'] ?? null; $screen = $cur === null ? 'rate-card-add' : 'rate-card-edit'; $fixed = $cur !== null && $cur['engagement_count'] > 0;
$back = $cur === null ? ['/settings/rate-cards/', 'Rate cards'] : ['/settings/rate-cards/' . $id, $cur['name']]; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? ($copyOf === null ? 'Add a rate card' : 'Copy ' . $copyOf['name']) : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Rate cards', '/settings/rate-cards/'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/rate-cards/save.php" hx-post="/settings/rate-cards/save.php" hx-target="#flash" id="rate-card-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="rate_card" value="<?= (int) $id ?>"><?php endif; ?><?php if ($copyOf !== null): ?><input type="hidden" name="copy_of" value="<?= (int) $copyOf['rate_card_id'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <?php if ($copyOf !== null): ?><div class="alert alert-info fs-12" id="rate-card-form-copy">The <?= $copyOf['rate_count'] ?> rate<?= $copyOf['rate_count'] === 1 ? '' : 's' ?> of <?= e($copyOf['name']) ?> are copied onto the new card.</div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="rate-card-form-field-name">Name</label>
            <input type="text" name="name" id="rate-card-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($cur['name'] ?? ($copyOf === null ? '' : $copyOf['name'] . ' (copy)')) ?>">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="rate-card-form-field-currency">Currency<?= $fixed ? ' (fixed: ' . $cur['engagement_count'] . ' engagement' . ($cur['engagement_count'] === 1 ? ' uses' : 's use') . ' it)' : '' ?></label><input type="text" name="currency" id="rate-card-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" pattern="[A-Za-z]{3}" value="<?= e($cur['currency'] ?? $copyOf['currency'] ?? $settings['base_currency']) ?>" <?= $fixed ? 'readonly' : '' ?>></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="rate-card-form-field-from">Effective from</label><input type="date" name="effective_from" id="rate-card-form-field-from" class="form-control btn-touch" value="<?= e($cur['effective_from'] ?? date('Y-m-d')) ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="rate-card-form-field-description">Description</label>
            <textarea name="description" id="rate-card-form-field-description" class="form-control" rows="2" maxlength="500"><?= e($cur['description'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="rate-card-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="rate-card-form-cancel-link"') ?>
    </form>
</div>
