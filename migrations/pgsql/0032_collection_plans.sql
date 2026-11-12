-- Dedicated grant, immutable collection scope; all deliveries use existing fenced connector queue.
CREATE TABLE collection_plans (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 grant_id uuid NOT NULL UNIQUE REFERENCES connector_service_grants(id) ON DELETE RESTRICT,
 name varchar(120) NOT NULL, collector_name varchar(64) NOT NULL CHECK(collector_name ~ '^[a-z][a-z0-9_.-]{0,63}$'),
 interval_seconds integer CHECK(interval_seconds BETWEEN 60 AND 86400),
 cron_expression varchar(120), timezone varchar(64) NOT NULL DEFAULT 'UTC',
 jitter_seconds integer NOT NULL DEFAULT 0 CHECK(jitter_seconds BETWEEN 0 AND 30),
 enabled boolean NOT NULL DEFAULT true, next_due_at timestamptz NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK ((interval_seconds IS NULL) <> (cron_expression IS NULL)),
 UNIQUE(id,grant_id,collector_name)
);
CREATE INDEX collection_plans_due ON collection_plans(next_due_at,id) WHERE enabled;
ALTER TABLE connector_runs ADD COLUMN collection_plan_id uuid REFERENCES collection_plans(id) ON DELETE RESTRICT;
ALTER TABLE connector_runs ADD CONSTRAINT collection_run_scope FOREIGN KEY(collection_plan_id,grant_id,collector_name) REFERENCES collection_plans(id,grant_id,collector_name) ON DELETE RESTRICT, ADD CONSTRAINT collection_run_collector CHECK(collection_plan_id IS NULL OR collector_name IS NOT NULL);
ALTER TABLE connector_runs ADD COLUMN redrive_of uuid REFERENCES connector_runs(id) ON DELETE RESTRICT;
CREATE UNIQUE INDEX collection_runs_redrive ON connector_runs(redrive_of) WHERE redrive_of IS NOT NULL;
CREATE INDEX collection_runs_plan ON connector_runs(collection_plan_id,created_at DESC,id);
CREATE TABLE collection_run_attempts (
 run_id uuid NOT NULL REFERENCES connector_runs(id) ON DELETE RESTRICT,
 attempt integer NOT NULL CHECK(attempt>0), started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 finished_at timestamptz, outcome varchar(24) NOT NULL DEFAULT 'running', error_code varchar(64),
 PRIMARY KEY(run_id,attempt), CHECK(outcome IN ('running','retry_scheduled','lease_expired','succeeded','failed','cancelled'))
);
-- Capture claims and outcomes in the same transaction as queue transitions, including lost leases.
CREATE FUNCTION collection_attempt_transition() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r connector_runs%ROWTYPE; result_status text;
BEGIN
 IF NEW.queue <> 'connectors' OR NEW.attempts=0 THEN RETURN NEW; END IF;
 SELECT * INTO r FROM connector_runs WHERE id=NEW.id AND collector_name IS NOT NULL;
 IF NOT FOUND THEN RETURN NEW; END IF;
 IF NEW.status='running' AND (OLD.status<>'running' OR NEW.attempts<>OLD.attempts) THEN
   UPDATE collection_run_attempts SET outcome='lease_expired',finished_at=clock_timestamp(),error_code='lease_lost' WHERE run_id=NEW.id AND outcome='running' AND attempt<NEW.attempts;
   INSERT INTO collection_run_attempts(run_id,attempt) VALUES(NEW.id,NEW.attempts) ON CONFLICT DO NOTHING;
 ELSIF NEW.status<>'running' AND OLD.status='running' THEN
   result_status := CASE WHEN r.cancelled_at IS NOT NULL THEN 'cancelled' WHEN r.result IS NOT NULL THEN 'succeeded' WHEN NEW.status='queued' THEN 'retry_scheduled' WHEN NEW.status='failed' AND r.finished_at IS NULL THEN 'lease_expired' ELSE 'failed' END;
   UPDATE collection_run_attempts SET outcome=result_status,finished_at=clock_timestamp(),error_code=CASE WHEN result_status='retry_scheduled' THEN COALESCE(left(NEW.last_error,64),'execution_retry') WHEN result_status='lease_expired' THEN 'lease_lost' ELSE r.error_code END WHERE run_id=NEW.id AND attempt=NEW.attempts AND outcome='running';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER collection_attempt_transition AFTER UPDATE OF status,attempts ON queue_jobs FOR EACH ROW EXECUTE FUNCTION collection_attempt_transition();
