<?php /** Everyone's hours per day for a week (screen `team-time`). Data: rows, ws, days, dept, departments, here */ $prev = (new DateTimeImmutable($ws))->modify('-7 days')->format('Y-m-d'); $next = (new DateTimeImmutable($ws))->modify('+7 days')->format('Y-m-d'); $q = static fn (string $w): string => '/time/team?week=' . $w . ($dept !== null ? '&department=' . $dept : ''); ?>
<?= view('shared/header.php', ['id' => 'team-time', 'title' => 'Team time', 'crumbs' => [['Home', '/'], ['Team time', null]]]) ?>
<div class="main-content" id="team-time-content">
    <div class="card mb-3"><div class="card-body py-2 d-flex justify-content-between align-items-center gap-2">
        <?= hx_link($q($prev), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="team-prev" aria-label="Previous week"') ?>
        <form method="get" action="/time/team" hx-get="/time/team" hx-target="#page-content" hx-push-url="true" class="d-flex align-items-center gap-2 min-w-0"><input type="hidden" name="week" value="<?= e($ws) ?>"><span class="fw-bold text-nowrap" id="team-week-label"><?= e(week_label($ws)) ?></span>
            <select name="department" class="form-select form-select-sm" id="team-filter-department" onchange="this.form.requestSubmit()"><option value="">Every practice</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= $dept === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></form>
        <?= hx_link($q($next), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="team-next" aria-label="Next week"') ?>
    </div></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0 text-center" id="team-time-table">
        <thead><tr><th class="text-start">Person</th><?php foreach ($days as $d): ?><th class="<?= $d['weekend'] ? 'text-muted' : '' ?>"><?= e($d['dow']) ?></th><?php endforeach; ?><th>Total</th><th>Week</th></tr></thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="10" class="text-muted py-3" id="team-time-empty">Nothing logged this week by anyone you may see.</td></tr><?php endif; ?>
            <?php foreach ($rows as $p): $tone = ['open' => 'secondary', 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'warning'][$p['status']] ?? 'secondary'; ?>
                <tr id="team-row-<?= (int) $p['member_id'] ?>"><td class="text-start fw-semibold"><?= hx_link(with_back('/time/?week=' . $ws . '&member=' . (int) $p['member_id'], $here), e($p['member_name'])) ?></td>
                <?php foreach ($days as $d): $h = $p['days'][$d['date']] ?? 0.0; ?><td class="<?= $h <= 0 && !$d['weekend'] ? 'bg-soft-danger text-danger' : '' ?>" id="team-cell-<?= (int) $p['member_id'] ?>-<?= e($d['date']) ?>"><?= $h > 0 ? e(days_label($h)) : ($d['weekend'] ? '' : '—') ?></td><?php endforeach; ?>
                <td class="fw-semibold"><?= e(days_label($p['total'])) ?></td><td><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>"><?= e(WEEK_STATUSES[$p['status']] ?? $p['status']) ?></span></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
