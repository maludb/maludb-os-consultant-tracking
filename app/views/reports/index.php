<?php /** The reports as cards (screen `reports`). Data: cards [{key, title, blurb, url}], here */ ?>
<?= view('shared/header.php', ['id' => 'reports', 'title' => 'Reports', 'crumbs' => [['Home', '/'], ['Reports', null]]]) ?>
<div class="main-content" id="reports-content">
    <?= view('reports/partials/report-cards.php', ['cards' => $cards, 'here' => $here]) ?>
</div>
