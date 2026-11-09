-- CajeerServers 0.1.7: durable queues and cron dispatch outbox.
CREATE TABLE queue_jobs (
    id uuid PRIMARY KEY,
    queue varchar(40) NOT NULL,
    type varchar(80) NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    status varchar(16) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','completed','failed')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts integer NOT NULL DEFAULT 3 CHECK (max_attempts BETWEEN 1 AND 25),
    available_at timestamptz NOT NULL DEFAULT now(),
    reserved_until timestamptz,
    lease_token varchar(96),
    last_error text,
    result jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    CONSTRAINT queue_jobs_reservation CHECK (status <> 'running' OR (lease_token IS NOT NULL AND reserved_until IS NOT NULL))
);
CREATE INDEX queue_jobs_available_idx ON queue_jobs (queue,status,available_at,created_at) WHERE status='queued';
CREATE INDEX queue_jobs_reservation_idx ON queue_jobs (queue,reserved_until) WHERE status='running';
CREATE INDEX queue_jobs_failed_idx ON queue_jobs (finished_at DESC) WHERE status='failed';

CREATE TABLE scheduler_tasks (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(80) UNIQUE NOT NULL,
    cron_expression varchar(200) NOT NULL,
    timezone varchar(80) NOT NULL DEFAULT 'UTC',
    job_type varchar(80) NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    queue varchar(40) NOT NULL DEFAULT 'default',
    enabled boolean NOT NULL DEFAULT true,
    last_run_at timestamptz,
    next_run_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX scheduler_tasks_due_idx ON scheduler_tasks (next_run_at) WHERE enabled=true;
CREATE TABLE scheduler_outbox (
    id uuid PRIMARY KEY,
    task_id uuid NOT NULL REFERENCES scheduler_tasks(id) ON DELETE CASCADE,
    due_at timestamptz NOT NULL,
    job_type varchar(80) NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    queue varchar(40) NOT NULL,
    max_attempts integer NOT NULL DEFAULT 3,
    status varchar(12) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent')),
    created_at timestamptz NOT NULL DEFAULT now(),
    sent_at timestamptz,
    UNIQUE (task_id,due_at)
);
CREATE INDEX scheduler_outbox_pending_idx ON scheduler_outbox (created_at,id) WHERE status='pending';
