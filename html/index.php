<?php
declare(strict_types=1);
/** / — home (screen `dashboard`): my week and timer, my expenses; a manager's approvals and budget alerts; billing's figures and the Clerk's note. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'dashboard');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen(['may' => $s['may'], 'my_week' => $s['my_week'], 'timer' => $s['timer'], 'my_expenses' => $s['my_expenses'], 'approvals' => $s['approvals'],
        'budget_alerts' => $s['budget_alerts'], 'billing' => $s['billing'], 'clerk_note' => $s['clerk_note']]);
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'dashboard']);
