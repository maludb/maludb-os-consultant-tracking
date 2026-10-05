<?php
declare(strict_types=1);

/**
 * The shell's menu — ONE table: the sidebar and the phone's tab bar read it (Phase 2 builds the shell), so a menu item and
 * the right that opens its screen can never disagree. An item shows only when the person holds its right (several joined
 * by "|": any one). Screens are the action manifest's (Phase 1); until a screen is built its item points at a page the
 * shell marks as coming.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    return [
        'Work' => [
            ['home',         '/',                    'feather-home',          'Home',              'time.write|reports.view'],
            ['timesheet',    '/time/',               'feather-clock',         'My timesheet',      'time.write'],
            ['expenses',     '/expenses/',           'feather-credit-card',   'My expenses',       'expenses.write'],
            ['engagements',  '/engagements/',        'feather-briefcase',     'Engagements',       'engagements.view'],
            ['clients',      '/clients/',            'feather-users',         'Clients',           'engagements.view'],
        ],
        'Manage' => [
            ['time-approvals','/time/approvals',     'feather-check-square',  'Time approvals',    'time.approve'],
            ['team-time',    '/time/team',           'feather-grid',          'Team time',         'time.approve'],
            ['missing-time', '/time/missing',        'feather-alert-circle',  'Missing time',      'time.approve'],
            ['expense-approvals','/expenses/approvals','feather-check-circle','Expense approvals', 'expenses.approve'],
            ['usage',        '/usage/',              'feather-cpu',           'AI usage and hosting', 'usage.write'],
            ['os-statement', '/usage/os',            'feather-activity',      'The OS statement',  'usage.write'],
            ['budgets',      '/usage/budgets',       'feather-trending-up',   'Budgets',           'engagements.manage'],
        ],
        'Billing' => [
            ['unbilled',     '/billing/unbilled',    'feather-layers',        'Unbilled',          'invoices.draft'],
            ['invoices',     '/invoices/',           'feather-file-text',     'Invoices',          'invoices.draft'],
            ['credit-notes', '/credit-notes/',       'feather-corner-up-left','Credit notes',      'billing.write'],
            ['payments',     '/payments/',           'feather-download',      'Payments',          'billing.write'],
            ['claims',       '/billing/claims',      'feather-inbox',         'Payment claims',    'billing.write'],
            ['ar-aging',     '/reports/ar-aging',    'feather-clock',         'Who owes us',       'billing.write|reports.view'],
            ['reimbursements','/expenses/reimbursements','feather-repeat',    'Reimbursements',    'billing.write'],
        ],
        'Reports' => [
            ['reports',      '/reports/',            'feather-bar-chart-2',   'Reports',           'reports.view|reports.view_own'],
            ['exports',      '/exports',             'feather-download-cloud','Exports',           'exports.download'],
        ],
        'Settings' => [
            ['general',      '/settings/general',    'feather-sliders',       'Settings',          'settings.manage'],
            ['roles',        '/settings/roles/',     'feather-award',         'Consulting roles',  'settings.manage'],
            ['rate-cards',   '/settings/rate-cards/','feather-tag',           'Rate cards',        'rate_cards.manage'],
            ['cost-rates',   '/settings/cost-rates/','feather-lock',          'Cost rates',        'settings.manage'],
            ['capacity',     '/settings/capacity/',  'feather-battery',       'Capacity',          'settings.manage'],
            ['activity-types','/settings/activity-types/','feather-list',     'Activity types',    'settings.manage'],
            ['categories',   '/settings/categories/','feather-folder',        'Expense categories','settings.manage'],
            ['providers',    '/settings/providers/', 'feather-server',        'Providers',         'settings.manage'],
            ['allocation-rules','/settings/allocation-rules/','feather-git-merge','Allocation rules','settings.manage'],
            ['schedules',    '/settings/schedules/', 'feather-refresh-cw',    'Hosting schedules', 'usage.write'],
            ['agents',       '/settings/agents',     'feather-cpu',           'Agents',            'settings.manage'],
            ['connections',  '/settings/connections','feather-share-2',       'Connections',       'settings.manage'],
            ['my-settings',  '/settings/',           'feather-settings',      'My settings',       'time.write|reports.view'],
        ],
    ];
}

/** The phone's tabs: Home, Timesheet, Expenses, Engagements — and More (the sidebar) which the layout adds. */
function nav_tabs(): array
{
    return [
        ['home',        '/',              'feather-home',        'Home',        'time.write|reports.view'],
        ['timesheet',   '/time/',         'feather-clock',       'Time',        'time.write'],
        ['expenses',    '/expenses/',     'feather-credit-card', 'Expenses',    'expenses.write'],
        ['engagements', '/engagements/',  'feather-briefcase',   'Engagements', 'engagements.view'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest Consultant Tracking role the member holds, in words — the header's badge. */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $st = db()->prepare('SELECT roles FROM members WHERE id = :id');
    $st->execute(['id' => (int) $m['id']]);
    $held = pg_text_array((string) ($st->fetchColumn() ?: '{}'));
    if ($held === []) {
        return ($m['capability'] ?? '') === 'admin' ? 'CT admin' : 'Consultant';
    }
    foreach (['admin' => 'CT admin', 'billing' => 'Billing', 'finance' => 'Finance', 'manager' => 'Manager', 'user' => 'Consultant'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return 'Consultant';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** A path a page may send a person back to: local, no scheme, no protocol-relative — else null. */
function safe_local_path(?string $path): ?string
{
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return null;
    }
    return $path;
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/notifications' => 'Notifications', '/activity' => 'Activity', '/settings/' => 'My settings', '/settings/tokens/' => 'Tokens',
               '/reports/utilization' => 'Utilization', '/reports/realization' => 'Realization', '/reports/wip' => 'Unbilled work', '/reports/revenue' => 'Revenue',
               '/reports/margin' => 'Margin', '/reports/budget-burn' => 'Budget burn', '/reports/ai-passthrough' => 'AI pass-through', '/reports/expenses' => 'Expense report',
               '/reports/missing-time' => 'Missing time', '/reports/ar-aging' => 'Who owes us', '/exports' => 'Exports'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    foreach (['engagements' => 'the engagement', 'clients' => 'the client', 'invoices' => 'the invoice', 'credit-notes' => 'the credit note',
              'payments' => 'the payment', 'expenses' => 'the expense', 'usage' => 'the usage charge', 'time' => 'the timesheet',
              'settings/rate-cards' => 'the rate card', 'settings/allocation-rules' => 'the allocation rule', 'settings/schedules' => 'the schedule'] as $dir => $label) {
        if (preg_match('#^/' . $dir . '/\d+$#', $path)) {
            return [$back, $label];
        }
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which
 * slice builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this
 * as unbuilt, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}
