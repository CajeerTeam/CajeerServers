# Proxmox Backup Server integration (0.6.8)

## Setup

1. Apply `php bin/cajeer migrate` (migration 0044). Configure the existing connector outbound CIDR allowlist (`CONNECTOR_ALLOWED_NETWORKS`).
2. PBS uses HTTPS on port 8007. Set `PBS_CA_FILE` to the operator-managed PEM CA chain when PBS is not trusted by the system trust store. The certificate must match the configured DNS name/IP. No self-signed bypass, redirect or user-provided endpoint path is supported. Restart web and workers after changing environment.
3. In PBS create a dedicated read-only user and API token. Assign DatastoreAudit on the intended datastore/namespace to both user and token (PBS token privileges are intersected with user privileges). Sync job visibility can also require Remote.Audit on the source. Do not grant Admin merely for discovery. PBS may silently filter rows based on ACL.
4. In CajeerServers create an Inventory server asset and a connector target `pbs`, endpoint hostname, port 8007, bound to that asset. Create an `api_token` Vault credential with scalar value `user@realm!token:secret`; bind it for purpose `api` to the target and asset. Never use a PVE `token=secret` header or copy the secret into the target name or URL.
5. Verify health via MFA, then create a collection plan `pbs.backups`, operation `inspect`, with an authorized Vault service grant. Use a reasonable interval (e.g. 300 seconds). Run once and open Data Collection → Collectors → PBS.

## Runtime

The driver calls `/api2/json/version` and `/api2/json/admin/datastore`, then uses only fixed GET paths `/admin/{sync,verify,prune}`, `/admin/datastore/{store}/{status,gc,namespace,groups,snapshots}`. Namespace query parameters are URL-encoded. The adapter supports PBS major versions 3 and 4; no PVE credential or action driver is used. Health/rotation require authenticated datastore enumeration (an empty visible list is legitimate).

Datastores and backup groups are scoped by name, namespace, type, ID; snapshots additionally by backup time. Capacity, sizes and retention counters use decimal strings to preserve unsigned 64-bit values. Snapshot size is logical, not unique deduplicated disk usage. Job statuses are normalized to finite values; raw error messages, notes, archive filenames, datastore paths, encryption fingerprints and remote configs are not returned. GC is per datastore. PVE-scheduled vzdump jobs are not PBS jobs.

A successful plan saves `pbs_backup_state` with target and credential revisions in the same transaction as `connector_runs.result`. Current read uses `GET /api/v1/connectors/targets/{id}/pbs-backups` (target read + inventory read + workspace membership). It returns `not_collected`, `available`, or `stale`. Rebinding, disabling, target edit and credential rotation invalidate the current view. Historical scheduled runs remain readable with normal inventory/target permissions. Manual Inspect also refreshes current state. No CMDB assets or relationships are created, changed or deleted by this collector.

## Completeness and operational limits

`complete` means successful bounded collection of the token-visible scope, never full server visibility. A 403/404 in an optional section produces an explicit issue; 401, TLS/transport errors, malformed data and server errors fail the run rather than overwrite a prior successful observation. The worker rechecks its grant and revisions before writing. Collection is capped at 100 discovery requests, ~30 seconds between request starts, 2500 normalized records and ~700 KB payload; individual requests use existing transport timeouts and 2 MiB body limit. If capped, the snapshot is explicitly partial. PBS API snapshot listings are not paginated; very large stores may need a more narrowly scoped token. The collector does not implement incremental backfill for larger installations. Repeated runs are full refreshes of visible scope.

## Tests

`php tests/pbs-backup-smoke.php` validates normalized fields, identity collisions, ACL, bounds, provider policy and migration parsing. `python3 tests/pbs-backup-https-integration.py` exercises the actual HTTPS transport and registered collector with a local verified TLS server for PBS 3/4 response shapes, invalid auth, ACL, malformed data, redirects, wrong CA/hostname and rotation/health. Strict deployment verification additionally requires PostgreSQL, browser E2E and a real staging PBS connection; mock API responses are not certification against all PBS releases.

References: [PBS API viewer](https://pbs.proxmox.com/docs/api-viewer/index.html), [PBS documentation](https://pbs.proxmox.com/docs/), [PBS upstream source](https://github.com/proxmox/proxmox-backup).
