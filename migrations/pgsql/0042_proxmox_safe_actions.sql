-- Preserve all historical action rows and expand the closed action vocabulary.
ALTER TABLE connector_actions DROP CONSTRAINT connector_actions_action_check;
ALTER TABLE connector_actions ADD CONSTRAINT connector_actions_action_check CHECK(action IN ('vm.start','vm.shutdown','vm.stop','vm.reboot','lxc.start','lxc.shutdown','lxc.stop','lxc.reboot'));
-- Fair bounded polling even when a task status is inaccessible or no longer retained.
ALTER TABLE connector_actions ADD COLUMN task_checked_at timestamptz;
CREATE INDEX connector_actions_pending_tasks ON connector_actions(target_id,task_checked_at ASC NULLS FIRST,created_at,id) WHERE status='submitted';
