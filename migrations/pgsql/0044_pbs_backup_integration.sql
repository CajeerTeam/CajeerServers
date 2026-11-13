ALTER TABLE connector_targets DROP CONSTRAINT connector_targets_provider_check, ADD CONSTRAINT connector_targets_provider_check CHECK(provider IN ('proxmox','vmmanager','ssh','winrm','snmp','custom','prometheus','pbs'));
ALTER TABLE connector_health_metrics DROP CONSTRAINT connector_health_metrics_provider_check, ADD CONSTRAINT connector_health_metrics_provider_check CHECK(provider IN ('proxmox','vmmanager','ssh','winrm','snmp','custom','prometheus','pbs'));
CREATE OR REPLACE FUNCTION cajeer_credential_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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
     (connector_provider IN ('prometheus','pbs') AND kind='api_token') OR
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
CREATE OR REPLACE VIEW connector_health_current AS
SELECT t.id AS target_id,t.provider,
 CASE WHEN t.state='disabled' THEN 'disabled' WHEN c.id IS NULL THEN 'unknown' ELSE COALESCE(h.status,'unknown') END AS health_status,
 CASE WHEN c.id IS NOT NULL AND t.state='active' THEN h.last_checked_at END AS health_checked_at,
 CASE WHEN c.id IS NOT NULL AND t.state='active' THEN h.last_success_at END AS health_success_at,
 CASE WHEN c.id IS NOT NULL AND t.state='active' THEN h.duration_ms END AS health_duration_ms,
 CASE WHEN c.id IS NOT NULL AND t.state='active' THEN h.error_code END AS health_error_code,
 CASE WHEN c.id IS NOT NULL AND t.state='active' THEN h.circuit_open_until END AS circuit_open_until,
 CASE WHEN c.id IS NOT NULL AND t.state='active' THEN COALESCE(h.consecutive_failures,0) ELSE 0 END AS consecutive_failures
FROM connector_targets t
LEFT JOIN connector_health_state h ON h.target_id=t.id AND h.target_version=t.version
LEFT JOIN connector_credential_bindings b ON b.connector_id=t.id AND b.credential_id=h.credential_id
 AND b.purpose=CASE WHEN t.provider IN ('proxmox','vmmanager','prometheus','pbs') THEN 'api' WHEN t.provider='custom' THEN 'custom' ELSE t.provider END
LEFT JOIN credentials c ON c.id=b.credential_id AND c.version=h.credential_version AND c.state='active' AND c.deleted_at IS NULL;
-- Latest PBS discovery scoped to target and credential revisions.
CREATE TABLE pbs_backup_state (
 target_id uuid PRIMARY KEY,
 workspace_id uuid NOT NULL,
 target_version bigint NOT NULL CHECK(target_version>=1),
 credential_id uuid NOT NULL,
 credential_version bigint NOT NULL CHECK(credential_version>=1),
 observed_at timestamptz NOT NULL,
 snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object' AND octet_length(snapshot::text)<=1048576),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
