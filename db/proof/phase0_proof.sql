-- Phase 0 proof of the schema (docs/consultant-tracking-design.md §10). Everything happens inside one transaction that is
-- ROLLED BACK: fixtures written as consultant_tracking_rw, reads as the two read roles, acting members set through
-- app.member_id exactly as PHP and the MCP servers set it. Run as postgres on the SCRATCH database:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d ct_dev -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '1', true);
INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user', 'active', 'write', '{user}'),             -- a Consultant (AI Strategy)
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user', 'active', 'write', '{manager,user}'),     -- the Manager of both engagements
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user', 'active', 'write', '{billing,user}'),     -- Billing
 (29, 'human', 'SMOKE Ann',     'ann@cpa.invalid',       'user', 'active', 'read',  '{finance,user}'),     -- Finance, external
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',  'user', 'active', 'write', '{user}'),             -- a Consultant (ML Engineering)
 (31, 'human', 'SMOKE Lee',     'lee@example.invalid',   'user', 'active', 'write', '{user}'),             -- a Consultant staffed nowhere
 (40, 'agent', 'SMOKE Clerk',   NULL,                    'user', 'active', 'write', '{billing,user}'),     -- the Billing Clerk
 (41, 'agent', 'SMOKE Watcher', NULL,                    'user', 'active', NULL,    '{}');                 -- an agent not admitted yet
UPDATE members SET is_external = true WHERE id = 29;
INSERT INTO departments (id, name, is_system, system_key) VALUES (3, 'Accounting', true, 'accounting'), (7, 'AI Strategy', false, NULL), (8, 'ML Engineering', false, NULL);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES (26, 7, false, true), (27, 7, false, true), (30, 8, false, true), (31, 8, false, true), (28, 3, false, true);
UPDATE ct_settings SET firm_name = 'SMOKE Consulting', morning_note_member_id = 28;
INSERT INTO rate_cards (name, currency, effective_from, created_by) VALUES ('SMOKE Standard 2026', 'USD', '2026-01-01', 28);
SELECT id AS card FROM rate_cards WHERE name = 'SMOKE Standard 2026' \gset
INSERT INTO rate_card_rates (rate_card_id, consulting_role_id, bill_rate)
SELECT :card, id, CASE key WHEN 'principal' THEN 300 WHEN 'senior_consultant' THEN 250 WHEN 'consultant' THEN 180 WHEN 'analyst' THEN 120 ELSE 160 END FROM consulting_roles;
INSERT INTO member_cost_rates (member_id, hourly_cost, valid_from, created_by) VALUES (26, 70, '2026-01-01', 1), (27, 120, '2026-01-01', 1), (30, 55, '2026-01-01', 1);
INSERT INTO clients (name, legal_name, email, terms_days, po_required, default_rate_card_id, created_by) VALUES
 ('SMOKE Acme', 'Acme Corp', 'ap@acme.invalid', 30, true, :card, 28), ('SMOKE Beta', 'Beta LLC', 'ap@beta.invalid', 15, false, :card, 28);
SELECT id AS acme FROM clients WHERE name = 'SMOKE Acme' \gset
SELECT id AS beta FROM clients WHERE name = 'SMOKE Beta' \gset
INSERT INTO client_contacts (client_id, name, email, role, receives_invoices, is_primary) VALUES (:acme, 'Jo Payer', 'jo@acme.invalid', 'Accounts payable', true, true);
INSERT INTO engagements (client_id, name, kind, status, start_date, currency, po_number, nte_amount, budget_hours, ai_budget_amount, expense_policy, billing_cadence, department_id, manager_member_id, created_by)
VALUES (:acme, 'SMOKE Acme pilot', 'time_and_materials', 'active', '2026-03-02', 'USD', 'PO-4411', 20000, 100, 500, '{"markup_pct": 10}', 'monthly', 7, 27, 27);
SELECT id AS eng FROM engagements WHERE name = 'SMOKE Acme pilot' \gset
INSERT INTO engagements (client_id, name, kind, status, start_date, currency, budget_amount, billing_cadence, department_id, manager_member_id, created_by)
VALUES (:beta, 'SMOKE Beta roadmap', 'fixed_bid', 'active', '2026-03-02', 'USD', 30000, 'on_milestone', 7, 27, 27);
SELECT id AS fb FROM engagements WHERE name = 'SMOKE Beta roadmap' \gset
INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from, created_by)
SELECT :eng, 26, id, '2026-03-02', 27 FROM consulting_roles WHERE key = 'consultant';
INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from, created_by)
SELECT :eng, 27, id, '2026-03-02', 27 FROM consulting_roles WHERE key = 'principal';
INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from, created_by)
SELECT :eng, 30, id, '2026-03-02', 27 FROM consulting_roles WHERE key = 'analyst';
INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from, created_by)
SELECT :fb, 26, id, '2026-03-02', 27 FROM consulting_roles WHERE key = 'consultant';
INSERT INTO milestones (engagement_id, name, amount, due_date, sort_order, created_by) VALUES (:fb, 'SMOKE M1 discovery', 10000, '2026-04-30', 1, 27), (:fb, 'SMOKE M2 roadmap', 20000, '2026-06-30', 2, 27);
SELECT id AS m1 FROM milestones WHERE name = 'SMOKE M1 discovery' \gset
SELECT id AS m2 FROM milestones WHERE name = 'SMOKE M2 roadmap' \gset
SELECT id AS r_consultant FROM consulting_roles WHERE key = 'consultant' \gset
SELECT id AS r_analyst FROM consulting_roles WHERE key = 'analyst' \gset
SELECT id AS a_build FROM activity_types WHERE key = 'build' \gset
SELECT id AS a_travel FROM activity_types WHERE key = 'travel' \gset
SELECT id AS c_meals FROM expense_categories WHERE key = 'meals' \gset
SELECT id AS c_hosting FROM expense_categories WHERE key = 'hosting' \gset
SELECT id AS p_anthropic FROM usage_providers WHERE provider_key = 'anthropic' \gset
SELECT id AS p_aws FROM usage_providers WHERE provider_key = 'aws' \gset
RESET ROLE;

-- ---------------------------------------------------------------- 1. the catalogue, the seeds, the numbers
SELECT pg_temp.check((SELECT count(*) FROM ct_roles) = 5 AND (SELECT count(*) FROM ct_rights) = 21 AND (SELECT count(*) FROM ct_roles WHERE is_admin) = 1, 'five roles, twenty-one rights, one admin role');
SELECT pg_temp.check((SELECT role_key FROM ct_roles ORDER BY sort_order LIMIT 1) = 'user', 'the base role is keyed user (the installer''s standing grant, D3)');
SELECT pg_temp.check((SELECT count(*) FROM ct_role_rights WHERE role_key = 'admin') = 21 AND (SELECT count(*) FROM ct_role_rights WHERE role_key = 'finance') = 6, 'admin gives every right; Finance reads, costs included, writes nothing');
SELECT pg_temp.check((SELECT count(*) FROM consulting_roles) = 5 AND (SELECT count(*) FROM activity_types) = 8 AND (SELECT count(*) FROM expense_categories) = 8 AND (SELECT count(*) FROM usage_providers) = 8 AND (SELECT count(*) FROM tax_rates) = 1,
                     'seeded: five consulting roles, eight activity types, eight categories, eight providers, one tax rate');
SELECT pg_temp.check((SELECT number FROM engagements WHERE id = :eng) = 'ENG-0001' AND (SELECT number FROM engagements WHERE id = :fb) = 'ENG-0002', 'engagements are numbered ENG-0001 onward at creation (D13)');
SELECT pg_temp.check(ct_week_start('2026-10-05') = '2026-10-05' AND ct_week_start('2026-10-11') = '2026-10-05' AND ct_week_start('2026-10-04') = '2026-09-28', 'the week starts Monday (D6)');
SELECT pg_temp.check((SELECT next_billing_date FROM engagements WHERE id = :eng) = '2026-04-01', 'a monthly engagement started March 2 bills first on April 1');

