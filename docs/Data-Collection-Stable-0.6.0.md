# Data Collection Layer 0.6.0 — stabilization

This release updates the actual data collection runtime introduced in 0.5.1–0.5.9. The release channel remains `development` until the complete production gate passes. A local smoke pass alone is not a stable deployment certification.

## Upgrade from 0.5.9

Back up the database, Vault and deployment configuration using the existing encrypted backup procedure. Stop scheduler and connector/script/ingestion/export workers and block administrative writes during the upgrade. Apply the changed files over 0.5.9; do not replace or edit previously applied SQL migrations. Run:

```sh
php bin/cajeer migrate
php bin/cajeer vault:check
php bin/cajeer collection:doctor --json
php scripts/verify-release.php
```

Migration 0036 creates the script history retention and export worker scheduling indexes. No existing data or signatures are rewritten. The script retention timer is optional until installed; terminal executions older than 90 days are deleted in bounded batches, including their output and idempotency records. Audit events, scripts, signatures and active jobs remain. Run `php bin/cajeer scripts:retain 1000` or install `deploy/systemd/cajeer-script-retention.{service,timer}`. Review the configured service user and path before enabling the timer.

## Exact metric values and portable archive import

Script metric normalization accepts an explicit decimal string and optional `kind: gauge|counter`. A large integral JSON token is decoded without rounding; text parsers keep high-precision values as strings. Ordinary short numeric values continue to use numeric types. For exact fractional values use decimal strings (bare JSON fractions are binary floating point). Negative counters and non-finite values are rejected. New large integer values remain exact across the parser, import, MetricStore and export. Existing stored rounded values cannot be repaired automatically. No signed manifest bytes are modified.

JSON exports of `metrics`, `events`, `observations` are now accepted by Manual Import. The importer projects portable fields and discards old server-controlled asset, series, source and batch identities. An old `cajeer_source` label is removed only when it matches the recorded ingestion source; the destination source is assigned by the server. A single file cannot collapse distinct exported series into the same portable identity. Use separate destination sources when needed. The existing limits (256 KiB / 1000 records), timestamps and permission checks still apply. Select a narrow export or split a larger archive. JSON preserves text verbatim; CSV remains spreadsheet-safe rather than a lossless archive. Run exports are not importable. No observations are automatically applied to CMDB.

Export jobs are selected by oldest available work across workspaces. Expired completed files cannot be downloaded after seven days even if the retention worker is stopped (HTTP 410). The normal export/download authorization checks remain.

## Readiness and build

`collection:doctor` reports registered collectors/exporters, applied migration checksums, plan/grant, redrive, ingestion source/kind, attempt history, script trust/scope, export checksum and script rootfs configuration. It makes read-only SQL probes with timeouts and does not run scripts or disclose data/credentials. Nonzero exit means a failed probe. The strict deployment verifier includes it. Configuration checks are not proof of sandbox isolation: run the native sandbox test under the dedicated worker user and actual cgroup.

Dependency resolution is separate from building. On a trusted network-connected maintainer host run `bash scripts/resolve-dependencies.sh`, inspect and commit the generated lockfiles. Do not use this script during deployment. Then run `bash scripts/build-release.sh` in a clean checkout with PHP 8.4+, Node 22 and Composer. The build refuses missing locks, validates Composer, uses `composer install` and `npm ci`, builds SDK and Nuxt, runs native checks and checks that lockfiles remain unchanged. It writes actual tool versions, lockfile and bundle SHA-256 checksums to `storage/tmp/release-build.sha256`. This is repeatable dependency installation and build provenance, not a guarantee of byte-identical Nuxt output on different hosts.

The source archive does not contain resolved Composer/Nuxt/SDK locks; the complete locked build has not been verified in the local environment. Do not fabricate locks. Run `php scripts/verify-release.php --strict` against isolated PostgreSQL 17/18, Redis, the actual script sandbox, deployed Nuxt/API, the test TLS fixtures and a disposable browser staging. See `docs/Data-Collection-Admin-0.5.9.md` and `docs/Secure-Script-Execution-0.5.3.md` for fixture/worker setup. Resume production workloads only after the gate passes. Keep the release channel as development until then.

## Boundaries

Scheduled collection is for named collectors; arbitrary scripts still require one-shot MFA/session-bound execution. Existing raw metrics retention and query-time aggregates are retained (no long-term materialized rollups). Webhook export delivery remains an operator CLI operation, not a persistent subscription system. Export artifacts are bounded PostgreSQL files, not multi-gigabyte S3 streams. Untrusted remote shell execution is not enabled.

The strict browser gate (`bash scripts/verify-browser-e2e.sh`) checks the actual Playwright JSON report: the connector lifecycle and data collection suites must execute and pass, not merely appear as skipped tests. CI also uses the locked build script.

Strict release tests require GNU `timeout` and terminate stalled test process groups (300 seconds per step by default, longer budgets for Nuxt and browser E2E). The native SSH fixture checks Paramiko before starting network tests.
