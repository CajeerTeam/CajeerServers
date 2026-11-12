-- Durable, bounded service delegation. No plaintext or reusable bearer token.
CREATE TABLE connector_service_grants (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 workspace_id uuid NOT NULL, target_id uuid NOT NULL, actor_id uuid NOT NULL,
 asset_id uuid NOT NULL, credential_id uuid NOT NULL, purpose varchar(64) NOT NULL,
 target_version integer NOT NULL, credential_version integer NOT NULL,
 operation varchar(16) NOT NULL CHECK(operation IN ('inspect','sync','health')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), expires_at timestamptz NOT NULL,
 revoked_at timestamptz, interval_seconds integer CHECK(interval_seconds BETWEEN 60 AND 86400),
 next_due_at timestamptz,
 CHECK ((interval_seconds IS NULL) = (next_due_at IS NULL))
);
CREATE INDEX connector_grants_due ON connector_service_grants(next_due_at) WHERE revoked_at IS NULL AND interval_seconds IS NOT NULL;
CREATE INDEX connector_grants_target ON connector_service_grants(target_id,created_at DESC);
CREATE TABLE connector_runs (
 id uuid PRIMARY KEY, grant_id uuid NOT NULL REFERENCES connector_service_grants(id) ON DELETE RESTRICT,
 target_id uuid NOT NULL, workspace_id uuid NOT NULL,
 idempotency_key varchar(160) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 finished_at timestamptz, cancelled_at timestamptz, result jsonb, error_code varchar(64),
 UNIQUE(grant_id,idempotency_key), CHECK (result IS NULL OR finished_at IS NOT NULL)
);
CREATE INDEX connector_runs_target ON connector_runs(target_id,created_at DESC);