-- ---------------------------------------------------------------- 2. the engagement's rules
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.refused($$INSERT INTO engagements (client_id, name, kind, currency, created_by) VALUES ($$ || :acme || $$, 'SMOKE retainer', 'retainer', 'USD', 27)$$, 'not in this version', 'a retainer is admitted by the schema and refused by the guard (D5)');
SELECT pg_temp.refused($$INSERT INTO engagements (client_id, name, kind, currency, nte_amount, created_by) VALUES ($$ || :acme || $$, 'SMOKE fb nte', 'fixed_bid', 'USD', 100, 27)$$, 'check constraint', 'a fixed bid carries no not-to-exceed');
SELECT pg_temp.refused($$UPDATE engagements SET status = 'closed' WHERE id = $$ || :eng || $$ AND false; INSERT INTO engagements (client_id, name, kind, status, currency, created_by) VALUES ($$ || :acme || $$, 'SMOKE born closed', 'time_and_materials', 'closed', 'USD', 27)$$, 'born a draft or active', 'an engagement is born a draft or active');
INSERT INTO engagements (client_id, name, kind, currency, created_by) VALUES (:acme, 'SMOKE draft eng', 'time_and_materials', 'USD', 27);
SELECT id AS dr FROM engagements WHERE name = 'SMOKE draft eng' \gset
SELECT pg_temp.refused($$UPDATE engagements SET status = 'closed' WHERE id = $$ || :dr, 'becomes active or is cancelled', 'a draft does not close; it activates or is cancelled');
UPDATE engagements SET status = 'active' WHERE id = :dr;
SELECT pg_temp.check((SELECT activated_at IS NOT NULL FROM engagements WHERE id = :dr), 'activating stamps activated_at');
UPDATE engagements SET status = 'closed' WHERE id = :dr;
SELECT pg_temp.refused($$DELETE FROM engagements WHERE id = $$ || :dr, 'never deleted', 'an engagement that was active is never deleted');
SELECT pg_temp.refused($$UPDATE engagements SET number = 'ENG-9999' WHERE id = $$ || :eng, 'keeps its number', 'the number never changes');
SELECT pg_temp.refused($$INSERT INTO engagement_members (engagement_id, member_id, consulting_role_id, valid_from) VALUES ($$ || :eng || $$, 26, $$ || :r_analyst || $$, '2026-05-01')$$, 'conflicting key', 'one person is staffed once at a time on an engagement (EXCLUDE)');
SELECT pg_temp.refused($$INSERT INTO member_cost_rates (member_id, hourly_cost, valid_from) VALUES (26, 80, '2026-06-01')$$, 'conflicting key', 'cost rates never overlap for one person');
-- the rate resolution (D6)
SELECT pg_temp.check((SELECT bill_rate = 180 AND rate_source = 'rate_card' FROM ct_resolve_rate(:eng, 26, '2026-03-02')), 'Priya''s rate on Acme comes from the client''s default card: 180 (rate_card)');
INSERT INTO engagement_role_rates (engagement_id, consulting_role_id, bill_rate) VALUES (:eng, :r_consultant, 200);
SELECT pg_temp.check((SELECT bill_rate = 200 AND rate_source = 'engagement_role' FROM ct_resolve_rate(:eng, 26, '2026-03-02')), 'an engagement role rate overrides the card: 200 (engagement_role)');
UPDATE engagement_members SET bill_rate_override = 210 WHERE engagement_id = :eng AND member_id = 26;
SELECT pg_temp.check((SELECT bill_rate = 210 AND rate_source = 'member_override' FROM ct_resolve_rate(:eng, 26, '2026-03-02')), 'a person''s override on the engagement wins: 210 (member_override)');
UPDATE engagement_members SET bill_rate_override = NULL WHERE engagement_id = :eng AND member_id = 26;
DELETE FROM engagement_role_rates WHERE engagement_id = :eng;
SELECT pg_temp.check((SELECT rate_source FROM ct_resolve_rate(:eng, 31, '2026-03-02')) = 'none', 'a person never staffed has no rate (none)');
SELECT pg_temp.check((SELECT bill_rate = 300 FROM ct_resolve_rate(:eng, 27, '2026-03-02')), 'Marco the principal prices at 300');
RESET ROLE;

-- ---------------------------------------------------------------- 3. time — the exemplar's rules
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.refused($$INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, currency, timesheet_id) VALUES (31, $$ || :eng || $$, '2026-03-02', 2, $$ || :a_build || $$, 'USD', 0)$$, 'not staffed', 'a person not staffed on the engagement logs no time on it');
SELECT pg_temp.refused($$INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, currency, timesheet_id) VALUES (26, $$ || :eng || $$, '2026-03-02', 0.3, $$ || :a_build || $$, 'USD', 0)$$, 'steps of', 'hours come in the settings'' increment (0.3 refused)');
SELECT pg_temp.refused($$INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, currency, timesheet_id) VALUES (26, $$ || :eng || $$, '2026-02-27', 2, $$ || :a_build || $$, 'USD', 0)$$, 'not staffed', 'no time before the engagement started');
INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, billable, note, internal_note, currency, timesheet_id) VALUES
 (26, :eng, '2026-03-02', 4.00, :a_build, true, 'Discovery workshop with the Acme team', 'they were late', 'XXX', 0),
 (26, :eng, '2026-03-03', 3.50, :a_build, true, 'Data pipeline review', NULL, 'XXX', 0),
 (26, :eng, '2026-03-04', 2.00, :a_travel, false, 'Travel to Acme', NULL, 'XXX', 0);
SELECT id AS ts1 FROM timesheets WHERE member_id = 26 AND week_start = '2026-03-02' \gset
SELECT pg_temp.check(:ts1 IS NOT NULL AND (SELECT status = 'open' AND total_hours = 9.5 AND billable_hours = 7.5 FROM timesheets WHERE id = :ts1), 'the week is made open on the first entry and keeps its totals (9.5 h, 7.5 billable)');
SELECT pg_temp.check((SELECT bool_and(currency = 'USD' AND department_id = 7 AND status = 'draft') FROM time_entries WHERE timesheet_id = :ts1), 'an entry takes the engagement''s currency, the person''s practice, and is born a draft');
SELECT pg_temp.refused($$INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, currency, timesheet_id) VALUES (26, $$ || :eng || $$, '2026-03-02', 13, $$ || :a_build || $$, 'USD', 0)$$, 'cap is', 'the day''s cap refuses 17 hours');
SELECT pg_temp.refused($$UPDATE time_entries SET status = 'approved' WHERE timesheet_id = $$ || :ts1, 'never by hand', 'a status is never set by hand');
SELECT pg_temp.refused($$UPDATE timesheets SET status = 'approved' WHERE id = $$ || :ts1, 'never by hand', 'a week''s status is never set by hand');
SELECT pg_temp.refused($$SELECT ct_timesheet_approve($$ || :ts1 || $$, 27)$$, 'not submitted', 'an open week is not approved');
SELECT ct_timesheet_submit(:ts1, 26);
SELECT pg_temp.check((SELECT status = 'submitted' AND submitted_at IS NOT NULL FROM timesheets WHERE id = :ts1) AND (SELECT bool_and(status = 'submitted') FROM time_entries WHERE timesheet_id = :ts1), 'Priya submits her week; every entry follows');
SELECT pg_temp.refused($$UPDATE time_entries SET hours = 5 WHERE timesheet_id = $$ || :ts1 || $$ AND entry_date = '2026-03-02'$$, 'reopen it', 'a submitted entry is not edited');
SELECT pg_temp.refused($$INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, currency, timesheet_id) VALUES (26, $$ || :eng || $$, '2026-03-05', 1, $$ || :a_build || $$, 'USD', 0)$$, 'reopen it', 'no entry joins a submitted week');
SELECT pg_temp.refused($$SELECT ct_timesheet_approve($$ || :ts1 || $$, 26)$$, 'own time', 'nobody approves their own time');
SELECT pg_temp.refused($$SELECT ct_timesheet_reject($$ || :ts1 || $$, 26, 'x')$$, 'Reopen your own', 'one reopens, not rejects, one''s own week');
-- Dana (no time.approve) cannot approve
SELECT set_config('app.member_id', '30', true);
SELECT pg_temp.refused($$SELECT ct_timesheet_approve($$ || :ts1 || $$, 30)$$, 'Manager role', 'a Consultant cannot approve');
-- Marco approves: the snapshots
SELECT set_config('app.member_id', '27', true);
SELECT * FROM ct_timesheet_approve(:ts1, 27) \gset ap_
SELECT pg_temp.check(:ap_approved = 3 AND :ap_left_submitted = 0 AND :'ap_week_status' = 'approved', 'Marco approves the three entries and the week');
SELECT pg_temp.check((SELECT bill_rate = 180 AND rate_source = 'rate_card' AND cost_rate = 70 AND consulting_role_id = :r_consultant AND approved_by = 27 FROM time_entries WHERE timesheet_id = :ts1 AND entry_date = '2026-03-02'),
                     'approval snapshots the bill rate (180, rate_card), the role and the cost rate (70) onto the entry (D6)');
