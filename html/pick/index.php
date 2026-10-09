<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// Pattern A fragment: the record picker's rows (app/picker.php). GET /pick/?source=<key>&q=&page=&<declared params>.
// The source names the rights that admit a caller (app/pickers.php, the same rights as the screens that use the field) and its filter
// params; nothing else of the request reaches it. A search is not an action: nothing is logged. Errors answer a small fragment, never
// a page or a redirect, because the answer lands inside the modal.
header('Vary: HX-Request');
if (!is_logged_in()) {
    http_response_code(401);
    echo view('shared/picker-rows.php', ['error' => 'Sign in again to choose.']);
    exit;
}
$key = preg_replace('/[^a-z0-9_]/', '', mb_substr(request_string('source'), 0, 40));
$source = $key !== '' ? picker_source($key) : null;
if ($source === null) {
    http_response_code(404);
    echo view('shared/picker-rows.php', ['error' => 'That list does not exist.']);
    exit;
}
if (!nav_has_right(implode('|', $source['roles']))) {
    http_response_code(403);
    echo view('shared/picker-rows.php', ['error' => 'You do not have permission to choose from this list.']);
    exit;
}
$q = trim(mb_substr(request_string('q'), 0, 80));
$page = max(1, request_integer('page') ?? 1);
$params = [];
foreach ($source['params'] as $name) {
    $params[$name] = request_integer($name);
}
echo view('shared/picker-rows.php', [
    'source' => $key, 'q' => $q, 'params' => $params, 'selected' => request_integer('selected'), 'noun' => $source['noun'],
    'result' => ($source['search'])(db(), $q, $params, $page),
]);
