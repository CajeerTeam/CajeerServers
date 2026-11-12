-- Manual ingestion provenance and bounded durable export artifacts.
ALTER TABLE data_ingestion_inbox ADD COLUMN accepted_by uuid REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE data_export_jobs (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL,
 target_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 idempotency_key varchar(128) NOT NULL, request_digest char(64) NOT NULL,
 kind varchar(16) NOT NULL CHECK(kind IN ('metrics','events','observations','runs')),
 format varchar(32) NOT NULL CHECK(format ~ '^[a-z][a-z0-9_]{0,31}$'),
 from_at timestamptz NOT NULL, to_at timestamptz NOT NULL,
 series_id uuid, status varchar(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','succeeded','failed','cancelled')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), finished_at timestamptz,
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5), available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 error_code varchar(64), record_count integer, artifact text, artifact_sha256 char(64),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE,
 UNIQUE(actor_id,target_id,idempotency_key),
 CHECK(to_at>from_at AND to_at-from_at<=interval '30 days'),
 CHECK(format<>'openmetrics' OR kind='metrics'), CHECK(series_id IS NULL OR kind='metrics'),
 CHECK((status='queued')=(finished_at IS NULL)), CHECK((status='succeeded')=(artifact IS NOT NULL)),
 CHECK((artifact IS NULL)=(artifact_sha256 IS NULL)), CHECK(octet_length(artifact)<=4194304)
);
CREATE INDEX data_export_ready ON data_export_jobs(available_at,id) WHERE status='queued';
CREATE INDEX data_export_workspace ON data_export_jobs(workspace_id,status);
CREATE INDEX data_export_target ON data_export_jobs(target_id,created_at DESC,id);
CREATE INDEX data_export_retention ON data_export_jobs(finished_at) WHERE status<>'queued';
