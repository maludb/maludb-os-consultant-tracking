<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; html/pick/index.php serves it).
 * One entry per record kind AND per scope: a source's `roles` is a rights spec ("a|b": any one — the shell's nav_has_right()), the
 * same right the screens that use the field demand, and its `search` reads the views the screens read, so what a person may pick is what
 * they may see (a consultant staffed on two engagements is offered those two, never the firm's).
 *   client             the clients the caller may see (mcp_clients)                      — the billing and report filters, credit notes
 *   client_live        every live client by name                                         — the engagement form (the view guards details, not names)
 *   engagement         the engagements the caller may see, any status; client_id narrows — the list filters and reports
 *   engagement_draftable   the engagements an invoice may be drafted for                   — the invoice form
 *   engagement_loggable    the engagements member_id may log on `on` (YYYYMMDD)            — a time entry, the timer, an expense
 *   engagement_managed     the live engagements the caller manages                         — usage charges, imports, allocation rules, hosting schedules
 *   member             admitted members; human=1 drops agents                            — managers, capacity, cost rates, staffing, acceptance, reports
 * `params` are the only filter keys the endpoint forwards (integers).
 */

require_once __DIR__ . '/features/engagements/queries.php';

$statusColor = static fn (string $status): string => ['active' => 'success', 'on_hold' => 'warning', 'draft' => 'secondary', 'closed' => 'dark', 'cancelled' => 'danger'][$status] ?? 'secondary';
$engagementRow = static function (array $e) use ($statusColor): array {
    return [
        'id' => (int) $e['engagement_id'], 'label' => $e['number'] . ' · ' . $e['name'],
        'detail' => implode(' · ', array_filter([$e['client_name'] ?? null, isset($e['currency']) ? trim((string) $e['currency']) : null])),
        'badge' => ($e['status'] ?? 'active') === 'active' ? null : (ENGAGEMENT_STATUSES[$e['status']] ?? $e['status']), 'color' => $statusColor((string) ($e['status'] ?? 'active')),
    ];
};
$engagementLabel = static function (PDO $pdo, int $id): ?string {
    $st = $pdo->prepare('SELECT number, name FROM mcp_engagements WHERE engagement_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r['number'] . ' · ' . $r['name'];
};
$clientRow = static fn (array $c): array => ['id' => (int) $c['client_id'], 'label' => $c['name'], 'detail' => $c['code'] ?? null];
$memberRow = static fn (array $m): array => [
    'id' => (int) $m['member_id'], 'label' => $m['display_name'] . ($m['member_kind'] === 'agent' ? ' (agent)' : ''),
    'detail' => $m['member_kind'] === 'agent' ? 'Agent' : ($m['job_title'] ?? null),
];
$size = 25;

return [
    'client' => [
        'title' => 'Clients', 'noun' => 'client', 'roles' => ['engagements.view', 'clients.manage', 'invoices.draft', 'reports.view', 'billing.write'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($clientRow, $size): array {
            require_once __DIR__ . '/features/clients/queries.php';
            $total = count_clients($pdo, ['q' => $q]);
            return picker_result(array_map($clientRow, find_clients($pdo, ['q' => $q], $size, ($page - 1) * $size)), $total, $page, $size);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            $st = $pdo->prepare('SELECT name FROM mcp_clients WHERE client_id = :id');
            $st->execute(['id' => $id]);
            return ($v = $st->fetchColumn()) === false ? null : (string) $v;
        },
    ],
    'client_live' => [
        'title' => 'Clients', 'noun' => 'client', 'roles' => ['engagements.manage'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($clientRow, $size): array {
            require_once __DIR__ . '/features/clients/queries.php';
            $r = find_live_clients_page($pdo, $q, $size, ($page - 1) * $size);
            return picker_result(array_map($clientRow, $r['rows']), $r['total'], $page, $size);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            $st = $pdo->prepare('SELECT name FROM clients WHERE id = :id');
            $st->execute(['id' => $id]);
            return ($v = $st->fetchColumn()) === false ? null : (string) $v;
        },
    ],
    'engagement' => [
        'title' => 'Engagements', 'noun' => 'engagement', 'roles' => ['engagements.view', 'invoices.draft', 'reports.view', 'reports.view_own', 'usage.write'], 'params' => ['client_id'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($engagementRow, $size): array {
            require_once __DIR__ . '/features/engagements/queries.php';
            $filters = ['q' => $q, 'client_id' => $params['client_id'] ?? null, 'status' => ['all']];
            return picker_result(array_map($engagementRow, find_engagements($pdo, $filters, $size, ($page - 1) * $size)), count_engagements($pdo, $filters), $page, $size);
        },
        'label' => $engagementLabel,
    ],
    'engagement_draftable' => [
        'title' => 'Engagements', 'noun' => 'engagement', 'roles' => ['invoices.draft', 'billing.write'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($engagementRow, $size): array {
            require_once __DIR__ . '/features/invoices/queries.php';
            $r = draftable_engagements_page($pdo, $q, $size, ($page - 1) * $size);
            return picker_result(array_map($engagementRow, $r['rows']), $r['total'], $page, $size);
        },
        'label' => $engagementLabel,
    ],
    'engagement_loggable' => [
        'title' => 'Engagements', 'noun' => 'engagement', 'roles' => ['time.write', 'expenses.write'], 'params' => ['member_id', 'on'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($engagementRow, $size): array {
            require_once __DIR__ . '/features/time/queries.php';
            $on = isset($params['on']) ? DateTimeImmutable::createFromFormat('!Ymd', (string) $params['on']) : false;
            $date = $on !== false && $on->format('Ymd') === (string) $params['on'] ? $on->format('Y-m-d') : date('Y-m-d');
            $r = loggable_engagements_page($pdo, $params['member_id'] ?? (int) current_member_id(), $date, $q, $size, ($page - 1) * $size);
            return picker_result(array_map($engagementRow, $r['rows']), $r['total'], $page, $size);
        },
        'label' => $engagementLabel,
    ],
    'engagement_managed' => [
        'title' => 'Engagements', 'noun' => 'engagement', 'roles' => ['usage.write'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($engagementRow, $size): array {
            require_once __DIR__ . '/features/usage/queries.php';
            $r = usage_engagements_page($pdo, $q, $size, ($page - 1) * $size);
            return picker_result(array_map($engagementRow, $r['rows']), $r['total'], $page, $size);
        },
        'label' => $engagementLabel,
    ],
    'member' => [
        'title' => 'People', 'noun' => 'person', 'roles' => ['settings.manage', 'cost.table', 'staffing.write', 'milestones.write', 'engagements.manage', 'reports.view'], 'params' => ['human'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($memberRow, $size): array {
            $r = members_page($pdo, $q, ($params['human'] ?? 0) === 1, $size, ($page - 1) * $size);
            return picker_result(array_map($memberRow, $r['rows']), $r['total'], $page, $size);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            $st = $pdo->prepare("SELECT display_name, member_kind FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL");
            $st->execute(['id' => $id]);
            $m = $st->fetch();
            return $m === false ? null : $m['display_name'] . ($m['member_kind'] === 'agent' ? ' (agent)' : '');
        },
    ],
];
