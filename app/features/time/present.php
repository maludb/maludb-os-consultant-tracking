<?php
declare(strict_types=1);

/** The JSON shapes of time (whitelists over the views' rows — a bill rate or a cost is there only when the view gave it). */
function present_timesheet(array $t): array
{
    return ['timesheet_id' => $t['timesheet_id'], 'member' => ['member_id' => $t['member_id'], 'display_name' => $t['member_name']], 'week_start' => $t['week_start'], 'label' => week_label($t['week_start']), 'status' => $t['status'],
            'total_hours' => $t['total_hours'], 'billable_hours' => $t['billable_hours'], 'submitted_entries' => $t['submitted_entries'], 'submitted_at' => json_ts($t['submitted_at']), 'approved_at' => json_ts($t['approved_at']),
            'approved_by' => $t['approved_by'], 'rejected_at' => json_ts($t['rejected_at']), 'rejected_by' => $t['rejected_by'], 'rejection_reason' => $t['rejection_reason']];
}

function present_time_entry(array $e): array
{
    return ['entry_id' => $e['entry_id'], 'timesheet_id' => $e['timesheet_id'], 'member' => ['member_id' => $e['member_id'], 'display_name' => $e['member_name']],
            'engagement' => ['engagement_id' => $e['engagement_id'], 'number' => $e['engagement_number'], 'name' => $e['engagement_name']], 'entry_date' => $e['entry_date'], 'hours' => $e['hours'],
            'activity' => $e['activity_type_id'] === null ? null : ['activity_type_id' => $e['activity_type_id'], 'label' => $e['activity_label']], 'billable' => $e['billable'], 'note' => $e['note'], 'internal_note' => $e['internal_note'],
            'status' => $e['status'], 'bill_rate' => $e['bill_rate'], 'rate_source' => $e['rate_source'], 'bill_value' => $e['bill_value'], 'cost_rate' => $e['cost_rate'], 'currency' => $e['currency'], 'billed' => $e['billed'],
            'approved_at' => json_ts($e['approved_at']), 'written_off_reason' => $e['written_off_reason'], 'from_timer' => $e['timer_started_at'] !== null];
}

function present_timer(?array $t): ?array
{
    return $t === null ? null : ['engagement' => ['engagement_id' => $t['engagement_id'], 'number' => $t['engagement_number'], 'name' => $t['engagement_name']], 'activity_type_id' => $t['activity_type_id'], 'note' => $t['note'],
        'started_at' => json_ts($t['started_at']), 'elapsed_hours' => $t['elapsed_hours']];
}

function present_pending_week(array $t): array
{
    return present_timesheet($t) + ['mine_to_approve' => $t['mine_to_approve'], 'engagements_in' => $t['engagements_in']];
}
