-- Credential secret revisions and atomic offline master-key rewrapping.
-- Current-row version is optimistic-lock version; secret_version changes ONLY when the secret changes.
ALTER TABLE credentials ADD COLUMN secret_version bigint NOT NULL DEFAULT 1
  CHECK(secret_version >= 1);

CREATE TABLE vault_credential_versions (
    credential_id uuid NOT NULL REFERENCES credentials(id) ON DELETE RESTRICT,
    secret_version bigint NOT NULL CHECK (secret_version >= 1),
    state varchar(12) NOT NULL CHECK (state IN ('active','pending','retired','discarded')),
    encrypted_payload bytea NOT NULL CHECK(octet_length(encrypted_payload) >= 132
        AND substring(encrypted_payload FROM 1 FOR 4) = decode('434a5631','hex')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    activated_at timestamptz,
    retired_at timestamptz,
    created_by uuid REFERENCES users(id) ON DELETE SET NULL,
    PRIMARY KEY (credential_id, secret_version),
    CHECK ((state <> 'active' OR activated_at IS NOT NULL)
       AND (state NOT IN ('retired','discarded') OR retired_at IS NOT NULL))
);
CREATE UNIQUE INDEX vault_credential_one_active ON vault_credential_versions(credential_id)
  WHERE state = 'active';
CREATE UNIQUE INDEX vault_credential_one_pending ON vault_credential_versions(credential_id)
  WHERE state = 'pending';
CREATE INDEX vault_credential_versions_recent ON vault_credential_versions(credential_id,secret_version DESC);

-- Existing live and archived encrypted records retain exact ciphertext and UUIDs.
INSERT INTO vault_credential_versions(credential_id,secret_version,state,encrypted_payload,activated_at,retired_at,created_by)
SELECT id,1,CASE WHEN deleted_at IS NOT NULL THEN 'retired' ELSE 'active' END,
       encrypted_payload,created_at,CASE WHEN deleted_at IS NOT NULL THEN deleted_at ELSE NULL END,created_by
FROM credentials WHERE vault_format='cjev1';

-- The 0.3.1 verifier table remains immutable; rotation uses a separate active verifier.
CREATE TABLE vault_key_state (
    id smallint PRIMARY KEY CHECK (id=1),
    key_id varchar(32) NOT NULL CHECK(key_id ~ '^[0-9a-f]{32}$'),
    verifier bytea NOT NULL CHECK (octet_length(verifier) >= 132),
    generation bigint NOT NULL DEFAULT 1 CHECK(generation > 0),
    changed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
INSERT INTO vault_key_state(id,key_id,verifier)
SELECT 1,key_id,verifier FROM vault_key_verifiers WHERE id=1;

CREATE TABLE vault_key_rotation_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    from_key_id varchar(32) NOT NULL,
    to_key_id varchar(32) NOT NULL,
    credentials_rewrapped integer NOT NULL CHECK(credentials_rewrapped >= 0),
    versions_rewrapped integer NOT NULL CHECK(versions_rewrapped >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CHECK(from_key_id <> to_key_id)
);

-- Preserve strict credential identity and optimistic locking during normal operations.
-- A CLI-only, offline key operation changes ONLY the encrypted envelope, not its revision.
CREATE OR REPLACE FUNCTION cajeer_vault_credential_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
       OR NEW.type IS DISTINCT FROM OLD.type OR NEW.vault_format IS DISTINCT FROM OLD.vault_format THEN
        RAISE EXCEPTION 'Credential cryptographic identity cannot change' USING ERRCODE='23514';
    END IF;
    IF current_setting('cajeer.vault_rewrap',true)='on'
       AND to_jsonb(NEW)-'encrypted_payload'=to_jsonb(OLD)-'encrypted_payload'
       AND NEW.encrypted_payload IS DISTINCT FROM OLD.encrypted_payload THEN
        RETURN NEW;
    END IF;
    IF NEW.version <> OLD.version+1 THEN
        RAISE EXCEPTION 'Credential version must increment by one' USING ERRCODE='23514';
    END IF;
    IF OLD.deleted_at IS NOT NULL THEN
        RAISE EXCEPTION 'Archived credential cannot be changed' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;

-- Fail closed for stale web/queue processes that started before a key rotation.
-- No CJV1 INSERT or UPDATE may introduce ciphertext with the previous key ID.
CREATE FUNCTION cajeer_vault_active_cipher_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected varchar(32);
BEGIN
    IF NEW.vault_format <> 'cjev1' THEN RETURN NEW; END IF;
    IF TG_OP='UPDATE' THEN
        IF NEW.encrypted_payload IS NOT DISTINCT FROM OLD.encrypted_payload THEN RETURN NEW; END IF;
    END IF;
    IF current_setting('cajeer.vault_rewrap',true)='on' THEN RETURN NEW; END IF;
    SELECT key_id INTO expected FROM vault_key_state WHERE id=1;
    IF expected IS NULL OR encode(substring(NEW.encrypted_payload FROM 5 FOR 16),'hex') <> expected THEN
        RAISE EXCEPTION 'Vault credential master key is not active' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER zz_vault_active_cipher_guard BEFORE INSERT OR UPDATE ON credentials
FOR EACH ROW EXECUTE FUNCTION cajeer_vault_active_cipher_guard();

CREATE FUNCTION cajeer_vault_version_cipher_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected varchar(32);
BEGIN
    IF current_setting('cajeer.vault_rewrap',true)='on' THEN RETURN NEW; END IF;
    IF TG_OP='UPDATE' THEN
        IF NEW.encrypted_payload IS DISTINCT FROM OLD.encrypted_payload THEN
            RAISE EXCEPTION 'Cannot overwrite an existing encrypted secret version' USING ERRCODE='23514';
        END IF;
    END IF;
    SELECT key_id INTO expected FROM vault_key_state WHERE id=1;
    IF expected IS NULL OR encode(substring(NEW.encrypted_payload FROM 5 FOR 16),'hex') <> expected THEN
        RAISE EXCEPTION 'Vault secret version key is not active' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER zz_vault_version_cipher_guard BEFORE INSERT OR UPDATE ON vault_credential_versions
FOR EACH ROW EXECUTE FUNCTION cajeer_vault_version_cipher_guard();
