# Collector Runtime & Registry — 0.5.1

## Upgrade

Back up the database and stop connector workers before deploying the changed files over 0.5.0. Run `php bin/cajeer migrate` (migration 0029 adds a nullable name to existing connector runs; previous runs remain valid). Run `php scripts/verify-release.php`, `php bin/cajeer connector:doctor --json` and the strict release gate on disposable staging before deployment. Use the existing PostgreSQL `connectors` queue and `php bin/cajeer connector:work 20` under a process supervisor. The worker's existing 240-second hard timeout and 300-second lease apply.

## Available collectors

`proxmox.inventory`, `vmmanager.inventory`, `ssh.inventory`, `winrm.inventory`, `snmp.inventory`; an installed custom connector also exposes `custom.inventory` if its actual manifest supports inspection or sync. `php bin/cajeer collection:list` lists registered executable capabilities. The collectors use the existing connector drivers (including TLS/SSH trust, CIDR, bounded transport, Vault bindings and normalized resource validation). No alternate remote endpoint or arbitrary commands are accepted.

## Run a collection

1. Configure a target with an Inventory asset and compatible active Vault credential. Use the existing MFA step-up to authorize a service grant for `inspect` or `sync` in the target's workspace. A health grant cannot collect. `inspect` stores a normalized snapshot and preview without changing Inventory; `sync` also reconciles the same snapshot using existing stable IDs, generation fencing, provenance and transactions. Both modes require Inventory read permission.
2. In `/connectors`, select the target, Runs tab, service grant, then the named collector. Submit. The existing REST API also accepts the optional `collector` field:

```http
POST /api/v1/connectors/targets/<target-uuid>/runs
Content-Type: application/json
X-CSRF-Token: <session-token>
Idempotency-Key: collect-20261112-01

{"grant_id":"<grant-uuid>","collector":"proxmox.inventory"}
```

The response is 202 and contains the run ID. Reusing the same grant/key/name returns the same run; changing the collector with that key is a conflict. Unknown/mismatched collectors and health grants are rejected before any secret is decrypted. CLI (trusted local operator, existing grants only):

```bash
php bin/cajeer collection:run <actor-uuid> <target-uuid> <grant-uuid> collect-01 proxmox.inventory
php bin/cajeer connector:work 20
php bin/cajeer collection:status <actor-uuid> <target-uuid> <run-uuid>
```

3. Read `GET /api/v1/connectors/targets/<target-uuid>/runs/<run-uuid>` for status and `result.collection` (schema_version, collector, sink, observed_at UTC, complete, count, observations). The list response omits the collection observations; the detail response includes them. The snapshot contains normalized infrastructure observations, not raw HTTP responses, stdout, passwords or tokens. It may contain sensitive infrastructure metadata; normal Workspace and Inventory permissions apply. The collection portion is limited to 2 MiB and 2000 resources. Over-limit results fail rather than truncate. The existing preview/reconciliation response is retained. A sync with no resources is rejected rather than clearing Inventory. Snapshot timestamp represents start of collection; completeness follows the connector's existing semantics. No historical metrics are generated.

## Execution and compatibility

Queue jobs retain an empty payload. The named collector is persisted in connector_runs, scoped by the existing grant. The worker validates registry, target, grant, actor and credential before decrypting, then checks live permissions and lease again before committing. Result and Inventory commit together; existing compact ACK and recovery prevent repeated application. Cancellation and revocation use the existing API. Existing runs, scheduler ticks, signed connector events and manual inspect/sync remain unchanged unless the caller explicitly selects a named collector. Named scheduled collection plans, signed scripts, parsing of raw outputs, metrics, push ingestion and retention are future 0.5.x features; the current grant's interval still schedules ordinary connector operations, not the named collection.

The registry exposes a trusted PHP CollectorInterface with immutable context/plan. Built-in collectors reuse ConnectorRegistry rather than implement alternate transport or Vault access. Register trusted adapters through the DI registry; public arbitrary collector registration is not supported. Do not run untrusted PHP in this process. Future SDK work will cover separate parser/exporter extension contracts. TypeScript `enqueue(id, grantId, key, collector?)` uses the same endpoint.

## Tests

`php tests/collector-runtime-smoke.php` covers registry, execution, policy mismatch, validation, result limits and DI. `python3 tests/connector-https-integration.py` runs an actual TLS Proxmox-compatible collection. The existing `tests/connector-health-postgres-integration.php` now includes named inspect/sync, saved snapshot, idempotency, cancellation and grant revocation via a deterministic SNMP fixture. Requires isolated PostgreSQL 17/18 with pdo_pgsql and `CAJEER_PG_TEST=1` / `CAJEER_PG_TEST_DATABASE=cajeer_test_*`. Strict gate also includes all prior protocol and browser tests. Do not treat a successful local smoke run as full deployment approval.
