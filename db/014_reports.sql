-- 014: THE REPORTS as SQL functions (design §6), so a screen, a tool and a CSV never disagree. Each answers only what
-- the caller may see: engagements through ct_visible_engagement_ids(); a person's own figures under reports.view_own;
-- the firm's under reports.view; cost and margin under cost.view — and for a Manager only on the engagements they
-- manage (design §3). "Billed" is an invoice line item (db/012); "unbilled" is approved, billable and not billed.
BEGIN;

-- The bill value of an approved time entry (NULL when it has no rate), and the cost.
CREATE OR REPLACE FUNCTION ct_time_bill_value(p_hours numeric, p_rate numeric) RETURNS numeric
    LANGUAGE sql IMMUTABLE AS $$ SELECT CASE WHEN p_rate IS NULL THEN NULL ELSE round(p_hours * p_rate, 2) END $$;

-- ---------------------------------------------------------------------------------------------
-- B1 — what is approved and unbilled (WIP), per engagement.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_unbilled(p_as_of date DEFAULT current_date, p_engagement_id bigint DEFAULT NULL, p_client_id bigint DEFAULT NULL)
    RETURNS TABLE (engagement_id bigint, engagement_number text, engagement_name text, client_id bigint, client_name text, currency char(3),
                   time_hours numeric, time_amount numeric, time_no_rate_hours numeric, expense_amount numeric, usage_amount numeric,
                   milestone_amount numeric, total_amount numeric, oldest_item date)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH vis AS (SELECT e.* FROM engagements e WHERE e.id IN (SELECT ct_visible_engagement_ids())
                   AND (p_engagement_id IS NULL OR e.id = p_engagement_id) AND (p_client_id IS NULL OR e.client_id = p_client_id)),
    t AS (SELECT engagement_id, sum(hours) FILTER (WHERE bill_rate IS NOT NULL) AS hours, sum(round(hours * bill_rate, 2)) AS amount,
                 sum(hours) FILTER (WHERE bill_rate IS NULL) AS no_rate, min(entry_date) AS oldest
            FROM time_entries x WHERE x.status = 'approved' AND x.billable AND x.entry_date <= p_as_of AND NOT ct_item_billed('time_entry', x.id)
             AND x.engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id),
    x AS (SELECT engagement_id, sum(billable_amount) AS amount, min(expense_date) AS oldest FROM expenses x
           WHERE x.status = 'approved' AND x.billable AND x.expense_date <= p_as_of AND NOT ct_item_billed('expense', x.id)
             AND x.engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id),
    u AS (SELECT engagement_id, sum(billable_amount) AS amount, min(period_start) AS oldest FROM usage_charges x
           WHERE x.status = 'approved' AND x.billable AND x.period_end <= p_as_of AND NOT ct_item_billed('usage_charge', x.id)
             AND x.engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id),
    m AS (SELECT engagement_id, sum(amount) AS amount, min(accepted_at::date) AS oldest FROM milestones x
           WHERE x.status = 'accepted' AND x.amount > 0 AND NOT ct_item_billed('milestone', x.id)
             AND x.engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id)
    SELECT v.id, v.number, v.name, v.client_id, c.name, v.currency,
           COALESCE(t.hours, 0), COALESCE(t.amount, 0), COALESCE(t.no_rate, 0), COALESCE(x.amount, 0), COALESCE(u.amount, 0), COALESCE(m.amount, 0),
           COALESCE(t.amount, 0) + COALESCE(x.amount, 0) + COALESCE(u.amount, 0) + COALESCE(m.amount, 0),
           LEAST(t.oldest, x.oldest, u.oldest, m.oldest)
      FROM vis v JOIN clients c ON c.id = v.client_id
      LEFT JOIN t ON t.engagement_id = v.id LEFT JOIN x ON x.engagement_id = v.id LEFT JOIN u ON u.engagement_id = v.id LEFT JOIN m ON m.engagement_id = v.id
     WHERE COALESCE(t.amount, 0) + COALESCE(x.amount, 0) + COALESCE(u.amount, 0) + COALESCE(m.amount, 0) > 0 OR COALESCE(t.no_rate, 0) > 0
     ORDER BY c.name, v.number;
$$;

