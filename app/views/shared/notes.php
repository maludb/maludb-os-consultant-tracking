<?php /** The notes panel of a record (shared; internal, never on the secure link). Data: type, id, notes, may (note, admin), here, tz, prefix, me */ $p = $prefix ?? 'record'; ?>
<div class="card mb-3" id="<?= e($p) ?>-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div>
    <div class="list-group list-group-flush" id="<?= e($p) ?>-notes-list">
        <?php if ($notes === []): ?><div class="list-group-item text-muted fs-12" id="<?= e($p) ?>-notes-empty">No note yet.</div><?php endif; ?>
        <?php foreach ($notes as $n): $nid = (int) $n['note_id']; ?>
            <div class="list-group-item" id="note-<?= $nid ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fs-12 text-muted"><?= e($n['member_name'] ?? 'Someone') ?><?= ($n['member_kind'] ?? '') === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?> · <?= e(format_ts($n['created_at'], $tz)) ?></div>
                    <?php if (($may['admin'] ?? false) || ($n['member_id'] !== null && $n['member_id'] === ($me ?? -1))): ?><form method="post" action="/engagements/notes/delete.php" hx-post="/engagements/notes/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Remove this note?"><?= csrf_field() ?><input type="hidden" name="note" value="<?= $nid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="note-<?= $nid ?>-delete-btn" title="Remove">&times;</button></form><?php endif; ?>
                </div>
                <div><?= nl2br(e($n['body'])) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($may['note']): ?>
    <div class="card-body border-top">
        <form method="post" action="/engagements/notes/save.php" hx-post="/engagements/notes/save.php" hx-target="#flash" id="<?= e($p) ?>-note-form">
            <?= csrf_field() ?><input type="hidden" name="record_type" value="<?= e($type) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <textarea name="body" class="form-control mb-2" rows="2" maxlength="8000" required placeholder="A note for whoever reads this next (never shown to the client)" id="<?= e($p) ?>-note-body"></textarea>
            <button type="submit" class="btn btn-light btn-touch w-100" id="<?= e($p) ?>-note-add-btn">Add a note</button>
        </form>
    </div>
    <?php endif; ?>
</div>
