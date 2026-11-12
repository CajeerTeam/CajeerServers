# CajeerServers 0.4.1 — Unified Connector Runtime & Registry

## Upgrade from 0.4.0

Apply changed files over the existing tree, preserving paths. Existing PostgreSQL schema and credential encryption remain compatible; no migration is added. Apply any outstanding migrations from 0.4.0 first. Back up the database and configuration before deployment.

**Required production configuration change:** set `CONNECTOR_ALLOWED_NETWORKS` to explicit infrastructure CIDRs, e.g. `10.40.8.0/24,fd12:3456:789a::/64`. Outside `local`, `test`, and `development`, an empty allowlist denies all outbound connector requests. The list is operator configuration, not tenant input. Include only trusted endpoints; enforce egress restrictions at the network/firewall level too. In local/test/development an empty list permits ordinary addresses but rejects loopback and special addresses. Loopback can be explicitly allowed for an isolated fixture. Link-local, multicast, unspecified and known metadata addresses and IPv4-mapped IPv6 are always denied. All resolved addresses must pass policy; the transport connects to a pinned IP and retains the original TLS peer name and HTTP Host. Redirects are disabled. Keep `PROXMOX_CA_FILE` configured for internal CA trust. TLS verification cannot be disabled. Do not allow production access to the loopback fixture.

The HTTP client accepts only bounded GET responses; timeout is 8 seconds per connect/header I/O and body read deadline, maximum response is 2 MiB per request, and Proxmox caps discovery at 256 nodes and 1500 guests. System DNS resolution and stream header parsing are not a hard end-to-end deadline. Use network-level timeouts and PHP worker execution limits in production.

## Runtime

`ConnectorRegistry` stores actual trusted driver instances registered by `Application` DI, never classes supplied by HTTP or a database manifest. `ConnectorManifest` describes the implemented operations, accepted credential types/purpose, transport and resource types. `ConnectorContext` contains endpoint and credential type only. The secret is passed separately to `execute()` inside the one-use Vault consumer and is not stored in a result, metadata, queue or log. Registry validates normalized observations against the existing CMDB input rules, rejects duplicate external IDs and unadvertised resource types, and sanitizes driver errors. Drivers must not include secrets in observation fields.

The only registered production driver is Proxmox; `inspect`, `sync`, `validate_rotation`, and `health` perform actual API requests. The other existing provider IDs remain configurable for compatibility but cannot execute (501). Unsupported operations fail before a Vault grant is consumed. `health` checks `/api2/json/nodes` without importing records. No periodic monitoring or worker jobs are introduced; those are scheduled for later releases.

`ConnectorBindingService` checks the registered operation, workspace manager/owner role, target ETag, active state, supported credential type/purpose and matching asset binding. `ConnectorRuntimeService` spends a fresh password+TOTP Vault grant, dispatches through the Registry and rechecks binding and credential version after I/O and inside the sync transaction. Sync keeps the existing `proxmox:<target UUID>:<external id>` identity format and transactional HistoryService import. Empty sync is rejected. Vault pending activation proof uses registered driver capabilities and the existing 10-minute validation table; it is invalidated by revision changes or removal/recreation of the asset binding.

## API/UI

Existing routes and response shapes remain compatible (inspect, sync, validate-rotation). New authenticated GET `/api/v1/connectors/drivers` returns executable capabilities. New POST `/api/v1/connectors/targets/{id}/health` requires a fresh scoped grant, CSRF, If-Match, and the connector management + Vault use permissions. Returns `connector_id`, `status=healthy`, `duration_ms`; remote failures return sanitized 502. `/connectors` uses Registry metadata to display operation buttons and marks unregistered providers as configuration-only. `/vault` shows generic connector validation requirements.

## Verification

- `php scripts/verify-release.php` — native regression suites, lint and frontend contract checks.
- `php tests/connector-runtime-smoke.php` — actual Proxmox dispatch and independent test driver, rejection of unsupported operations, normalized results, network policy and route auth.
- `python3 tests/connector-https-integration.py` — real local HTTPS fixture (Python 3, OpenSSL CLI and PHP OpenSSL required), verified CA and hostname, pinned address with original Host, real discovery/health, rejected untrusted CA/hostname/redirect/oversize.
- `CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_connectors ... php tests/vault-bindings-postgres-integration.php` — disposable PostgreSQL 17+ database; existing integration expanded to unified sync, no duplicates, single-use grants, rotation and health. Fixture transport supplies synthetic provider data; real vendor staging must also be tested.

Local validation does not establish a production deployment. PostgreSQL integration, real Proxmox appliance, Nuxt build/typecheck and browser E2E remain release-gate requirements. Composer/npm lock files are absent in source and were not fabricated. VMmanager, SSH, WinRM, SNMP, third-party SDK, background grants, queue and reconciliation expansion are not part of this version. Trusted driver code is not sandboxed; provider IDs are the existing database allowlist (including `custom`), new identifiers require a schema/policy update.