SELECT pg_temp.check((SELECT bill_rate IS NULL AND rate_source = 'none' AND cost_rate = 70 FROM time_entries WHERE timesheet_id = :ts1 AND entry_date = '2026-03-04'), 'a non-billable entry carries no bill rate but still a cost');
SELECT pg_temp.refused($$UPDATE time_entries SET hours = 1 WHERE timesheet_id = $$ || :ts1 || $$ AND entry_date = '2026-03-02'$$, 'locked', 'an approved entry is locked');
SELECT pg_temp.refused($$DELETE FROM time_entries WHERE timesheet_id = $$ || :ts1 || $$ AND entry_date = '2026-03-02'$$, 'never deleted', 'an approved entry is never deleted');
SELECT pg_temp.refused($$SELECT ct_timesheet_approve($$ || :ts1 || $$, 27)$$, 'not submitted', 'approving twice is refused');
-- Dana's week: submit, reject with a reason, fix, resubmit, approve; partial approval when another manager's engagement is on the week
SELECT set_config('app.member_id', '30', true);
INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, billable, note, currency, timesheet_id) VALUES
 (30, :eng, '2026-03-09', 8.00, :a_build, true, 'Labelling pipeline', 'XXX', 0), (30, :eng, '2026-03-10', 6.00, :a_build, true, 'Model evaluation', 'XXX', 0);
SELECT id AS ts2 FROM timesheets WHERE member_id = 30 AND week_start = '2026-03-09' \gset
SELECT ct_timesheet_submit(:ts2, 30);
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.refused($$SELECT ct_timesheet_reject($$ || :ts2 || $$, 27, '')$$, 'needs a reason', 'a rejection needs a reason');
SELECT ct_timesheet_reject(:ts2, 27, 'Tuesday looks like Monday''s hours again — please check');
SELECT pg_temp.check((SELECT status = 'rejected' AND rejected_by = 27 AND rejection_reason LIKE 'Tuesday%' FROM timesheets WHERE id = :ts2) AND (SELECT bool_and(status = 'rejected') FROM time_entries WHERE timesheet_id = :ts2), 'a rejected week carries who and why; its entries are rejected (editable)');
SELECT set_config('app.member_id', '30', true);
UPDATE time_entries SET hours = 4 WHERE timesheet_id = :ts2 AND entry_date = '2026-03-10';
INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, billable, note, currency, timesheet_id) VALUES (30, :eng, '2026-03-11', 2.00, :a_build, true, 'Fixes', 'XXX', 0);
SELECT pg_temp.check((SELECT status FROM time_entries WHERE timesheet_id = :ts2 AND entry_date = '2026-03-11') = 'rejected' AND (SELECT total_hours FROM timesheets WHERE id = :ts2) = 14, 'Dana edits and adds in her rejected week; the totals follow (14 h)');
SELECT ct_timesheet_submit(:ts2, 30);
SELECT pg_temp.check((SELECT status = 'submitted' AND rejection_reason IS NULL FROM timesheets WHERE id = :ts2), 'resubmitting clears the rejection');
-- reopen one's own submitted week
SELECT ct_timesheet_reopen(:ts2, 30);
SELECT pg_temp.check((SELECT status FROM timesheets WHERE id = :ts2) = 'open' AND (SELECT bool_and(status = 'draft') FROM time_entries WHERE timesheet_id = :ts2), 'Dana reopens her submitted week before anyone approved it');
SELECT ct_timesheet_submit(:ts2, 30);
SELECT set_config('app.member_id', '27', true);
SELECT * FROM ct_timesheet_approve(:ts2, 27) \gset ap2_
SELECT pg_temp.check(:ap2_approved = 3 AND :'ap2_week_status' = 'approved' AND (SELECT bill_rate = 120 AND cost_rate = 55 FROM time_entries WHERE timesheet_id = :ts2 AND entry_date = '2026-03-09'), 'Dana''s week approved at the analyst''s 120, cost 55');
SELECT pg_temp.refused($$SELECT ct_timesheet_reopen($$ || :ts2 || $$, 30)$$, 'Only a submitted week', 'an approved week is not reopened');
-- write-off
SELECT id AS e_wo FROM time_entries WHERE timesheet_id = :ts2 AND entry_date = '2026-03-11' \gset
SELECT pg_temp.refused($$SELECT ct_time_entry_write_off($$ || :e_wo || $$, 27, '')$$, 'needs a reason', 'a write-off needs a reason');
SELECT ct_time_entry_write_off(:e_wo, 27, 'Rework we should not bill');
SELECT pg_temp.check((SELECT status = 'written_off' AND written_off_by = 27 FROM time_entries WHERE id = :e_wo), 'an approved entry is written off with a reason (T6)');
-- the timer
SELECT set_config('app.member_id', '26', true);
SELECT ct_timer_start(26, :eng, :a_build, 'SMOKE timer');
SELECT pg_temp.check((SELECT count(*) FROM time_timers WHERE member_id = 26) = 1, 'a timer runs for Priya');
SELECT (ct_timer_stop(26)).id AS e_timer \gset
SELECT pg_temp.check((SELECT hours = 0.25 AND status = 'draft' AND timer_started_at IS NOT NULL FROM time_entries WHERE id = :e_timer) AND NOT EXISTS (SELECT 1 FROM time_timers WHERE member_id = 26), 'stopping the timer writes a draft entry rounded up to one increment (0.25 h)');
DELETE FROM time_entries WHERE id = :e_timer;
RESET ROLE;

-- ---------------------------------------------------------------- 4. expenses and the receipt rule (D7)
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '26', true);
INSERT INTO expenses (member_id, engagement_id, expense_date, category_id, vendor_name, description, amount, tax_amount, currency, paid_with, created_by)
VALUES (26, :eng, '2026-03-03', :c_meals, 'Bistro', 'Dinner with the Acme team', 120.00, 9.00, 'XXX', 'personal', 26);
SELECT id AS x1 FROM expenses WHERE description = 'Dinner with the Acme team' \gset
SELECT pg_temp.check((SELECT number ~ '^EXP-[0-9]{6}$' AND currency = 'USD' AND markup_pct = 10 AND billable_amount = 132.00 AND reimbursable AND department_id = 7 FROM expenses WHERE id = :x1),
                     'an expense is numbered, takes the engagement''s currency and its 10 % policy markup (120 → 132.00), is reimbursable when paid personally');
