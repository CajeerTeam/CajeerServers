-- 0.4.0: server-side proof that a pending Vault secret passed a real connector check.
-- Proofs contain only IDs, revisions and timestamps. No tokens, responses or endpoints.
CREATE TABLE vault_rotation_validations (
    credential_id uuid NOT NULL,
    secret_version bigint NOT NULL,
    connector_id uuid NOT NULL REFERENCES connector_targets(id) ON DELETE CASCADE,
    credential_version bigint NOT NULL CHECK (credential_version > 0),
    connector_version bigint NOT NULL CHECK (connector_version > 0),
    validated_by uuid REFERENCES users(id) ON DELETE SET NULL,
    validated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (credential_id, secret_version, connector_id),
    FOREIGN KEY (credential_id, secret_version)
        REFERENCES vault_credential_versions(credential_id, secret_version) ON DELETE CASCADE
);
CREATE INDEX vault_rotation_validations_recent_idx
    ON vault_rotation_validations(credential_id, secret_version, validated_at DESC);