-- B1 — the items behind one engagement's WIP.
CREATE OR REPLACE FUNCTION ct_unbilled_items(p_engagement_id bigint, p_as_of date DEFAULT current_date)
    RETURNS TABLE (kind text, item_id bigint, item_date date, member_id bigint, member_name text, description text, quantity numeric, amount numeric, no_rate boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT 'time', t.id, t.entry_date, t.member_id, m.display_name, COALESCE(t.note, a.label, 'Time'), t.hours, round(t.hours * t.bill_rate, 2), t.bill_rate IS NULL
      FROM time_entries t JOIN members m ON m.id = t.member_id LEFT JOIN activity_types a ON a.id = t.activity_type_id
     WHERE t.engagement_id = p_engagement_id AND ct_can_see_engagement(p_engagement_id) AND t.status = 'approved' AND t.billable AND t.entry_date <= p_as_of AND NOT ct_item_billed('time_entry', t.id)
    UNION ALL
    SELECT 'expense', x.id, x.expense_date, x.member_id, m.display_name, x.description, 1, x.billable_amount, false
      FROM expenses x JOIN members m ON m.id = x.member_id
     WHERE x.engagement_id = p_engagement_id AND ct_can_see_engagement(p_engagement_id) AND x.status = 'approved' AND x.billable AND x.expense_date <= p_as_of AND NOT ct_item_billed('expense', x.id)
    UNION ALL
    SELECT 'usage', u.id, u.period_end, NULL, NULL, u.description, u.quantity, u.billable_amount, false
      FROM usage_charges u
     WHERE u.engagement_id = p_engagement_id AND ct_can_see_engagement(p_engagement_id) AND u.status = 'approved' AND u.billable AND u.period_end <= p_as_of AND NOT ct_item_billed('usage_charge', u.id)
    UNION ALL
    SELECT 'milestone', ms.id, ms.accepted_at::date, NULL, NULL, ms.name, 1, ms.amount, false
      FROM milestones ms
     WHERE ms.engagement_id = p_engagement_id AND ct_can_see_engagement(p_engagement_id) AND ms.status = 'accepted' AND ms.amount > 0 AND NOT ct_item_billed('milestone', ms.id)
     ORDER BY 3, 1, 2;
$$;

-- ---------------------------------------------------------------------------------------------
-- T5 — utilization: billable hours against capacity, per person (own under reports.view_own; everyone under reports.view).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_utilization(p_from date, p_to date, p_member_id bigint DEFAULT NULL, p_department_id bigint DEFAULT NULL)
    RETURNS TABLE (member_id bigint, member_name text, department_id bigint, department_name text, capacity_hours numeric, logged_hours numeric,
                   billable_hours numeric, approved_billable_hours numeric, utilization_pct numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH people AS (
        SELECT m.id, m.display_name FROM members m
         WHERE m.status = 'active' AND m.member_kind = 'human' AND m.capability IS NOT NULL
           AND (p_member_id IS NULL OR m.id = p_member_id)
           AND (ct_has_right('reports.view') OR (ct_has_right('reports.view_own') AND m.id = app_current_member_id()))
           AND (p_department_id IS NULL OR ct_primary_department(m.id) = p_department_id)
    ), weeks AS (SELECT generate_series(ct_week_start(p_from), ct_week_start(p_to), interval '7 days')::date AS ws),
    cap AS (SELECT p.id, sum(ct_capacity(p.id, w.ws) * (LEAST(w.ws + 6, p_to) - GREATEST(w.ws, p_from) + 1) / 7.0) AS hours FROM people p CROSS JOIN weeks w GROUP BY p.id),
    hrs AS (SELECT t.member_id, sum(t.hours) AS logged, sum(t.hours) FILTER (WHERE t.billable) AS billable,
                   sum(t.hours) FILTER (WHERE t.billable AND t.status = 'approved') AS approved_billable
              FROM time_entries t WHERE t.entry_date BETWEEN p_from AND p_to AND t.status <> 'rejected' AND t.member_id IN (SELECT id FROM people) GROUP BY t.member_id)
    SELECT p.id, p.display_name, d.id, d.name, round(COALESCE(c.hours, 0), 2), COALESCE(h.logged, 0), COALESCE(h.billable, 0), COALESCE(h.approved_billable, 0),
           CASE WHEN COALESCE(c.hours, 0) > 0 THEN round(100 * COALESCE(h.billable, 0) / c.hours, 1) END
      FROM people p LEFT JOIN cap c ON c.id = p.id LEFT JOIN hrs h ON h.member_id = p.id
      LEFT JOIN departments d ON d.id = ct_primary_department(p.id)
     ORDER BY p.display_name;
$$;

-- ---------------------------------------------------------------------------------------------
-- B8 — realization: of the hours approved, how many were billed, written off, still unbilled; standard vs billed value.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_realization(p_from date, p_to date, p_engagement_id bigint DEFAULT NULL)
    RETURNS TABLE (engagement_id bigint, engagement_number text, engagement_name text, client_name text, approved_hours numeric, billed_hours numeric,
                   written_off_hours numeric, unbilled_hours numeric, no_rate_hours numeric, standard_amount numeric, billed_amount numeric,
                   realization_hours_pct numeric, realization_amount_pct numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH vis AS (SELECT e.* FROM engagements e WHERE e.id IN (SELECT ct_visible_engagement_ids()) AND (p_engagement_id IS NULL OR e.id = p_engagement_id)
                    AND (ct_has_right('reports.view') OR ct_has_right('engagements.manage'))),
    t AS (SELECT x.engagement_id,
                 sum(x.hours) FILTER (WHERE x.status IN ('approved', 'written_off')) AS approved,
                 sum(x.hours) FILTER (WHERE x.status = 'approved' AND ct_item_billed('time_entry', x.id)) AS billed,
                 sum(x.hours) FILTER (WHERE x.status = 'written_off') AS wo,
                 sum(x.hours) FILTER (WHERE x.status = 'approved' AND x.billable AND x.bill_rate IS NOT NULL AND NOT ct_item_billed('time_entry', x.id)) AS unbilled,
                 sum(x.hours) FILTER (WHERE x.status = 'approved' AND x.billable AND x.bill_rate IS NULL) AS no_rate,
                 sum(round(x.hours * x.bill_rate, 2)) FILTER (WHERE x.status IN ('approved', 'written_off')) AS standard,
                 sum((SELECT i.amount FROM invoice_line_items i WHERE i.time_entry_id = x.id)) FILTER (WHERE x.status = 'approved') AS billed_amt
            FROM time_entries x WHERE x.entry_date BETWEEN p_from AND p_to AND x.engagement_id IN (SELECT id FROM vis) GROUP BY x.engagement_id)
    SELECT v.id, v.number, v.name, c.name, COALESCE(t.approved, 0), COALESCE(t.billed, 0), COALESCE(t.wo, 0), COALESCE(t.unbilled, 0), COALESCE(t.no_rate, 0),
           COALESCE(t.standard, 0), COALESCE(t.billed_amt, 0),
           CASE WHEN COALESCE(t.approved, 0) > 0 THEN round(100 * COALESCE(t.billed, 0) / t.approved, 1) END,
           CASE WHEN COALESCE(t.standard, 0) > 0 THEN round(100 * COALESCE(t.billed_amt, 0) / t.standard, 1) END
      FROM vis v JOIN clients c ON c.id = v.client_id JOIN t ON t.engagement_id = v.id
     ORDER BY c.name, v.number;
$$;

-- ---------------------------------------------------------------------------------------------
-- CE5 / U6 — where an engagement stands against its budgets (hours, fee, NTE, AI), at bill value, never cost.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_budget_status(p_engagement_id bigint)
    RETURNS TABLE (engagement_id bigint, budget_hours numeric, hours_used numeric, hours_pct numeric,
                   budget_amount numeric, amount_used numeric, amount_pct numeric,
                   nte_amount numeric, invoiced_total numeric, nte_pct numeric,
                   ai_budget_amount numeric, ai_cost numeric, ai_pct numeric,
                   pace_hours_per_week numeric, weeks_left_at_pace numeric)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE e engagements%ROWTYPE; h numeric; a numeric; inv numeric; ai numeric; weeks numeric; pace numeric;
BEGIN
    IF NOT ct_can_see_engagement(p_engagement_id) THEN RETURN; END IF;
    SELECT * INTO e FROM engagements WHERE id = p_engagement_id;
    SELECT COALESCE(sum(t.hours), 0),
           COALESCE(sum(CASE WHEN t.status IN ('approved', 'written_off') THEN round(t.hours * t.bill_rate, 2)
                             ELSE round(t.hours * (ct_resolve_rate(t.engagement_id, t.member_id, t.entry_date)).bill_rate, 2) END) FILTER (WHERE t.billable), 0)
      INTO h, a FROM time_entries t WHERE t.engagement_id = e.id AND t.status <> 'rejected';
    a := a + COALESCE((SELECT sum(x.billable_amount) FROM expenses x WHERE x.engagement_id = e.id AND x.status IN ('submitted', 'approved') AND x.billable), 0)
           + COALESCE((SELECT sum(u.billable_amount) FROM usage_charges u WHERE u.engagement_id = e.id AND u.status <> 'void' AND u.billable), 0);
    SELECT COALESCE(sum(i.total), 0) INTO inv FROM invoices i WHERE i.engagement_id = e.id AND i.status NOT IN ('draft', 'void');
    SELECT COALESCE(sum(u.cost_amount), 0) INTO ai FROM usage_charges u WHERE u.engagement_id = e.id AND u.status <> 'void';
    weeks := GREATEST(1, (LEAST(current_date, COALESCE(e.end_date, current_date)) - e.start_date) / 7.0);
    pace := round(h / weeks, 2);
    RETURN QUERY SELECT e.id, e.budget_hours, h, CASE WHEN e.budget_hours > 0 THEN round(100 * h / e.budget_hours, 1) END,
                        e.budget_amount, a, CASE WHEN e.budget_amount > 0 THEN round(100 * a / e.budget_amount, 1) END,
                        e.nte_amount, inv, CASE WHEN e.nte_amount > 0 THEN round(100 * inv / e.nte_amount, 1) END,
                        e.ai_budget_amount, ai, CASE WHEN e.ai_budget_amount > 0 THEN round(100 * ai / e.ai_budget_amount, 1) END,
                        pace, CASE WHEN e.budget_hours > 0 AND pace > 0 THEN round((e.budget_hours - h) / pace, 1) END;
END$$;

-- Write the alerts an engagement's thresholds have crossed (the worker, the approve handlers); returns how many new.
CREATE OR REPLACE FUNCTION ct_budget_alerts_check(p_engagement_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b record; th integer; made integer := 0; s ct_settings%ROWTYPE;
BEGIN
    SELECT * INTO s FROM ct_settings WHERE id = 1;
    SELECT * INTO b FROM ct_budget_status(p_engagement_id);
    IF b.engagement_id IS NULL THEN RETURN 0; END IF;
    FOREACH th IN ARRAY s.budget_thresholds_pct LOOP
        IF b.hours_pct >= th THEN
            INSERT INTO budget_alerts (engagement_id, kind, threshold_pct, value_at, budget_at) VALUES (p_engagement_id, 'hours', th, b.hours_used, b.budget_hours) ON CONFLICT DO NOTHING;
            IF FOUND THEN made := made + 1; END IF;
        END IF;
        IF b.amount_pct >= th THEN
            INSERT INTO budget_alerts (engagement_id, kind, threshold_pct, value_at, budget_at) VALUES (p_engagement_id, 'amount', th, b.amount_used, b.budget_amount) ON CONFLICT DO NOTHING;
            IF FOUND THEN made := made + 1; END IF;
        END IF;
        IF b.nte_pct >= th THEN
            INSERT INTO budget_alerts (engagement_id, kind, threshold_pct, value_at, budget_at) VALUES (p_engagement_id, 'nte', th, b.invoiced_total, b.nte_amount) ON CONFLICT DO NOTHING;
            IF FOUND THEN made := made + 1; END IF;
        END IF;
    END LOOP;
    IF b.ai_pct >= s.ai_budget_threshold_pct THEN
        INSERT INTO budget_alerts (engagement_id, kind, threshold_pct, value_at, budget_at) VALUES (p_engagement_id, 'ai', s.ai_budget_threshold_pct, b.ai_cost, b.ai_budget_amount) ON CONFLICT DO NOTHING;
        IF FOUND THEN made := made + 1; END IF;
    END IF;
    RETURN made;
END$$;

-- ---------------------------------------------------------------------------------------------
-- B9 — margin: bill value less cost (time at cost rate, expenses at amount, usage at cost). cost.view, and for a Manager
-- only the engagements they manage. The same nothing for everyone else.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_margin(p_from date, p_to date, p_engagement_id bigint DEFAULT NULL)
    RETURNS TABLE (engagement_id bigint, engagement_number text, engagement_name text, client_name text, currency char(3),
                   bill_value numeric, time_cost numeric, expense_cost numeric, usage_cost numeric, total_cost numeric, margin numeric, margin_pct numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH vis AS (SELECT e.* FROM engagements e
                  WHERE ct_has_right('cost.view') AND (p_engagement_id IS NULL OR e.id = p_engagement_id)
                    AND (ct_sees_all() OR e.id IN (SELECT ct_managed_engagement_ids()))),
    t AS (SELECT x.engagement_id, sum(round(x.hours * x.bill_rate, 2)) FILTER (WHERE x.billable AND x.status = 'approved') AS bill, sum(round(x.hours * COALESCE(x.cost_rate, 0), 2)) AS cost
            FROM time_entries x WHERE x.status IN ('approved', 'written_off') AND x.entry_date BETWEEN p_from AND p_to AND x.engagement_id IN (SELECT id FROM vis) GROUP BY x.engagement_id),
    x AS (SELECT engagement_id, sum(billable_amount) FILTER (WHERE billable) AS bill, sum(amount) AS cost FROM expenses
           WHERE status = 'approved' AND expense_date BETWEEN p_from AND p_to AND engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id),
    u AS (SELECT engagement_id, sum(billable_amount) FILTER (WHERE billable) AS bill, sum(cost_amount) AS cost FROM usage_charges
           WHERE status = 'approved' AND period_end BETWEEN p_from AND p_to AND engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id),
    m AS (SELECT engagement_id, sum(amount) AS bill FROM milestones WHERE status IN ('accepted', 'invoiced') AND accepted_at::date BETWEEN p_from AND p_to AND engagement_id IN (SELECT id FROM vis) GROUP BY engagement_id)
    SELECT v.id, v.number, v.name, c.name, v.currency,
           COALESCE(t.bill, 0) + COALESCE(x.bill, 0) + COALESCE(u.bill, 0) + COALESCE(m.bill, 0),
           COALESCE(t.cost, 0), COALESCE(x.cost, 0), COALESCE(u.cost, 0),
           COALESCE(t.cost, 0) + COALESCE(x.cost, 0) + COALESCE(u.cost, 0),
           COALESCE(t.bill, 0) + COALESCE(x.bill, 0) + COALESCE(u.bill, 0) + COALESCE(m.bill, 0) - COALESCE(t.cost, 0) - COALESCE(x.cost, 0) - COALESCE(u.cost, 0),
           CASE WHEN COALESCE(t.bill, 0) + COALESCE(x.bill, 0) + COALESCE(u.bill, 0) + COALESCE(m.bill, 0) > 0
                THEN round(100 * (COALESCE(t.bill, 0) + COALESCE(x.bill, 0) + COALESCE(u.bill, 0) + COALESCE(m.bill, 0) - COALESCE(t.cost, 0) - COALESCE(x.cost, 0) - COALESCE(u.cost, 0))
                           / (COALESCE(t.bill, 0) + COALESCE(x.bill, 0) + COALESCE(u.bill, 0) + COALESCE(m.bill, 0)), 1) END
      FROM vis v JOIN clients c ON c.id = v.client_id
      LEFT JOIN t ON t.engagement_id = v.id LEFT JOIN x ON x.engagement_id = v.id LEFT JOIN u ON u.engagement_id = v.id LEFT JOIN m ON m.engagement_id = v.id
     WHERE t.engagement_id IS NOT NULL OR x.engagement_id IS NOT NULL OR u.engagement_id IS NOT NULL OR m.engagement_id IS NOT NULL
     ORDER BY c.name, v.number;
$$;

-- ---------------------------------------------------------------------------------------------
-- B5 — who owes us, by client, in the five buckets (billing.write or reports.view).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_ar_aging(p_as_of date DEFAULT current_date)
    RETURNS TABLE (client_id bigint, client_name text, currency char(3), current_due numeric, d1_30 numeric, d31_60 numeric, d61_90 numeric, d90_plus numeric, total numeric, open_invoices integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.id, c.name, i.currency,
           sum(i.balance_due) FILTER (WHERE i.due_date >= p_as_of),
           sum(i.balance_due) FILTER (WHERE p_as_of - i.due_date BETWEEN 1 AND 30),
           sum(i.balance_due) FILTER (WHERE p_as_of - i.due_date BETWEEN 31 AND 60),
           sum(i.balance_due) FILTER (WHERE p_as_of - i.due_date BETWEEN 61 AND 90),
           sum(i.balance_due) FILTER (WHERE p_as_of - i.due_date > 90),
           sum(i.balance_due), count(*)::int
      FROM invoices i JOIN clients c ON c.id = i.client_id
     WHERE (ct_has_right('billing.write') OR ct_has_right('reports.view')) AND i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.issue_date <= p_as_of
     GROUP BY c.id, c.name, i.currency ORDER BY c.name, i.currency;
$$;

-- ---------------------------------------------------------------------------------------------
-- B4 — revenue: what was invoiced (issued, not void) in a period, by client | engagement | kind | practice | month.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_revenue(p_from date, p_to date, p_by text DEFAULT 'client')
    RETURNS TABLE (group_id text, group_label text, currency char(3), invoices integer, subtotal numeric, tax numeric, total numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE p_by WHEN 'engagement' THEN e.id::text WHEN 'kind' THEN l.kind WHEN 'practice' THEN COALESCE(i.department_id::text, '0')
                     WHEN 'month' THEN to_char(i.issue_date, 'YYYY-MM') ELSE c.id::text END,
           CASE p_by WHEN 'engagement' THEN e.number || ' ' || e.name WHEN 'kind' THEN l.kind WHEN 'practice' THEN COALESCE(d.name, 'No practice')
                     WHEN 'month' THEN to_char(i.issue_date, 'YYYY-MM') ELSE c.name END,
           i.currency, count(DISTINCT i.id)::int, sum(l.line_subtotal), sum(l.line_tax), sum(l.line_total)
      FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id JOIN clients c ON c.id = i.client_id JOIN engagements e ON e.id = i.engagement_id
      LEFT JOIN departments d ON d.id = i.department_id
     WHERE i.status NOT IN ('draft', 'void') AND i.issue_date BETWEEN p_from AND p_to
       AND (ct_has_right('reports.view') OR i.engagement_id IN (SELECT ct_managed_engagement_ids()))
     GROUP BY 1, 2, i.currency ORDER BY 2, i.currency;
$$;

-- ---------------------------------------------------------------------------------------------
-- U1 — AI pass-through: what the client's AI systems cost and what was billed for it, by engagement and provider.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_ai_passthrough(p_from date, p_to date, p_engagement_id bigint DEFAULT NULL)
    RETURNS TABLE (engagement_id bigint, engagement_number text, provider text, kind text, currency char(3), charges integer, tokens numeric,
                   cost_amount numeric, billable_amount numeric, billed_amount numeric, unbilled_amount numeric, markup numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT e.id, e.number, COALESCE(p.name, '—'), u.kind, u.currency, count(*)::int, sum(u.quantity) FILTER (WHERE u.unit = 'tokens'),
           sum(u.cost_amount), sum(u.billable_amount) FILTER (WHERE u.billable),
           sum(u.billable_amount) FILTER (WHERE u.billable AND ct_item_billed('usage_charge', u.id)),
           sum(u.billable_amount) FILTER (WHERE u.billable AND NOT ct_item_billed('usage_charge', u.id)),
           sum(u.billable_amount) FILTER (WHERE u.billable) - sum(u.cost_amount)
      FROM usage_charges u JOIN engagements e ON e.id = u.engagement_id LEFT JOIN usage_providers p ON p.id = u.provider_id
     WHERE u.status <> 'void' AND u.period_end BETWEEN p_from AND p_to AND (p_engagement_id IS NULL OR u.engagement_id = p_engagement_id)
       AND u.engagement_id IN (SELECT ct_visible_engagement_ids()) AND (ct_has_right('usage.write') OR ct_has_right('reports.view'))
     GROUP BY e.id, e.number, p.name, u.kind, u.currency ORDER BY e.number, 3;
$$;

-- ---------------------------------------------------------------------------------------------
-- T2 — missing time: who has not submitted a week, and which weekdays are empty (time.approve or reports.view).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_missing_time(p_week_start date DEFAULT NULL)
    RETURNS TABLE (member_id bigint, member_name text, week_start date, timesheet_id bigint, status text, hours_logged numeric, empty_weekdays integer, capacity_hours numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH ws AS (SELECT COALESCE(ct_week_start(p_week_start), ct_week_start(current_date) - 7) AS d),
    people AS (SELECT m.id, m.display_name FROM members m
                WHERE m.status = 'active' AND m.member_kind = 'human' AND m.capability IS NOT NULL AND NOT m.is_external
                  AND (ct_has_right('time.approve') OR ct_has_right('reports.view'))
                  AND ct_capacity(m.id, (SELECT d FROM ws)) > 0)
    SELECT p.id, p.display_name, (SELECT d FROM ws), t.id, COALESCE(t.status, 'none'),
           COALESCE((SELECT sum(hours) FROM time_entries x WHERE x.timesheet_id = t.id AND x.status <> 'rejected'), 0),
           (SELECT count(*)::int FROM generate_series(0, 4) g
             WHERE NOT EXISTS (SELECT 1 FROM time_entries x WHERE x.member_id = p.id AND x.entry_date = (SELECT d FROM ws) + g AND x.status <> 'rejected')),
           ct_capacity(p.id, (SELECT d FROM ws))
      FROM people p LEFT JOIN timesheets t ON t.member_id = p.id AND t.week_start = (SELECT d FROM ws)
     WHERE COALESCE(t.status, 'none') NOT IN ('submitted', 'approved')
     ORDER BY p.display_name;
$$;

-- B12 — a client's statement: invoices, credits, payments in date order with a running balance.
CREATE OR REPLACE FUNCTION ct_client_statement(p_client_id bigint, p_from date DEFAULT NULL, p_to date DEFAULT current_date)
    RETURNS TABLE (entry_date date, kind text, number text, description text, currency char(3), charge numeric, credit numeric, running_balance numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH rows_ AS (
        SELECT i.issue_date AS d, 'invoice' AS k, i.number AS n, 'Invoice ' || i.number || ' — ' || e.name AS descr, i.currency AS cur, i.total AS chg, 0::numeric AS cr, 1 AS ord
          FROM invoices i JOIN engagements e ON e.id = i.engagement_id WHERE i.client_id = p_client_id AND i.status NOT IN ('draft', 'void')
        UNION ALL
        SELECT c.issue_date, 'credit_note', c.number, 'Credit note ' || c.number || COALESCE(' — ' || c.reason, ''), c.currency, 0, c.total, 2
          FROM credit_notes c WHERE c.client_id = p_client_id AND c.status = 'issued'
        UNION ALL
        SELECT p.paid_on, 'payment', i.number, 'Payment on ' || i.number || COALESCE(' (' || p.reference || ')', ''), p.currency, 0, p.amount, 3
          FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.client_id = p_client_id AND p.status = 'recorded'
        UNION ALL
        SELECT i.written_off_at::date, 'write_off', i.number, 'Written off ' || i.number, i.currency, 0, i.total - i.amount_paid - i.amount_credited, 4
          FROM invoices i WHERE i.client_id = p_client_id AND i.status = 'written_off')
    SELECT r.d, r.k, r.n, r.descr, r.cur, r.chg, r.cr, sum(r.chg - r.cr) OVER (PARTITION BY r.cur ORDER BY r.d, r.ord, r.n ROWS UNBOUNDED PRECEDING)
      FROM rows_ r
     WHERE (ct_has_right('billing.write') OR ct_has_right('reports.view')) AND r.d <= p_to AND (p_from IS NULL OR r.d >= p_from)
     ORDER BY r.cur, r.d, r.ord, r.n;
$$;

GRANT EXECUTE ON FUNCTION ct_time_bill_value(numeric, numeric), ct_unbilled(date, bigint, bigint), ct_unbilled_items(bigint, date), ct_utilization(date, date, bigint, bigint),
    ct_realization(date, date, bigint), ct_budget_status(bigint), ct_margin(date, date, bigint), ct_ar_aging(date), ct_revenue(date, date, text),
    ct_ai_passthrough(date, date, bigint), ct_missing_time(date), ct_client_statement(bigint, date, date) TO consultant_tracking_rw, consultant_tracking_records_ro;
REVOKE ALL ON FUNCTION ct_budget_alerts_check(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_budget_alerts_check(bigint) TO consultant_tracking_rw;

COMMIT;
