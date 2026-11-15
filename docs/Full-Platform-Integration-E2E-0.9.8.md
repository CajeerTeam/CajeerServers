# Full Platform Integration & E2E — 0.9.8

This release fixes a real cross-tab authentication race: CSRF is derived from the opaque session credential and APP_KEY with a domain-separated HMAC. The database stores only its digest. Repeated authenticated GET /auth/csrf calls return the same token until session replacement; legacy session hashes are upgraded at first fetch. Existing cookies, same-origin validation, session expiry and revocation remain enforced. Clients holding a legacy token must fetch again once after upgrade.

## Disposable full HTTP integration

Use a **dedicated PostgreSQL 17/18 test server**, a test-only role with CREATEDB and schema privileges, Redis on loopback port 6379, PHP 8.4+ with pdo_pgsql/sodium/openssl and other project requirements, Python 3, OpenSSL, and matching PostgreSQL client tools. Never point this at production. The configured test DB name must begin `cajeer_test_`; the harness creates two uniquely named databases and deletes only those it created. The suite uses a temporary project copy, separate storage and keys, and a local TLS protocol fixture, not a physical router.

```sh
export CAJEER_PG_TEST=1
export CAJEER_PG_TEST_DATABASE=cajeer_test_ci
export CAJEER_PG_TEST_HOST=127.0.0.1
export CAJEER_PG_TEST_PORT=5432
export CAJEER_PG_TEST_USERNAME=cajeer
export CAJEER_PG_TEST_PASSWORD='test-only-password'
export CAJEER_PG_TEST_SSLMODE=disable
# PG_DUMP_BINARY and PG_RESTORE_BINARY can select matching client tools.
python3 tests/platform-http-e2e.py
```

The journey installs PostgreSQL schema and admin, initializes Vault, starts a real PHP server, enrolls MFA, verifies concurrent CSRF, creates workspaces and CMDB history, performs IPAM assignment, uses a TLS-pinned RouterOS fixture through an actual collection plan, connector scheduler/queue/worker, tests deduplication/diff/export, collects native inventory, network topology and time-series metrics, denies cross-workspace UUID access, processes a general queue job, takes a managed full backup and runs a recovery drill. The restored application is booted with the recovered keys, files and separate DB; a fresh MFA login reads the recovered asset and verifies that the original encrypted configuration backup can still be decrypted. Restored workers are never started. Cleanup removes both created databases and temporary files; on abrupt SIGKILL remove the named disposable resources manually. Run `--smoke` for real loopback HTTP liveness and maintenance admission without PostgreSQL. The full journey is required by strict verification and PostgreSQL CI; smoke is not a substitute.

## Browser acceptance

Commit real Composer and Admin npm locks, build the production app, deploy a disposable staging instance with working workers, and connect the supplied isolated HTTPS fixture. Use the staging workflow or `bash scripts/verify-browser-e2e.sh` with the following variables: E2E_BASE_URL, E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD, E2E_ADMIN_TOTP_SECRET, E2E_CONNECTOR_FIXTURE_HOST, E2E_CONNECTOR_FIXTURE_PORT, E2E_CONNECTOR_FIXTURE_PIN; set E2E_ALLOW_WRITES, E2E_ISOLATED_STAGING, E2E_CONNECTOR_UI, E2E_VAULT_BINDINGS, E2E_TRANSFER_WRITABLE, E2E_HISTORY_WRITABLE, E2E_VAULT_UI and E2E_EXPECT_WORKER to 1. The staging API must report the checkout version and readiness; Admin login must return HTML. TLS verification must not be disabled. Browser report gate requires all 17 suites / 21 tests, first-pass results only, zero skips/flakes/failures/errors. No raw reports are automatically uploaded (they can contain credentials). Vault viewer test creates its own isolated user and credentials, then disables the user. Use disposable staging and tear it down after acceptance; cleanup is not a substitute for isolation.

Existing live acceptance for Proxmox VE/PBS, VMmanager and OS/network remains mandatory. The fixture demonstrates protocol interaction, not certification on physical devices. Full browser, PostgreSQL/Redis and external live acceptance must actually pass before a stable release. No new schema migration; use the existing offline upgrade from 0.9.7 to 0.9.8 with pre-upgrade encrypted backup and retained source code.
