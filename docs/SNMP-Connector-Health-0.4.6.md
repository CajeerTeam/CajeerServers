# 0.4.6 — SNMP & Connector Health

## Upgrade from 0.4.5

Stop connector workers/scheduler, take and verify an encrypted backup, overlay the changed files, and apply `php bin/cajeer migrate` (0026_connector_health.sql). Restart workers after migration. PHP 8.4+ `ext-snmp` (Net-SNMP) must be installed in the CLI and web runtime for SNMP targets, e.g. `php8.4-snmp` on distributions providing that package. Verify with `php -m`. Existing HTTP/SSH/WinRM targets do not require this extension.

```dotenv
CONNECTOR_ALLOWED_NETWORKS=10.40.8.0/24
CONNECTOR_SNMP_ALLOW_V2C=false
```

Use actual approved management subnets; permit outbound UDP to the configured SNMP port (default 161). DNS is resolved and checked before opening a session to a pinned IP. The setting enables legacy v2c only when explicitly true; v2c sends community strings in cleartext, so use only isolated trusted management networks. SNMPv3 uses authPriv with SHA256 or SHA512 and AES128. AES256 and authNoPriv are not supported by this driver. Configure a read-only SNMP user on the device (no write view), with access to the standard system, interfaces and IP MIB subtrees.

In Vault create `snmp_v3` (username, auth_protocol, auth_passphrase, privacy_protocol, privacy_passphrase), or `snmp_v2c` (community) if enabled. Create target provider `snmp`, attach an Inventory asset and bind the credential with purpose `snmp`. Use MFA step-up before manual health, inspect, sync, and pending rotation verification. Only GET and GETNEXT of fixed standard OIDs are supported; no SNMP SET, arbitrary OIDs or scripts.

Health/rotation read sysName and sysObjectID. Sync also reads sysDescr, ifNumber, ifDescr, ifOperStatus, IPv4 ipAdEntIfIndex and modern IPv4/IPv6 ipAddressIfIndex. Maximum 64 interfaces/addresses and 64 rows per subtree; 45-second operation budget, one-second request timeout and no transport retry. Invalid/nonmonotonic OIDs, incomplete interface counts, malformed fields, and addresses referencing unknown interfaces fail closed. A single router observation has endpoint-scoped external ID `device` and a partial snapshot; interface data is `attributes.network_device`. Empty address tables do not erase existing IP addresses. Manual attributes retain provenance protection. Hardware replacement at the same endpoint is not automatically distinguished; sysObjectID is a model identifier, not a serial number. No automatic IPAM reservations or CMDB interface asset creation.

## Operational health

All registered drivers record authorized remote outcomes, not local RBAC/grant failures. `connector_health_current` exposes unknown, healthy, degraded, unreachable, auth_failed, disabled; target list/detail show last checked, last success, duration, safe error code and consecutive failures. State is scoped to exact target and active credential version: changing either invalidates the displayed state. Pending rotation checks do not change active health. Health never changes Inventory assets. A successful operation resets the failure count and breaker. Three remote failures open a five-minute cooldown; explicit authentication failures open immediately for five minutes; rate limiting opens for one minute (five after three failures). SNMP authentication errors may appear as timeouts when the agent silently drops requests. Manually authorized health probes can run while open; regular inspect/sync and background jobs cannot. Scheduler skips open circuits and resumes at the next due interval after cooldown. Queued work that hits the breaker ends with a safe error; new scheduled runs are created subsequently. No automatic grant renewal.

Issue an MFA-scoped service grant for operation `health` with `interval_seconds` (60–86400), using the existing target `/service-grants` API and `If-Match`, then run `php bin/cajeer scheduler:run` and `php bin/cajeer connector:work 20` under cron/supervisor. A `sync` grant remains separate. See Connector-Execution-0.4.2.md for exact requests. PostgreSQL advisory locks enforce one remote operation per target, four per workspace, eight per provider across manual/background workers. Locks are released at completion or connection death; use dedicated PostgreSQL sessions (not transaction-mode connection pooling). Contention is retried by worker with existing bounded backoff.

The existing Prometheus exporter includes `cajeer_connector_health_targets`, `cajeer_connector_circuit_open`, `cajeer_connector_health_checks_total`, `cajeer_connector_health_duration_seconds_total`. Labels are provider/status/outcome only, without endpoint or secret labels. Metrics track all authorized remote operations, not only health probes.

## Verification

`php scripts/verify-release.php` includes SNMP/health smoke tests. `bash tests/snmp-protocol-integration.sh` requires native ext-snmp and snmpd and tests real loopback v2c and v3 authPriv. `CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_health ... php tests/connector-health-postgres-integration.php` requires a disposable PostgreSQL 17+ database and the usual test connection environment; uses an isolated schema, real Vault/queue/scheduler and deterministic SNMP session. Run both on staging before production and test actual appliance access/views. Native protocol and PostgreSQL tests are included in CI/strict release gate; they were not executed in the local authoring environment because dependencies were unavailable. Full Nuxt build/E2E also requires installed dependencies.
