ALTER TABLE connector_actions DROP CONSTRAINT connector_actions_action_check;
ALTER TABLE connector_actions ADD CONSTRAINT connector_actions_action_check CHECK(action IN ('vm.start','vm.shutdown','vm.stop','vm.reboot','lxc.start','lxc.shutdown','lxc.stop','lxc.reboot','vm.snapshot.create','vm.snapshot.delete','vm.snapshot.rollback','lxc.snapshot.create','lxc.snapshot.delete','lxc.snapshot.rollback'));
ALTER TABLE connector_actions ADD COLUMN snapshot_name varchar(40), ADD COLUMN snapshot_revision varchar(64);
ALTER TABLE connector_actions ADD CONSTRAINT connector_actions_snapshot_check CHECK (
 (action NOT LIKE '%.snapshot.%' AND snapshot_name IS NULL AND snapshot_revision IS NULL) OR
 (action LIKE '%.snapshot.%' AND snapshot_name IS NOT NULL AND snapshot_name ~ '^[A-Za-z][A-Za-z0-9_-]{0,39}$' AND lower(snapshot_name) NOT IN ('current','vzdump','pending') AND
 ((action LIKE '%.create' AND snapshot_revision IS NULL) OR (action NOT LIKE '%.create' AND snapshot_revision IS NOT NULL AND snapshot_revision ~ '^[a-f0-9]{64}$'))));

-- Existing power operators must not gain destructive snapshot or console access implicitly.
INSERT INTO permissions(scope,description) VALUES ('connectors.snapshots.execute','Execute explicitly confirmed remote snapshot actions'),('connectors.console.open','Request native Proxmox guest console link') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope IN ('connectors.snapshots.execute','connectors.console.open') ON CONFLICT DO NOTHING;
