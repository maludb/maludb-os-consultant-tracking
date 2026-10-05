<?php /** The documents panel of a record (shared by every record page). Data: type, id, attachments, may (attach, delete), here, prefix (ids: {prefix}-documents), clientVisible? (offer the switch) */ $p = $prefix ?? 'record'; $offer = $clientVisible ?? false; ?>
<div class="card mb-3" id="<?= e($p) ?>-documents"><div class="card-header"><h5 class="card-title mb-0">Documents</h5></div>
    <div class="card-body">
        <?php if ($attachments === []): ?><div class="text-muted fs-12" id="<?= e($p) ?>-documents-empty">Nothing attached.</div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2" id="<?= e($p) ?>-documents-list">
            <?php foreach ($attachments as $a): $aid = (int) $a['attachment_id']; ?>
                <span class="border rounded px-2 py-1 d-inline-flex align-items-center gap-2 fs-12" id="attachment-chip-<?= $aid ?>">
                    <a href="/attachments/<?= $aid ?>" target="_blank" rel="noopener" id="attachment-chip-<?= $aid ?>-link"><i class="feather-paperclip me-1"></i><?= e($a['filename']) ?></a><span class="text-muted">· <?= e(fmt_bytes((int) $a['byte_size'])) ?></span>
                    <?php if ($a['client_visible']): ?><span class="badge bg-soft-info text-info" title="Shared with the client on the secure link">shared</span><?php endif; ?>
                    <?php if ($may['delete']): ?><form method="post" action="/engagements/attachments/delete.php" hx-post="/engagements/attachments/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Remove <?= e($a['filename']) ?>?"><?= csrf_field() ?><input type="hidden" name="attachment" value="<?= $aid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="attachment-chip-<?= $aid ?>-delete-btn" title="Remove">&times;</button></form><?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php if ($may['attach']): ?>
        <form method="post" action="/engagements/attachments/save.php" hx-post="/engagements/attachments/save.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" class="row g-2 align-items-end mt-2" id="<?= e($p) ?>-attachment-form">
            <?= csrf_field() ?><input type="hidden" name="record_type" value="<?= e($type) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <div class="col-8"><input type="file" name="file" class="form-control btn-touch" id="<?= e($p) ?>-attachment-file" required></div>
            <div class="col-4"><button type="submit" class="btn btn-light btn-touch w-100" id="<?= e($p) ?>-attachment-add-btn">Add</button></div>
            <?php if ($offer): ?><div class="col-12"><input type="hidden" name="client_visible" value="no"><label class="d-flex align-items-center gap-2 fs-12 mb-0" for="<?= e($p) ?>-attachment-visible"><input type="checkbox" class="form-check-input mt-0" name="client_visible" value="yes" id="<?= e($p) ?>-attachment-visible">Share it with the client on the secure link</label></div><?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>
