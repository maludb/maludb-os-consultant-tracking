<?php /** Home (screen `dashboard`). Data: s (home_summary), tz. Each region is an empty state naming its slice until it ships. */
$may = $s['may'];
$coming = static fn (string $id, string $icon, string $title, string $text, string $slice): string =>
    '<div class="card mb-3" id="' . e($id) . '"><div class="card-body"><div class="empty-state" id="' . e($id) . '-empty"><span class="avatar-text avatar-lg rounded"><i class="' . e($icon) . '"></i></span>'
    . '<div><div class="fw-semibold">' . e($title) . '</div><div class="fs-12 text-muted">' . e($text) . ' <span class="text-muted">(' . e($slice) . ')</span></div></div></div></div></div>';
?>
<?= view('shared/header.php', ['id' => 'dashboard', 'title' => 'Home', 'crumbs' => [['Home', null]],
    'action' => $may['time'] ? hx_link('/time/entries/new', '<i class="feather-plus me-1"></i>Log time', 'btn btn-primary btn-touch', 'id="dashboard-log-btn"') : '']) ?>
<div class="main-content" id="dashboard-content">
    <div class="row g-3">
        <div class="col-lg-6">
            <?php if ($may['time']): ?>
                <?= $s['my_week'] === null ? $coming('home-my-week', 'feather-clock', 'My week', 'Your hours this week, the timer and Submit appear here.', 'slice 3') : view('time/partials/home-week.php', ['week' => $s['my_week'], 'timer' => $s['timer'], 'tz' => $tz]) ?>
            <?php endif; ?>
            <?php if ($may['expenses']): ?>
                <?= $s['my_expenses'] === null ? $coming('home-my-expenses', 'feather-credit-card', 'My expenses', 'What you are owed and what awaits a receipt appear here.', 'slice 4') : view('expenses/partials/home.php', ['x' => $s['my_expenses'], 'tz' => $tz]) ?>
            <?php endif; ?>
            <?= view('home/partials/bell.php', ['rows' => $s['bell'], 'tz' => $tz]) ?>
        </div>
        <div class="col-lg-6">
            <?php if ($may['approve']): ?>
                <?= $s['approvals'] === null ? $coming('home-approvals', 'feather-check-square', 'Approvals waiting', 'Weeks, expenses and usage charges awaiting you appear here.', 'slices 3–5') : view('home/partials/approvals.php', ['a' => $s['approvals']]) ?>
            <?php endif; ?>
            <?php if ($may['manage']): ?>
                <?= $s['budget_alerts'] === null ? $coming('home-budget-alerts', 'feather-trending-up', 'Budget alerts', 'Engagements crossing a threshold, and those ending soon, appear here.', 'slice 2') : view('engagements/partials/home-alerts.php', ['alerts' => $s['budget_alerts']]) ?>
            <?php endif; ?>
            <?php if ($may['billing']): ?>
                <?= $s['billing'] === null ? $coming('home-billing', 'feather-file-text', 'Billing', 'Unbilled work, drafts to issue, invoices due to draft, who owes us and the OS statement appear here.', 'slices 5–7') : view('home/partials/billing.php', ['b' => $s['billing'], 'tz' => $tz]) ?>
                <?php if ($may['clerk']): ?>
                <?= $s['clerk_note'] === null
                    ? '<div class="card mb-3" id="home-clerk-note"><div class="card-body"><div class="empty-state" id="home-clerk-note-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-cpu"></i></span><div><div class="fw-semibold">The Billing Clerk\'s morning note</div><div class="fs-12 text-muted">What the Clerk drafted, allocated and found is left here each morning. None yet.</div></div></div></div></div>'
                    : view('home/partials/clerk-note.php', ['note' => $s['clerk_note'], 'tz' => $tz]) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
