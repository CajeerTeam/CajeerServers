-- Additive provenance for OS/RouterOS IPAM imports. Manual records are never adopted.
CREATE TABLE network_discovery_ipam_import (
 target_id uuid NOT NULL, workspace_id uuid NOT NULL,
 kind text NOT NULL CHECK (kind IN ('network','interface','address')),
 external_key varchar(180) NOT NULL, object_id uuid NOT NULL,
 object_version bigint NOT NULL CHECK (object_version>0),
 PRIMARY KEY(target_id,kind,external_key), UNIQUE(kind,object_id),
 FOREIGN KEY(target_id,workspace_id) REFERENCES connector_targets(id,workspace_id) ON DELETE CASCADE
);
