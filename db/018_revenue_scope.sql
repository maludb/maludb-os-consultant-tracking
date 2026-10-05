-- 018: revenue is scoped like every other report (2026-10-05, found by slice 7's proofs). ct_revenue() admitted any holder of
-- reports.view to every invoice — a Manager read the whole firm's revenue. It now needs reports.view AND answers only the
-- invoices of engagements the caller may see (ct_visible_engagement_ids: all for Billing, Finance and the admin; a Manager's own,
-- staffed and practice engagements). Additive: one function redefined. The revenue screen opens to reports.view again.
BEGIN;

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
       AND ct_has_right('reports.view') AND i.engagement_id IN (SELECT ct_visible_engagement_ids())     -- db/018: reports.view is the firm's reports across the engagements the caller may see
     GROUP BY 1, 2, i.currency ORDER BY 2, i.currency;
$$;

COMMIT;
