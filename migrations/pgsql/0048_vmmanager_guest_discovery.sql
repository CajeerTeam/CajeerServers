-- Last observed guest state. The append-only execution history remains in connector_runs.
CREATE TABLE vmmanager_guest_state (
 target_id uuid PRIMARY KEY,
 workspace_id uuid NOT NULL,
 target_version bigint NOT NULL CHECK(target_version>=1),
 credential_id uuid NOT NULL,
 credential_version bigint NOT NULL CHECK(credential_version>=1),
 observed_at timestamptz NOT NULL,
 snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object' AND octet_length(snapshot::text)<=1048576),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
