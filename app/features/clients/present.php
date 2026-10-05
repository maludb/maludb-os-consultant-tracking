<?php
declare(strict_types=1);

/** The JSON shape of a client (a whitelist over the view's row; the view already blanked what the caller may not see). */
function present_client(array $c): array
{
    return ['client_id' => $c['client_id'], 'name' => $c['name'], 'legal_name' => $c['legal_name'], 'code' => $c['code'], 'email' => $c['email'], 'phone' => $c['phone'],
            'billing_address' => $c['billing_address'], 'tax_id' => $c['tax_id'], 'terms_days' => $c['terms_days'], 'currency' => $c['currency'], 'po_required' => $c['po_required'],
            'rate_card' => $c['default_rate_card_id'] === null ? null : ['rate_card_id' => $c['default_rate_card_id'], 'name' => $c['rate_card_name']],
            'notes' => $c['notes'], 'archived' => $c['archived_at'] !== null, 'active_engagements' => $c['active_engagements'], 'engagement_count' => $c['engagement_count'],
            'contact_count' => $c['contact_count'], 'open_balance' => $c['open_balance'], 'created_at' => json_ts($c['created_at']), 'updated_at' => json_ts($c['updated_at'])];
}

function present_client_contact(array $k): array
{
    return ['contact_id' => $k['contact_id'], 'client_id' => $k['client_id'], 'name' => $k['name'], 'email' => $k['email'], 'phone' => $k['phone'], 'role' => $k['role'],
            'receives_invoices' => $k['receives_invoices'], 'is_primary' => $k['is_primary']];
}

function present_client_engagement(array $e): array
{
    return ['engagement_id' => (int) $e['engagement_id'], 'number' => $e['number'], 'name' => $e['name'], 'kind' => $e['kind'], 'status' => $e['status'],
            'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'currency' => $e['currency'], 'manager_name' => $e['manager_name']];
}
