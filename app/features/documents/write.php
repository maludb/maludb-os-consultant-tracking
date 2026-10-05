<?php
declare(strict_types=1);

/**
 * Attachments and notes on any record (slice 1 builds them, every slice uses them): a file lands under storage/attachments/<record_type>/<record_id>/
 * and is served through the gate (never by Apache); the mcp_attachments / mcp_notes views decide who sees what. Help Desk's store, generalised.
 */

const ATTACHMENT_RECORD_TYPES = ['client', 'engagement', 'milestone', 'expense', 'usage_charge', 'invoice', 'credit_note', 'usage_import'];
const NOTE_RECORD_TYPES = ['client', 'engagement', 'milestone', 'expense', 'usage_charge', 'invoice', 'timesheet'];
const ATTACHMENT_MIME_ALLOW = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/heic', 'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'application/json',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip', 'application/x-zip-compressed', 'message/rfc822'];

function attachment_max_bytes(): int
{
    return max(1024, (int) env('ATTACHMENT_MAX_BYTES', (string) (20 * 1048576)));
}

/** Store an upload against a record. $file is one $_FILES entry. Answers the attachment id. */
function store_attachment(PDO $pdo, string $recordType, int $recordId, array $file, int $byMember, bool $clientVisible = false): int
{
    if (!in_array($recordType, ATTACHMENT_RECORD_TYPES, true)) {
        throw new DomainException('A file goes on a client, engagement, milestone, expense, usage charge, invoice, credit note or import.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'The upload did not arrive.');
    }
    $size = (int) ($file['size'] ?? 0);
    $max = attachment_max_bytes();
    if ($size > $max) {
        throw new DomainException('A file is at most ' . ($max >= 1048576 ? intdiv($max, 1048576) . ' MB' : intdiv($max, 1024) . ' KB') . '.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $name = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file';
    if (!in_array($mime, ATTACHMENT_MIME_ALLOW, true) || preg_match('/\.(exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll|php)$/i', $name)) {
        throw new DomainException('That kind of file is not accepted (images, PDFs, text, office documents and zip are).');
    }
    $sha = hash_file('sha256', $tmp);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, client_visible, uploaded_by) VALUES (:t, :r, :n, :mt, :s, :h, :p, :cv, :by) RETURNING id');
    $st->execute(['t' => $recordType, 'r' => $recordId, 'n' => $name, 'mt' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'cv' => $clientVisible ? 't' : 'f', 'by' => $byMember]);
    $id = (int) $st->fetchColumn();
    $dir = APP_ROOT . '/storage/attachments/' . $recordType . '/' . $recordId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/' . $recordType . '/' . $recordId . '/' . $id . '-' . substr($sha, 0, 12);
    $full = APP_ROOT . '/storage/' . $path;
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $full) : rename($tmp, $full))) {
        throw new DomainException('The file could not be stored.');
    }
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    return $id;
}

/** The file of an attachment the caller may see (the view decides), or null. */
function attachment_path(PDO $pdo, int $attachmentId): ?array
{
    $st = $pdo->prepare('SELECT a.attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.client_visible, b.storage_path FROM mcp_attachments a JOIN attachments b ON b.id = a.attachment_id WHERE a.attachment_id = :id');
    $st->execute(['id' => $attachmentId]);
    $r = $st->fetch();
    return $r === false ? null : ['path' => APP_ROOT . '/storage/' . $r['storage_path'], 'filename' => $r['filename'], 'mime' => $r['mime_type'], 'size' => (int) $r['byte_size'],
        'record_type' => $r['record_type'], 'record_id' => (int) $r['record_id'], 'client_visible' => (bool) $r['client_visible']];
}

/** Delete an attachment's row and file. Answers what it was (for the log). */
function delete_attachment(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT record_type, record_id, filename, storage_path, byte_size, mime_type FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    $full = APP_ROOT . '/storage/' . $r['storage_path'];
    if (is_file($full)) {
        @unlink($full);
    }
    return ['record_type' => $r['record_type'], 'record_id' => (int) $r['record_id'], 'filename' => $r['filename'], 'byte_size' => (int) $r['byte_size'], 'mime_type' => $r['mime_type']];
}

