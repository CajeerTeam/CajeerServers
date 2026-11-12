-- Scoped health observations; connector configuration changes invalidate old observations.
CREATE TABLE connector_health_state (
 target_id uuid PRIMARY KEY REFERENCES connector_targets(id) ON DELETE CASCADE,
 target_version bigint NOT NULL CHECK(target_version>=1),
 credential_id uuid NOT NULL,
 credential_version bigint NOT NULL CHECK(credential_version>=1),
 status varchar(16) NOT NULL CHECK(status IN ('healthy','degraded','unreachable','auth_failed')),
 consecutive_failures integer NOT NULL DEFAULT 0 CHECK(consecutive_failures>=0),
 last_checked_at timestamptz NOT NULL,
 last_success_at timestamptz,
 duration_ms integer NOT NULL CHECK(duration_ms BETWEEN 0 AND 300000),
 error_code varchar(64),
 circuit_open_until timestamptz
);
CREATE TABLE connector_health_metrics (
 provider varchar(20) NOT NULL CHECK(provider IN ('proxmox','vmmanager','ssh','winrm','snmp','custom')),
 outcome varchar(16) NOT NULL CHECK(outcome IN ('success','failure')),
 checks bigint NOT NULL DEFAULT 0 CHECK(checks>=0),
 duration_ms bigint NOT NULL DEFAULT 0 CHECK(duration_ms>=0),
 PRIMARY KEY(provider,outcome)
);
-- Invalidate stale status on disable, rebind, rotation, or credential deactivation.
CREATE VIEW connector_health_current AS
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
 AND b.purpose=CASE WHEN t.provider IN ('proxmox','vmmanager') THEN 'api' WHEN t.provider='custom' THEN 'custom' ELSE t.provider END
LEFT JOIN credentials c ON c.id=b.credential_id AND c.version=h.credential_version AND c.state='active' AND c.deleted_at IS NULL;

-- Extend the existing field-level provenance trigger to protect manual network metadata.
CREATE OR REPLACE FUNCTION cajeer_host_attributes_provenance() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE changed boolean;
BEGIN
 IF current_setting('cajeer.change_origin',true)='observation' THEN RETURN NULL; END IF;
 IF TG_OP='INSERT' THEN
  changed := NEW.attributes ? 'host_hardware' OR NEW.attributes ? 'network_device';
 ELSE
  changed := (OLD.attributes->'host_hardware') IS DISTINCT FROM (NEW.attributes->'host_hardware')
          OR (OLD.attributes->'network_device') IS DISTINCT FROM (NEW.attributes->'network_device');
 END IF;
 IF changed THEN
  INSERT INTO inventory_field_provenance(asset_id,field,mode,source,actor_id)
  VALUES(NEW.id,'attributes','manual','manual',NULLIF(current_setting('cajeer.actor_id',true),'')::uuid)
  ON CONFLICT(asset_id,field) DO UPDATE SET mode='manual',source='manual',actor_id=EXCLUDED.actor_id,observed_at=NULL,updated_at=clock_timestamp();
 END IF;
 RETURN NULL;
END;
$$;
INSERT INTO inventory_field_provenance(asset_id,field,mode,source)
SELECT id,'attributes','manual','manual' FROM inventory_assets WHERE attributes ? 'network_device'
ON CONFLICT(asset_id,field) DO NOTHING;
