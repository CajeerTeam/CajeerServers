-- Task history scoped to an exact target / credential revision, not Proxmox UPID.
CREATE TABLE vmmanager_task_cursor (
 target_id uuid NOT NULL, workspace_id uuid NOT NULL, scope_hash char(64) NOT NULL,
 revision bigint NOT NULL CHECK(revision>0), observed_at timestamptz NOT NULL,
 cursor jsonb NOT NULL CHECK(jsonb_typeof(cursor)='object'), summary jsonb NOT NULL CHECK(jsonb_typeof(summary)='object'),
 PRIMARY KEY(target_id,scope_hash),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
CREATE TABLE vmmanager_tasks (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 target_id uuid NOT NULL, scope_hash char(64) NOT NULL, remote_id bigint NOT NULL CHECK(remote_id>0),
 consul_id varchar(128), task_type varchar(128) NOT NULL, resource_id varchar(64),
 state text NOT NULL CHECK(state IN ('queued','deferred','running','succeeded','failed','unknown')),
 transition_seq bigint NOT NULL DEFAULT 0 CHECK(transition_seq>=0),
 first_seen_at timestamptz NOT NULL,last_seen_at timestamptz NOT NULL,checked_at timestamptz,check_state text,
 UNIQUE(target_id,scope_hash,remote_id), UNIQUE(id,target_id,scope_hash),
 FOREIGN KEY(target_id,scope_hash) REFERENCES vmmanager_task_cursor(target_id,scope_hash) ON DELETE CASCADE
);
CREATE INDEX vmmanager_tasks_page ON vmmanager_tasks(target_id,scope_hash,id DESC);
CREATE INDEX vmmanager_tasks_state_page ON vmmanager_tasks(target_id,scope_hash,state,id DESC);
CREATE INDEX vmmanager_tasks_pending ON vmmanager_tasks(target_id,scope_hash,checked_at ASC NULLS FIRST,id) WHERE state IN ('queued','deferred','running','unknown');
CREATE TABLE vmmanager_task_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,task_id bigint NOT NULL,target_id uuid NOT NULL,scope_hash char(64) NOT NULL,
 kind text NOT NULL CHECK(kind IN ('task_discovered','task_started','task_deferred','task_queued','task_completed','task_outcome_resolved')),
 state text NOT NULL CHECK(state IN ('queued','deferred','running','succeeded','failed','unknown')),transition_seq bigint NOT NULL CHECK(transition_seq>=0),observed_at timestamptz NOT NULL,
 UNIQUE(task_id,transition_seq,kind), FOREIGN KEY(task_id,target_id,scope_hash) REFERENCES vmmanager_tasks(id,target_id,scope_hash) ON DELETE CASCADE
);
CREATE INDEX vmmanager_task_events_page ON vmmanager_task_events(target_id,scope_hash,id DESC);
CREATE INDEX vmmanager_task_events_state_page ON vmmanager_task_events(target_id,scope_hash,state,id DESC);
