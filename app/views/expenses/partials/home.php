<?php /** The home's My expenses region (slice 4). Data: x = my_expenses_summary(), tz */ ?>
<div class="card h-100 mb-3" id="home-my-expenses"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start"><div class="fw-bold">My expenses</div><?= hx_link('/expenses/', 'Open', 'fs-12', 'id="home-my-expenses-link"') ?></div>
    <div class="fs-12 text-muted">
        <span id="home-my-expenses-counts"><?= (int) $x['counts']['draft'] ?> draft · <?= (int) $x['counts']['submitted'] ?> submitted · <?= (int) $x['counts']['approved'] ?> approved · <?= (int) $x['counts']['rejected'] ?> sent back</span>
    </div>
    <?php if ($x['owed'] !== []): ?><div class="fs-20 fw-bold mt-1" id="home-my-expenses-owed"><?= e(implode(' + ', array_map(static fn (string $c, string $a): string => fmt_money($a, $c), array_keys($x['owed']), array_values($x['owed'])))) ?> <span class="fs-12 fw-normal text-muted">owed to you (<?= (int) $x['owed_count'] ?>)</span></div>
    <?php else: ?><div class="fs-12 text-muted mt-1" id="home-my-expenses-owed-none">Nothing is owed to you.</div><?php endif; ?>
    <?php if ($x['needs_receipt'] > 0): ?><div class="fs-12 text-danger mt-1" id="home-my-expenses-needs"><?= (int) $x['needs_receipt'] ?> expense<?= (int) $x['needs_receipt'] === 1 ? '' : 's' ?> await a receipt. <?= hx_link('/expenses/?status=draft', 'See them') ?></div><?php endif; ?>
    <div class="mt-2"><?= hx_link('/expenses/new', '<i class="feather-camera me-1"></i>Snap a receipt', 'btn btn-light btn-touch w-100', 'id="home-my-expenses-add"') ?></div>
</div></div>
