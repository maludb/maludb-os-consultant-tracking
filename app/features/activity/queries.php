<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log: their own rows; their visible engagements' rows with reports.view or time.approve; everything for Billing, Finance, admin; their own weeks'). */
const ACTIVITY_PAGE = 50;

/** Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days), engagement, invoice, timesheet. */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (['engagement' => 'engagement_id', 'invoice' => 'invoice_id', 'timesheet' => 'timesheet_id'] as $k => $col) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = "$col = :$k";
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_kind, actor_client_id, source, action, entity_type, entity_id, engagement_id, client_id, invoice_id, timesheet_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** The record a row concerns, as a link: [url, label] or null. */
function activity_record_link(array $r): ?array
{
    if (($r['invoice_id'] ?? null) !== null) { return ['/invoices/' . (int) $r['invoice_id'], 'invoice']; }
    if (($r['timesheet_id'] ?? null) !== null) { return ['/time/weeks/' . (int) $r['timesheet_id'], 'week']; }
    if (($r['engagement_id'] ?? null) !== null) { return ['/engagements/' . (int) $r['engagement_id'], 'engagement']; }
    if (($r['client_id'] ?? null) !== null) { return ['/clients/' . (int) $r['client_id'], 'client']; }
    return null;
}

/** One line for a row, in words: "Priya logged time". Falls back to the event name. Later slices add their events. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? ($r['actor_client_id'] !== null ? 'A client' : 'Consultant Tracking');
    if (($r['actor_kind'] ?? '') === 'agent') { $who .= ' (agent)'; }
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'assistant.ask' => 'asked the assistant',
        'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications',
        'client.create' => 'added a client', 'client.update' => 'changed a client', 'client.archive' => 'archived a client', 'client.restore' => 'restored a client', 'client.delete' => 'deleted a client',
        'client_contact.save' => 'changed a client contact', 'client_contact.remove' => 'removed a client contact', 'client.statement_send' => 'sent a client statement',
        'consulting_role.save' => 'changed a consulting role', 'consulting_role.archive' => 'archived a consulting role', 'rate_card.save' => 'changed a rate card', 'rate_card.rate' => 'changed a rate on a card',
        'rate_card.archive' => 'archived a rate card', 'rate_card.delete' => 'deleted a rate card', 'cost_rate.save' => 'set a cost rate', 'cost_rate.end' => 'ended a cost rate', 'capacity.save' => 'set a capacity',
        'activity_type.save' => 'changed an activity type', 'activity_type.archive' => 'archived an activity type', 'expense_category.save' => 'changed an expense category', 'expense_category.archive' => 'archived an expense category',
        'usage_provider.save' => 'changed a provider', 'tax_rate.save' => 'changed a tax rate', 'tax_rate.archive' => 'archived a tax rate', 'settings.save' => 'changed the settings', 'numbering.save' => 'changed the numbering',
        'engagement.create' => 'opened an engagement', 'engagement.update' => 'changed an engagement', 'engagement.activate' => 'activated an engagement', 'engagement.hold' => 'put an engagement on hold',
        'engagement.close' => 'closed an engagement', 'engagement.reopen' => 'reopened an engagement', 'engagement.cancel' => 'cancelled an engagement', 'engagement.delete' => 'deleted a draft engagement',
        'engagement.staff' => 'staffed someone', 'engagement.unstaff' => 'ended a staffing', 'engagement.role_rate' => 'changed a role rate', 'milestone.save' => 'changed a milestone', 'milestone.deliver' => 'delivered a milestone',
        'milestone.accept' => 'recorded a milestone accepted', 'milestone.cancel' => 'cancelled a milestone', 'milestone.delete' => 'deleted a milestone', 'budget_alert.write' => 'wrote a budget alert', 'budget_alert.acknowledge' => 'acknowledged a budget alert',
        'time_entry.save' => 'logged time', 'time_entry.delete' => 'removed a time entry', 'time_entry.write_off' => 'wrote off time', 'timer.start' => 'started a timer', 'timer.stop' => 'stopped a timer',
        'timesheet.submit' => 'submitted a week', 'timesheet.reopen' => 'reopened a week', 'timesheet.approve' => 'approved a week', 'timesheet.reject' => 'rejected a week', 'timesheet.nudge' => 'nudged someone about their time',
        'expense.save' => 'recorded an expense', 'expense.delete' => 'removed an expense', 'expense.submit' => 'submitted an expense', 'expense.approve' => 'approved an expense', 'expense.reject' => 'rejected an expense',
        'expense.reimburse' => 'marked an expense reimbursed', 'expense.attach' => 'attached a receipt',
        'usage_charge.draft' => 'drafted a usage charge', 'usage_charge.delete' => 'removed a usage charge', 'usage_charge.approve' => 'approved a usage charge', 'usage_charge.void' => 'voided a usage charge',
        'usage_import.run' => 'imported a usage file', 'usage_import.delete' => 'removed a usage import', 'ai_statement.pull' => 'pulled the OS statement', 'ai_statement.allocate' => 'allocated the OS statement',
        'allocation_rule.save' => 'changed an allocation rule', 'allocation_rule.delete' => 'deleted an allocation rule', 'recurring_charge.save' => 'changed a hosting schedule', 'recurring_charge.run' => 'ran a hosting schedule', 'recurring_charge.delete' => 'deleted a hosting schedule',
        'invoice.draft' => 'drafted an invoice', 'invoice.update' => 'changed a draft invoice', 'invoice.add_unbilled' => 'pulled unbilled work into an invoice', 'invoice.line_save' => 'changed an invoice line', 'invoice.line_remove' => 'removed an invoice line',
        'invoice.item_remove' => 'took an item off an invoice', 'invoice.delete' => 'deleted a draft invoice', 'invoice.issue' => 'issued an invoice', 'invoice.send' => 'sent an invoice', 'invoice.view' => 'opened the invoice', 'invoice.remind' => 'sent a reminder',
        'invoice.void' => 'voided an invoice', 'invoice.write_off' => 'wrote off an invoice', 'invoice.external_ref' => 'noted the ledger\'s reference', 'invoice.secure_link_rotate' => 'rotated a secure link',
        'payment.record' => 'recorded a payment', 'payment.void' => 'voided a payment', 'payment_claim.submit' => 'said they paid', 'payment_claim.confirm' => 'confirmed a payment claim', 'payment_claim.dismiss' => 'dismissed a payment claim',
        'credit_note.draft' => 'drafted a credit note', 'credit_note.line_save' => 'changed a credit note line', 'credit_note.delete' => 'deleted a draft credit note', 'credit_note.issue' => 'issued a credit note',
        'credit_note.apply' => 'applied a credit note', 'credit_note.unapply' => 'unapplied a credit note', 'credit_note.void' => 'voided a credit note',
        'attachment.add' => 'attached a file', 'attachment.delete' => 'removed an attachment', 'note.add' => 'added a note', 'note.delete' => 'removed a note',
        'export.download' => 'downloaded an export', 'share.read' => 'a sibling application read our data', 'morning_note.send' => 'sent the morning note', 'worker.pass' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action']));
}
