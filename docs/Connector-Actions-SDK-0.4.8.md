# Connector Actions & Extension SDK — 0.4.8

## Upgrade

Stop connector workers, take a verified backup and apply `php bin/cajeer migrate` (0028). Power actions are OFF by default; enable `CONNECTOR_WRITE_ACTIONS=true` only on a controlled installation. The operator needs `connectors.actions.execute`, `connectors.targets.read`, `connectors.credentials.manage`, `vault.credentials.use`, `inventory.assets.read` and workspace owner/manager. History requires `connectors.actions.read` and target visibility. New scopes are granted to administrator by migration. Use a restricted Proxmox API token with read permissions and VM.PowerMgmt **only for selected test/managed VMs**. Do not use an unrestricted cluster administrator token. Existing service grants and webhooks cannot invoke write actions.

## Power execution

1. Synchronize the Proxmox target; the QEMU VM must be mapped, seen and conflict-free. Get current target ETag and its bound inventory asset/credential ID.
2. Use `POST /api/v1/vault/credentials/{credentialId}/authorize-use` with `asset_id`, `purpose: api`, password and fresh TOTP; authenticated session and CSRF are required.
3. POST `/api/v1/connectors/targets/{id}/actions` with session cookie, fresh `X-CSRF-Token`, `If-Match` target ETag, `Idempotency-Key` (16–128 chars, unique per intent) and JSON `{"action":"vm.start","external_id":"qemu:101","confirm":"vm.start qemu:101"}`. Shutdown uses `vm.shutdown` and `vm.shutdown qemu:101`. Only QEMU, no stop/force or user-supplied remote commands/paths.
4. The driver re-discovers the current VM/node and dispatches one verified-TLS POST. The persistent intent is committed before remote POST. A target advisory lock and workspace/credential locks serialize concurrent operations. HTTP 202 with `submitted` means accepted by Proxmox, not succeeded. `GET /targets/{id}/actions/{actionId}` reads stored status, `POST .../refresh` with fresh MFA grant, CSRF and ETag reads the remote UPID task result (no write). A stopped task with exitstatus OK marks succeeded, otherwise failed; running remains submitted.
5. If the remote result is ambiguous, status `unknown` or `dispatching` blocks additional actions on the same target/VM. Never repeat with a new idempotency key. Verify in Proxmox and use `POST .../acknowledge` with a fresh MFA grant and `{"confirm":"I verified the remote result"}` to mark acknowledged, not succeeded. Submitted tasks can also be acknowledged after independent verification if original binding is no longer usable. GET history remains available when write actions disabled. Same key returns the existing operation, not a duplicate POST; if target version changed use history instead. No automatic write retries or background grants.

Power submission runs in a bounded HTTP request, with Proxmox executing its own asynchronous task. The durable ledger is deliberately not a queue for remote writes: there is no unattended execution or automatic polling. Transport timeout may be ambiguous; recovery is explicit. Multiple targets pointing to the same cluster are not globally deduplicated; maintain one target per cluster. VMID reuse is not independently detectable; restrict remote token ACL and review the target/VM before action.

## PHP SDK v1

`packages/php-sdk/src/Connector.php` extends the host runtime interface; `HttpConnector` supplies verified HTTPS and token decoding. SDK code runs inside the trusted PHP host, not a sandbox. The operator installs a reviewed PHP file outside the web root and sets `CONNECTOR_EXTENSION_FILE=/absolute/path/entry.php`. It returns a closure `(HttpsTransport $transport, int $sdkVersion): ConnectorInterface` and must check version 1. Only one `custom` provider is supported, with existing `api_token`/`generic_secret` binding policies. Duplicate providers and write-capable custom drivers are rejected. Drivers receive plaintext only during trusted consumer execution and must not log/store it. Runtime validates observations. SDK does not give untrusted code permission isolation; use filesystem controls and review code before installation. See `examples/extensions/demo-connector/entry.php` for a working custom API driver. It calls `GET /inventory/v1/servers` on the configured target with bearer token and expects `{"data":[{"id":"s1","name":"server-one","status":"online"}]}`. Set `DEMO_CONNECTOR_CA_FILE` for private CA; network allowlist still applies. Create `custom` target and `api_token` credential with purpose `api`, attach inventory asset, then health/inspect/sync. The driver returns partial snapshots so missing resources are not inferred. Test fixture: `python3 tests/connector-actions-https-integration.py`.

## TypeScript SDK

`cd packages/ts-sdk && npm install && npm run build`; use `new CajeerServersClient('/api/v1')` from a logged-in same-origin browser. Methods for target CRUD (create/update/read/delete), bindings, MFA authorization, health/inspect/sync, grants, runs, resources and actions. Each mutation obtains fresh CSRF and serializes writes per SDK instance; do not run independent clients concurrently on the same session. Errors are `ApiError` with HTTP status/problem; no implicit retries. ETag is explicit, no silent conflict overwrite. Example:

```ts
const target = await client.getTarget(targetId)
await client.authorizeUse(credentialId, target.asset_id!, 'api', password, totp)
const action = await client.power(target.id, target.etag, 'vm.start', 'qemu:101', 'vm.start qemu:101', crypto.randomUUID())
console.log(action.id, action.status)
// Later: fresh MFA authorization, then client.refreshAction(target.id, target.etag, action.id)
```

TypeScript types are maintained against the existing API, not a generated full OpenAPI SDK. Client uses browser cookies; Node requires a cookie-aware fetch supplied by caller. Do not store passwords or credentials in client state. No Nuxt action UI in this release (0.4.9).

## Verification

`php scripts/verify-release.php`, `python3 tests/connector-actions-https-integration.py`, `bash tests/connector-sdk-integration.sh`. PostgreSQL integration test requires isolated `CAJEER_PG_TEST=1`, `CAJEER_PG_TEST_DATABASE=cajeer_test_*`, PostgreSQL 17+ and pdo_pgsql. Actual power changes on a real appliance require an isolated test VM and controlled staging. Never run production action tests against live workload.
