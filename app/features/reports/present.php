<?php
declare(strict_types=1);

/** Reports as JSON: the rows the function answered (typed), the cells the page shows, the totals — the same shapes Phase 4's report tools will answer. Internal keys (links, subtotal flags) stay out of the rows. */

function present_report(string $key, array $filters, array $report, array $extra = []): array
{
    $defs = report_defs();
    $cells = report_cells($report);
    $strip = static function (array $row): array {
        foreach (array_keys($row) as $k) {
            if (str_ends_with((string) $k, '_href')) {
                unset($row[$k]);
            }
        }
        return $row;
    };
    return [
        'report' => $key,
        'title' => $defs[$key]['title'] ?? $key,
        'filters' => $filters,
        'columns' => array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type']], $report['columns']),
        'rows' => array_map($strip, $report['rows']),
        'totals' => array_map($strip, $report['totals']),
        'cells' => $cells,
        'row_count' => report_row_count($report),
    ] + $extra;
}

/** The filters as logged and as the CSV's filename knows them: only what is set, no person's name. */
function report_log_filters(array $f): array
{
    return array_filter($f, static fn ($v): bool => $v !== null && $v !== '');
}

/** The report's name for a filename: "utilization-2026-09-01_2026-09-30". */
function report_filename(string $key, array $f): string
{
    $tail = isset($f['from'], $f['to']) ? $f['from'] . '_' . $f['to'] : ($f['as_of'] ?? ($f['week'] ?? date('Y-m-d')));
    return $key . '-' . $tail . '.csv';
}

/** The screen view of a report, with the report's name and filters in `after` (the page's own log; a JSON read logs only when asked to). */
function report_screen_view(PDO $pdo, string $key, array $f): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'report', 'after' => ['report' => $key] + report_log_filters($f)]);
}
