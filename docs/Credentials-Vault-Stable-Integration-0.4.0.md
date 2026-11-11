# CajeerServers 0.4.0 — Credentials Vault Stable Integration

## Production behaviour

- The CJV1 Vault and CLI key enrollment, secret history, workspace scoping, ETag, CSRF, RBAC, write-only secret forms and independent Vault master key remain in place.
- An operator can stage a new encrypted `proxmox_api` or compatible `api_token` credential without disrupting the active secret.
- For every active Proxmox connector bound to that credential, the operator must validate the staged version **using the actual pending secret**, over verified HTTPS, before activating it. This is not a human checkbox bypass: the server rejects activation with HTTP 409 unless all required connectors were validated. Credentials without active Proxmox connector bindings retain the explicit external-confirmation flow.
- `/connectors` offers “Проверить новую версию” when the selected active Proxmox target has a pending Vault version. The operator enters their password and a fresh TOTP, requests a 120-second single-use asset credential grant and initiates a read-only discovery using the pending encrypted revision.
- The remote Proxmox adapter requires successful `/api2/json/nodes` and `/api2/json/cluster/resources?type=vm` responses, valid JSON and **at least one node**. Its TLS peer verification, hostname validation and CA trust settings are enforced. Failure returns 502 without exposing the token, host response or secret.
- Only after the remote API has succeeded does the server persist a validation record in PostgreSQL. That record contains credential ID, secret revision, connector ID, optimistic credential/connector versions, validator and timestamp. No plaintext, ciphertext or remote response is stored.
- Validation expires after **10 minutes**. Rotation activation checks every active bound Proxmox target again in the same PostgreSQL transaction. Credential/connector edits, rebinding or removal render old proofs unusable; successful activation and cancellation remove related proofs.
- `GET /vault/credentials/{id}/versions` returns `required_targets`, `validated_targets` and `activation_ready` only for pending versions. `/vault` shows progress and prevents accidental activation until required validations are complete.

## Deployment and recovery

1. Install PHP 8.4+ with `sodium` and `pdo_pgsql`, PostgreSQL 17+ (18 recommended), Nuxt dependencies and trusted CA bundle. For self-issued Proxmox certificates configure the readable `PROXMOX_CA_FILE` certificate bundle and **never disable TLS verification**.
2. Take a tested encrypted backup, including `VAULT_MASTER_KEY_FILE` and app config, before migrating. Deploy the changed files over **0.3.9**, not over earlier versions.
3. Stop PHP long-lived workers for deployment. Apply all migrations with `php bin/cajeer migrate`, including `0022_vault_validated_rotation.sql`. The migration adds only validation-proof metadata (not secret material).
4. Verify `php bin/cajeer vault:check` and `php scripts/verify-release.php`. Restore the service only after full health and migration readiness checks; if using cached long-lived PHP workers, restart them.
5. With a disposable test workspace, stage a Proxmox credential, open its bound active connector, use password+**fresh** TOTP to validate it, then activate in `/vault`. Confirm the old secret stops being used only after the transaction succeeds; test cancellation and expired validation too.

## Authorization and REST

`POST /api/v1/connectors/targets/{id}/validate-rotation` requires session authentication, CSRF, `If-Match` for the connector, `connectors.credentials.manage`, `vault.credentials.use` and `vault.credentials.update`, an owner/manager workspace role, an active Proxmox target and dual bindings to the same active credential. Body: `{ "revision": 2 }`. First request `/api/v1/vault/credentials/{credentialId}/authorize-use` with target `asset_id`, purpose `api`, password and TOTP. Never pass a secret in the validation request. The returned JSON contains only identifiers, bool, safe counts and revision.

The proof is not a substitute for secure device/connector configuration. Offline Key Encryption Key rotation requires downtime; high-availability key storage, hardware-backed keys, automatic failover, PITR and S3 snapshotting are not part of this release.

## Tests and unresolved release prerequisites

- `php tests/vault-stable-smoke.php`: parser, libsodium envelope separation and negative HTTP access. Included in `php scripts/verify-release.php`.
- `php tests/vault-bindings-postgres-integration.php`: isolated PostgreSQL schema, migrations, MFA + single-use grant, trusted pending-secret consumer, Proxmox response adapter, read-only discovery and DB validation proof, activation denial before validation, successful atomic activation after validation, stale revisions, no plaintext persistence, no CMDB changes.
- Existing Proxmox smoke covers real HTTPS certificate validation and rejection of untrusted CAs.
- Full PostgreSQL staging integration, real-device HTTPS, `npm ci`, Nuxt build/typecheck and browser E2E cannot be claimed as passed unless separately executed. The incoming repository does **not** contain `composer.lock` or `admin/package-lock.json`; valid dependency lockfiles must be generated with package managers against registries and committed before strict production promotion. Do not invent lockfiles.
