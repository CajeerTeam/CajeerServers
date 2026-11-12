-- External push sources are scoped to one existing target and inspect grant.
CREATE TABLE data_ingestion_sources (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces(id),
 target_id uuid NOT NULL, grant_id uuid NOT NULL REFERENCES connector_service_grants(id) ON DELETE RESTRICT,
 actor_id uuid NOT NULL REFERENCES users(id), name varchar(120) NOT NULL,
 kind varchar(16) NOT NULL CHECK(kind IN ('metrics','events','observations')),
 secret_ciphertext bytea NOT NULL, key_version integer NOT NULL DEFAULT 1 CHECK(key_version>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), revoked_at timestamptz,
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE RESTRICT,
 UNIQUE(id,workspace_id,target_id)
);
CREATE INDEX data_ingestion_sources_target ON data_ingestion_sources(target_id,created_at DESC);
CREATE TABLE data_ingestion_inbox (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), source_id uuid NOT NULL, workspace_id uuid NOT NULL, target_id uuid NOT NULL,
 batch_id varchar(128) NOT NULL, body_digest char(64) NOT NULL, kind varchar(16) NOT NULL CHECK(kind IN ('metrics','events','observations')),
 payload jsonb NOT NULL CHECK(jsonb_typeof(payload)='array'), payload_bytes integer NOT NULL CHECK(payload_bytes BETWEEN 1 AND 524288), received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 status varchar(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','succeeded','failed')),
 available_at timestamptz NOT NULL DEFAULT clock_timestamp(), attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5),
 dispatch_count integer NOT NULL DEFAULT 1 CHECK(dispatch_count BETWEEN 1 AND 10),
 finished_at timestamptz, error_code varchar(64), result jsonb,
 FOREIGN KEY(source_id,workspace_id,target_id) REFERENCES data_ingestion_sources(id,workspace_id,target_id) ON DELETE RESTRICT,
 UNIQUE(source_id,batch_id), CHECK((status='queued')=(finished_at IS NULL)), CHECK((status='succeeded')=(result IS NOT NULL))
);
CREATE INDEX data_ingestion_inbox_source ON data_ingestion_inbox(source_id);
CREATE INDEX data_ingestion_workspace ON data_ingestion_inbox(workspace_id,status);
CREATE INDEX data_ingestion_workspace_ready ON data_ingestion_inbox(workspace_id,available_at,id) WHERE status='queued';
CREATE INDEX data_ingestion_ready ON data_ingestion_inbox(available_at,id) WHERE status='queued';
CREATE INDEX data_ingestion_target ON data_ingestion_inbox(target_id,received_at DESC,id);
CREATE INDEX data_ingestion_retention ON data_ingestion_inbox(finished_at) WHERE status<>'queued';
CREATE TABLE data_ingestion_attempts (
 batch_id uuid NOT NULL REFERENCES data_ingestion_inbox(id) ON DELETE CASCADE,
 dispatch integer NOT NULL, attempt integer NOT NULL, started_at timestamptz NOT NULL, finished_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 outcome varchar(16) NOT NULL CHECK(outcome IN ('succeeded','failed','retry_scheduled')), error_code varchar(64),
 PRIMARY KEY(batch_id,dispatch,attempt)
);
CREATE TABLE data_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), source_id uuid NOT NULL, workspace_id uuid NOT NULL, target_id uuid NOT NULL,
 event_id varchar(128) NOT NULL, type varchar(128) NOT NULL, severity varchar(16) NOT NULL,
 message varchar(4096) NOT NULL, observed_at timestamptz NOT NULL, digest char(64) NOT NULL, received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(source_id,workspace_id,target_id) REFERENCES data_ingestion_sources(id,workspace_id,target_id) ON DELETE RESTRICT,
 UNIQUE(source_id,event_id)
);
CREATE INDEX data_events_workspace ON data_events(workspace_id);
CREATE INDEX data_events_source ON data_events(source_id);
CREATE INDEX data_events_target ON data_events(target_id,observed_at DESC,id);
CREATE INDEX data_events_retention ON data_events(received_at);
INSERT INTO permissions(scope,description) VALUES ('ingestion.read','Read push sources, batches and events'),('ingestion.manage','Manage push sources and redrive'),('ingestion.write','Delegate external data ingestion') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope IN ('ingestion.read','ingestion.manage','ingestion.write') ON CONFLICT DO NOTHING;

-- Explicit provenance distinguishes push sources even if a pull exporter supplies the same labels.
ALTER TABLE metric_series ADD COLUMN ingestion_source_id uuid;
ALTER TABLE metric_series ADD CONSTRAINT metric_series_ingestion_source_fk FOREIGN KEY(ingestion_source_id,workspace_id,target_id) REFERENCES data_ingestion_sources(id,workspace_id,target_id) ON DELETE RESTRICT;
CREATE INDEX metric_series_ingestion_source ON metric_series(ingestion_source_id) WHERE ingestion_source_id IS NOT NULL;
