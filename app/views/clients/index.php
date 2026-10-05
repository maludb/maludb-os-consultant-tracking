<?php /** The clients as cards (screen `client-list`). Data: rows, filters, may (manage, billing), here, notice */ ?>
<?= view('shared/header.php', ['id' => 'client-list', 'title' => 'Clients', 'crumbs' => [['Home', '/'], ['Clients', null]],
    'action' => $may['manage'] ? hx_link('/clients/new', '<i class="feather-plus me-1"></i>Add client', 'btn btn-primary btn-touch', 'id="client-list-add-btn"') : '']) ?>
<div class="main-content" id="client-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/clients/" hx-get="/clients/" hx-target="#client-list-results" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#client-filter-q" hx-push-url="true" id="client-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" for="client-filter-q">Name, code or legal name</label><input type="search" name="q" id="client-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-12 col-md-4"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="client-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="client-filter-archived" <?= $filters['archived'] ? 'checked' : '' ?>>Archived clients</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <div id="client-list-results">
        <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="client-list-empty"><?= $filters['archived'] ? 'No archived client.' : ($filters['q'] !== '' ? 'No client matches.' : ($may['manage'] ? 'No client yet. Add the first one.' : 'No client yet — you see the clients of the engagements you are staffed on.')) ?></div></div><?php endif; ?>
        <div class="row g-3" id="client-list-cards">
            <?php foreach ($rows as $c): ?><div class="col-12 col-md-6 col-xl-4"><?= view('clients/partials/client-card.php', ['c' => $c, 'here' => $here, 'may' => $may]) ?></div><?php endforeach; ?>
        </div>
    </div>
</div>
