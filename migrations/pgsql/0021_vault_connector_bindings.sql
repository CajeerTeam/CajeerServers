-- 0.3.6: enforce credential scope/purpose and bind active credentials to stored connector configurations.
-- Connector configurations are metadata, not a claim of working remote connections.
CREATE TABLE connector_targets (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
 provider varchar(20) NOT NULL CHECK(provider IN ('proxmox','vmmanager','ssh','winrm','snmp','custom')),
 name varchar(120) NOT NULL CHECK(length(trim(name))>0),
 endpoint_host varchar(253) NOT NULL CHECK(length(trim(endpoint_host))>0),
 endpoint_port integer NOT NULL CHECK(endpoint_port BETWEEN 1 AND 65535),
 asset_id uuid,
 state varchar(12) NOT NULL DEFAULT 'active' CHECK(state IN ('active','disabled')),
 version bigint NOT NULL DEFAULT 1 CHECK(version>=1),
 created_by uuid REFERENCES users(id) ON DELETE SET NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(id,workspace_id),
 FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT
);
CREATE UNIQUE INDEX connector_targets_unique_name ON connector_targets(workspace_id,lower(name));
CREATE INDEX connector_targets_workspace_idx ON connector_targets(workspace_id,created_at DESC);
-- Do not allow an already soft-deleted asset to be selected via direct SQL.
CREATE FUNCTION cajeer_connector_target_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.asset_id IS NOT NULL AND NOT EXISTS(
    SELECT 1 FROM inventory_assets a
    WHERE a.id=NEW.asset_id AND a.workspace_id=NEW.workspace_id AND a.deleted_at IS NULL FOR SHARE
 ) THEN RAISE EXCEPTION 'Connector target asset must be active in the same workspace' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER connector_target_guard BEFORE INSERT OR UPDATE OF asset_id,workspace_id ON connector_targets
 FOR EACH ROW EXECUTE FUNCTION cajeer_connector_target_guard();

CREATE TABLE connector_credential_bindings (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 workspace_id uuid NOT NULL,
 connector_id uuid NOT NULL,
 credential_id uuid NOT NULL,
 purpose varchar(12) NOT NULL CHECK(purpose IN ('api','ssh','winrm','snmp','custom')),
 created_by uuid REFERENCES users(id) ON DELETE SET NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(connector_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
 FOREIGN KEY(credential_id,workspace_id) REFERENCES inventory_credential_scopes(credential_id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
 UNIQUE(connector_id,purpose)
);
CREATE INDEX connector_credential_by_credential ON connector_credential_bindings(credential_id);

-- The database is the last line of defence against direct SQL and concurrent revocation.
CREATE FUNCTION cajeer_credential_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE kind varchar(32); status varchar(16); owner_ws uuid; format varchar(16); archived timestamptz;
        connector_provider varchar(20); connector_status varchar(12);
BEGIN
 SELECT type,state,workspace_id,vault_format,deleted_at INTO kind,status,owner_ws,format,archived
 FROM credentials WHERE id=NEW.credential_id FOR SHARE;
 IF NOT FOUND OR owner_ws IS DISTINCT FROM NEW.workspace_id OR format<>'cjev1' OR status<>'active' OR archived IS NOT NULL THEN
   RAISE EXCEPTION 'Credential unavailable for workspace binding' USING ERRCODE='23514';
 END IF;
 IF NOT (
   (kind IN ('api_token','proxmox_api','vmmanager_api') AND NEW.purpose='api') OR
   (kind IN ('ssh_password','ssh_private_key') AND NEW.purpose='ssh') OR
   (kind='winrm' AND NEW.purpose='winrm') OR
   (kind IN ('snmp_v2c','snmp_v3') AND NEW.purpose='snmp') OR
   (kind='generic_secret' AND NEW.purpose='custom')
 ) THEN RAISE EXCEPTION 'Credential purpose incompatible with profile' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='connector_credential_bindings' THEN
   SELECT provider,state INTO connector_provider,connector_status FROM connector_targets
     WHERE id=NEW.connector_id AND workspace_id=NEW.workspace_id FOR SHARE;
   IF NOT FOUND OR connector_status<>'active' OR NOT (
     (connector_provider='proxmox' AND kind IN ('proxmox_api','api_token')) OR
     (connector_provider='vmmanager' AND kind IN ('vmmanager_api','api_token')) OR
     (connector_provider='ssh' AND kind IN ('ssh_password','ssh_private_key')) OR
     (connector_provider='winrm' AND kind='winrm') OR
     (connector_provider='snmp' AND kind IN ('snmp_v2c','snmp_v3')) OR
     (connector_provider='custom' AND kind IN ('generic_secret','api_token'))
   ) THEN RAISE EXCEPTION 'Connector incompatible with credential' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER inventory_binding_guard BEFORE INSERT OR UPDATE ON inventory_asset_credential_bindings
 FOR EACH ROW EXECUTE FUNCTION cajeer_credential_binding_guard();
CREATE TRIGGER connector_binding_guard BEFORE INSERT OR UPDATE ON connector_credential_bindings
 FOR EACH ROW EXECUTE FUNCTION cajeer_credential_binding_guard();

-- Prevent soft-deletion of an Inventory asset still selected as a connector target.
CREATE FUNCTION cajeer_connector_target_asset_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.deleted_at IS DISTINCT FROM OLD.deleted_at AND NEW.deleted_at IS NOT NULL)
 OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id THEN
   IF EXISTS(SELECT 1 FROM connector_targets WHERE asset_id=OLD.id) THEN
     RAISE EXCEPTION 'Detach connector target before changing the asset lifecycle' USING ERRCODE='23514';
   END IF;
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER connector_target_asset_guard BEFORE UPDATE OF deleted_at,workspace_id ON inventory_assets
 FOR EACH ROW EXECUTE FUNCTION cajeer_connector_target_asset_guard();

INSERT INTO permissions(scope,description) VALUES
 ('connectors.targets.read','Read workspace connector configurations without secrets'),
 ('connectors.targets.manage','Manage workspace connector configurations'),
 ('connectors.credentials.read','Read connector credential references'),
 ('connectors.credentials.manage','Bind or detach Vault credentials to connector configurations')
ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator'
 AND p.scope IN ('connectors.targets.read','connectors.targets.manage','connectors.credentials.read','connectors.credentials.manage')
ON CONFLICT DO NOTHING;
