-- Target-scoped signing keys and durable event-to-run dispatch. No raw payload or plaintext.
CREATE TABLE connector_webhook_sources (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), target_id uuid NOT NULL,
 workspace_id uuid NOT NULL, grant_id uuid NOT NULL REFERENCES connector_service_grants(id),
 actor_id uuid NOT NULL, secret_ciphertext bytea NOT NULL,
 key_version integer NOT NULL DEFAULT 1 CHECK(key_version>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), revoked_at timestamptz
);
CREATE INDEX connector_webhook_sources_target ON connector_webhook_sources(target_id,created_at DESC);
CREATE TABLE connector_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), source_id uuid NOT NULL REFERENCES connector_webhook_sources(id),
 event_id varchar(128) NOT NULL, event_type varchar(32) NOT NULL CHECK(event_type IN ('resource.created','resource.updated','resource.deleted','inventory.refresh')),
 resource_id varchar(180), body_digest char(64) NOT NULL,
 received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 run_id uuid NOT NULL REFERENCES connector_runs(id), dispatch_count integer NOT NULL DEFAULT 1 CHECK(dispatch_count BETWEEN 1 AND 10),
 UNIQUE(source_id,event_id)
);
CREATE INDEX connector_events_source ON connector_events(source_id,received_at DESC,id);
CREATE TABLE connector_event_dispatches (
 event_id uuid NOT NULL REFERENCES connector_events(id), run_id uuid PRIMARY KEY REFERENCES connector_runs(id),
 dispatched_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX connector_event_dispatches_event ON connector_event_dispatches(event_id,dispatched_at);
