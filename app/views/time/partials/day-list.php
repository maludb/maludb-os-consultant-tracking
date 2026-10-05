<?php /** The week as a day list (the phone's shape): each day its entries, the total, "+ Add". Data: shape, editable, here, addBase ('/time/entries/new?date=' + member), showRates?, showCost?, manager? */ $editable = $editable ?? false; ?>
<div id="week-days">
    <?php foreach ($shape['days'] as $date => $d): ?>
        <div class="card mb-2 <?= $d['day']['weekend'] && $d['entries'] === [] ? 'opacity-75' : '' ?>" id="day-<?= e($date) ?>">
            <div class="card-header py-2 d-flex justify-content-between align-items-center"><span class="fw-semibold <?= $d['day']['today'] ? 'text-primary' : '' ?>"><?= e($d['day']['label']) ?><?= $d['day']['today'] ? ' · today' : '' ?></span><span class="fs-12" id="day-<?= e($date) ?>-total"><?= $d['total'] > 0 ? e(fmt_hours($d['total'])) : '<span class="text-muted">—</span>' ?></span></div>
            <?php if ($d['entries'] !== []): ?><div class="list-group list-group-flush"><?php foreach ($d['entries'] as $e): ?><?= view('time/partials/time-entry-row.php', ['e' => $e, 'editable' => $editable && in_array($e['status'], ['draft', 'rejected'], true), 'here' => $here, 'showRates' => $showRates ?? false, 'showCost' => $showCost ?? false, 'manager' => $manager ?? false]) ?><?php endforeach; ?></div><?php endif; ?>
            <?php if ($editable): ?><div class="card-body py-2"><?= hx_link(with_back($addBase . $date, $here), '<i class="feather-plus me-1"></i>Add', 'btn btn-light btn-sm w-100', 'id="day-' . e($date) . '-add"') ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
