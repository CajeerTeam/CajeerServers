CREATE TABLE system_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 target_id uuid NOT NULL, workspace_id uuid NOT NULL,
 source text NOT NULL CHECK(source IN ('syslog','ssh','winrm')),
 event_key varchar(64) NOT NULL, channel varchar(64) NOT NULL,
 event_at timestamptz, received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 severity smallint NOT NULL CHECK(severity BETWEEN 0 AND 7), facility smallint CHECK(facility BETWEEN 0 AND 23),
 hostname varchar(255) NOT NULL, application varchar(255) NOT NULL, message text NOT NULL CHECK(octet_length(message)<=4096),
 UNIQUE(target_id,source,event_key), FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
CREATE INDEX system_events_target_page ON system_events(target_id,id DESC);
CREATE INDEX system_events_target_received ON system_events(target_id,received_at DESC);
CREATE INDEX system_events_retention ON system_events(received_at,id);
CREATE TABLE system_event_cursors (
 target_id uuid PRIMARY KEY, workspace_id uuid NOT NULL, target_version bigint NOT NULL,
 credential_id uuid NOT NULL, credential_version bigint NOT NULL,
 cursor jsonb NOT NULL, updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
INSERT INTO permissions(scope,description) VALUES ('system.events.read','Read sensitive system and network log messages') ON CONFLICT(scope) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope='system.events.read' ON CONFLICT DO NOTHING;
