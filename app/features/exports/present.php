<?php
declare(strict_types=1);

/** The exports page as JSON: the catalogue of downloads the caller may take. Never a document's rows (those are the download). */

function present_exports(): array
{
    return array_map(static fn (string $key, array $e): array => ['export' => $key, 'title' => $e['title'], 'schema' => $e['schema'], 'blurb' => $e['blurb'], 'window' => $e['period'], 'formats' => array_keys(EXPORT_FORMATS)], array_keys(EXPORTS), EXPORTS);
}
