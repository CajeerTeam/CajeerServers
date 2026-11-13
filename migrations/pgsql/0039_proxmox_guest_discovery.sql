-- Detailed guest state is scoped to the same target and credential revisions as cluster state.
CREATE TABLE proxmox_guest_state (
 target_id uuid PRIMARY KEY,
 workspace_id uuid NOT NULL,
 target_version bigint NOT NULL CHECK(target_version>=1),
 credential_id uuid NOT NULL,
 credential_version bigint NOT NULL CHECK(credential_version>=1),
 observed_at timestamptz NOT NULL,
 snapshot jsonb NOT NULL CHECK(jsonb_typeof(snapshot)='object' AND octet_length(snapshot::text)<=1048576),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
-- Keep the first known generation evidence even if an ACL hides the guest or credentials rotate.
CREATE TABLE proxmox_guest_identity (
 target_id uuid NOT NULL,
 workspace_id uuid NOT NULL,
 vmid integer NOT NULL CHECK(vmid BETWEEN 1 AND 999999999),
 guest_type text NOT NULL CHECK(guest_type IN ('qemu','lxc')),
 fingerprint text CHECK(fingerprint ~ '^[0-9a-f]{64}$'),
 PRIMARY KEY(target_id,vmid),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