SELECT pg_temp.refused($$SELECT ct_expense_submit($$ || :x1 || $$, 26)$$, 'receipt is required', 'a 120.00 expense does not submit without a receipt (threshold 75)');
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('expense', :x1, 'receipt.jpg', 'image/jpeg', 12345, repeat('a', 64), 'attachments/expense/1/x', 26);
SELECT ct_expense_submit(:x1, 26);
SELECT pg_temp.check((SELECT status = 'submitted' FROM expenses WHERE id = :x1), 'with the receipt attached it submits');
INSERT INTO expenses (member_id, engagement_id, expense_date, category_id, description, amount, currency, paid_with, created_by) VALUES (26, :eng, '2026-03-03', :c_meals, 'Coffee', 40.00, 'XXX', 'company_card', 26);
SELECT id AS x2 FROM expenses WHERE description = 'Coffee' \gset
SELECT ct_expense_submit(:x2, 26);
SELECT pg_temp.check((SELECT status = 'submitted' AND NOT reimbursable FROM expenses WHERE id = :x2), 'a 40.00 company-card expense submits without a receipt and is not reimbursable');
SELECT pg_temp.refused($$UPDATE expenses SET amount = 10 WHERE id = $$ || :x1, 'ask for it back', 'a submitted expense''s money is not edited');
SELECT pg_temp.refused($$SELECT ct_expense_approve($$ || :x1 || $$, 26)$$, 'own expense', 'nobody approves their own expense');
SELECT set_config('app.member_id', '27', true);
SELECT ct_expense_approve(:x1, 27); SELECT ct_expense_approve(:x2, 27);
SELECT pg_temp.check((SELECT bool_and(status = 'approved' AND approved_by = 27) FROM expenses WHERE id IN (:x1, :x2)), 'Marco approves both');
SELECT pg_temp.refused($$UPDATE expenses SET amount = 10 WHERE id = $$ || :x1, 'locked', 'an approved expense is locked');
SELECT pg_temp.refused($$UPDATE expenses SET reimbursed_at = now() WHERE id = $$ || :x1, 'through its function', 'reimbursement is recorded through its function');
SELECT pg_temp.refused($$SELECT ct_expense_mark_reimbursed($$ || :x2 || $$, 28, 'PAY-1')$$, 'company card', 'a company-card expense is not reimbursed');
SELECT set_config('app.member_id', '28', true);
SELECT ct_expense_mark_reimbursed(:x1, 28, 'PAYRUN-2026-03');
SELECT pg_temp.check((SELECT reimbursed_at IS NOT NULL AND reimbursed_ref = 'PAYRUN-2026-03' FROM expenses WHERE id = :x1), 'a personal expense is marked reimbursed with the pay run''s reference (H2)');
RESET ROLE;

-- ---------------------------------------------------------------- 5. usage and hosting (D8)
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '27', true);
INSERT INTO usage_charges (engagement_id, period_start, period_end, kind, provider_id, description, quantity, unit, unit_cost, pricing, markup_pct, currency, created_by)
VALUES (:eng, '2026-03-01', '2026-03-31', 'hosting', :p_aws, 'Pilot VM, March', 1, 'months', 180, 'markup', 20, 'XXX', 27);
SELECT id AS u1 FROM usage_charges WHERE description = 'Pilot VM, March' \gset
SELECT pg_temp.check((SELECT number ~ '^USG-[0-9]{6}$' AND cost_amount = 180.00 AND billable_amount = 216.00 AND currency = 'USD' FROM usage_charges WHERE id = :u1), 'a hosting charge at cost plus 20 %: 180 → 216.00');
INSERT INTO usage_charges (engagement_id, period_start, period_end, kind, provider_id, description, quantity, unit, unit_cost, pricing, resale_unit_price, currency, created_by)
VALUES (:eng, '2026-03-01', '2026-03-31', 'model_usage', :p_anthropic, 'Tokens, March', 2000000, 'tokens', 0.000005, 'resale', 15, 'XXX', 27);
SELECT id AS u2 FROM usage_charges WHERE description = 'Tokens, March' \gset
SELECT pg_temp.check((SELECT cost_amount = 10.00 AND billable_amount = 30.00 FROM usage_charges WHERE id = :u2), 'two million tokens at 0.000005 cost 10.00 and resell at 15 per million: 30.00');
SELECT pg_temp.refused($$UPDATE usage_charges SET status = 'approved' WHERE id = $$ || :u1, 'never by hand', 'a usage charge''s status is never set by hand');
SELECT ct_usage_charge_approve(:u1, 27); SELECT ct_usage_charge_approve(:u2, 27);
SELECT pg_temp.refused($$UPDATE usage_charges SET quantity = 2 WHERE id = $$ || :u1, 'locked', 'an approved charge is locked');
-- a schedule
INSERT INTO recurring_charges (engagement_id, kind, provider_id, description, quantity, unit, unit_cost, pricing, cadence, next_date, created_by)
VALUES (:eng, 'hosting', :p_aws, 'Pilot VM', 1, 'months', 180, 'cost', 'monthly', '2026-09-01', 27);
SELECT id AS sch FROM recurring_charges WHERE description = 'Pilot VM' \gset
SELECT (ct_recurring_charge_run(:sch, 27)).id AS u3 \gset
SELECT pg_temp.check((SELECT period_start = '2026-09-01' AND period_end = '2026-09-30' AND source = 'schedule' AND billable_amount = 180 AND status = 'draft' FROM usage_charges WHERE id = :u3)
                     AND (SELECT next_date = '2026-10-01' AND run_count = 1 FROM recurring_charges WHERE id = :sch), 'a schedule falling due drafts September''s charge and moves to October');
SELECT (ct_recurring_charge_run(:sch, 27)).id AS u4 \gset
SELECT pg_temp.refused($$SELECT ct_recurring_charge_run($$ || :sch || $$, 27)$$, 'not due until', 'a schedule not yet due is not run');
-- the kernel's statement
SELECT ct_ai_statement_load($${"schema":"os.ledger-period/1","period":"2026-09","period_start":"2026-09-01","period_end":"2026-09-30","status":"open","lines":[]}$$::jsonb) AS st_open \gset
SELECT pg_temp.refused($$SELECT ct_ai_statement_allocate((SELECT id FROM ai_statements WHERE period = '2026-09'), 27)$$, 'still open', 'an open month is never allocated');
SELECT (ct_ai_statement_load($${"schema":"os.ledger-period/1","period":"2026-09","period_start":"2026-09-01","period_end":"2026-09-30","status":"closed","lines":[
  {"provider":"anthropic","model":"claude-sonnet-5","department_id":7,"department":"AI Strategy","agent_id":40,"agent":"Clerk","application_id":50,"application":"Projects","calls":120,"input_tokens":900000,"output_tokens":100000,"cache_read_tokens":0,"cache_write_tokens":0,"amount":"123.4567","currency":"USD"},
  {"provider":"anthropic","model":"claude-sonnet-5","department_id":3,"department":"Accounting","agent_id":41,"agent":"Watcher","application_id":null,"application":null,"calls":10,"input_tokens":50000,"output_tokens":5000,"cache_read_tokens":0,"cache_write_tokens":0,"amount":"50.0000","currency":"USD"},
  {"provider":"openai","model":"gpt-5","department_id":8,"department":"ML Engineering","agent_id":40,"agent":"Clerk","application_id":null,"application":null,"calls":3,"input_tokens":10000,"output_tokens":1000,"cache_read_tokens":0,"cache_write_tokens":0,"amount":"10.0001","currency":"USD"}]}$$::jsonb)).id AS st \gset
SELECT pg_temp.check((SELECT kernel_status = 'closed' AND status = 'pulled' FROM ai_statements WHERE id = :st) AND (SELECT count(*) FROM ai_statement_lines WHERE statement_id = :st) = 3, 'the closed month is kept verbatim: three lines');
INSERT INTO allocation_rules (name, kernel_agent_id, engagement_id, share_pct, pricing, markup_pct, valid_from, created_by) VALUES ('SMOKE Clerk → Acme 60', 40, :eng, 60, 'cost', 0, '2026-01-01', 27);
INSERT INTO allocation_rules (name, kernel_agent_id, engagement_id, share_pct, pricing, markup_pct, valid_from, created_by) VALUES ('SMOKE Clerk → Beta 40', 40, :fb, 40, 'markup', 10, '2026-01-01', 27);
SELECT ct_ai_statement_allocate(:st, 27) AS made \gset
SELECT pg_temp.check(:made = 4, 'the allocation makes four draft charges: one per engagement × provider (Acme and Beta, anthropic and openai)');
SELECT pg_temp.check((SELECT sum(cost_amount) FROM usage_charges WHERE statement_id = :st AND engagement_id = :eng) = 80.07 AND (SELECT sum(cost_amount) FROM usage_charges WHERE statement_id = :st AND engagement_id = :fb) = 53.38,
                     'Acme gets 60 % (74.07 + 6.00 = 80.07), Beta 40 % (49.38 + 4.00 = 53.38), each piece rounded once');
