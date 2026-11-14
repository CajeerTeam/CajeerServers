-- Preserve existing PVE actions; authorize VMmanager writes independently.
ALTER TABLE connector_actions DROP CONSTRAINT connector_actions_action_check;
ALTER TABLE connector_actions ADD CONSTRAINT connector_actions_action_check CHECK(action IN ('vm.start','vm.shutdown','vm.stop','vm.reboot','lxc.start','lxc.shutdown','lxc.stop','lxc.reboot','vm.snapshot.create','vm.snapshot.delete','vm.snapshot.rollback','lxc.snapshot.create','lxc.snapshot.delete','lxc.snapshot.rollback','vmmanager.host.start','vmmanager.host.stop','vmmanager.host.reboot'));
INSERT INTO permissions(scope,description) VALUES ('connectors.vmmanager.actions.execute','Execute explicitly confirmed VMmanager guest power operations') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope='connectors.vmmanager.actions.execute' ON CONFLICT DO NOTHING;
