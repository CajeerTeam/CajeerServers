-- 0.3.1 Vault crypto key enrollment. Root key is NEVER stored in PostgreSQL.
CREATE TABLE vault_key_verifiers (
    id smallint PRIMARY KEY CHECK (id = 1),
    key_id varchar(32) NOT NULL CHECK (key_id ~ '^[0-9a-f]{32}$'),
    verifier bytea NOT NULL CHECK (octet_length(verifier) >= 4 + 16 + 24 + 48 + 24 + 16),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE FUNCTION vault_verifier_forbid_mutations() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Vault key verifier is immutable; never overwrite enrollment';
END;
$$;

CREATE TRIGGER vault_verifier_immutable BEFORE UPDATE OR DELETE ON vault_key_verifiers
FOR EACH ROW EXECUTE FUNCTION vault_verifier_forbid_mutations();
CREATE TRIGGER vault_verifier_no_truncate BEFORE TRUNCATE ON vault_key_verifiers
FOR EACH STATEMENT EXECUTE FUNCTION vault_verifier_forbid_mutations();