/** The attachments of a record the caller may see. */
function find_attachments(PDO $pdo, string $recordType, int $recordId): array
{
    $st = $pdo->prepare('SELECT attachment_id, filename, mime_type, byte_size, client_visible, uploaded_by, uploaded_by_name, created_at FROM mcp_attachments WHERE record_type = :t AND record_id = :r ORDER BY attachment_id');
    $st->execute(['t' => $recordType, 'r' => $recordId]);
    return array_map(static function (array $a): array {
        $a['attachment_id'] = (int) $a['attachment_id'];
        $a['byte_size'] = (int) $a['byte_size'];
        $a['client_visible'] = (bool) $a['client_visible'];
        $a['uploaded_by'] = $a['uploaded_by'] === null ? null : (int) $a['uploaded_by'];
        return $a;
    }, $st->fetchAll());
}

function present_attachment(array $a): array
{
    return ['attachment_id' => $a['attachment_id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => $a['byte_size'], 'client_visible' => $a['client_visible'],
            'uploaded_by' => $a['uploaded_by'] === null ? null : ['member_id' => $a['uploaded_by'], 'display_name' => $a['uploaded_by_name']], 'created_at' => json_ts($a['created_at']),
            'url' => '/attachments/' . $a['attachment_id']];
}

function add_note(PDO $pdo, string $recordType, int $recordId, string $body, int $by): int
{
    if (!in_array($recordType, NOTE_RECORD_TYPES, true)) {
        throw new DomainException('A note goes on a client, engagement, milestone, expense, usage charge, invoice or timesheet.');
    }
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:t, :r, :m, :b) RETURNING id');
    $st->execute(['t' => $recordType, 'r' => $recordId, 'm' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

/** Delete a note: its author's, or the admin's. Answers [record_type, record_id]. */
function delete_note(PDO $pdo, int $id, int $by, bool $admin): array
{
    $st = $pdo->prepare('SELECT record_type, record_id, member_id FROM notes WHERE id = :id');
    $st->execute(['id' => $id]);
    $n = $st->fetch();
    if ($n === false) {
        throw new DomainException('Not found.');
    }
    if ((int) $n['member_id'] !== $by && !$admin) {
        throw new DomainException('Only its author or the admin removes a note.');
    }
    $pdo->prepare('DELETE FROM notes WHERE id = :id')->execute(['id' => $id]);
    return ['record_type' => $n['record_type'], 'record_id' => (int) $n['record_id']];
}

/** The notes of a record the caller may see, newest first. */
function find_notes(PDO $pdo, string $recordType, int $recordId): array
{
    $st = $pdo->prepare('SELECT note_id, member_id, member_name, member_kind, body, created_at FROM mcp_notes WHERE record_type = :t AND record_id = :r ORDER BY note_id DESC');
    $st->execute(['t' => $recordType, 'r' => $recordId]);
    return array_map(static function (array $n): array {
        $n['note_id'] = (int) $n['note_id'];
        $n['member_id'] = $n['member_id'] === null ? null : (int) $n['member_id'];
        return $n;
    }, $st->fetchAll());
}

function present_note(array $n): array
{
    return ['note_id' => $n['note_id'], 'by' => $n['member_id'] === null ? null : ['member_id' => $n['member_id'], 'display_name' => $n['member_name'], 'member_kind' => $n['member_kind']],
            'body' => $n['body'], 'created_at' => json_ts($n['created_at'])];
}

/** Whether the caller may see a record of a type (through its view) — the gate the generic doors use. */
function record_visible(PDO $pdo, string $recordType, int $recordId): bool
{
    $views = ['client' => ['mcp_clients', 'client_id'], 'engagement' => ['mcp_engagements', 'engagement_id'], 'milestone' => ['mcp_milestones', 'milestone_id'], 'expense' => ['mcp_expenses', 'expense_id'],
              'usage_charge' => ['mcp_usage_charges', 'usage_charge_id'], 'invoice' => ['mcp_invoices', 'invoice_id'], 'credit_note' => ['mcp_credit_notes', 'credit_note_id'],
              'timesheet' => ['mcp_timesheets', 'timesheet_id'], 'usage_import' => ['mcp_usage_imports', 'import_id']];
    if (!isset($views[$recordType])) {
        return false;
    }
    [$view, $col] = $views[$recordType];
    $st = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$col} = :id");
    $st->execute(['id' => $recordId]);
    return $st->fetchColumn() !== false;
}

/** Whether the caller may write on a record: its manager, its owner, or the right the type needs (the record's writer). */
function record_writable(PDO $pdo, string $recordType, int $recordId): bool
{
    if (!record_visible($pdo, $recordType, $recordId)) {
        return false;
    }
    if (is_ct_admin()) {
        return true;
    }
    return match ($recordType) {
        'client' => has_right('clients.manage'),
        'engagement', 'milestone' => db_bool($pdo, 'SELECT ct_can_manage_engagement(:id)', ['id' => $recordType === 'engagement' ? $recordId : (int) one_value($pdo, 'SELECT engagement_id FROM milestones WHERE id = :m', ['m' => $recordId])]),
        'expense' => db_bool($pdo, 'SELECT member_id = app_current_member_id() OR ct_can_manage_engagement(engagement_id) FROM expenses WHERE id = :id', ['id' => $recordId]) && has_right('attachments.write'),
        'usage_charge', 'usage_import' => has_right('usage.write'),
        'invoice', 'credit_note' => has_right('invoices.draft') || has_right('billing.write'),
        'timesheet' => db_bool($pdo, 'SELECT member_id = app_current_member_id() FROM timesheets WHERE id = :id', ['id' => $recordId]) || has_right('time.approve'),
        default => false,
    };
}

/** The page a record lives on (the landing after a file or note is added). */
function record_url(string $recordType, int $recordId, ?PDO $pdo = null): string
{
    if ($recordType === 'milestone' && $pdo !== null) {
        return '/engagements/' . (int) one_value($pdo, 'SELECT engagement_id FROM milestones WHERE id = :id', ['id' => $recordId]) . '/milestones';
    }
    return match ($recordType) {
        'client' => '/clients/' . $recordId, 'engagement' => '/engagements/' . $recordId, 'milestone' => '/engagements/milestones/' . $recordId, 'expense' => '/expenses/' . $recordId,
        'usage_charge' => '/usage/' . $recordId, 'usage_import' => '/usage/imports/' . $recordId, 'invoice' => '/invoices/' . $recordId, 'credit_note' => '/credit-notes/' . $recordId,
        'timesheet' => '/time/weeks/' . $recordId, default => '/',
    };
}

/** A record's key columns for the log (engagement_id, client_id, invoice_id, timesheet_id). */
function record_log_keys(PDO $pdo, string $recordType, int $recordId): array
{
    return match ($recordType) {
        'client' => ['client_id' => $recordId],
        'engagement' => ['engagement_id' => $recordId, 'client_id' => (int) one_value($pdo, 'SELECT client_id FROM engagements WHERE id = :id', ['id' => $recordId]) ?: null],
        'milestone' => ['engagement_id' => (int) one_value($pdo, 'SELECT engagement_id FROM milestones WHERE id = :id', ['id' => $recordId]) ?: null],
        'expense' => ['engagement_id' => (int) one_value($pdo, 'SELECT engagement_id FROM expenses WHERE id = :id', ['id' => $recordId]) ?: null],
        'usage_charge' => ['engagement_id' => (int) one_value($pdo, 'SELECT engagement_id FROM usage_charges WHERE id = :id', ['id' => $recordId]) ?: null],
        'invoice' => ['invoice_id' => $recordId, 'client_id' => (int) one_value($pdo, 'SELECT client_id FROM invoices WHERE id = :id', ['id' => $recordId]) ?: null],
        'credit_note' => ['client_id' => (int) one_value($pdo, 'SELECT client_id FROM credit_notes WHERE id = :id', ['id' => $recordId]) ?: null],
        'timesheet' => ['timesheet_id' => $recordId],
        default => [],
    };
}
