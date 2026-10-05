<?php /** One entry as a row of a day list. Data: e, editable (bool), here, showRates?, showCost?, manager? (the week view: status chips, write-off) */
$id = (int) $e['entry_id']; $editable = $editable ?? false; $showRates = $showRates ?? false; $showCost = $showCost ?? false; $manager = $manager ?? false;
$tone = ['draft' => null, 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'warning', 'written_off' => 'dark'][$e['status']] ?? null;
$inner = '<div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><span class="fw-semibold" id="time-entry-row-' . $id . '-engagement">' . e($e['engagement_number']) . '</span> <span class="text-muted">' . e($e['engagement_name']) . '</span>'
    . ($e['activity_label'] ? ' <span class="badge bg-soft-secondary text-secondary">' . e($e['activity_label']) . '</span>' : '')
    . ($e['billable'] ? ' <i class="feather-dollar-sign text-success" title="Billable"></i>' : '')
    . ($tone !== null && ($manager || $e['status'] !== 'submitted') ? ' <span class="badge bg-soft-' . $tone . ' text-' . $tone . '" id="time-entry-row-' . $id . '-status">' . e(ENTRY_STATUSES[$e['status']]) . '</span>' : '')
    . ($e['billed'] ? ' <span class="badge bg-soft-dark text-dark">billed</span>' : '')
    . ($e['timer_started_at'] !== null ? ' <i class="feather-clock text-muted" title="From the timer"></i>' : '')
    . ($e['note'] ? '<div class="fs-12 text-muted text-truncate">' . e(strtok((string) $e['note'], "\n")) . '</div>' : '')
    . ($manager && $e['internal_note'] ? '<div class="fs-12 text-muted fst-italic text-truncate">Internal: ' . e(strtok((string) $e['internal_note'], "\n")) . '</div>' : '')
    . ($showRates && ($e['bill_rate'] !== null || !empty($e['will_apply'])) ? '<div class="fs-12" id="time-entry-row-' . $id . '-rate">' . ($e['bill_rate'] !== null ? e(fmt_money($e['bill_rate'], $e['currency'])) . '/h <span class="text-muted">(' . e((string) $e['rate_source']) . ')</span> · ' . e(fmt_money($e['bill_value'], $e['currency']))
        : (($e['will_apply']['bill_rate'] ?? null) === null ? '<span class="text-danger">no rate will apply</span>' : 'will apply ' . e(fmt_money($e['will_apply']['bill_rate'], $e['currency'])) . '/h <span class="text-muted">(' . e($e['will_apply']['rate_source']) . ')</span> · ' . e(fmt_money((float) $e['hours'] * (float) $e['will_apply']['bill_rate'], $e['currency'])))) . '</div>' : '')
    . ($showCost && $e['cost_rate'] !== null ? '<div class="fs-12 text-muted" id="time-entry-row-' . $id . '-cost">cost ' . e(fmt_money($e['cost_rate'], $e['currency'])) . '/h</div>' : '')
    . '</div><div class="text-end flex-shrink-0"><span class="fw-bold" id="time-entry-row-' . $id . '-hours">' . e(fmt_hours($e['hours'])) . '</span></div></div>';
?>
<?php if ($editable): ?><a href="<?= e(with_back('/time/entries/' . $id . '/edit', $here)) ?>" hx-get="<?= e(with_back('/time/entries/' . $id . '/edit', $here)) ?>" hx-target="#page-content" hx-push-url="true" class="list-group-item list-group-item-action" id="time-entry-row-<?= $id ?>"><?= $inner ?></a>
<?php else: ?><div class="list-group-item" id="time-entry-row-<?= $id ?>"><?= $inner ?>
    <?php if ($manager && $e['status'] === 'approved' && !$e['billed'] && !empty($e['i_manage'])): ?><form method="post" action="/time/entries/write-off.php" hx-post="/time/entries/write-off.php" hx-target="#flash" class="mt-1 d-flex gap-1" hx-confirm="Write off <?= e(fmt_hours($e['hours'])) ?> on <?= e($e['engagement_number']) ?>? It will never be invoiced."><?= csrf_field() ?><input type="hidden" name="entry" value="<?= $id ?>"><input type="text" name="reason" class="form-control form-control-sm" placeholder="Why" maxlength="500" required id="time-entry-row-<?= $id ?>-writeoff-reason"><button type="submit" class="btn btn-sm btn-light" id="time-entry-row-<?= $id ?>-writeoff-btn">Write off</button></form><?php endif; ?>
    <?php if ($manager && $e['status'] === 'written_off'): ?><div class="fs-12 text-muted">Written off: <?= e((string) $e['written_off_reason']) ?></div><?php endif; ?>
</div><?php endif; ?>
