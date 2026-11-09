-- 0.1.9: grant operational dashboard and diagnostic rights to the administrator role.
-- RBAC remains deny-by-default for other roles. No database credentials or secrets are stored here.
INSERT INTO permissions (scope, description) VALUES
 ('admin.dashboard.read', 'Read operational dashboard and dependency checks'),
 ('admin.jobs.read', 'Read allowed queue job statuses'),
 ('admin.jobs.execute', 'Dispatch allowlisted diagnostic jobs'),
 ('admin.scheduler.read', 'Read scheduler tasks and operational statistics')
ON CONFLICT (scope) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='administrator' AND p.scope IN (
 'admin.dashboard.read','admin.jobs.read','admin.jobs.execute','admin.scheduler.read'
)
ON CONFLICT DO NOTHING;
