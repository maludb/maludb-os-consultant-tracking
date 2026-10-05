<?php /** One card per report the caller may open. Data: cards, here */ ?>
<?php if ($cards === []): ?><div class="card"><div class="card-body text-muted" id="reports-empty">No report is open to you.</div></div><?php endif; ?>
<div class="row g-3" id="reports-cards">
    <?php foreach ($cards as $c): ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="report-card-<?= e($c['key']) ?>"><div class="card-body p-3">
            <div class="fw-bold mb-1"><?= hx_link(with_back($c['url'], $here), e($c['title']), '', 'id="report-card-' . e($c['key']) . '-link"') ?></div>
            <div class="fs-12 text-muted"><?= e($c['blurb']) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>
