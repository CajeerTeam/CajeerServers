-- Scope by target/profile/credential revisions; old visibility is never reused by a new binding.
CREATE TABLE proxmox_task_cursor (
 target_id uuid NOT NULL, workspace_id uuid NOT NULL, scope_hash char(64) NOT NULL,
 revision bigint NOT NULL CHECK(revision>0), observed_at timestamptz NOT NULL,
 cursor jsonb NOT NULL CHECK(jsonb_typeof(cursor)='object' AND octet_length(cursor::text)<=262144),
 summary jsonb NOT NULL CHECK(jsonb_typeof(summary)='object'),
 PRIMARY KEY(target_id,scope_hash),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
CREATE TABLE proxmox_tasks (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 target_id uuid NOT NULL, workspace_id uuid NOT NULL, scope_hash char(64) NOT NULL,
 upid varchar(512) NOT NULL, node varchar(120) NOT NULL, task_type varchar(64) NOT NULL,
 resource_id varchar(180) NOT NULL, task_user varchar(180) NOT NULL,
 started_at bigint NOT NULL CHECK(started_at>=0), ended_at bigint CHECK(ended_at>=started_at),
 state text NOT NULL CHECK(state IN ('running','succeeded','warning','failed','unknown')),
 first_seen_at timestamptz NOT NULL, last_seen_at timestamptz NOT NULL,
 checked_at timestamptz, check_state text,
 UNIQUE(target_id,scope_hash,upid),
 UNIQUE(id,target_id,scope_hash),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE,
 FOREIGN KEY(target_id,scope_hash) REFERENCES proxmox_task_cursor(target_id,scope_hash) ON DELETE CASCADE
);
CREATE INDEX proxmox_tasks_page ON proxmox_tasks(target_id,scope_hash,id DESC);
CREATE INDEX proxmox_tasks_pending ON proxmox_tasks(target_id,scope_hash,checked_at ASC NULLS FIRST,id) WHERE state='running';
CREATE TABLE proxmox_task_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 task_id bigint NOT NULL, target_id uuid NOT NULL, scope_hash char(64) NOT NULL,
 kind text NOT NULL CHECK(kind IN ('task_discovered','task_completed','task_outcome_resolved')),
 state text NOT NULL CHECK(state IN ('running','succeeded','warning','failed','unknown')),
 occurred_at bigint, observed_at timestamptz NOT NULL,
 UNIQUE(task_id,kind),
 FOREIGN KEY(task_id,target_id,scope_hash) REFERENCES proxmox_tasks(id,target_id,scope_hash) ON DELETE CASCADE
);
CREATE INDEX proxmox_task_events_page ON proxmox_task_events(target_id,scope_hash,id DESC);
