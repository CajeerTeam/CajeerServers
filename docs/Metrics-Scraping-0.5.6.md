# Metrics Scraping & Time-Series Storage — 0.5.6

## Upgrade

Backup PostgreSQL, stop scheduler/connector workers, overlay the changed files onto 0.5.5,
run `php bin/cajeer migrate` (0033), `php bin/cajeer vault:check`, and `php scripts/verify-release.php`.
Rebuild Admin UI and SDK using the installed dependency lockfiles. Run the PostgreSQL and real protocol integration checks before production use.

## Sources and scheduling

Use an Inventory asset and a connector bound to the same credential/Workspace. The six named collectors below run with the **inspect** grant, not sync. The existing plan creation API consumes a fresh MFA Vault grant, fixes target/credential version, collector and schedule, and uses the existing fenced queue worker. No metric data is written to CMDB.

* `prometheus.metrics`: new `prometheus` provider, HTTPS port 443 by default, fixed `/metrics`, opaque `api_token` as Bearer. Configure `PROMETHEUS_CA_FILE` for internal CA; `CONNECTOR_ALLOWED_NETWORKS` required in production. For exporters without TLS/auth put an operator-controlled TLS/auth reverse proxy in front. No plaintext HTTP or arbitrary path.
* `proxmox.metrics`: `/api2/json/nodes` and `/api2/json/cluster/resources?type=vm`; CPU ratio, memory/disk bytes, uptime, network/disk counters when returned. Resource IDs remain `node:*`, `qemu:*`, `lxc:*`.
* `vmmanager.metrics`: API v3 node/host state as `vmmanager.resource.up` (0/1); unknown states omitted. No undocumented utilization API.
* `ssh.metrics`: fixed Python collector reads `/proc/stat` over 250 ms, `/proc/meminfo`, `/proc/net/dev`, root filesystem `statvfs`. CPU ratio, total/available memory/disk, network byte counters. Existing host key policy and SSH credential support.
* `winrm.metrics`: fixed PowerShell/CIM collector, CPU formatted counter, memory, system drive, raw network byte counters. Existing verified HTTPS Basic policy; Windows PowerShell 5.1. No arbitrary scripts.
* `snmp.metrics`: sysUpTime, IF-MIB ifHCInOctets/ifHCOutOctets and ifInErrors/ifOutErrors with numeric ifIndex labels. Existing bounded GET/GETNEXT, SNMPv3 authPriv by default (legacy v2c opt-in), exact Counter64 decimal values. Unsupported optional tables may be empty; no fabricated zeros.

Example after creating target and bindings and obtaining a fresh `/vault/credentials/{id}/authorize-use` grant:

```http
POST /api/v1/connectors/targets/{id}/collection-plans
If-Match: "<target-id>:<version>"
X-CSRF-Token: <token>
Content-Type: application/json

{"name":"Exporter metrics","collector":"prometheus.metrics","operation":"inspect","ttl_seconds":86400,"interval_seconds":300}
```

`php bin/cajeer scheduler:run` and `php bin/cajeer connector:work 20` use the existing scheduler/worker. A plan can be paused, revoked or manually executed via the existing collection plan endpoints. The result includes `collection.metric_samples`, count and `metrics` insert/dedup statistics. Only the detailed run contains samples; list results omit them. The run and samples commit together after grant, lease, role and binding revalidation. A cancelled/revoked run cannot commit samples. No automatic script ingestion in this version.

## Storage semantics

`metric_series` is scoped to Workspace, target, linked asset, target configuration revision and hash of metric name/resource/normalized labels. Target revision prevents blending endpoints after reconfiguration. A series has fixed kind/unit; changes conflict rather than silently reinterpreting history. Value is PostgreSQL `numeric`, not float; Counter64 is stored/returned as decimal strings. Metric labels are data, not permissions; do not expose exporter secrets or credentials as labels. Resource IDs denote source-specific identifiers, not user-supplied Inventory UUIDs.

`metric_samples` is monthly UTC RANGE-partitioned on timestamp. Partition provisioning uses a transaction-scoped lock and creates missing partitions within the retention window; the app DB role must own the metric tables (or operators must precreate current/upcoming partitions using the migration owner). The unique key is `(series_id, observed_at)`. Repeated same value is idempotent; a different value at that timestamp fails the transaction. Samples without a source timestamp use the runtime's database-clock observation timestamp. Explicit timestamps must be UTC and between now-30 days and now+5 minutes. Limits: 2000 samples/batch, 1 MiB normalized output, 16 labels/sample, 256 bytes/label value, 10000 series/workspace. Prometheus raw text limit 256 KiB. Large scrape endpoints should be filtered/sharded upstream. Prometheus text 0.0.4 supports scalar samples and explicit millisecond timestamps; TYPE counter/gauge and scalar histogram buckets/counts supported, NaN/Inf rejected, no native histograms/exemplars or complete OpenMetrics 1.0 negotiation. No arbitrary PromQL.

## Queries

`GET /api/v1/connectors/targets/{id}/metric-series?metric=system.cpu.utilization&limit=100&offset=0`

`GET /api/v1/connectors/targets/{id}/metric-series/{seriesId}/samples?from=2026-11-12T00:00:00Z&to=2026-11-12T01:00:00Z&aggregation=avg&step=60`

Both require session, connectors.targets.read, inventory.assets.read and Workspace membership. Series metadata and samples are scoped to the target, including historical revisions. Time range `[from,to)` is at most 30 days; raw returns up to 2000 points, aggregates up to 2000 buckets with input limit 200000 points. `avg/min/max/count` are query-time downsampling with UTC epoch-aligned buckets. `rate` only for counters: sum of nonnegative deltas / observed elapsed seconds per bucket; decreases treated as resets (delta=current), no assumption of modulus or wraparound. First sample in requested range has no predecessor, no extrapolation; intervals are attributed to their later sample's bucket. Explicit discontinuity or missed polls affect the estimate. No persistent long-term rollups yet. Numeric results are decimal strings; UI graph uses approximate JS number, table preserves exact strings.

Use the new panel in `/connectors` to browse series, choose range and aggregation, and inspect chart/table. TypeScript SDK `metricSeries()` and `metricQuery()` use the same authorized API.

## Retention

`php bin/cajeer metrics:retain 10000` removes at most 10000 samples older than 30 days per invocation, using SKIP LOCKED, then removes bounded empty expired series under workspace locks. Schedule it each minute using the provided systemd timer or cron. Adjust batch size (max 100000) to sustained ingestion; monitor rows deleted and database disk/WAL. Range queries hide expired data even if cleanup lags. Monthly partitions are retained as empty tables; operators can drop fully expired partitions in a maintenance window. No destructive drop during collection. Existing connector run history stores normalized result snapshots and is governed by existing run retention; this job does not prune run history or backup files. This is a 30-day raw storage baseline, not a long-term monitoring replacement.

## Tests / deployment gate

`php tests/metrics-smoke.php`, `python3 tests/metrics-https-integration.py`, `php tests/metrics-postgres-integration.php` (requires the existing CAJEER_PG_TEST settings and disposable PostgreSQL 17/18). Native SNMP, SSH, WinRM and VMmanager/Proxmox appliance tests must also be run in staging. No actual production hosts are touched by fixtures. Full strict release-gate remains required.
