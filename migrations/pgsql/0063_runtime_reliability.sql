-- Durable retry metadata for outbox delivery; existing pending entries are immediately eligible.
ALTER TABLE scheduler_outbox
    ADD COLUMN dispatch_attempts integer NOT NULL DEFAULT 0 CHECK (dispatch_attempts >= 0),
    ADD COLUMN next_attempt_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    ADD COLUMN last_error varchar(80);
CREATE INDEX scheduler_outbox_retry_idx ON scheduler_outbox (next_attempt_at, created_at, id) WHERE status='pending';
CREATE INDEX scheduler_outbox_pending_task_idx ON scheduler_outbox (task_id) WHERE status='pending';
ALTER TABLE scheduler_tasks ADD COLUMN last_error varchar(80);