SELECT pg_temp.check((SELECT round(sum(amount), 2) - sum(allocated_amount) FROM ai_statement_lines WHERE statement_id = :st) = 50.01
                     AND (SELECT remainder FROM (SELECT round(amount, 2) - allocated_amount AS remainder FROM ai_statement_lines WHERE statement_id = :st AND line_no = 1) r) = 0.01,
                     'the remainder is what no rule placed (50.00) plus the cents (0.01): pieces + remainder = the statement');
SELECT pg_temp.check((SELECT billable_amount FROM usage_charges WHERE statement_id = :st AND engagement_id = :fb AND provider_id = :p_anthropic) = 54.32, 'Beta''s piece is billed at cost plus 10 %: 49.38 → 54.32');
SELECT pg_temp.refused($$SELECT ct_ai_statement_load('{"schema":"os.ledger-period/1","period":"2026-09","period_start":"2026-09-01","period_end":"2026-09-30","status":"closed","lines":[{"provider":"anthropic","amount":"1.00","currency":"USD"}]}'::jsonb)$$, 'changed statement', 'an allocated month does not follow a changed document');
-- re-allocation after a new rule: the drafts are remade; an approved one is kept
SELECT id AS u_acme_a FROM usage_charges WHERE statement_id = :st AND engagement_id = :eng AND provider_id = :p_anthropic \gset
SELECT ct_usage_charge_approve(:u_acme_a, 27);
INSERT INTO allocation_rules (name, kernel_agent_id, engagement_id, share_pct, pricing, valid_from, created_by) VALUES ('SMOKE Watcher → Acme', 41, :eng, 100, 'cost', '2026-01-01', 27);
SELECT ct_ai_statement_allocate(:st, 27) AS made2 \gset
SELECT pg_temp.check((SELECT status FROM usage_charges WHERE id = :u_acme_a) = 'approved' AND (SELECT sum(cost_amount) FROM usage_charges WHERE statement_id = :st AND engagement_id = :eng AND status <> 'void') = 130.07
                     AND (SELECT round(sum(amount), 2) - sum(allocated_amount) FROM ai_statement_lines WHERE statement_id = :st) = 0.01,
                     're-allocating keeps the approved charge, remakes the drafts, places Watcher''s 50.00 on Acme (130.07); the remainder is the cent');
INSERT INTO allocation_rules (name, kernel_agent_id, engagement_id, share_pct, valid_from, created_by) VALUES ('SMOKE too much', 41, :fb, 50, '2026-01-01', 27);
SELECT pg_temp.refused($$SELECT ct_ai_statement_allocate($$ || :st || $$, 27)$$, 'more than the whole', 'rules sharing out more than 100 % are refused by name');
DELETE FROM allocation_rules WHERE name = 'SMOKE too much';
RESET ROLE;

-- ---------------------------------------------------------------- 6. milestones
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.refused($$UPDATE milestones SET status = 'invoiced' WHERE id = $$ || :m1, 'Only an accepted', 'a planned milestone is not invoiced');
UPDATE milestones SET status = 'delivered' WHERE id = :m1;
SELECT pg_temp.refused($$UPDATE milestones SET status = 'accepted' WHERE id = $$ || :m1, 'names who accepted', 'accepting names who accepted');
UPDATE milestones SET status = 'accepted', accepted_by = 27, acceptance_note = 'Signed off by the Beta CTO by email' WHERE id = :m1;
SELECT pg_temp.check((SELECT status = 'accepted' AND accepted_at IS NOT NULL AND delivered_at IS NOT NULL FROM milestones WHERE id = :m1), 'M1 delivered then accepted, both stamped');
SELECT pg_temp.refused($$UPDATE milestones SET amount = 1 WHERE id = $$ || :m1, 'amount is fixed', 'an accepted milestone''s amount is fixed');
RESET ROLE;

-- ---------------------------------------------------------------- 7. the unbilled report before billing (B1)
SET ROLE consultant_tracking_records_ro;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.check((SELECT time_hours = 19.5 AND time_amount = 2790.00 AND expense_amount = 176.00 AND usage_amount = 320.07 AND milestone_amount = 0 FROM ct_unbilled('2026-12-31', :eng)),
                     'Acme''s WIP: 19.5 h = 2790.00 (7.5 × 180 + 12 × 120), expenses 176.00 (132 + 44), usage 320.07 (216 + 30 + the approved 74.07 piece)');
SELECT pg_temp.check((SELECT milestone_amount = 10000 AND time_amount = 0 FROM ct_unbilled('2026-12-31', :fb)), 'Beta''s WIP is the accepted milestone, 10,000');
SELECT pg_temp.check((SELECT count(*) FROM ct_unbilled_items(:eng, '2026-12-31')) = 9, 'nine unbilled items behind Acme (4 billable time entries, 2 expenses, 3 usage — the non-billable, the written-off and the draft charges excluded)');
RESET ROLE;

-- ---------------------------------------------------------------- 8. invoicing (D9, D10, D13)
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO invoices (client_id, engagement_id, period_start, period_end, issue_date, due_date, currency, created_by) VALUES (:acme, :eng, '2026-03-01', '2026-03-31', '2026-04-01', '2026-05-01', 'XXX', 28);
SELECT id AS inv1 FROM invoices WHERE engagement_id = :eng AND status = 'draft' \gset
SELECT pg_temp.check((SELECT currency = 'USD' AND po_number = 'PO-4411' AND department_id = 7 AND number IS NULL FROM invoices WHERE id = :inv1), 'a draft takes the engagement''s currency, PO and practice; no number yet');
SELECT pg_temp.refused($$INSERT INTO invoices (client_id, engagement_id, issue_date, due_date, currency) VALUES ($$ || :beta || $$, $$ || :eng || $$, '2026-04-01', '2026-05-01', 'USD')$$, 'another client', 'an invoice names the engagement''s own client');
SELECT * FROM ct_invoice_add_unbilled(:inv1, 28, '2026-12-31') \gset ub_
SELECT pg_temp.check(:ub_time_lines = 2 AND :ub_time_entries_billed = 4 AND :ub_time_no_rate = 0 AND :ub_expense_lines = 2 AND :ub_usage_lines = 3 AND :ub_milestone_lines = 0,
                     'add-unbilled: two time lines (by person), four entries, two expense lines, three usage lines');
