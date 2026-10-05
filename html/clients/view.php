<?php
declare(strict_types=1);
/** /clients/{id} — one client (screen `client-view`): contacts (inline), engagements (slice 2 fills the screens), invoices and statement (slice 6; empty shapes), documents, notes, the timeline. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_right('engagements.view');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('client') ?? refuse(404, 'Client not found.');
$c = find_client($pdo, $id) ?? refuse(404, 'Client not found.');
$settings = find_settings($pdo);
$engagements = find_client_engagements($pdo, $id);
$attachments = find_attachments($pdo, 'client', $id);
$notes = find_notes($pdo, 'client', $id);
$timeline = find_record_activity($pdo, 'client', $id, 30);
$may = ['manage' => has_right('clients.manage'), 'delete' => is_ct_admin(), 'billing' => has_right('billing.write') || has_right('reports.view'), 'attach' => record_writable($pdo, 'client', $id),
        'engagements' => has_right('engagements.manage')];
log_screen_view($pdo, 'client-view');
if (wants_json()) {
    respond_screen(['client' => present_client($c), 'contacts' => array_map('present_client_contact', $c['contacts']), 'engagements' => array_map('present_client_engagement', $engagements),
        'invoices' => [], 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map('present_activity_row', $timeline), 'defaults' => ['currency' => $settings['base_currency'], 'terms_days' => $settings['default_terms_days']], 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved the client.'], 'contact' => ['success', 'Saved the contact.'], 'contact_removed' => ['success', 'The contact was removed.'], 'restored' => ['success', $c['name'] . ' is back.'],
            'attached' => ['success', 'The file is attached.'], 'file_removed' => ['success', 'The file was removed.'], 'noted' => ['success', 'The note is added.'], 'note_removed' => ['success', 'The note was removed.']];
render_screen($c['name'], view('clients/view.php', ['c' => $c, 'engagements' => $engagements, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'settings' => $settings, 'may' => $may,
    'here' => here_url(), 'editContact' => request_integer('contact'), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'clients', 'screen' => 'client-view', 'entity' => 'client', 'recordId' => (string) $id]);
