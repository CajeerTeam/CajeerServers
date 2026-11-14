CREATE TABLE network_configuration_backups (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 target_id uuid NOT NULL, workspace_id uuid NOT NULL,
 version bigint NOT NULL CHECK(version>0), target_version bigint NOT NULL,
 credential_id uuid NOT NULL, credential_version bigint NOT NULL,
 identity_hash varchar(64) NOT NULL, sha256 varchar(64) NOT NULL, content_hash varchar(64) NOT NULL,
 byte_size integer NOT NULL CHECK(byte_size BETWEEN 1 AND 1048576),
 encrypted_payload bytea NOT NULL, observed_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(target_id,version), FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
CREATE INDEX network_configuration_backups_history ON network_configuration_backups(target_id,version DESC);
INSERT INTO permissions(scope,description) VALUES ('network.configuration.read','Read and export sensitive network configuration backups'),('network.configuration.backup','Collect network configuration backups') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope IN ('network.configuration.read','network.configuration.backup') ON CONFLICT DO NOTHING;
