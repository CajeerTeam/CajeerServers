-- Last observed network state. The append-only execution history remains in connector_runs.
CREATE TABLE vmmanager_network_state (
 target_id uuid PRIMARY KEY,
 workspace_id uuid NOT NULL,
 target_version bigint NOT NULL CHECK(target_version>=1),
 credential_id uuid NOT NULL,
 credential_version bigint NOT NULL CHECK(credential_version>=1),
 observed_at timestamptz NOT NULL,
 snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object' AND octet_length(snapshot::text)<=1048576),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);

-- Provenance only for rows created by this connector. Manual edits invalidate version ownership.
CREATE TABLE vmmanager_ipam_import (
 target_id uuid NOT NULL, workspace_id uuid NOT NULL, kind text NOT NULL CHECK(kind IN ('network','interface','address')),
 external_key varchar(180) NOT NULL, object_id uuid NOT NULL, object_version bigint NOT NULL CHECK(object_version>0),
 PRIMARY KEY(target_id,kind,external_key), UNIQUE(kind,object_id),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
