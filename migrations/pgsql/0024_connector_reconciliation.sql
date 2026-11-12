-- Stable connector identities and non-destructive snapshot reconciliation.
CREATE SEQUENCE connector_snapshot_generation AS bigint;
CREATE TABLE connector_discovery_state (
 target_id uuid PRIMARY KEY, workspace_id uuid NOT NULL,
 endpoint_host varchar(253) NOT NULL, endpoint_port integer NOT NULL,
 generation bigint NOT NULL DEFAULT 0, scope_hash char(64),
 last_snapshot_at timestamptz, last_complete_at timestamptz,
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
CREATE TABLE connector_discovered_resources (
 target_id uuid NOT NULL, workspace_id uuid NOT NULL,
 external_id varchar(180) NOT NULL, resource_type varchar(64) NOT NULL,
 asset_id uuid, presence varchar(12) NOT NULL DEFAULT 'unknown' CHECK(presence IN ('unknown','seen','stale','missing')),
 first_seen_at timestamptz, last_seen_at timestamptz, absent_since timestamptz,
 missed_complete integer NOT NULL DEFAULT 0 CHECK(missed_complete>=0),
 last_generation bigint NOT NULL DEFAULT 0,
 conflict_code varchar(48), observed_name varchar(255), parent_external_id varchar(180), allow_create boolean NOT NULL DEFAULT false,
 PRIMARY KEY(target_id,external_id), UNIQUE(asset_id),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE,
 FOREIGN KEY(asset_id,workspace_id) REFERENCES inventory_assets(id,workspace_id) ON DELETE RESTRICT
);
CREATE INDEX connector_discovered_presence ON connector_discovered_resources(target_id,presence,external_id);
CREATE INDEX connector_discovery_candidate_name ON inventory_assets(workspace_id,type,lower(name)) WHERE deleted_at IS NULL;
-- Preserve the IDs created by 0.3.7–0.4.2. A legacy row is unknown until seen in a new snapshot.
INSERT INTO connector_discovered_resources(target_id,workspace_id,external_id,resource_type,asset_id,observed_name)
SELECT t.id,t.workspace_id,substring(a.external_id FROM length(t.provider||':'||t.id::text||':')+1),a.type,a.id,a.name
FROM connector_targets t JOIN inventory_assets a ON a.workspace_id=t.workspace_id AND a.source='import'
 AND left(a.external_id,length(t.provider||':'||t.id::text||':'))=t.provider||':'||t.id::text||':'
WHERE length(substring(a.external_id FROM length(t.provider||':'||t.id::text||':')+1)) BETWEEN 1 AND 180;
INSERT INTO connector_discovery_state(target_id,workspace_id,endpoint_host,endpoint_port)
SELECT t.id,t.workspace_id,t.endpoint_host,t.endpoint_port FROM connector_targets t
WHERE EXISTS(SELECT 1 FROM connector_discovered_resources r WHERE r.target_id=t.id);
