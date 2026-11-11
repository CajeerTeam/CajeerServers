-- Strictly session-bound one-use internal secret consumption. No plaintext storage.
CREATE TABLE vault_use_grants (
 session_id uuid NOT NULL REFERENCES auth_sessions(id) ON DELETE CASCADE,
 actor_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 asset_id uuid NOT NULL,
 credential_id uuid NOT NULL,
 workspace_id uuid NOT NULL,
 purpose varchar(64) NOT NULL CHECK (purpose ~ '^[a-z][a-z0-9_.-]{0,63}$'),
 credential_version bigint NOT NULL CHECK (credential_version >= 1),
 issued_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 PRIMARY KEY(session_id,asset_id,credential_id,purpose),
 FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON UPDATE RESTRICT ON DELETE CASCADE,
 FOREIGN KEY(credential_id,workspace_id) REFERENCES inventory_credential_scopes(credential_id,workspace_id) ON UPDATE RESTRICT ON DELETE CASCADE,
 CHECK (expires_at > issued_at)
);
CREATE INDEX vault_use_grants_expiry_idx ON vault_use_grants(expires_at);
-- Independent permission: metadata visibility does NOT authorize decryption.
INSERT INTO permissions(scope,description)
VALUES('vault.credentials.use','Step-up authorized internal use of a scoped credential')
ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug='administrator' AND p.scope='vault.credentials.use'
ON CONFLICT DO NOTHING;
