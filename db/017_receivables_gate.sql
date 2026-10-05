-- 017: who owes us is firm-wide (2026-10-05, found by slice 6's proofs). ct_ar_aging() and ct_client_statement() admitted anyone with
-- reports.view, so a Manager — whose reports.view is their own engagements' (design §3) — could read every client's balances through the
-- records MCP. Both now need billing.write, or reports.view across every engagement (Finance, the admin): a partial aging or a partial
-- statement would mislead, so neither is scoped down to a Manager's engagements. The screens already gate on the same rule
-- (`receivables.view` in app/auth.php). Additive: two functions redefined.
BEGIN;

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
     WHERE (ct_has_right('billing.write') OR (ct_has_right('reports.view') AND ct_sees_all())) AND i.status IN ('issued', 'sent', 'viewed', 'partially_paid') AND i.issue_date <= p_as_of
     GROUP BY c.id, c.name, i.currency ORDER BY c.name, i.currency;
$$;

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
     WHERE (ct_has_right('billing.write') OR (ct_has_right('reports.view') AND ct_sees_all())) AND r.d <= p_to AND (p_from IS NULL OR r.d >= p_from)
     ORDER BY r.cur, r.d, r.ord, r.n;
$$;

COMMIT;
