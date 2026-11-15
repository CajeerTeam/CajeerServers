# Installation, Upgrade & Migration Stability — 0.9.2

## Supported topology and prerequisites

Single-host application (one shared local `storage/app`, regular non-symlink files and a filesystem supporting flock/atomic rename). Run PHP-FPM and CLI workers as the same dedicated service account, with private owner-writable state. Do not delete or replace `maintenance.lock` while processes are running. PostgreSQL 17/18, PHP 8.4+, configured Redis, a private `.env` and keys, local storage and real `pg_dump`/`pg_restore` executables. For remote production PostgreSQL use verify-full TLS. S3/external storage recovery and multi-host deployment are not supported by this upgrade command; handle those with a separately verified DR process. No automatic network download or installation of release code occurs.

## Clean installation

Prepare PostgreSQL DB and owner (public schema USAGE/CREATE, pgcrypto extension available), Redis, storage/logs/cache/tmp/app/files, TLS and Nginx/PHP-FPM/Admin build. Configure `.env` from `.env.example` or export DB_* for the CLI. Keep secret input files owner-only (0600). Do not override installed settings with conflicting service environment variables. Use the 0.9.1 build instructions for dependencies; unresolved Composer/Admin lockfiles must be resolved before a production build.

```sh
php bin/cajeer install:check --database --json
php bin/cajeer install --env=production --url=https://infra.example.test \
  --db-host=127.0.0.1 --db-database=cajeerservers --db-username=cajeer \
  --db-password-file=/secure/db-password --admin-email=admin@example.test \
  --admin-name=Administrator --admin-password-file=/secure/admin-password
php bin/cajeer install:status --database --json
php bin/cajeer health:ready
```

Web Installer uses the same service and rechecks the setup token under the installation lock. The PostgreSQL session advisory lock protects competing installations even from different directories. Schema migrations run in one transaction; first administrator, role, default workspace owner and installation DB record in another transaction. A failed migration leaves no partial batch. If the DB commit succeeded but the local marker write failed, keep the same configuration and run `install:recover` with the **matching release**. Never generate a new APP_KEY or Vault key for an existing installation. `install:status --database` distinguishes missing local marker, missing DB record and differing IDs; it does not repair them automatically. If initial install fails before commit, the saved `.env` is retained for retry. Verify Redis and Nginx separately (`nginx -t`, HTTP/TLS and readiness probes); the installer preflight does not start/configure system services.

## Upgrade from 0.9.1

1. Before replacing any files, stop ingress/HTTP, PHP-FPM, Nuxt, all schedulers/timers, queue/connector/script/export/ingestion workers, syslog and external writers. Wait for active remote operations to finish; verify external PostgreSQL/storage writers are stopped. 0.9.1 processes do not understand the new maintenance lock. Keep PostgreSQL and Redis available.
2. Retain a complete trusted 0.9.1 release directory with its `VERSION`, historical SQL and built code (including prior Admin/vendor dependencies). Deploy 0.9.2 code to the application directory, retaining `.env`, APP_KEY/Vault key, `storage/app/installed.json`, local storage and existing data. Never overwrite state with files from another environment. Install/build 0.9.2 dependencies before deployment. If the old marker is missing, recover it using 0.9.1 before switching. The original marker may contain an older historical version; the previous release's migration checksums determine source compatibility.
3. Use absolute paths, a new archive filename outside the project public/storage tree, and a distinct backup key. Set PG_DUMP_BINARY and PG_RESTORE_BINARY to the real client executables matching the server (e.g. `/usr/lib/postgresql/17/bin/pg_dump` and `/usr/lib/postgresql/17/bin/pg_restore`, not a symlink wrapper). Create a private key with `backup:key:generate` before enabling maintenance, if needed.

```sh
php bin/cajeer maintenance:enable
php bin/cajeer maintenance:status
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/0.9.1 \
  --archive=/secure/cajeer-0.9.1-pre-upgrade.cjdr \
  --key-file=/secure/backup.key --offline
```