SELECT pg_temp.check((SELECT subtotal = 3286.07 AND total = 3286.07 AND status = 'draft' FROM invoices WHERE id = :inv1), 'the draft totals 3286.07 (2790 + 176 + 320.07)');
SELECT pg_temp.check((SELECT quantity = 7.5 AND unit_price = 180 AND line_total = 1350.00 FROM invoice_lines WHERE invoice_id = :inv1 AND kind = 'time' AND group_key = '26'), 'Priya''s time line: 7.5 h × 180 = 1350.00');
SELECT * FROM ct_invoice_add_unbilled(:inv1, 28, '2026-12-31') \gset ub2_
SELECT pg_temp.check(:ub2_time_lines = 0 AND :ub2_expense_lines = 0 AND :ub2_usage_lines = 0, 'a second add-unbilled finds nothing: every item is already on the draft (billed once)');
SELECT id AS l_time FROM invoice_lines WHERE invoice_id = :inv1 AND kind = 'time' AND group_key = '26' \gset
SELECT pg_temp.refused($$INSERT INTO invoice_line_items (invoice_id, line_id, time_entry_id, amount) SELECT $$ || :inv1 || $$, $$ || :l_time || $$, id, 1 FROM time_entries WHERE timesheet_id = $$ || :ts1 || $$ AND entry_date = '2026-03-02'$$, 'duplicate key', 'the same entry on a second line is refused (UNIQUE)');
SELECT pg_temp.refused($$INSERT INTO invoice_line_items (invoice_id, line_id, time_entry_id, amount) VALUES ($$ || :inv1 || $$, $$ || :l_time || $$, $$ || :e_wo || $$, 1)$$, 'approved, billable, priced', 'a written-off entry is not invoiced');
SELECT pg_temp.check((SELECT count(*) FROM ct_unbilled('2026-12-31', :eng)) = 0, 'Acme''s WIP is empty once the draft holds every item');
SELECT pg_temp.refused($$UPDATE invoices SET status = 'issued' WHERE id = $$ || :inv1, 'through its function', 'issuing is a function, never a status set by hand');
-- a deleted draft burns no number
INSERT INTO invoices (client_id, engagement_id, issue_date, due_date, currency, created_by) VALUES (:beta, :fb, '2026-04-02', '2026-04-17', 'USD', 28);
DELETE FROM invoices WHERE engagement_id = :fb AND status = 'draft';
-- the PO rule and the NTE cap
UPDATE invoices SET po_number = NULL WHERE id = :inv1;
SELECT pg_temp.refused($$SELECT ct_invoice_issue($$ || :inv1 || $$, 28)$$, 'requires a PO', 'Acme requires a PO number on every invoice');
UPDATE invoices SET po_number = 'PO-4411' WHERE id = :inv1;
UPDATE engagements SET nte_amount = 3000 WHERE id = :eng;
SELECT pg_temp.refused($$SELECT ct_invoice_issue($$ || :inv1 || $$, 28)$$, 'not-to-exceed', 'issuing above the not-to-exceed is refused without a reason');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.refused($$SELECT ct_invoice_issue($$ || :inv1 || $$, 27, 'client agreed by email')$$, 'Only Billing', 'a Manager cannot override the cap');
SELECT set_config('app.member_id', '28', true);
SELECT ct_invoice_issue(:inv1, 28, 'Acme agreed to the overrun by email on Mar 28');
SELECT pg_temp.check((SELECT number = 'INV-00001' AND status = 'issued' AND issued_by = 28 AND nte_override_reason LIKE 'Acme agreed%' FROM invoices WHERE id = :inv1), 'issued as INV-00001 (the deleted draft burned nothing), the override recorded');
UPDATE engagements SET nte_amount = 20000 WHERE id = :eng;
SELECT pg_temp.check((SELECT count(*) FROM ct_ar_aging('2026-04-20')) = 1 AND (SELECT current_due FROM ct_ar_aging('2026-04-20') WHERE client_id = :acme) = 3286.07, 'the aging on April 20 shows Acme''s 3286.07 current, nothing else yet (B5)');
SELECT pg_temp.check((SELECT d1_30 FROM ct_ar_aging('2026-05-20') WHERE client_id = :acme) = 3286.07, 'on May 20 the same invoice sits in the 1–30 bucket');
SELECT pg_temp.refused($$INSERT INTO invoice_lines (invoice_id, sort_order, kind, description, quantity, unit_price) VALUES ($$ || :inv1 || $$, 9, 'fixed', 'late', 1, 1)$$, 'only while it is a draft', 'an issued invoice''s lines are fixed');
SELECT pg_temp.refused($$UPDATE invoices SET total = 1 WHERE id = $$ || :inv1, 'cannot change', 'an issued invoice''s totals are fixed');
SELECT pg_temp.refused($$SELECT ct_invoice_issue($$ || :inv1 || $$, 28)$$, 'already issued', 'issuing twice is refused');
SELECT pg_temp.refused($$DELETE FROM invoice_line_items WHERE invoice_id = $$ || :inv1, 'void it to release', 'an issued invoice keeps its backing items');
SELECT pg_temp.refused($$UPDATE engagements SET currency = 'EUR' WHERE id = $$ || :eng, 'currency is fixed', 'an engagement with invoices keeps its currency');
SELECT pg_temp.check(ct_item_billed('expense', :x1) AND ct_item_billed('usage_charge', :u1), 'the expense and the usage charge are billed');
SELECT pg_temp.refused($$SELECT ct_usage_charge_void($$ || :u1 || $$, 27, 'oops')$$, 'on an invoice', 'a billed usage charge is not voided');
SELECT pg_temp.refused($$SELECT ct_expense_reject($$ || :x2 || $$, 27, 'oops')$$, 'billed or reimbursed', 'a billed expense is not rejected');
-- the secure link (D9)
SELECT ct_secure_link_mint(:inv1) AS link1 \gset
SELECT ct_secure_link_invoice(:'link1') AS opened1 \gset
SELECT pg_temp.check(:opened1 = :inv1 AND (SELECT status = 'viewed' AND viewed_at IS NOT NULL FROM invoices WHERE id = :inv1), 'the mailed link opens the invoice and marks it viewed (B10)');
SELECT pg_temp.check(ct_secure_link_invoice('deadbeef') IS NULL AND ct_secure_link_invoice(repeat('0', 48)) IS NULL, 'a malformed or unknown token opens nothing');
SELECT ct_secure_link_mint(:inv1) AS link2 \gset
SELECT pg_temp.check(ct_secure_link_invoice(:'link1') IS NULL AND ct_secure_link_invoice(:'link2') = :inv1, 'rotating kills the old link; the new one works');
SELECT pg_temp.check((SELECT count(*) FROM invoice_links_secure WHERE token_hash IN (:'link1', :'link2')) = 0, 'only hashes are stored, never the token');
INSERT INTO payment_claims (invoice_id, claimed_on, method, reference, amount, note) VALUES (:inv1, '2026-04-20', 'transfer', 'ACME-7781', 1000, 'half now');
SELECT id AS claim FROM payment_claims WHERE invoice_id = :inv1 \gset
SELECT pg_temp.check((SELECT balance_due = 3286.07 AND amount_paid = 0 FROM invoices WHERE id = :inv1), 'a claim records nothing as money');
SELECT (ct_payment_claim_confirm(:claim, 28)).id AS pay1 \gset
SELECT pg_temp.check((SELECT status = 'partially_paid' AND amount_paid = 1000 AND balance_due = 2286.07 FROM invoices WHERE id = :inv1) AND (SELECT status = 'confirmed' AND payment_id = :pay1 FROM payment_claims WHERE id = :claim),
                     'billing confirms the claim into a payment: partially paid, derived never typed (D10)');
SELECT pg_temp.refused($$INSERT INTO invoice_payments (invoice_id, paid_on, amount, currency, method, recorded_by) VALUES ($$ || :inv1 || $$, '2026-04-25', 2286.08, 'USD', 'transfer', 28)$$, 'overpay', 'overpaying is refused');
SELECT pg_temp.refused($$UPDATE invoices SET status = 'paid' WHERE id = $$ || :inv1, 'never typed', 'paid is never typed');
SELECT pg_temp.refused($$SELECT ct_invoice_void($$ || :inv1 || $$, 28, 'oops')$$, 'payments or credits', 'an invoice with payments is not voided');
INSERT INTO invoice_payments (invoice_id, paid_on, amount, currency, method, reference, recorded_by) VALUES (:inv1, '2026-04-25', 2286.07, 'USD', 'transfer', 'ACME-7790', 28);
SELECT pg_temp.check((SELECT status = 'paid' AND paid_at IS NOT NULL AND balance_due = 0 FROM invoices WHERE id = :inv1), 'the second payment settles it: paid');
SELECT pg_temp.refused($$DELETE FROM invoice_payments WHERE invoice_id = $$ || :inv1, 'never deleted', 'a payment is never deleted');
-- a fixed-bid invoice: the milestone; then void releases it
INSERT INTO invoices (client_id, engagement_id, issue_date, due_date, currency, created_by) VALUES (:beta, :fb, '2026-05-01', '2026-05-16', 'XXX', 28);
SELECT id AS inv2 FROM invoices WHERE engagement_id = :fb AND status = 'draft' \gset
SELECT * FROM ct_invoice_add_unbilled(:inv2, 28, '2026-12-31') \gset ub3_
SELECT pg_temp.check(:ub3_milestone_lines = 1 AND (SELECT total FROM invoices WHERE id = :inv2) = 10000, 'the Beta draft bills the accepted milestone: 10,000');
SELECT ct_invoice_issue(:inv2, 28);
SELECT pg_temp.check((SELECT number FROM invoices WHERE id = :inv2) = 'INV-00002' AND (SELECT status FROM milestones WHERE id = :m1) = 'invoiced', 'INV-00002 follows gaplessly; the milestone is marked invoiced');
SELECT ct_invoice_void(:inv2, 28, 'Wrong amount agreed');
SELECT pg_temp.check((SELECT status = 'void' AND void_reason = 'Wrong amount agreed' FROM invoices WHERE id = :inv2) AND NOT ct_item_billed('milestone', :m1) AND (SELECT status FROM milestones WHERE id = :m1) = 'accepted'
                     AND (SELECT count(*) FROM invoice_lines WHERE invoice_id = :inv2) = 1, 'a void releases the milestone (accepted, unbilled) and keeps the line as text');
