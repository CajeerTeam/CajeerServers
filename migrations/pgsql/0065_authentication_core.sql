-- Preserve existing cookies, hashes, expiry and MFA state. Existing sessions were password sessions.
CREATE TABLE auth_external_identities (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    issuer text NOT NULL CHECK (octet_length(issuer) BETWEEN 1 AND 2048),
    subject text NOT NULL CHECK (octet_length(subject) BETWEEN 1 AND 255),
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (issuer, subject),
    UNIQUE (id, user_id)
);
CREATE INDEX auth_external_identities_user_idx ON auth_external_identities(user_id);

ALTER TABLE auth_sessions ADD COLUMN auth_method text NOT NULL DEFAULT 'password';
ALTER TABLE auth_sessions ADD COLUMN authenticated_at timestamptz;
UPDATE auth_sessions SET authenticated_at = created_at;
ALTER TABLE auth_sessions ALTER COLUMN authenticated_at SET NOT NULL;
ALTER TABLE auth_sessions ALTER COLUMN authenticated_at SET DEFAULT now();
ALTER TABLE auth_sessions ADD COLUMN external_identity_id uuid;
ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_method_check CHECK (
    (auth_method = 'password' AND external_identity_id IS NULL) OR
    (auth_method = 'external' AND external_identity_id IS NOT NULL)
);
ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_external_identity_fk
    FOREIGN KEY (external_identity_id, user_id) REFERENCES auth_external_identities(id, user_id) ON DELETE CASCADE;
CREATE INDEX auth_sessions_external_identity_idx ON auth_sessions(external_identity_id) WHERE external_identity_id IS NOT NULL;

-- A provider assertion may be exchanged once, even if it fails local MFA.
-- Store only its SHA-256 digest, never a provider token or authorization code.
CREATE TABLE auth_external_assertions (
    issuer text NOT NULL CHECK (octet_length(issuer) BETWEEN 1 AND 2048),
    assertion_digest char(64) NOT NULL CHECK (assertion_digest ~ '^[a-f0-9]{64}$'),
    expires_at timestamptz NOT NULL,
    PRIMARY KEY (issuer, assertion_digest)
);
CREATE INDEX auth_external_assertions_expiry_idx ON auth_external_assertions(expires_at);

-- Keep every existing privileged session check effective when an identity is disabled.
-- A re-enabled identity must authenticate again; it cannot revive old sessions.
CREATE FUNCTION auth_revoke_disabled_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.user_id IS DISTINCT FROM OLD.user_id
        OR NEW.issuer IS DISTINCT FROM OLD.issuer OR NEW.subject IS DISTINCT FROM OLD.subject THEN
        RAISE EXCEPTION 'External identity binding is immutable; remove and re-create it instead';
    END IF;
    IF OLD.enabled AND NOT NEW.enabled THEN
        UPDATE auth_sessions SET revoked_at = clock_timestamp()
        WHERE external_identity_id = OLD.id AND revoked_at IS NULL;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER auth_external_identity_disabled AFTER UPDATE ON auth_external_identities
FOR EACH ROW EXECUTE FUNCTION auth_revoke_disabled_identity();
