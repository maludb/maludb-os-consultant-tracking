<?php /** The milestones tab (screen `engagement-milestones`): cards; add or change; Deliver, Accept (a form: who, how, evidence), Cancel, Delete. Data: e, may (milestones), here, editing, accepting, tz */
$id = $e['engagement_id']; $cur = null; $acc = null;
foreach ($e['milestones'] as $m) { if ($editing !== null && $m['milestone_id'] === $editing) { $cur = $m; } if ($accepting !== null && $m['milestone_id'] === $accepting) { $acc = $m; } }
$tone = ['planned' => 'secondary', 'delivered' => 'info', 'accepted' => 'success', 'invoiced' => 'dark', 'cancelled' => 'danger']; $base = '/engagements/' . $id . '/milestones'; ?>
<div class="row g-3" id="engagement-milestones-content">
    <div class="col-lg-7">
        <?php if ($e['milestones'] === []): ?><div class="card"><div class="card-body text-muted" id="milestones-empty"><?= $e['kind'] === 'fixed_bid' ? 'No milestone yet. A fixed bid is billed as its milestones are accepted.' : 'No milestone yet. On a T&M engagement a milestone is a deliverable with no amount.' ?></div></div><?php endif; ?>
        <div class="row g-3" id="milestones-cards">
            <?php foreach ($e['milestones'] as $m): $mid = $m['milestone_id']; $t = $tone[$m['status']]; ?>
                <div class="col-12 col-md-6"><div class="card h-100" id="milestone-card-<?= $mid ?>"><div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold min-w-0" id="milestone-card-<?= $mid ?>-name"><?= e($m['name']) ?></div><span class="badge bg-soft-<?= $t ?> text-<?= $t ?>" id="milestone-card-<?= $mid ?>-status"><?= e(MILESTONE_STATUSES[$m['status']]) ?></span></div>
                    <div class="fs-12 text-muted mt-1"><?= (float) $m['amount'] > 0 ? e(fmt_money($m['amount'], $e['currency'])) : 'No amount' ?><?= $m['due_date'] ? ' · due ' . e(format_date($m['due_date'])) : '' ?><?= $m['delivered_at'] ? ' · delivered ' . e(format_ts($m['delivered_at'], $tz, 'M j')) : '' ?><?= $m['accepted_at'] ? ' · accepted ' . e(format_ts($m['accepted_at'], $tz, 'M j')) . ($m['accepted_by_name'] ? ' by ' . e($m['accepted_by_name']) : '') : '' ?><?= $m['billed'] ? ' · <span class="badge bg-soft-dark text-dark">billed</span>' : '' ?></div>
                    <?php if ($m['description']): ?><div class="fs-12 mt-1"><?= nl2br(e($m['description'])) ?></div><?php endif; ?>
                    <?php if ($m['acceptance_note']): ?><div class="fs-12 mt-1 text-muted" id="milestone-card-<?= $mid ?>-acceptance">Accepted: <?= e($m['acceptance_note']) ?></div><?php endif; ?>
                    <?php if ($m['cancel_reason']): ?><div class="fs-12 mt-1 text-muted">Cancelled: <?= e($m['cancel_reason']) ?></div><?php endif; ?>
                    <?php if ($may['milestones'] && !in_array($m['status'], ['invoiced', 'cancelled'], true)): ?>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <?php if ($m['status'] === 'planned'): ?><form method="post" action="/engagements/milestones/deliver.php" hx-post="/engagements/milestones/deliver.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="milestone" value="<?= $mid ?>"><button type="submit" class="btn btn-sm btn-light" id="milestone-card-<?= $mid ?>-deliver-btn">Deliver</button></form><?php endif; ?>
                        <?php if (in_array($m['status'], ['planned', 'delivered'], true)): ?><?= hx_link($base . '?accept=' . $mid . '#milestone-accept-form', 'Accept', 'btn btn-sm btn-primary', 'id="milestone-card-' . $mid . '-accept-btn"') ?><?= hx_link($base . '?milestone=' . $mid . '#milestone-form', 'Change', 'btn btn-sm btn-light', 'id="milestone-card-' . $mid . '-edit-btn"') ?>
                            <form method="post" action="/engagements/milestones/cancel.php" hx-post="/engagements/milestones/cancel.php" hx-target="#flash" class="d-inline" hx-confirm="Cancel <?= e($m['name']) ?>?" hx-prompt="Why is it cancelled?"><?= csrf_field() ?><input type="hidden" name="milestone" value="<?= $mid ?>"><input type="hidden" name="reason" value="cancelled from the card"><button type="submit" class="btn btn-sm btn-light" id="milestone-card-<?= $mid ?>-cancel-btn">Cancel it</button></form><?php endif; ?>
                        <?php if ($m['status'] === 'planned'): ?><form method="post" action="/engagements/milestones/delete.php" hx-post="/engagements/milestones/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Delete <?= e($m['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="milestone" value="<?= $mid ?>"><button type="submit" class="btn btn-sm btn-light text-danger" id="milestone-card-<?= $mid ?>-delete-btn">Delete</button></form><?php endif; ?>
                        <?php if ($m['status'] === 'accepted' && (float) $m['amount'] > 0 && !$m['billed']): ?><span class="btn btn-sm btn-light disabled" title="Slice 6 builds invoicing" id="milestone-card-<?= $mid ?>-invoice-btn">Invoice this milestone (slice 6)</span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div></div></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($may['milestones']): ?>
    <div class="col-lg-5">
        <?php if ($acc !== null): ?>
        <div class="card mb-3" id="milestone-accept-card"><div class="card-header"><h5 class="card-title mb-0">Accept <?= e($acc['name']) ?></h5></div><div class="card-body">
            <form method="post" action="/engagements/milestones/accept.php" hx-post="/engagements/milestones/accept.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="milestone-accept-form">
                <?= csrf_field() ?><input type="hidden" name="milestone" value="<?= (int) $acc['milestone_id'] ?>">
                <label class="form-label fs-12 text-muted" for="milestone-accept-field-note">Who at the client accepted it, and how</label>
                <textarea name="acceptance_note" id="milestone-accept-field-note" class="form-control mb-3" rows="3" maxlength="2000" required placeholder="Jo (AP) by email on Mar 3"></textarea>
                <label class="form-label fs-12 text-muted" for="milestone-accept-field-by">Recorded by</label>
                <select name="accepted_by" id="milestone-accept-field-by" class="form-select btn-touch mb-3"><?php foreach (members_for_pick(db()) as $m): if ($m['member_kind'] !== 'human') { continue; } ?><option value="<?= (int) $m['member_id'] ?>" <?= (int) $m['member_id'] === (int) current_member_id() ? 'selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12 text-muted" for="milestone-accept-field-file">Evidence (the client's email, the signed sheet) — optional</label>
                <input type="file" name="file" id="milestone-accept-field-file" class="form-control btn-touch mb-3">
                <?php if ((float) $acc['amount'] > 0): ?><div class="fs-12 text-muted mb-3">Accepting fixes the amount (<?= e(fmt_money($acc['amount'], $e['currency'])) ?>) and makes it billable.</div><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="milestone-accept-save-btn">Accept</button>
                <?= hx_link($base, 'Cancel', 'btn btn-light btn-touch w-100 mt-2', 'id="milestone-accept-cancel"') ?>
            </form>
        </div></div>
        <?php endif; ?>
        <div class="card" id="milestone-form-card"><div class="card-header"><h5 class="card-title mb-0"><?= $cur === null ? 'Add a milestone' : 'Change ' . e($cur['name']) ?></h5></div><div class="card-body">
            <form method="post" action="/engagements/milestones/save.php" hx-post="/engagements/milestones/save.php" hx-target="#flash" id="milestone-form">
                <?= csrf_field() ?><input type="hidden" name="engagement" value="<?= $id ?>"><?php if ($cur !== null): ?><input type="hidden" name="milestone" value="<?= (int) $cur['milestone_id'] ?>"><?php endif; ?>
                <label class="form-label fs-12 text-muted" for="milestone-form-field-name">Name</label>
                <input type="text" name="name" id="milestone-form-field-name" class="form-control btn-touch mb-3" maxlength="160" required value="<?= e($cur['name'] ?? '') ?>">
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="milestone-form-field-amount">Amount (<?= e($e['currency']) ?>; 0 = a deliverable)</label><input type="text" name="amount" id="milestone-form-field-amount" class="form-control btn-touch" inputmode="decimal" value="<?= e($cur === null ? '' : number_format((float) $cur['amount'], 2, '.', '')) ?>" <?= $cur !== null && $cur['status'] === 'accepted' ? 'readonly' : '' ?>></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="milestone-form-field-due">Due</label><input type="date" name="due_date" id="milestone-form-field-due" class="form-control btn-touch" value="<?= e($cur['due_date'] ?? '') ?>"></div>
                </div>
                <label class="form-label fs-12 text-muted" for="milestone-form-field-description">Description</label>
                <textarea name="description" id="milestone-form-field-description" class="form-control mb-3" rows="2" maxlength="2000"><?= e($cur['description'] ?? '') ?></textarea>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="milestone-form-save-btn"><?= $cur === null ? 'Add' : 'Save' ?></button>
                <?php if ($cur !== null): ?><?= hx_link($base, 'Cancel', 'btn btn-light btn-touch w-100 mt-2', 'id="milestone-form-cancel"') ?><?php endif; ?>
            </form>
        </div></div>
    </div>
    <?php endif; ?>
</div>
