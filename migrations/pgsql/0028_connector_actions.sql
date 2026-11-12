-- Durable intent precedes remote POST. Never automatically retry a write action.
CREATE TABLE connector_actions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), target_id uuid NOT NULL, workspace_id uuid NOT NULL,
 actor_id uuid NOT NULL, credential_id uuid NOT NULL, credential_version integer NOT NULL, target_version integer NOT NULL,
 action varchar(32) NOT NULL CHECK(action IN ('vm.start','vm.shutdown')),
 external_id varchar(180) NOT NULL, idempotency_key varchar(128) NOT NULL,
 status varchar(20) NOT NULL CHECK(status IN ('dispatching','submitted','succeeded','failed','unknown','acknowledged')),
 node varchar(120), remote_task varchar(512), error_code varchar(64),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(target_id,idempotency_key)
);
CREATE UNIQUE INDEX connector_actions_unresolved ON connector_actions(target_id,external_id) WHERE status IN ('dispatching','submitted','unknown');
CREATE INDEX connector_actions_history ON connector_actions(target_id,created_at DESC,id);
INSERT INTO permissions(scope,description) VALUES ('connectors.actions.read','Read remote action history'),('connectors.actions.execute','Execute explicitly confirmed remote power actions') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope IN ('connectors.actions.read','connectors.actions.execute') ON CONFLICT DO NOTHING;
