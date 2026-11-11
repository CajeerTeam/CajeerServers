# CajeerServers 0.3.5 — Credential Versioning & Rotation

## Scope

This release implements encrypted **local** secret versioning and an **offline** Vault master-key rewrap. It does **not** automatically revoke or rotate tokens on Proxmox, VMmanager, SSH, SNMP or WinRM endpoints. Do not mark a staged secret as active until you have independently verified that the external service recognizes it.

Existing `credentials` rows and UUIDs remain in place. Migration `0020_vault_versioning_rotation.sql` adds `secret_version`, a version-history table, an active verifier and rotation events. It backfills CJV1 records, including archived records, without decrypting their payloads. Existing migration files MUST NOT be modified after they have been applied.

## Encrypted credential versions

- `credentials.version` is the optimistic concurrency/ETag revision. Any metadata or rotation operation increments it.
- `credentials.secret_version` identifies the active encrypted payload, and changes only when an encrypted payload is activated/replaced.
- `vault_credential_versions` stores ciphertext for `active`, `pending`, `retired` and `discarded` versions. `GET .../versions` exposes only version numbers, state and timestamps.
- The 0.3.2 direct `PUT .../secret` also stores the superseded encrypted version; it refuses changes while a rotation is pending.
- `POST /api/v1/vault/credentials/{id}/rotation` takes `{ "secret": ... }` with a strong `If-Match` and CSRF token. The value is validated for the credential type and encrypted immediately. It does not replace the active secret.
- After **external** validation, `POST /api/v1/vault/credentials/{id}/rotation/{revision}/activate` takes `{ "confirmed": true }`, the current ETag and CSRF. Activation retires the former active version and atomically installs the staged ciphertext.
- To abort, `POST /api/v1/vault/credentials/{id}/rotation/{revision}/cancel` requires the current ETag and CSRF. Discarded versions are never activated.
- Mutations require an authenticated browser session, `vault.credentials.update`, workspace owner/manager and a correct CSRF token. No API provides plaintext retrieval. Trusted adapters continue using the existing one-use step-up permissions.

## Master-key rotation — offline runbook

**Preconditions:** PostgreSQL backup (tested restore), secure backup of the current master-key file, a disposable staging rehearsal, and a full maintenance window. Do not run master-key rotation while PHP-FPM, HTTP workers, queue consumers, schedulers or scripts capable of writing CJV1 credentials are active. Stop them *before* the operation.

1. Check active key and PostgreSQL verifier:

   ```bash
   php bin/cajeer vault:check
   php bin/cajeer vault:key:status
   ```

2. Prepare a **different** private key in a distinct path (never overwrite the currently active file):

   ```bash
   php bin/cajeer vault:key:generate --output=/etc/cajeerservers/vault-next.key
   ```

   Mode must be `0600`, owned by the running CLI service user. Keep both keys safely backed up. Ensure the path and parent are not symlinks.

3. With all application/queue services stopped, run:

   ```bash
   php bin/cajeer vault:key:rotate \
     --next-key-file=/etc/cajeerservers/vault-next.key --confirm-offline --json
   ```

   This acquires PostgreSQL locks and performs a **single transaction**. Every CJV1 credential (including archived records) and every encrypted historical version is authenticated and rewrapped with the new key. The payload nonce and ciphertext stay unchanged; only the per-secret DEK wrapper changes. The active verifier, key generation, rotation event and revocation of outstanding one-time grants are committed atomically. Any error rolls back all changes.

4. **Before restarting any application process**, update `VAULT_MASTER_KEY_FILE` to the new key path, making the change with a secure edit of the environment configuration. Do not delete the former key yet. Restart PHP-FPM and workers only after:

   ```bash
   php bin/cajeer vault:check
   php bin/cajeer vault:probe
   ```

5. Run a functional test of encrypted credential creation, stage/activate/cancel, and trusted internal secret consumption. Only after a verified backup/restore test should you retire the old key material according to your organization's key-retention policy.

**Recovery:** If PostgreSQL commits but the key-file configuration was not switched (e.g. host crash), processes using the old key fail closed. Keep the newly staged key available and point `VAULT_MASTER_KEY_FILE` to it, then verify before restarting services. If rotation failed *before* commit, the old key remains active. If you deliberately need to revert after a committed rotation, stop services and run the same rewrap procedure in reverse (new key is active; old key is the staged next key). This is not an automatic rollback of external credentials.

**Concurrency:** PostgreSQL rejects CJV1 ciphertext with a key ID different from the active verifier, preventing stale worker writes after the switch. Table locks protect the rewrap transaction itself. These checks are defense in depth and do not replace stopping application processes during offline rotation.

## Verification

```bash
php tests/vault-rotation-smoke.php
php scripts/verify-release.php
```

On an **isolated disposable PostgreSQL 17/18 test database**:

```bash
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_vaultrotate \
 php tests/vault-rotation-postgres-integration.php
```

This integration checks actual ciphertext persistence, ETag protection, staged activation, cancellation, direct replacement, key rewrap of archived and historical versions, old-key rejection and SQL guards.

**Release validation note:** native PHP tests do not substitute for successful PostgreSQL integration, the actual Nuxt build and browser E2E on the deployment target. Full production readiness is not claimed until those checks pass.