The command authenticates the local marker against PostgreSQL and checks all 60 source migration checksums. It holds a database advisory lock across backup/migrations/readiness and a local exclusive lock across the operation. The `upgrade.json` journal is atomically persisted (0600); phases are `backup`, `migrate`, `verify`, `complete`. Backup uses the existing encrypted full archive with a unique upgrade ID and source release metadata. After successful verification SHA-256 is pinned; a crash after backup publication resumes only with the matching authenticated upgrade ID. Source and target migration fingerprints and installation identity must remain unchanged. Atomic migrations reject explicit transaction commands, and use a 10s SQL lock timeout and 15-minute statement timeout. Existing migrations are unchanged in this release; normally zero new SQL files apply. Failed post-upgrade readiness leaves the marker at the old release and maintenance active. Fix the cause and rerun the exact command with the same archive/source paths and key. Never edit/remove the journal or discard the verified backup to force success. If a backup phase fails, fix the key/permissions/DB; an unrelated pre-existing archive is never accepted.

4. After `complete`, the installation marker is updated to 0.9.2, but maintenance is still active. Inspect the deployed Admin build, PHP configuration, web-server config, storage and worker configuration. Then explicitly reopen:

```sh
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
php bin/cajeer migrate:validate
php bin/cajeer release:check --strict --json
```

Start the stopped services and verify actual HTTP/TLS, login and a controlled collection. If needed re-enable maintenance (after draining requests/workers). `maintenance:disable` refuses an incomplete upgrade, old installation marker, invalid schema, database identity mismatch or failed diagnostics (including Redis, queue/scheduler, storage and initialized Vault key). Liveness `/api/v1/live` stays available; other PHP HTTP requests receive 503 and Retry-After. CLI workers are blocked before execution. New code also rejects old version markers even if no maintenance marker was created. Static assets and external writers are not controlled by the PHP gate. Direct pending `migrate` on an installed system is refused; use the offline upgrade path.

## Failure / rollback

If resuming is not safe, restore the authenticated source archive into a **new empty PostgreSQL database** and **new directories**, not over the existing system. Keep the original application offline. Create the empty DB using an authorized administrator and grant the recovery role ownership. The exact target name must be confirmed.

```sh
php bin/cajeer upgrade:restore --offline --key-file=/secure/backup.key \
  --target-db=cajeerservers_recovered --confirm-target=cajeerservers_recovered \
  --storage-target=/secure/recovered-files --recovery-target=/secure/recovered-secrets
```

The command uses the journal-bound archive, verifies its hash and encrypted header, executes pg_restore into the replacement DB, restores files and keys, and checks the restored installation ID and all source migrations. The original DB and maintenance state are retained. Before cutover, deploy the retained **0.9.1 code**, configure its `.env` from the recovered settings with the replacement database and local storage paths, reconfigure external environment variables and recovered APP_KEY/Vault key. Restore secrets with 0600 permissions and restrict access. Do not copy the 0.9.2 upgrade journal/marker into the old release. Use the source release marker or `install:recover` and validate schema, credentials, login, storage and remote tasks before opening traffic. Database/keys/storage recovery is implemented; switching deployment pointers/services and external systems is deliberately operator-controlled. Redis in-flight jobs and remote action outcomes are not included in a PostgreSQL/local-storage archive; reconcile remote actions and queue state before resuming. Do not operate the old and upgraded databases simultaneously against the same devices.

## Tests

`php tests/upgrade-smoke.php` (real filesystem flock and HTTP dispatch, CLI admission); `php tests/database-smoke.php` (PDO test double, including atomic rollback); `php tests/installer-smoke.php`. The CI PostgreSQL matrix additionally runs `tests/upgrade-postgres-integration.php` on PostgreSQL 17/18 + Redis with real pg_dump/pg_restore, clean 60-migration installation, forced readiness failure/resume and isolated recovery. It creates and drops two random `cajeer_test_*` databases; use only a dedicated disposable test server and a role with CREATEDB. `CAJEER_PG_TEST=1`, `CAJEER_PG_TEST_DATABASE=cajeer_test_*`, existing CAJEER_PG_TEST connection settings and PG binary paths are required. Strict verification runs this scenario too. Full PostgreSQL/Redis acceptance and production deployment rehearsal must actually pass before certification.