SELECT pg_temp.check((SELECT milestone_amount FROM ct_unbilled('2026-12-31', :fb)) = 10000, 'the released milestone is WIP again');
-- a credit note against the paid invoice? no — against an open one: issue INV-00003 for Beta again, credit part of it, write off the rest
INSERT INTO invoices (client_id, engagement_id, issue_date, due_date, currency, created_by) VALUES (:beta, :fb, '2026-05-02', '2026-05-17', 'XXX', 28);
SELECT id AS inv3 FROM invoices WHERE engagement_id = :fb AND status = 'draft' \gset
SELECT * FROM ct_invoice_add_unbilled(:inv3, 28, '2026-12-31') \gset ub4_
SELECT ct_invoice_issue(:inv3, 28);
INSERT INTO credit_notes (client_id, invoice_id, reason, created_by) VALUES (:beta, :inv3, 'Scope reduced', 28);
SELECT id AS cn FROM credit_notes WHERE invoice_id = :inv3 \gset
INSERT INTO credit_note_lines (credit_note_id, sort_order, kind, description, quantity, unit_price) VALUES (:cn, 1, 'milestone', 'Discovery scope reduced', 1, 2500);
SELECT pg_temp.refused($$INSERT INTO credit_applications (credit_note_id, invoice_id, amount) VALUES ($$ || :cn || $$, $$ || :inv3 || $$, 2500)$$, 'not issued', 'a draft credit note is not applied');
SELECT ct_credit_note_issue(:cn, 28);
INSERT INTO credit_applications (credit_note_id, invoice_id, amount, applied_by) VALUES (:cn, :inv3, 2500, 28);
SELECT pg_temp.check((SELECT number = 'CN-00001' FROM credit_notes WHERE id = :cn) AND (SELECT amount_credited = 2500 AND balance_due = 7500 AND status = 'partially_paid' FROM invoices WHERE id = :inv3), 'CN-00001 applied: the invoice owes 7500');
SELECT pg_temp.refused($$SELECT ct_invoice_write_off($$ || :inv3 || $$, 28, '')$$, 'needs a reason', 'a write-off needs a reason');
SELECT ct_invoice_write_off(:inv3, 28, 'Beta folded');
SELECT pg_temp.check((SELECT status = 'written_off' AND written_off_by = 28 FROM invoices WHERE id = :inv3) AND ct_item_billed('milestone', :m1), 'written off; the milestone stays billed');
RESET ROLE;

-- ---------------------------------------------------------------- 9. the reports
SET ROLE consultant_tracking_records_ro;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.check((SELECT logged_hours = 9.5 AND billable_hours = 7.5 AND capacity_hours = 40 AND utilization_pct = 18.8 FROM ct_utilization('2026-03-02', '2026-03-08', 26)), 'Priya''s utilization that week: 7.5 billable of 40 = 18.8 %');
SELECT pg_temp.check((SELECT approved_hours = 23.5 AND billed_hours = 19.5 AND written_off_hours = 2 AND realization_hours_pct = 83.0 FROM ct_realization('2026-03-01', '2026-03-31', :eng)),
                     'Acme''s realization: 23.5 approved (2 non-billable, 2 written off), 19.5 billed = 83.0 %');
SELECT pg_temp.check((SELECT hours_used = 23.5 AND hours_pct = 23.5 AND invoiced_total = 3286.07 AND nte_pct = 16.4 AND ai_cost = 680.07 AND ai_pct = 136.0 FROM ct_budget_status(:eng)),
                     'Acme''s budget status: 23.5 of 100 h, 16.4 % of the NTE, AI cost 680.07 (every live charge, drafts included) = 136.0 % of 500');
SELECT pg_temp.check((SELECT bill_value = 3212.00 AND time_cost = 1435.00 AND expense_cost = 160.00 AND usage_cost = 190.00 AND margin = 1427.00 FROM ct_margin('2026-03-01', '2026-03-31', :eng)),
                     'Acme''s March margin as Billing sees it: 3212.00 bill value (2790 time + 176 expenses + 246 usage) less 1435.00 time cost (the written-off hours cost too), 160.00 expenses, 190.00 usage = 1427.00');
SELECT pg_temp.check((SELECT total = 7500 AND d90_plus = 7500 FROM ct_ar_aging('2026-09-01') WHERE client_id = :beta) IS NULL, 'a written-off invoice is not in the aging');
SELECT pg_temp.check((SELECT count(*) FROM ct_ar_aging()) = 0, 'once paid, voided and written off, nothing is in the aging');
SELECT pg_temp.check((SELECT total FROM ct_revenue('2026-01-01', '2026-12-31', 'client') WHERE group_label = 'SMOKE Acme') = 3286.07 AND (SELECT count(*) FROM ct_revenue('2026-01-01', '2026-12-31', 'kind')) = 4, 'revenue by client and by kind (time, expense, usage, milestone)');
SELECT pg_temp.check((SELECT sum(billed_amount) FROM ct_ai_passthrough('2026-03-01', '2026-03-31', :eng)) = 246.00 AND (SELECT sum(markup) FROM ct_ai_passthrough('2026-03-01', '2026-03-31', :eng)) = 56.00, 'AI pass-through in March: 246.00 billed (216 + 30) on 190.00 cost — 56.00 markup');
SELECT pg_temp.check((SELECT count(*) FROM ct_missing_time('2026-03-09')) = 5 AND EXISTS (SELECT 1 FROM ct_missing_time('2026-03-09') WHERE member_id = 26 AND empty_weekdays = 5),
                     'missing time for the week of Mar 9: everyone but Dana, who submitted; Priya''s five weekdays empty');
SELECT pg_temp.check((SELECT running_balance FROM ct_client_statement(:acme) ORDER BY entry_date DESC, kind DESC LIMIT 1) = 0 AND (SELECT count(*) FROM ct_client_statement(:acme)) = 3, 'Acme''s statement: the invoice and two payments, balance 0');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check((SELECT margin FROM ct_margin('2026-03-01', '2026-03-31', :eng)) = 1427.00, 'Marco, who manages Acme, sees its margin');
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check((SELECT count(*) FROM ct_margin('2026-03-01', '2026-03-31')) = 0 AND (SELECT count(*) FROM ct_utilization('2026-03-02', '2026-03-08')) = 1, 'Priya sees no margin and only her own utilization');
SELECT pg_temp.check((SELECT hours_used FROM ct_budget_status(:eng)) = 23.5 AND (SELECT count(*) FROM ct_budget_status(:fb)) = 1 AND (SELECT count(*) FROM ct_budget_status(:dr)) = 0, 'a consultant sees budget burn on the engagements they are staffed on, not others');
RESET ROLE;
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '27', true);
SELECT ct_budget_alerts_check(:eng) AS alerts \gset
SELECT pg_temp.check(:alerts = 1 AND EXISTS (SELECT 1 FROM budget_alerts WHERE engagement_id = :eng AND kind = 'ai' AND threshold_pct = 80), 'one alert written: the AI budget crossed 80 %');
SELECT pg_temp.check(ct_budget_alerts_check(:eng) = 0, 'checking again writes nothing new');
RESET ROLE;

