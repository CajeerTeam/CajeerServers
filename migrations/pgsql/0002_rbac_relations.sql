-- Keep authorization data relational: references and cascades are enforced by PostgreSQL.
CREATE TABLE user_roles (
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    assigned_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, role_id)
);
CREATE INDEX user_roles_role_id_idx ON user_roles (role_id);

CREATE TABLE role_permissions (
    role_id uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id uuid NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);
CREATE INDEX role_permissions_permission_id_idx ON role_permissions (permission_id);

CREATE UNIQUE INDEX api_tokens_token_hash_unique ON api_tokens (token_hash);
CREATE INDEX api_tokens_user_id_idx ON api_tokens (user_id);
CREATE INDEX api_tokens_expires_at_idx ON api_tokens (expires_at);
CREATE INDEX connectors_credential_id_idx ON connectors (credential_id);
CREATE INDEX sync_jobs_connector_id_idx ON sync_jobs (connector_id);
CREATE INDEX action_requests_actor_user_id_idx ON action_requests (actor_user_id);
CREATE INDEX action_requests_target_asset_id_idx ON action_requests (target_asset_id);
CREATE INDEX audit_log_created_at_idx ON audit_log (created_at DESC);
CREATE INDEX audit_log_actor_idx ON audit_log (actor_type, actor_id, created_at DESC);
