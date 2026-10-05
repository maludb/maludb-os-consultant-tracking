<?php /** The five document sequences (screen `numbering`). Data: rows, notice */ ?>
<?= view('shared/header.php', ['id' => 'numbering', 'title' => 'Numbering', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Numbering', null]], 'back' => back_link() ?? ['/settings/general', 'Settings']]) ?>
<div class="main-content" id="numbering-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3">Invoices and credit notes take their number when issued, gapless; engagements, expenses and usage charges at creation. The next number may be raised, never lowered below what is issued.</div>
    <div class="row g-3">
        <?php foreach ($rows as $r): $k = $r['kind']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="numbering-row-<?= e($k) ?>"><div class="card-body p-3">
                <div class="fw-bold mb-1"><?= e($r['label']) ?></div>
                <div class="fs-12 text-muted mb-2">Next: <code id="numbering-row-<?= e($k) ?>-preview"><?= e($r['preview']) ?></code><?= $r['highest_issued'] > 0 ? ' · highest issued ' . (int) $r['highest_issued'] : '' ?></div>
                <form method="post" action="/settings/numbering.php" hx-post="/settings/numbering.php" hx-target="#flash" id="numbering-form-<?= e($k) ?>" class="row g-2" hx-confirm="Change the numbering of <?= e(strtolower($r['label'])) ?>?">
                    <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($k) ?>">
                    <div class="col-5"><label class="form-label fs-12 text-muted" for="numbering-form-<?= e($k) ?>-prefix">Prefix</label><input type="text" name="prefix" id="numbering-form-<?= e($k) ?>-prefix" class="form-control btn-touch text-uppercase" maxlength="9" value="<?= e($r['prefix']) ?>"></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="numbering-form-<?= e($k) ?>-next">Next</label><input type="number" name="next_value" id="numbering-form-<?= e($k) ?>-next" class="form-control btn-touch" min="<?= (int) $r['highest_issued'] + 1 ?>" value="<?= (int) $r['next_value'] ?>"></div>
                    <div class="col-3"><label class="form-label fs-12 text-muted" for="numbering-form-<?= e($k) ?>-pad">Pad</label><input type="number" name="padding" id="numbering-form-<?= e($k) ?>-pad" class="form-control btn-touch" min="1" max="12" value="<?= (int) $r['padding'] ?>"></div>
                    <div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100" id="numbering-form-<?= e($k) ?>-save-btn">Save</button></div>
                </form>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
