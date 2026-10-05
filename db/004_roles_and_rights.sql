-- 004: Consultant Tracking's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the
-- claims and the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- ct_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose: the installer's
-- --grant-standing-departments gives the standing departments the role keyed member/user/write, and in a consultancy
-- EVERY insider logs time (D3) — so the switch is right here.
--
-- Rights narrow by RECORD, not by role alone (design §3): a Consultant sees the engagements they are STAFFED on and their
-- own time and expenses; a Manager the engagements they manage or whose practice they administer; Billing, Finance and
-- admin everything. ct_can_see_engagement() (db/008) is that rule; cost.view reaches a manager's own engagements' margin
-- only, and the cost-rate table itself is the admin's (db/007).
BEGIN;

CREATE TABLE ct_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE ct_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX ct_roles_one_admin ON ct_roles (is_admin) WHERE is_admin;
CREATE TABLE ct_role_rights (
    role_key  text NOT NULL REFERENCES ct_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES ct_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO ct_rights (right_key, description, sort_order) VALUES
 ('time.write',         'Log time on the engagements they are staffed on; submit their own week', 1),
 ('expenses.write',     'Record and submit their own expenses, with receipts', 2),
 ('engagements.view',   'See the engagements they are staffed on: client, dates, budget burn (never cost)', 3),
 ('attachments.write',  'Attach files to their own records', 4),
 ('reports.view_own',   'See their own hours, expenses and reimbursements', 5),
 ('engagements.manage', 'Create and change the engagements they manage or whose practice they administer', 6),
 ('staffing.write',     'Staff people on their engagements, with a role and a rate override', 7),
 ('time.approve',       'Approve or reject submitted timesheets on their engagements; see team time; write off hours', 8),
 ('expenses.approve',   'Approve or reject submitted expenses on their engagements', 9),
 ('usage.write',        'Record and import hosting and model-usage charges; run schedules; allocate the OS statement', 10),
 ('usage.approve',      'Approve usage charges for billing', 11),
 ('milestones.write',   'Add, deliver and accept milestones on their engagements', 12),
 ('rates.view',         'See bill rates and rate cards', 13),
 ('cost.view',          'See cost rates on their own engagements and the margin they make (the rate table itself is the admin''s)', 14),
 ('invoices.draft',     'Draft invoices from unbilled work on their engagements', 15),
 ('reports.view',       'See the firm''s reports across every engagement they may see', 16),
 ('clients.manage',     'Create and change clients and their contacts', 17),
 ('rate_cards.manage',  'Create rate cards and their rates', 18),
 ('billing.write',      'Issue, send, remind, credit, void and write off invoices; record payments; confirm claims; mark reimbursements paid', 19),
 ('exports.download',   'Download the exports (invoices, expenses, WIP, time, usage)', 20),
 ('settings.manage',    'Run Consultant Tracking: settings, consulting roles, cost rates, capacity, categories, providers, allocation rules, numbering, agents', 21);

INSERT INTO ct_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('user',     'Consultant', 'Logs time and expenses on the engagements they are staffed on. Everyone who works here.', 'write', false, 1),
 ('manager',  'Manager',    'Owns engagements: staffs them, approves time and expenses, records usage, watches budgets, drafts invoices; sees cost and margin on their own.', 'write', false, 2),
 ('billing',  'Billing',    'Clients, rate cards and every engagement; issues, sends and settles invoices; exports.', 'write', false, 3),
 ('finance',  'Finance',    'Reads everything, cost and margin included; downloads every export; changes nothing. An external accountant''s role.', 'read', false, 4),
 ('admin',    'CT admin',   'Runs Consultant Tracking: everything Billing does, plus the settings and the cost rates. A super-admin holds this role.', 'admin', true, 5);

INSERT INTO ct_role_rights (role_key, right_key)
SELECT 'user', r FROM unnest(ARRAY['time.write','expenses.write','engagements.view','attachments.write','reports.view_own']) r
UNION ALL SELECT 'manager', r FROM unnest(ARRAY['time.write','expenses.write','engagements.view','attachments.write','reports.view_own',
                                                 'engagements.manage','staffing.write','time.approve','expenses.approve','usage.write','usage.approve',
                                                 'milestones.write','rates.view','cost.view','invoices.draft','reports.view']) r
UNION ALL SELECT 'billing', r FROM unnest(ARRAY['time.write','expenses.write','engagements.view','attachments.write','reports.view_own',
                                                 'engagements.manage','staffing.write','time.approve','expenses.approve','usage.write','usage.approve',
                                                 'milestones.write','rates.view','cost.view','invoices.draft','reports.view',
                                                 'clients.manage','rate_cards.manage','billing.write','exports.download']) r
UNION ALL SELECT 'finance', r FROM unnest(ARRAY['engagements.view','reports.view_own','rates.view','cost.view','reports.view','exports.download']) r
UNION ALL SELECT 'admin', right_key FROM ct_rights;

-- The rule: a right the member's roles give. The member must be active and admitted. A member the kernel has not yet
-- sent roles for (roles = '{}') is read from the capability: admin = admin, anything else = user. A super-admin holds
-- every right (the kernel lists the admin role for them; this is the belt to that brace).
CREATE OR REPLACE FUNCTION ct_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM members m
          JOIN ct_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (m.roles)
                    OR (cardinality(m.roles) = 0 AND rr.role_key = CASE m.capability WHEN 'admin' THEN 'admin' ELSE 'user' END)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin'))
         WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

-- The caller holds a role that reaches EVERY engagement (Billing, Finance, admin, the super-admin) — the wall-less set.
CREATE OR REPLACE FUNCTION ct_sees_all() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM members m
         WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL
           AND ((m.member_kind = 'human' AND m.business_role = 'super_admin')
                OR m.roles && ARRAY['billing', 'finance', 'admin']
                OR (cardinality(m.roles) = 0 AND m.capability = 'admin')));
END$$;

-- Runs Consultant Tracking: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION ct_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND ct_has_right('settings.manage'));
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM ct_role_rights rr
                   JOIN ct_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM ct_roles r;

GRANT SELECT ON ct_rights, ct_roles, ct_role_rights, mcp_app_roles TO consultant_tracking_rw, consultant_tracking_records_ro;
GRANT EXECUTE ON FUNCTION ct_has_right(text), ct_sees_all(), ct_is_admin() TO consultant_tracking_rw, consultant_tracking_records_ro, consultant_tracking_activity_ro;

COMMIT;