-- ---------------------------------------------------------------- 10. who sees what, as the records role
SET ROLE consultant_tracking_records_ro;
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 5, 'app_roles readable by the records role');
SELECT set_config('app.member_id', '26', true);          -- Priya: Consultant
SELECT pg_temp.check((SELECT array_agg(engagement_id ORDER BY engagement_id) FROM mcp_engagements) = ARRAY[:eng, :fb]::bigint[] AND (SELECT bool_and(NOT i_manage) FROM mcp_engagements), 'Priya sees the two engagements she is staffed on, manages neither');
SELECT pg_temp.check((SELECT count(*) FROM mcp_clients) = 2 AND (SELECT bool_and(email IS NULL AND phone IS NULL) FROM mcp_clients), 'Priya sees her clients'' names, never their contact details');
SELECT pg_temp.check((SELECT count(*) FROM mcp_time_entries) = 3 AND (SELECT bool_and(bill_rate IS NULL AND cost_rate IS NULL) FROM mcp_time_entries), 'Priya sees her own three entries, with no bill rate and no cost');
SELECT pg_temp.check((SELECT count(*) FROM mcp_timesheets) = 2 AND (SELECT count(*) FROM mcp_expenses) = 2 AND (SELECT bool_and(has_receipt = (expense_id = :x1)) FROM mcp_expenses), 'Priya sees her two weeks (March, and the timer''s) and her two expenses, with the receipt flag');
SELECT pg_temp.check((SELECT count(*) FROM mcp_invoices) = 0 AND (SELECT count(*) FROM mcp_usage_charges) = 0 AND (SELECT count(*) FROM mcp_rate_cards) = 0 AND (SELECT count(*) FROM mcp_member_cost_rates) = 0, 'Priya sees no invoice, no usage, no rate card, no cost rate');
SELECT pg_temp.check((SELECT bill_rate_override IS NULL FROM mcp_engagement_members WHERE member_id = 26 AND engagement_id = :eng) AND (SELECT count(*) FROM mcp_engagement_members) = 4, 'Priya sees who is staffed beside her, without rates');
SELECT set_config('app.member_id', '27', true);          -- Marco: Manager of both
SELECT pg_temp.check((SELECT count(*) FROM mcp_engagements WHERE i_manage) = 2 AND (SELECT count(*) FROM mcp_time_entries) = 6 AND (SELECT bool_and(bill_rate IS NOT NULL AND cost_rate IS NOT NULL) FROM mcp_time_entries WHERE status = 'approved' AND billable),
                     'Marco manages both, sees every entry on them with bill rate and cost');
SELECT pg_temp.check((SELECT count(*) FROM mcp_invoices) = 3 AND (SELECT count(*) FROM mcp_member_cost_rates) = 0 AND (SELECT count(*) FROM mcp_rate_cards) = 1, 'Marco sees the invoices and the rate card, not the cost-rate table');
SELECT pg_temp.check((SELECT count(*) FROM mcp_timesheets) = 2, 'Marco sees both weeks (entries on his engagements)');
SELECT set_config('app.member_id', '30', true);          -- Dana: Consultant on Acme only
SELECT pg_temp.check((SELECT array_agg(engagement_id) FROM mcp_engagements) = ARRAY[:eng]::bigint[] AND (SELECT count(*) FROM mcp_time_entries) = 3 AND (SELECT count(*) FROM mcp_timesheets) = 1, 'Dana sees Acme alone, her three entries, her one week');
SELECT set_config('app.member_id', '31', true);          -- Lee: staffed nowhere
SELECT pg_temp.check((SELECT count(*) FROM mcp_engagements) = 0 AND (SELECT count(*) FROM mcp_clients) = 0, 'Lee, staffed nowhere, sees no engagement and no client');
SELECT set_config('app.member_id', '29', true);          -- Ann: Finance, external
SELECT pg_temp.check((SELECT count(*) FROM mcp_engagements) = 3 AND (SELECT count(*) FROM mcp_member_cost_rates) = 3 AND (SELECT count(*) FROM mcp_invoices) = 3 AND (SELECT bool_and(cost_rate IS NOT NULL) FROM mcp_time_entries WHERE status IN ('approved', 'written_off')),
                     'Ann (Finance) reads everything: engagements, the cost-rate table, invoices, cost snapshots');
SELECT set_config('app.member_id', '28', true);          -- Bea: Billing
SELECT pg_temp.check((SELECT email FROM mcp_clients WHERE client_id = :acme) = 'ap@acme.invalid' AND (SELECT count(*) FROM mcp_client_contacts) = 1 AND (SELECT count(*) FROM mcp_member_cost_rates) = 3, 'Bea sees contact details and, holding cost.view business-wide, the cost-rate table');
SELECT pg_temp.check((SELECT remainder FROM mcp_ai_statement_lines WHERE line_no = 1) = 0.01 AND (SELECT allocated_amount FROM mcp_ai_statements) = 183.45, 'the OS statement''s lines and remainder are readable with usage.write');
SELECT set_config('app.member_id', '41', true);          -- an agent not admitted
SELECT pg_temp.check((SELECT count(*) FROM mcp_engagements) = 0 AND (SELECT count(*) FROM mcp_members) = 0, 'an agent not yet admitted sees nothing');
SELECT set_config('app.member_id', '999', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_engagements) = 0 AND (SELECT count(*) FROM mcp_clients) = 0, 'an unknown member sees nothing');
SELECT pg_temp.refused($$SELECT * FROM member_cost_rates$$, 'permission denied', 'the records role has no base table');
SELECT pg_temp.refused($$SELECT ct_cost_rate(26, current_date)$$, 'permission denied', 'the records role cannot ask a cost rate directly');
RESET ROLE;

-- ---------------------------------------------------------------- 11. the activity view, as the activity role
SET ROLE consultant_tracking_rw;
SELECT set_config('app.member_id', '28', true);
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, engagement_id, invoice_id, timesheet_id, after)
VALUES (28, 'web', 'invoice.issue', 'invoice', :inv1, :eng, :inv1, NULL, '{"amount": 3286.07, "currency": "USD"}'),
       (27, 'web', 'timesheet.approve', 'timesheet', :ts1, :eng, NULL, :ts1, '{"approved": 3}'),
       (40, 'agent', 'ai_statement.allocate', 'ai_statement', :st, NULL, NULL, NULL, '{"charges": 3}'),
       (NULL, 'cron', 'directory.sync', NULL, NULL, NULL, NULL, NULL, '{"members": 9}');
SELECT pg_temp.refused($$UPDATE activity_log SET after = '{}' WHERE action = 'directory.sync'$$, 'permission denied', 'the activity log is append-only even for the writer');
RESET ROLE;
SET ROLE consultant_tracking_activity_ro;
SELECT set_config('app.member_id', '29', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 4, 'Finance reads the whole trail');
SELECT pg_temp.check((SELECT actor_kind FROM mcp_activity_log WHERE action = 'ai_statement.allocate') = 'agent' AND (SELECT (after->>'amount')::numeric FROM mcp_activity_log WHERE action = 'invoice.issue') = 3286.07, 'the trail says who was an agent and what the money was (B11)');
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check((SELECT array_agg(action) FROM mcp_activity_log) = ARRAY['timesheet.approve'], 'Priya reads what happened to her own week, nothing else');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 2, 'Marco reads his engagements'' trail (the issue and the approval)');
SELECT pg_temp.refused($$SELECT * FROM mcp_expenses$$, 'permission denied', 'the activity role has no records view but names and record facts');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) = 9, 'the activity role sees the names beside a row');
SELECT set_config('app.member_id', '999', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 0, 'an unknown member reads no trail');
RESET ROLE;

-- ---------------------------------------------------------------- the verdict
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label AS result FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed, of ' || count(*) AS summary FROM proof;
ROLLBACK;
