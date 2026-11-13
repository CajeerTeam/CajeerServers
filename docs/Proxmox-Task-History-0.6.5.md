# Proxmox Task History & Events — 0.6.5

## Deployment

Apply migration `0041_proxmox_task_history.sql` with `php bin/cajeer migrate`, rebuild the Admin UI and restart workers. Use a verified PVE 8/9 TLS profile and an API credential bound to the Inventory asset and connector. In Collections → Plans and runs create `proxmox.tasks` with operation `inspect`, select an interval (e.g. 60 seconds), authorize using the existing MFA-scoped service grant and run it. No CMDB modifications, task stop, remote writes or task log downloads occur. `Sys.Audit` on `/nodes/<node>` is required to see tasks of other users; otherwise PVE returns only permitted tasks. See upstream [PVE task API](https://github.com/proxmox/pve-manager/blob/master/PVE/API2/Tasks.pm).

## Incremental algorithm

- Up to four visible nodes per run, persisted round-robin order; 100 records per API page. Initial archive backfill starts 30 days before the first scan of a node. A fixed upper bound prevents moving time windows; dense intervals are split until a short page is returned. A single second with >=100 entries uses overlapping offset pages. Cursor advances only on successful pages. A 10-minute replay window covers delayed archive writes.
- Independent archive-head scan follows the last observed UPID across runs, including tasks that started long before the current window and completed recently. Scan continuation stores the candidate, previous anchor and overlapping offset; if the old anchor is no longer present, it scans to a short page and reports `anchor_lost` (possible retention/ACL gap). The first head page can include tasks older than 30 days.
- Active task pages rotate; up to 20 oldest unchecked running UPIDs are polled with a separate time budget. Missing/denied/offline status checks never infer completion. The PVE status endpoint can omit endtime: keep NULL until archive evidence supplies it.
- Stable identity is the full UPID scoped to target, target version and credential identity/version. `task_discovered`, `task_completed`, `task_outcome_resolved` are unique per UPID and event kind. Terminal status cannot regress to running. Unknown can later resolve; endtime enrichment does not duplicate completion. Event occurrence and local observation times differ; a completion with unknown endtime has NULL occurrence.
- Cursor revision and all writes commit in the existing authorized run transaction. Failed/revoked/cancelled jobs cannot advance progress. Credential/profile revision changes start a fresh scope; old scopes remain stored but are not exposed by the current endpoint. No raw task log or arbitrary exit message is persisted. UPID, resource ID and PVE principal are visible metadata.

## Read API and UI

`GET /connectors/targets/{id}/proxmox-tasks?view=tasks|events&limit=50&before=<id>&state=running|succeeded|warning|failed|unknown`. Requires session, connectors.targets.read, inventory.assets.read and Workspace membership. Keyset pages sort by local insertion ID descending, not remote start time; `next_before` is a string or null. Admin Collections → Collectors displays history/events and per-node progress. `available` is saved visible data, not proof of global completeness; `stale` requires a new collection. SDK: `proxmoxTasks`, `proxmoxTaskEvents`.

## Operational limits

The upstream node API is ACL-filtered, retention-limited and uses offset pagination; it does not provide a transactional snapshot. Concurrent archive rotation, very high task rates, long downtime or previously hidden tasks can cause gaps. Overlap, time slicing and head replay reduce but cannot eliminate them. A short page only proves exhaustion of that visible query, never deletion or full historical coverage. History is retained until target deletion; monitor PostgreSQL growth and plan retention/archival separately. The collector is bounded per invocation; schedule repeated runs to catch up large clusters. No raw log ingestion, live streaming or external notification dispatch in this release.
