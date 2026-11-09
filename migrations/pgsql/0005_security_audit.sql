-- 0.1.6 Security: transactional RBAC, replay-resistant MFA and security telemetry.
-- Existing 0.1.5 credentials remain usable; MFA activation revokes old sessions/tokens.
INSERT INTO permissions (scope, description) VALUES
 ('security.users.read', 'Read user directory'),
 ('security.users.manage', 'Create users and change their status'),
 ('security.roles.read', 'Read roles and assigned permissions'),
 ('security.roles.manage', 'Manage role grants and memberships'),
 ('security.roles.grant_administrator', 'Grant or revoke high-privilege administrator role'),
 ('security.audit.read', 'Read security audit trail'),
 ('security.webhooks.manage', 'Manage signed webhook sources')
ON CONFLICT (scope) DO NOTHING;

INSERT INTO roles (slug, name) VALUES ('administrator', 'Администратор')
ON CONFLICT (slug) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'administrator'
ON CONFLICT DO NOTHING;

ALTER TABLE auth_sessions ADD COLUMN mfa_verified boolean NOT NULL DEFAULT false;
ALTER TABLE api_tokens ADD COLUMN mfa_verified boolean NOT NULL DEFAULT false;

CREATE TABLE user_mfa (
 user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
 secret_ciphertext bytea NOT NULL,
 enabled_at timestamptz,
 last_counter bigint,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE user_mfa_recovery (
 user_id uuid NOT NULL REFERENCES user_mfa(user_id) ON DELETE CASCADE,
 code_hash char(64) NOT NULL,
 used_at timestamptz,
 PRIMARY KEY (user_id, code_hash)
);

CREATE TABLE security_rate_limits (
 subject_hash char(64) PRIMARY KEY,
 hits integer NOT NULL CHECK (hits > 0),
 expires_at timestamptz NOT NULL
);
CREATE INDEX security_rate_limits_expiry_idx ON security_rate_limits (expires_at);

ALTER TABLE audit_log ADD COLUMN request_id varchar(64);
CREATE INDEX audit_log_keyset_idx ON audit_log (created_at DESC, id DESC);
CREATE OR REPLACE FUNCTION cajeer_audit_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'audit_log is append-only';
END
$$;
CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log
FOR EACH ROW EXECUTE FUNCTION cajeer_audit_append_only();

CREATE TABLE webhook_sources (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 name varchar(100) NOT NULL,
 secret_ciphertext bytea NOT NULL,
 enabled boolean NOT NULL DEFAULT true,
 created_by uuid REFERENCES users(id) ON DELETE SET NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 revoked_at timestamptz
);
CREATE TABLE webhook_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 source_id uuid NOT NULL REFERENCES webhook_sources(id) ON DELETE RESTRICT,
 replay_digest char(64) NOT NULL UNIQUE,
 payload jsonb NOT NULL,
 received_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX webhook_events_source_idx ON webhook_events (source_id, received_at DESC);
