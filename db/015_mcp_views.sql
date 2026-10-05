-- 015: WHO SEES WHAT, and the mcp_* visibility views — the only thing the two read roles see (memory.md §1). Every WHERE
-- clause IS the row rule (design §3, db/004, db/008); the servers never filter in Python. Caller checks are scalar
-- subqueries or uncorrelated sets, tested once per statement. Cost (cost rates, cost snapshots, margin) is shown only
-- under cost.view, and to a Manager only on the engagements they manage. Bill rates need rates.view. A client's contact
-- details need clients.manage or billing.write. Plus the two functions the MCP servers need at first contact.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO consultant_tracking_records_ro, consultant_tracking_activity_ro, consultant_tracking_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO consultant_tracking_records_ro, consultant_tracking_activity_ro, consultant_tracking_rw;

-- Cost is visible to the caller on this engagement: cost.view, and (sees all, or manages it).
CREATE OR REPLACE FUNCTION ct_cost_visible(p_engagement_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN ct_has_right('cost.view') AND (ct_sees_all() OR p_engagement_id IN (SELECT ct_managed_engagement_ids()));
END$$;
GRANT EXECUTE ON FUNCTION ct_cost_visible(bigint) TO consultant_tracking_rw, consultant_tracking_records_ro, consultant_tracking_activity_ro;

-- ---------------------------------------------------------------------------------------------
-- People, departments, settings, the vocabulary: every admitted member.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.business_role, m.is_external, m.status, m.capability, m.roles, m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids,
       ct_primary_department(m.id) AS primary_department_id
  FROM members m
 WHERE (SELECT app_is_active_member()) AND m.status = 'active';

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.firm_name, s.firm_address, s.base_currency, s.timezone, s.week_start_dow, s.hours_increment, s.max_hours_per_day, s.receipt_threshold, s.default_markup_pct,
       s.default_terms_days, s.default_time_grouping, s.share_receipts_default, s.link_lifetime_days, s.reminder_days, s.budget_thresholds_pct, s.ai_budget_threshold_pct,
       s.capacity_hours_per_week, s.ai_statement_poll, s.morning_note_member_id, s.missing_time_nudge_dow, s.updated_at
  FROM ct_settings s WHERE s.id = 1 AND (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_consulting_roles WITH (security_barrier = true) AS
SELECT r.id AS consulting_role_id, r.key, r.title, r.description, r.sort_order, r.archived_at FROM consulting_roles r WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_activity_types WITH (security_barrier = true) AS
SELECT a.id AS activity_type_id, a.key, a.label, a.billable_default, a.sort_order, a.archived_at FROM activity_types a WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_expense_categories WITH (security_barrier = true) AS
SELECT c.id AS category_id, c.key, c.label, c.receipt_required_above, c.default_markup_pct, c.billable_default, c.sort_order, c.archived_at
  FROM expense_categories c WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_usage_providers WITH (security_barrier = true) AS
SELECT p.id AS provider_id, p.provider_key, p.name, p.csv_mapping, p.sort_order, p.archived_at FROM usage_providers p WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_tax_rates WITH (security_barrier = true) AS
SELECT t.id AS tax_rate_id, t.name, t.rate, t.is_default, t.archived_at FROM tax_rates t WHERE (SELECT app_is_active_member());

-- ---------------------------------------------------------------------------------------------
-- Rates: cards and their rates need rates.view; the cost-rate table is the admin's and Finance's; capacity is a report fact.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_rate_cards WITH (security_barrier = true) AS
SELECT r.id AS rate_card_id, r.name, r.currency, r.effective_from, r.description, r.archived_at, r.created_by, r.created_at,
       (SELECT count(*) FROM rate_card_rates x WHERE x.rate_card_id = r.id) AS rate_count
  FROM rate_cards r WHERE (SELECT ct_has_right('rates.view'));

CREATE OR REPLACE VIEW mcp_rate_card_rates WITH (security_barrier = true) AS
SELECT x.rate_card_id, x.consulting_role_id, cr.title AS role_title, x.bill_rate, x.updated_at
  FROM rate_card_rates x JOIN consulting_roles cr ON cr.id = x.consulting_role_id WHERE (SELECT ct_has_right('rates.view'));

CREATE OR REPLACE VIEW mcp_member_cost_rates WITH (security_barrier = true) AS
SELECT c.id AS cost_rate_id, c.member_id, m.display_name AS member_name, c.hourly_cost, c.valid_from, c.valid_to, c.note, c.created_at
  FROM member_cost_rates c JOIN members m ON m.id = c.member_id
 WHERE (SELECT ct_has_right('cost.view')) AND (SELECT ct_sees_all());

CREATE OR REPLACE VIEW mcp_member_capacity WITH (security_barrier = true) AS
SELECT c.id AS capacity_id, c.member_id, m.display_name AS member_name, c.hours_per_week, c.valid_from, c.valid_to
  FROM member_capacity c JOIN members m ON m.id = c.member_id
 WHERE c.member_id = app_current_member_id() OR (SELECT ct_has_right('reports.view'));

-- ---------------------------------------------------------------------------------------------
-- Clients and engagements: the engagements the caller may see, and their clients; contact details to those who bill.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_clients WITH (security_barrier = true) AS
SELECT c.id AS client_id, c.name, c.legal_name, c.code, c.terms_days, c.currency, c.po_required, c.default_rate_card_id, c.member_id, c.archived_at, c.created_at, c.updated_at,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN c.email::text END AS email,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN c.phone END AS phone,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN c.billing_address END AS billing_address,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN c.tax_id END AS tax_id,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN c.notes END AS notes,
       (SELECT count(*) FROM engagements e WHERE e.client_id = c.id AND e.status = 'active') AS active_engagements
  FROM clients c
 WHERE (SELECT ct_sees_all()) OR (SELECT ct_has_right('clients.manage'))
    OR c.id IN (SELECT e.client_id FROM engagements e WHERE e.id IN (SELECT ct_visible_engagement_ids()));

CREATE OR REPLACE VIEW mcp_client_contacts WITH (security_barrier = true) AS
SELECT k.id AS contact_id, k.client_id, k.name, k.role, k.receives_invoices, k.is_primary, k.archived_at,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN k.email::text END AS email,
       CASE WHEN (SELECT ct_has_right('clients.manage')) OR (SELECT ct_has_right('billing.write')) THEN k.phone END AS phone
  FROM client_contacts k WHERE k.client_id IN (SELECT client_id FROM mcp_clients);

CREATE OR REPLACE VIEW mcp_engagements WITH (security_barrier = true) AS
SELECT e.id AS engagement_id, e.number, e.client_id, c.name AS client_name, e.name, e.kind, e.status, e.start_date, e.end_date, e.currency, e.po_number,
       CASE WHEN (SELECT ct_has_right('rates.view')) THEN e.rate_card_id END AS rate_card_id,
       e.nte_amount, e.budget_hours, e.budget_amount, e.ai_budget_amount, e.expense_policy, e.billing_cadence, e.next_billing_date, e.time_grouping,
       e.share_receipts_with_client, e.department_id, d.name AS department_name, e.manager_member_id, mm.display_name AS manager_name, e.projects_ref, e.description,
       e.activated_at, e.closed_at, e.created_by, e.created_at, e.updated_at,
       (e.id IN (SELECT ct_managed_engagement_ids())) AS i_manage,
       EXISTS (SELECT 1 FROM engagement_members em WHERE em.engagement_id = e.id AND em.member_id = app_current_member_id() AND em.valid_from <= current_date AND (em.valid_to IS NULL OR em.valid_to > current_date)) AS i_am_staffed,
       (SELECT count(*) FROM engagement_members em WHERE em.engagement_id = e.id AND (em.valid_to IS NULL OR em.valid_to > current_date)) AS staffed_count
  FROM engagements e JOIN clients c ON c.id = e.client_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN members mm ON mm.id = e.manager_member_id
 WHERE e.id IN (SELECT ct_visible_engagement_ids());

CREATE OR REPLACE VIEW mcp_engagement_members WITH (security_barrier = true) AS
SELECT em.id AS staffing_id, em.engagement_id, em.member_id, m.display_name AS member_name, m.member_kind, em.consulting_role_id, cr.title AS role_title,
       CASE WHEN (SELECT ct_has_right('rates.view')) THEN em.bill_rate_override END AS bill_rate_override,
       em.valid_from, em.valid_to, em.note, em.created_at
  FROM engagement_members em JOIN members m ON m.id = em.member_id JOIN consulting_roles cr ON cr.id = em.consulting_role_id
 WHERE em.engagement_id IN (SELECT ct_visible_engagement_ids());

CREATE OR REPLACE VIEW mcp_engagement_role_rates WITH (security_barrier = true) AS
SELECT r.engagement_id, r.consulting_role_id, cr.title AS role_title, r.bill_rate, r.updated_at
  FROM engagement_role_rates r JOIN consulting_roles cr ON cr.id = r.consulting_role_id
 WHERE (SELECT ct_has_right('rates.view')) AND r.engagement_id IN (SELECT ct_visible_engagement_ids());

CREATE OR REPLACE VIEW mcp_milestones WITH (security_barrier = true) AS
SELECT ms.id AS milestone_id, ms.engagement_id, ms.name, ms.description, ms.amount, ms.due_date, ms.status, ms.sort_order, ms.delivered_at, ms.accepted_at, ms.accepted_by,
       am.display_name AS accepted_by_name, ms.acceptance_note, ms.cancelled_at, ms.cancel_reason, ms.created_at, ms.updated_at,
       ct_item_billed('milestone', ms.id) AS billed
  FROM milestones ms LEFT JOIN members am ON am.id = ms.accepted_by
 WHERE ms.engagement_id IN (SELECT ct_visible_engagement_ids());

CREATE OR REPLACE VIEW mcp_budget_alerts WITH (security_barrier = true) AS
SELECT b.id AS alert_id, b.engagement_id, e.number AS engagement_number, b.kind, b.threshold_pct, b.value_at, b.budget_at, b.written_at, b.acknowledged_by, b.acknowledged_at
  FROM budget_alerts b JOIN engagements e ON e.id = b.engagement_id
 WHERE b.engagement_id IN (SELECT ct_visible_engagement_ids()) AND ((SELECT ct_has_right('engagements.manage')) OR (SELECT ct_has_right('reports.view')));

-- ---------------------------------------------------------------------------------------------
-- Time: one's own weeks and entries; a manager's reach is the engagements they may see with time.approve; Billing,
-- Finance and admin see all. Bill rates with rates.view; cost snapshots with cost.view on a managed engagement.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_timesheets WITH (security_barrier = true) AS
SELECT t.id AS timesheet_id, t.member_id, m.display_name AS member_name, t.week_start, t.status, t.total_hours, t.billable_hours, t.submitted_at, t.approved_at, t.approved_by,
       t.rejected_at, t.rejected_by, t.rejection_reason, t.created_at, t.updated_at,
       (SELECT count(*) FROM time_entries e WHERE e.timesheet_id = t.id AND e.status = 'submitted') AS submitted_entries
  FROM timesheets t JOIN members m ON m.id = t.member_id
 WHERE t.member_id = app_current_member_id()
    OR (SELECT ct_sees_all())
    OR ((SELECT ct_has_right('time.approve')) AND EXISTS (SELECT 1 FROM time_entries e WHERE e.timesheet_id = t.id AND e.engagement_id IN (SELECT ct_visible_engagement_ids())));

CREATE OR REPLACE VIEW mcp_time_entries WITH (security_barrier = true) AS
SELECT e.id AS entry_id, e.timesheet_id, e.member_id, m.display_name AS member_name, e.engagement_id, g.number AS engagement_number, g.name AS engagement_name,
       e.entry_date, e.hours, e.activity_type_id, a.label AS activity_label, e.billable, e.note,
       CASE WHEN e.member_id = app_current_member_id() OR (SELECT ct_has_right('time.approve')) OR (SELECT ct_sees_all()) THEN e.internal_note END AS internal_note,
       e.status,
       CASE WHEN (SELECT ct_has_right('rates.view')) THEN e.bill_rate END AS bill_rate,
       CASE WHEN (SELECT ct_has_right('rates.view')) THEN e.rate_source END AS rate_source,
       e.consulting_role_id,
       CASE WHEN (SELECT ct_has_right('rates.view')) THEN round(e.hours * e.bill_rate, 2) END AS bill_value,
       CASE WHEN ct_cost_visible(e.engagement_id) THEN e.cost_rate END AS cost_rate,
       e.currency, e.department_id, e.approved_at, e.approved_by, e.written_off_at, e.written_off_by, e.written_off_reason, e.timer_started_at, e.created_at, e.updated_at,
       ct_item_billed('time_entry', e.id) AS billed
  FROM time_entries e JOIN members m ON m.id = e.member_id JOIN engagements g ON g.id = e.engagement_id LEFT JOIN activity_types a ON a.id = e.activity_type_id
 WHERE e.member_id = app_current_member_id()
    OR (SELECT ct_sees_all())
    OR ((SELECT ct_has_right('time.approve')) AND e.engagement_id IN (SELECT ct_visible_engagement_ids()));

CREATE OR REPLACE VIEW mcp_time_timers WITH (security_barrier = true) AS
SELECT t.member_id, t.engagement_id, g.number AS engagement_number, g.name AS engagement_name, t.activity_type_id, t.note, t.started_at,
       round(EXTRACT(EPOCH FROM (now() - t.started_at)) / 3600.0, 2) AS elapsed_hours
  FROM time_timers t JOIN engagements g ON g.id = t.engagement_id
 WHERE t.member_id = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Expenses: one's own; a manager's reach with expenses.approve; Billing, Finance, admin all.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_expenses WITH (security_barrier = true) AS
SELECT x.id AS expense_id, x.number, x.member_id, m.display_name AS member_name, x.engagement_id, g.number AS engagement_number, x.expense_date, x.category_id, c.label AS category_label,
       x.vendor_name, x.description, x.amount, x.tax_amount, x.currency, x.paid_with, x.reimbursable, x.billable, x.markup_pct, x.billable_amount, x.status,
       x.submitted_at, x.approved_at, x.approved_by, x.rejected_at, x.rejected_by, x.rejection_reason, x.reimbursed_at, x.reimbursed_ref, x.department_id, x.created_at, x.updated_at,
       ct_expense_has_receipt(x.id) AS has_receipt, ct_receipt_threshold(x.category_id, x.engagement_id) AS receipt_threshold, ct_item_billed('expense', x.id) AS billed
  FROM expenses x JOIN members m ON m.id = x.member_id JOIN engagements g ON g.id = x.engagement_id JOIN expense_categories c ON c.id = x.category_id
 WHERE x.member_id = app_current_member_id()
    OR (SELECT ct_sees_all())
    OR ((SELECT ct_has_right('expenses.approve')) AND x.engagement_id IN (SELECT ct_visible_engagement_ids()));

-- ---------------------------------------------------------------------------------------------
-- Usage and the OS statement: usage.write on a visible engagement, or sees all. Cost is a cost: cost_amount under cost.view.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_usage_charges WITH (security_barrier = true) AS
SELECT u.id AS usage_charge_id, u.number, u.engagement_id, g.number AS engagement_number, u.period_start, u.period_end, u.kind, u.provider_id, p.name AS provider_name,
       u.description, u.quantity, u.unit, u.pricing, u.markup_pct, u.resale_unit_price, u.billable_amount, u.billable, u.currency, u.source, u.import_id, u.statement_id,
       u.schedule_id, u.source_ref, u.status, u.approved_at, u.approved_by, u.voided_at, u.voided_by, u.void_reason, u.created_at, u.updated_at,
       CASE WHEN ct_cost_visible(u.engagement_id) THEN u.unit_cost END AS unit_cost,
       CASE WHEN ct_cost_visible(u.engagement_id) THEN u.cost_amount END AS cost_amount,
       ct_item_billed('usage_charge', u.id) AS billed
  FROM usage_charges u JOIN engagements g ON g.id = u.engagement_id LEFT JOIN usage_providers p ON p.id = u.provider_id
 WHERE (SELECT ct_sees_all())
    OR (((SELECT ct_has_right('usage.write')) OR (SELECT ct_has_right('engagements.manage'))) AND u.engagement_id IN (SELECT ct_visible_engagement_ids()));

CREATE OR REPLACE VIEW mcp_usage_imports WITH (security_barrier = true) AS
SELECT i.id AS import_id, i.provider_id, p.name AS provider_name, i.file_name, i.mapping, i.row_count, i.inserted, i.skipped, i.detail, i.created_by, i.created_at
  FROM usage_imports i LEFT JOIN usage_providers p ON p.id = i.provider_id WHERE (SELECT ct_has_right('usage.write'));

CREATE OR REPLACE VIEW mcp_allocation_rules WITH (security_barrier = true) AS
SELECT r.id AS rule_id, r.name, r.kernel_agent_id, r.kernel_department_id, r.kernel_application_id, r.engagement_id, g.number AS engagement_number, r.share_pct, r.pricing,
       r.markup_pct, r.resale_unit_price, r.valid_from, r.valid_to, r.active, r.created_by, r.created_at, r.updated_at
  FROM allocation_rules r JOIN engagements g ON g.id = r.engagement_id WHERE (SELECT ct_has_right('usage.write'));

CREATE OR REPLACE VIEW mcp_recurring_charges WITH (security_barrier = true) AS
SELECT r.id AS schedule_id, r.engagement_id, g.number AS engagement_number, r.kind, r.provider_id, p.name AS provider_name, r.description, r.quantity, r.unit,
       CASE WHEN ct_cost_visible(r.engagement_id) THEN r.unit_cost END AS unit_cost,
       r.pricing, r.markup_pct, r.resale_unit_price, r.cadence, r.every_n, r.next_date, r.end_date, r.last_run_at, r.run_count, r.active, r.created_at
  FROM recurring_charges r JOIN engagements g ON g.id = r.engagement_id LEFT JOIN usage_providers p ON p.id = r.provider_id
 WHERE (SELECT ct_has_right('usage.write')) AND r.engagement_id IN (SELECT ct_visible_engagement_ids());

CREATE OR REPLACE VIEW mcp_ai_statements WITH (security_barrier = true) AS
SELECT s.id AS ai_statement_id, s.period, s.period_start, s.period_end, s.kernel_status, s.document_hash, s.pulled_at, s.status, s.allocated_at, s.allocated_by, s.late_calls, s.created_at,
       (SELECT round(sum(amount), 2) FROM ai_statement_lines l WHERE l.statement_id = s.id) AS statement_amount,
       (SELECT sum(allocated_amount) FROM ai_statement_lines l WHERE l.statement_id = s.id) AS allocated_amount
  FROM ai_statements s WHERE (SELECT ct_has_right('usage.write'));

CREATE OR REPLACE VIEW mcp_ai_statement_lines WITH (security_barrier = true) AS
SELECT l.id AS line_id, l.statement_id, l.line_no, l.provider, l.model_id, l.model, l.kernel_department_id, l.department, l.kernel_agent_id, l.agent, l.kernel_application_id,
       l.application, l.calls, l.late_calls, l.input_tokens, l.output_tokens, l.cache_read_tokens, l.cache_write_tokens, l.amount, l.currency, l.note, l.allocated_amount,
       round(l.amount, 2) - l.allocated_amount AS remainder
  FROM ai_statement_lines l WHERE (SELECT ct_has_right('usage.write'));

CREATE OR REPLACE VIEW mcp_ai_statement_allocations WITH (security_barrier = true) AS
SELECT a.id AS allocation_id, a.statement_id, a.line_id, a.rule_id, a.usage_charge_id, a.amount, a.created_at
  FROM ai_statement_allocations a WHERE (SELECT ct_has_right('usage.write'));

-- ---------------------------------------------------------------------------------------------
-- Billing: invoices.draft on a visible engagement, or billing.write / sees all. A Consultant sees no invoice.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_invoices WITH (security_barrier = true) AS
SELECT i.id AS invoice_id, i.number, i.client_id, c.name AS client_name, i.engagement_id, g.number AS engagement_number, g.name AS engagement_name, i.period_start, i.period_end,
       i.issue_date, i.due_date, i.currency, i.status, i.subtotal, i.tax_total, i.total, i.amount_paid, i.amount_credited, i.balance_due, i.po_number, i.memo, i.terms_text,
       i.internal_note, i.external_ref, i.department_id, i.nte_override_reason, i.issued_at, i.issued_by, i.sent_at, i.viewed_at, i.paid_at, i.voided_at, i.voided_by, i.void_reason,
       i.written_off_at, i.written_off_by, i.write_off_reason, i.last_reminder_at, i.reminder_count, i.created_by, i.created_at, i.updated_at,
       (i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.due_date < current_date) AS overdue,
       (SELECT count(*) FROM payment_claims k WHERE k.invoice_id = i.id AND k.status = 'submitted') AS open_claims
  FROM invoices i JOIN clients c ON c.id = i.client_id JOIN engagements g ON g.id = i.engagement_id
 WHERE (SELECT ct_sees_all())
    OR ((SELECT ct_has_right('invoices.draft')) AND i.engagement_id IN (SELECT ct_visible_engagement_ids()));

CREATE OR REPLACE VIEW mcp_invoice_lines WITH (security_barrier = true) AS
SELECT l.id AS line_id, l.invoice_id, l.sort_order, l.kind, l.description, l.quantity, l.unit, l.unit_price, l.tax_rate_id, l.tax_rate, l.line_subtotal, l.line_tax, l.line_total, l.group_key,
       (SELECT count(*) FROM invoice_line_items x WHERE x.line_id = l.id) AS item_count
  FROM invoice_lines l WHERE l.invoice_id IN (SELECT invoice_id FROM mcp_invoices);

CREATE OR REPLACE VIEW mcp_invoice_line_items WITH (security_barrier = true) AS
SELECT x.id AS item_id, x.invoice_id, x.line_id, x.time_entry_id, x.expense_id, x.usage_charge_id, x.milestone_id, x.amount, x.created_at
  FROM invoice_line_items x WHERE x.invoice_id IN (SELECT invoice_id FROM mcp_invoices);

CREATE OR REPLACE VIEW mcp_credit_notes WITH (security_barrier = true) AS
SELECT n.id AS credit_note_id, n.number, n.client_id, c.name AS client_name, n.engagement_id, n.invoice_id, i.number AS invoice_number, n.issue_date, n.currency, n.status,
       n.subtotal, n.tax_total, n.total, n.amount_applied, n.balance, n.reason, n.external_ref, n.issued_at, n.issued_by, n.voided_at, n.voided_by, n.void_reason, n.created_by, n.created_at
  FROM credit_notes n JOIN clients c ON c.id = n.client_id LEFT JOIN invoices i ON i.id = n.invoice_id
 WHERE (SELECT ct_sees_all()) OR ((SELECT ct_has_right('invoices.draft')) AND n.engagement_id IN (SELECT ct_visible_engagement_ids()));

CREATE OR REPLACE VIEW mcp_credit_note_lines WITH (security_barrier = true) AS
SELECT l.id AS line_id, l.credit_note_id, l.sort_order, l.kind, l.description, l.quantity, l.unit_price, l.tax_rate, l.line_subtotal, l.line_tax, l.line_total
  FROM credit_note_lines l WHERE l.credit_note_id IN (SELECT credit_note_id FROM mcp_credit_notes);

CREATE OR REPLACE VIEW mcp_credit_applications WITH (security_barrier = true) AS
SELECT a.credit_note_id, a.invoice_id, a.amount, a.applied_by, a.applied_at FROM credit_applications a WHERE a.invoice_id IN (SELECT invoice_id FROM mcp_invoices);

CREATE OR REPLACE VIEW mcp_invoice_payments WITH (security_barrier = true) AS
SELECT p.id AS payment_id, p.invoice_id, i.number AS invoice_number, p.paid_on, p.amount, p.currency, p.method, p.reference, p.source, p.status, p.recorded_by, p.voided_at, p.voided_by,
       p.void_reason, p.created_at
  FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.invoice_id IN (SELECT invoice_id FROM mcp_invoices);

CREATE OR REPLACE VIEW mcp_payment_claims WITH (security_barrier = true) AS
SELECT k.id AS claim_id, k.invoice_id, i.number AS invoice_number, k.claimed_on, k.method, k.reference, k.amount, k.note, k.status, k.payment_id, k.decided_by, k.decided_at, k.created_at
  FROM payment_claims k JOIN invoices i ON i.id = k.invoice_id WHERE k.invoice_id IN (SELECT invoice_id FROM mcp_invoices);

-- ---------------------------------------------------------------------------------------------
-- Attachments and notes follow their record; notifications and tokens are one's own; dispatches to reports.view.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.client_visible, a.uploaded_by, m.display_name AS uploaded_by_name, a.created_at
  FROM attachments a LEFT JOIN members m ON m.id = a.uploaded_by
 WHERE (a.record_type = 'client' AND a.record_id IN (SELECT client_id FROM mcp_clients))
    OR (a.record_type = 'engagement' AND a.record_id IN (SELECT engagement_id FROM mcp_engagements))
    OR (a.record_type = 'milestone' AND a.record_id IN (SELECT milestone_id FROM mcp_milestones))
    OR (a.record_type = 'expense' AND a.record_id IN (SELECT expense_id FROM mcp_expenses))
    OR (a.record_type = 'usage_charge' AND a.record_id IN (SELECT usage_charge_id FROM mcp_usage_charges))
    OR (a.record_type = 'usage_import' AND (SELECT ct_has_right('usage.write')))
    OR (a.record_type = 'invoice' AND a.record_id IN (SELECT invoice_id FROM mcp_invoices))
    OR (a.record_type = 'credit_note' AND a.record_id IN (SELECT credit_note_id FROM mcp_credit_notes));

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, m.display_name AS member_name, m.member_kind, n.body, n.created_at
  FROM notes n LEFT JOIN members m ON m.id = n.member_id
 WHERE (n.record_type = 'client' AND n.record_id IN (SELECT client_id FROM mcp_clients))
    OR (n.record_type = 'engagement' AND n.record_id IN (SELECT engagement_id FROM mcp_engagements))
    OR (n.record_type = 'milestone' AND n.record_id IN (SELECT milestone_id FROM mcp_milestones))
    OR (n.record_type = 'expense' AND n.record_id IN (SELECT expense_id FROM mcp_expenses))
    OR (n.record_type = 'usage_charge' AND n.record_id IN (SELECT usage_charge_id FROM mcp_usage_charges))
    OR (n.record_type = 'invoice' AND n.record_id IN (SELECT invoice_id FROM mcp_invoices))
    OR (n.record_type = 'timesheet' AND n.record_id IN (SELECT timesheet_id FROM mcp_timesheets));

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS agent_dispatch_id, d.record_type, d.record_id, d.agent_member_id, m.display_name AS agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.request_id,
       d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at
  FROM agent_dispatches d JOIN members m ON m.id = d.agent_member_id
 WHERE d.agent_member_id = app_current_member_id() OR (SELECT ct_has_right('reports.view'));

CREATE OR REPLACE VIEW mcp_my_tokens WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id();

-- Activity: one's own rows always; everything to Billing/Finance/admin; a visible engagement's rows to reports.view
-- or time.approve; a cost rate never appears in a payload (the writers' rule).
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS activity_id, l.occurred_at, l.actor_member_id, am.display_name AS actor_name, am.member_kind AS actor_kind, l.actor_client_id, l.source, l.action,
       l.entity_type, l.entity_id, l.engagement_id, l.client_id, l.invoice_id, l.timesheet_id, l.department_id, l.before, l.after, l.agent_run_id, l.request_id
  FROM activity_log l
  LEFT JOIN members am ON am.id = l.actor_member_id
 WHERE l.actor_member_id = app_current_member_id()
    OR (SELECT ct_sees_all())
    OR (((SELECT ct_has_right('reports.view')) OR (SELECT ct_has_right('time.approve'))) AND l.engagement_id IN (SELECT ct_visible_engagement_ids()))
    OR l.timesheet_id IN (SELECT t.id FROM timesheets t WHERE t.member_id = app_current_member_id());

GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_consulting_roles, mcp_activity_types, mcp_expense_categories, mcp_usage_providers, mcp_tax_rates,
    mcp_rate_cards, mcp_rate_card_rates, mcp_member_cost_rates, mcp_member_capacity, mcp_clients, mcp_client_contacts, mcp_engagements, mcp_engagement_members,
    mcp_engagement_role_rates, mcp_milestones, mcp_budget_alerts, mcp_timesheets, mcp_time_entries, mcp_time_timers, mcp_expenses, mcp_usage_charges, mcp_usage_imports,
    mcp_allocation_rules, mcp_recurring_charges, mcp_ai_statements, mcp_ai_statement_lines, mcp_ai_statement_allocations, mcp_invoices, mcp_invoice_lines,
    mcp_invoice_line_items, mcp_credit_notes, mcp_credit_note_lines, mcp_credit_applications, mcp_invoice_payments, mcp_payment_claims, mcp_attachments, mcp_notes,
    mcp_notifications, mcp_agent_dispatches, mcp_my_tokens
    TO consultant_tracking_records_ro, consultant_tracking_rw;
GRANT SELECT ON mcp_activity_log TO consultant_tracking_activity_ro, consultant_tracking_rw;
GRANT SELECT ON mcp_members, mcp_departments, mcp_engagements, mcp_clients, mcp_invoices, mcp_timesheets TO consultant_tracking_activity_ro;   -- names and record facts beside an activity row

COMMIT;
