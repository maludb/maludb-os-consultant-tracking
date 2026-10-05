<?php
declare(strict_types=1);

/** The prelude of a client handler: the feature's files, and the client a request names (visible through the view, else 404). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/documents/write.php';

/** The client the request names (`client` or `client_id`), through the view: 404 when unseen; $live refuses an archived one in words. */
function client_from_request(PDO $pdo, bool $live = true): array
{
    $id = request_integer('client') ?? request_integer('client_id') ?? refuse(422, 'Say which client.');
    $c = find_client($pdo, $id) ?? refuse(404, 'Client not found.');
    if ($live && $c['archived_at'] !== null) {
        refuse(422, $c['name'] . ' is archived.');
    }
    return $c;
}
