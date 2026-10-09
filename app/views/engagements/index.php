<?php /** The engagements as cards (screen `engagement-list`). Data: rows, filters, statusParam, may, here, clients, departments, notice */ ?>
<?= view('shared/header.php', ['id' => 'engagement-list', 'title' => 'Engagements', 'crumbs' => [['Home', '/'], ['Engagements', null]],
    'action' => $may['manage'] ? hx_link('/engagements/new', '<i class="feather-plus me-1"></i>New engagement', 'btn btn-primary btn-touch', 'id="engagement-list-add-btn"') : '']) ?>
<div class="main-content" id="engagement-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/engagements/" hx-get="/engagements/" hx-target="#engagement-list-results" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#engagement-filter-q" hx-push-url="true" id="engagement-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="engagement-filter-q">Name, number, client or PO</label><input type="search" name="q" id="engagement-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="engagement-filter-status">Status</label><select name="status" id="engagement-filter-status" class="form-select btn-touch"><option value="">Open and closed</option><?php foreach (ENGAGEMENT_STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $statusParam === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?><option value="all" <?= $statusParam === 'all' ? 'selected' : '' ?>>Every one</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="engagement-filter-kind">Kind</label><select name="kind" id="engagement-filter-kind" class="form-select btn-touch"><option value="">Both</option><?php foreach (ENGAGEMENT_KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" id="engagement-filter-client-label" for="engagement-filter-client-open">Client</label><?= picker_field(['id' => 'engagement-filter-client', 'labelledby' => 'engagement-filter-client-label', 'name' => 'client', 'source' => 'client', 'value' => $filters['client_id'], 'placeholder' => 'Any']) ?></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="engagement-filter-mine"><input type="checkbox" class="form-check-input mt-0" name="mine" value="1" id="engagement-filter-mine" <?= $filters['mine'] ? 'checked' : '' ?>>Mine</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <div id="engagement-list-results">
        <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="engagement-list-empty"><?= $filters['q'] !== '' || $filters['mine'] ? 'No engagement matches.' : ($may['manage'] ? 'No engagement yet. Open the first one.' : 'No engagement yet — you see the engagements you are staffed on.') ?></div></div><?php endif; ?>
        <div class="row g-3" id="engagement-list-cards">
            <?php foreach ($rows as $e): ?><div class="col-12 col-md-6 col-xl-4"><?= view('engagements/partials/engagement-card.php', ['e' => $e, 'here' => $here, 'may' => ['ai' => ai_amount_visible()]]) ?></div><?php endforeach; ?>
        </div>
    </div>
</div>
