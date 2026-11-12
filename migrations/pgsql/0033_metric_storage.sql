-- Real metrics storage, independent of Inventory mutation. UTC monthly range partitions.
ALTER TABLE connector_targets DROP CONSTRAINT connector_targets_provider_check, ADD CONSTRAINT connector_targets_provider_check CHECK(provider IN ('proxmox','vmmanager','ssh','winrm','snmp','custom','prometheus'));
ALTER TABLE connector_health_metrics DROP CONSTRAINT connector_health_metrics_provider_check, ADD CONSTRAINT connector_health_metrics_provider_check CHECK(provider IN ('proxmox','vmmanager','ssh','winrm','snmp','custom','prometheus'));
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
     (connector_provider='prometheus' AND kind='api_token') OR
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
 AND b.purpose=CASE WHEN t.provider IN ('proxmox','vmmanager','prometheus') THEN 'api' WHEN t.provider='custom' THEN 'custom' ELSE t.provider END
LEFT JOIN credentials c ON c.id=b.credential_id AND c.version=h.credential_version AND c.state='active' AND c.deleted_at IS NULL;


CREATE TABLE metric_series (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 workspace_id uuid NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
 target_id uuid NOT NULL, asset_id uuid NOT NULL, target_version bigint NOT NULL CHECK(target_version>0),
 identity char(64) NOT NULL, metric varchar(128) NOT NULL, resource_external_id varchar(180) NOT NULL DEFAULT '',
 labels jsonb NOT NULL CHECK(jsonb_typeof(labels)='object'), kind varchar(8) NOT NULL CHECK(kind IN ('gauge','counter')), unit varchar(32),
 last_seen_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE RESTRICT,
 FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON DELETE RESTRICT,
 UNIQUE(workspace_id,target_id,asset_id,target_version,identity)
);
CREATE INDEX metric_series_target ON metric_series(workspace_id,target_id,metric,id);
CREATE TABLE metric_samples (
 series_id uuid NOT NULL REFERENCES metric_series(id) ON DELETE RESTRICT,
 observed_at timestamptz NOT NULL, value numeric NOT NULL CHECK(value NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)),
 PRIMARY KEY(series_id,observed_at)
) PARTITION BY RANGE(observed_at);
CREATE INDEX metric_samples_retention ON metric_samples(observed_at);
CREATE FUNCTION cajeer_metric_partition(month_date date) RETURNS void LANGUAGE plpgsql AS $$
DECLARE start_date date := date_trunc('month',month_date)::date; stop_date date := (start_date+interval '1 month')::date; part text := 'metric_samples_'||to_char(start_date,'YYYYMM');
BEGIN
 IF start_date<date_trunc('month',(clock_timestamp() AT TIME ZONE 'UTC')::date-interval '31 days')::date OR start_date>date_trunc('month',(clock_timestamp() AT TIME ZONE 'UTC')::date+interval '1 month')::date THEN RAISE EXCEPTION 'Metric partition outside retention window'; END IF;
 IF to_regclass(format('%I.%I',current_schema(),part)) IS NOT NULL THEN RETURN; END IF;
 PERFORM pg_advisory_xact_lock(5330033);
 IF to_regclass(format('%I.%I',current_schema(),part)) IS NOT NULL THEN RETURN; END IF;
 EXECUTE format('CREATE TABLE %I.%I PARTITION OF %I.metric_samples FOR VALUES FROM (%L) TO (%L)',current_schema(),part,current_schema(),start_date::text||' 00:00:00+00',stop_date::text||' 00:00:00+00');
END; $$;
SELECT cajeer_metric_partition(((clock_timestamp() AT TIME ZONE 'UTC')::date-interval '30 days')::date);
SELECT cajeer_metric_partition((clock_timestamp() AT TIME ZONE 'UTC')::date);
SELECT cajeer_metric_partition(((clock_timestamp() AT TIME ZONE 'UTC')::date+interval '1 month')::date);
