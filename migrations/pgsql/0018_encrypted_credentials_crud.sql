-- Secure metadata and lifecycle for CJV1 credentials. Never rewrite legacy ciphertext.
ALTER TABLE credentials
    ADD COLUMN workspace_id uuid REFERENCES workspaces(id) ON DELETE RESTRICT,
    ADD COLUMN description varchar(1024) NOT NULL DEFAULT '',
    ADD COLUMN vault_format varchar(16) NOT NULL DEFAULT 'legacy',
    ADD COLUMN state varchar(16) NOT NULL DEFAULT 'legacy',
    ADD COLUMN version bigint NOT NULL DEFAULT 1,
    ADD COLUMN created_by uuid REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN updated_by uuid REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN deleted_at timestamptz,
    ADD CONSTRAINT credentials_vault_version_chk CHECK(version >= 1),
    ADD CONSTRAINT credentials_vault_format_chk CHECK(vault_format IN ('legacy','cjev1')),
    ADD CONSTRAINT credentials_vault_state_chk CHECK(state IN ('legacy','active','disabled','archived')),
    ADD CONSTRAINT credentials_vault_metadata_chk CHECK(jsonb_typeof(metadata)='object'),
    ADD CONSTRAINT credentials_vault_consistency_chk CHECK(
        (vault_format='legacy' AND state='legacy' AND workspace_id IS NULL)
        OR (vault_format='cjev1' AND state IN ('active','disabled','archived') AND workspace_id IS NOT NULL
            AND substring(encrypted_payload from 1 for 4)=decode('434a5631','hex'))
    );
CREATE INDEX credentials_vault_workspace_list_idx ON credentials(workspace_id,created_at DESC,id)
    WHERE vault_format='cjev1' AND deleted_at IS NULL;
CREATE INDEX credentials_vault_workspace_state_idx ON credentials(workspace_id,state)
    WHERE vault_format='cjev1';
-- Immutable AAD context. Prevent transforming a legacy row or swapping workspaces/types via raw SQL.
CREATE OR REPLACE FUNCTION cajeer_vault_credential_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
        OR NEW.type IS DISTINCT FROM OLD.type OR NEW.vault_format IS DISTINCT FROM OLD.vault_format THEN
        RAISE EXCEPTION 'Credential cryptographic identity cannot change' USING ERRCODE='23514';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        RAISE EXCEPTION 'Credential version must increment by one' USING ERRCODE='23514';
    END IF;
    IF OLD.deleted_at IS NOT NULL THEN
        RAISE EXCEPTION 'Archived credential cannot be changed' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER credentials_vault_guard BEFORE UPDATE ON credentials
    FOR EACH ROW EXECUTE FUNCTION cajeer_vault_credential_guard();
-- Grant only the current administrator role; other roles receive no implicit privilege.
INSERT INTO permissions(scope,description) VALUES
 ('vault.credentials.read','View permitted Vault credential metadata'),
 ('vault.credentials.create','Create encrypted Vault credentials'),
 ('vault.credentials.update','Update or replace Vault credentials'),
 ('vault.credentials.delete','Archive Vault credentials')
ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator'
AND p.scope IN ('vault.credentials.read','vault.credentials.create','vault.credentials.update','vault.credentials.delete')
ON CONFLICT DO NOTHING;
