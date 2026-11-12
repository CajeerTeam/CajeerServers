ALTER TABLE script_versions ADD CONSTRAINT script_versions_execution_identity UNIQUE(workspace_id,script_id,id);
CREATE TABLE script_executions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), workspace_id uuid NOT NULL REFERENCES workspaces(id),
 script_id uuid NOT NULL, version_id uuid NOT NULL,
 actor_id uuid NOT NULL REFERENCES users(id), session_id uuid NOT NULL,
 target_id uuid, scope jsonb NOT NULL DEFAULT '{}'::jsonb, parameters jsonb NOT NULL,
 mode text NOT NULL CHECK(mode IN ('execute','preview')), channel text NOT NULL CHECK(channel IN ('sandbox','connector')),
 idempotency_key varchar(128) NOT NULL, request_hash char(64) NOT NULL,
 state text NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','running','succeeded','failed','cancelled')),
 timeout_seconds integer NOT NULL CHECK(timeout_seconds BETWEEN 1 AND 90),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), expires_at timestamptz NOT NULL DEFAULT (clock_timestamp()+interval '120 seconds'),
 started_at timestamptz, deadline_at timestamptz, finished_at timestamptz, result jsonb, error_code varchar(80),
 FOREIGN KEY(workspace_id,script_id,version_id) REFERENCES script_versions(workspace_id,script_id,id),
 UNIQUE(workspace_id,actor_id,idempotency_key),
 CHECK(jsonb_typeof(scope)='object' AND jsonb_typeof(parameters)='object'),
 CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 CHECK((state IN ('succeeded','failed','cancelled'))=(finished_at IS NOT NULL)),
 CHECK((state='succeeded')=(result IS NOT NULL))
);
CREATE INDEX script_execution_queue_idx ON script_executions(created_at,id) WHERE state='queued';
CREATE INDEX script_execution_history_idx ON script_executions(workspace_id,actor_id,created_at DESC);
CREATE INDEX script_execution_deadline_idx ON script_executions(deadline_at) WHERE state='running';
