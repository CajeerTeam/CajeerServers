# Data Collection Admin UI & E2E — 0.5.9

## Upgrade

Apply the changed files over 0.5.8. No new migrations; schema 0035 is required. Back up the database and code. Build Admin UI (`cd admin && npm install && npm run typecheck && npm run build`). Existing `/connectors` and `/scripts` remain supported.

## Collection center

Open `/collections` from the sidebar. Choose a workspace, find a connector by name or provider, select it, and use the registry, plans/runs, metrics, ingestion/import or export tabs. URL query parameters `workspace_id`, `target_id`, `view` retain scope. A target from another workspace is never mounted. Use the link to connector settings to create and bind a credential, and the scripts link to manage signed scripts in the same workspace. No extra database permissions are conferred by the UI.

Plans use existing MFA service grants, scheduler and worker. Pause prevents scheduling, not cancellation of existing runs. Metrics query uses exact numeric strings; the chart is approximate. Import requires an owned active source, `ingestion.manage`, `ingestion.write` and workspace manager/owner. Source creation also requires an eligible inspect grant; the server rechecks its validity. Preview reports invalid record indices. Import confirmation uses the existing content hash and does not apply candidates to CMDB. Exports are actor scoped and downloads verify SHA-256 before creating the local file. HTTPS secure context is required for Web Crypto. The worker and retention units from 0.5.8 remain necessary.

## Staging test

**Never run against production.** Use a disposable database and the existing fixture setup in `Connector-Admin-UI-E2E-0.4.9.md`. The fixture now includes synthetic CPU/memory/uptime samples. Configure a test administrator with MFA, Vault, the fixture CA and network allowlist. Run Scheduler, connector worker, ingestion worker, data-export worker and a non-root configured script worker with `SCRIPT_EXECUTION_ENABLED=true` and validated bubblewrap/cgroup rootfs. The latter must pass `tests/script-sandbox-integration.php` before enabling it. Use the same-origin Nuxt/PHP staging site over HTTPS (localhost HTTP is also a secure browser context). Run:

```sh
cd admin
npm run test:e2e:preflight
npx playwright test tests/e2e/data-collection.spec.ts
```

Required environment: `E2E_BASE_URL`, `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`, `E2E_ADMIN_TOTP_SECRET`, `E2E_ALLOW_WRITES=1`, `E2E_ISOLATED_STAGING=1`, `E2E_CONNECTOR_UI=1`, `E2E_CONNECTOR_FIXTURE_HOST`, `E2E_CONNECTOR_FIXTURE_PORT`. Test generates a fresh workspace, target, signed script, source and export; it checks real scheduled scrape, metrics, signed push/replay, invalid and valid manual import, CSV download integrity, Ed25519 approval and script preview, cross-workspace deep link. The private signing key is ephemeral in test memory. Dispose of staging afterwards, even on failure. No browser API mocks are used.

`php scripts/verify-release.php` runs local PHP and UI policy regressions; `--strict` requires real staging, dependencies and database. A local pass is not a browser or PostgreSQL pass.
