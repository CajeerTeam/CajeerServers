# Production Operations & Disaster Recovery 0.9.7

## Scope / prerequisites

Single-host deployment, PostgreSQL 17/18, local filesystem storage, PHP 8.4+, sodium, pdo_pgsql, Redis, pg_dump/pg_restore from the matching major version. Full managed backup does not capture Redis, S3, code/release packages, systemd, TLS keys, external CA/SSH trust files, or OS configuration. Maintain independent protected copies of those. Redis queues need persistence and separate backup/recovery; a PostgreSQL archive is not a Redis snapshot. Do not replay uncertain remote actions or copied queue deliveries blindly.

Run the same release code and same `storage/app` directory for all Cajeer HTTP, CLI, workers and scheduler processes. PHP-FPM and CLI must use the same service user and shared local lock inodes. All direct SQL/storage writers, old code, external jobs, and any other processes bypassing admission must be stopped for the consistent snapshot. A long-lived syslog receiver holds the shared lease; stop it before the backup window or the backup fails on its drain deadline. No destructive maintenance on the original system is performed by the backup timer.

### Production deployment

- Install the reviewed release and dependencies per `docs/Reproducible-Build-0.9.1.md`. Build Admin via `scripts/build-release.sh`; do not use the dev server.
- Deploy to `/opt/cajeer`; code is read-only to the service user, writable private storage and `.env` read access as appropriate. Install `deploy/php-fpm/cajeer.conf` into the corresponding PHP pool directory; verify using `php-fpm8.4 -t`. Install `deploy/systemd/cajeer-admin.service`; adapt Node path to the pinned runtime and the release path. Ensure `.output/server/index.mjs` exists.
- Configure `examples/nginx/cajeerservers-tls.conf` with actual DNS and TLS cert files, `APP_URL`, and FPM socket. Configure HTTPS only, set log policy for access logs (sample disables request paths and query strings), run `nginx -t`, then reload. FPM and Nuxt same origin, Nuxt loopback only. Inspect all worker units in `deploy/systemd`; use the same code and storage path. Test `php bin/cajeer health:ready`, `curl https://HOST/api/v1/live`, `curl https://HOST/api/v1/ready`, Admin login and controlled collection before serving traffic.

## Managed backup and retention

Create directories owned by the service user, mode 0700, outside both release and storage. Use canonical absolute paths (no symlinks). Example:

```sh
sudo install -d -m 0700 -o cajeer -g cajeer /var/backups/cajeer /etc/cajeer-backup
sudo -u cajeer php /opt/cajeer/bin/cajeer backup:key:generate --output=/etc/cajeer-backup/backup.key
```

Keep an offline, access-controlled copy of this independent key. Losing it makes every archive unrecoverable. Do not place it in a backup directory or a backed-up tree. Set `.env`:

```dotenv
BACKUP_KEY_FILE=/etc/cajeer-backup/backup.key
BACKUP_DIRECTORY=/var/backups/cajeer
BACKUP_KEEP=7
BACKUP_DRAIN_SECONDS=600
# optional, separate mounted filesystem, owned by service user and 0700
BACKUP_OFFSITE_DIRECTORY=/mnt/remote-backup/cajeer
PG_DUMP_BINARY=/usr/lib/postgresql/17/bin/pg_dump
PG_RESTORE_BINARY=/usr/lib/postgresql/17/bin/pg_restore
```

Offsite requires a different filesystem device from the primary directory; this prevents silently writing to the primary filesystem when a mount is missing in typical deployments. The operator must verify that it is actually off-site (a different filesystem is not proof of geographic separation), mounted, private, and supports hardlinks/fsync. Use a separate account/immutable or offline copy policy to defend against ransomware. The managed process never prunes the off-site copy; set separate operator retention.

Run once as the service user after stopping external writers/long-lived receiver:

```sh
php bin/cajeer backup:scheduled --offline
```

The explicit `--offline` acknowledges external writer quiescence. The command checks installation identity and the complete schema journal, APP_KEY and initialized Vault, takes a process-wide admission gate, drains existing shared leases, then creates and authenticates the archive. New HTTP/API requests get 503; liveness remains available. No new jobs enter while draining, but existing jobs may finish. A drain timeout fails safely; no permanent drain marker is created. After local publication and fsync, application admission reopens. Offsite copy, authentication and local retention continue under a separate run lock. The managed archive header contains the installation ID and `consistent_storage=true` under these assumptions. Old `backup:create` remains available, but its full archive is not claimed to be consistent unless the managed path was used.

All archives are encrypted, authenticated and published without overwrite. Copy is ciphertext-only, streamed, checked against SHA-256 and then decrypted/authenticated before publication. Retention keeps the current archive and up to `BACKUP_KEEP-1` most recent authenticated managed archives of the same installation/database/key, never removes manual, corrupt or different-key archives, and removes at most 20 per run. If copy fails, no retention runs. A successful archive may remain locally even if offsite fails. Status preserves last success and reports failure; a killed process can leave `running` until the next attempt, and temporary encrypted staging files can remain. Inspect and remove abandoned `.cajeer-encrypted-*`/`.cajeer-copy-*` only with backup stopped, after reviewing journal and paths. Do not delete lock files. Re-verify archive via `backup:verify --archive=...`.

Once the manual run and recovery drill are successful, install `deploy/systemd/cajeer-backup.{service,timer}` and `systemctl enable --now cajeer-backup.timer`. Daily 03:00 local server time, randomized delay up to 10 minutes. If external writers cannot be quiesced for the scheduled window, do not enable the timer; coordinate them via your own service dependencies/window. Set unit mount dependencies if required (`RequiresMountsFor=` for actual mount path); use `systemctl start cajeer-backup.service`, `journalctl -u cajeer-backup.service`, `systemctl list-timers`. Service timeout two hours. Adjust to measured backup size. Failed or skipped runs must alert; no timer means no automatic backup.

## Monitoring

Authenticated `/api/v1/admin/overview` now includes `operations`, and the Admin dashboard renders queue state for default/connectors, failed/expired leases, scheduler lag and outbox, local disk capacity, backup status and timestamps. Unavailable dependencies return null, not zero. Metrics at `/api/v1/metrics` (opt-in `PROMETHEUS_ENABLED=true` and strong `PROMETHEUS_SCRAPE_TOKEN`) require Bearer auth and HTTPS in production. Prometheus configuration: job `cajeerservers`, scheme `https`, metrics_path `/api/v1/metrics`, token from `authorization.credentials_file`, real server CA. Load `examples/prometheus/cajeerservers-alerts.yml`, check with `promtool check rules` and route alerts to Alertmanager. Default thresholds: 26h backup age, 10% local free disk, 5min scheduler lag, 1000 pending jobs; tune to actual RPO/SLAs. Metrics scrape is unavailable during snapshot (maintenance); use a maintenance silence and external liveness monitoring if planned. Backup status is local and tracks managed runs, not a fresh audit of archive presence; schedule recurring verification/drills. `backup_expected`/`offsite_expected` reflect `.env`, not one-off CLI options. Monitor actual offsite mount free space separately. `metrics:render`, `doctor:deep`, `queue:stats`, `scheduler:stats` remain available outside maintenance. No secrets, file paths, target names or workspace identifiers are emitted by new operational gauges.

## Recovery drill and actual recovery

First verify archived ciphertext and obtain matching release. On an isolated recovery host, configure PostgreSQL connection and use a role with permission to restore into a pre-created empty DB. Do not connect recovered credentials or jobs to production network. Use a new `cajeer_drill_*` database and two new canonical absolute directories outside the live release and storage, with private parents. Example (create empty DB via administrator before command):

```sh
php bin/cajeer backup:drill --offline \
  --archive=/var/backups/cajeer/ARCHIVE.cjdr --key-file=/etc/cajeer-backup/backup.key \
  --target-db=cajeer_drill_20261115 --confirm-target=cajeer_drill_20261115 \
  --storage-target=/secure/drill-files --recovery-target=/secure/drill-secrets
```

This performs real `pg_restore --single-transaction --exit-on-error`, validates installation ID and current migration checksums, CMDB, APP_KEY encryption, the first stored MFA ciphertext if present, and initialized Vault's authenticated verifier using the recovered key. The archive reader checks every file and dump hash. No workers or remote actions are activated, no source database overwritten. If verification fails, keep the recovered resources isolated for diagnosis; do not start them. Drill resources are not automatically deleted. Verify application login and controlled connector read on a separately isolated deployment as a further operator acceptance step. The drill does not prove the ability to reach every external provider or reproduce Redis deliveries.

For actual DR `backup:restore --offline --archive=... --target-db=NEW_EMPTY_DB --confirm-target=NEW_EMPTY_DB --storage-target=/new/files --recovery-target=/new/secrets`. The CLI works under maintenance and acquires exclusive admission. Use recovery `.env` as a private input, review host/DB/Redis/storage paths, recover APP_KEY and Vault key (configure only one Vault key source), restore external trust/config files from separately protected copies, run the matching application release and `install:recover` to reconstruct installation marker. Verify `migrate:validate`, `inventory:integrity --json --deep`, `vault:check`, `health:ready`, Admin authentication/MFA, history and a controlled connector read. Resume workers only after reviewing stale leases, Redis/outbox and remote `dispatching`/`unknown` actions; never automatically repeat uncertain actions. DR in a new database is not an automated source cutover. Do not start workers against restored copied credentials until approved. Use `backup:drill` and isolated infrastructure regularly to measure actual RTO/RPO.

## Upgrade 0.9.6 → 0.9.7

Stop previous HTTP, scheduler, workers and external writers. Preserve complete 0.9.6 release, .env, storage and keys. Deploy files into the new release copy; use the existing maintenance and upgrade mechanism:

```sh
php bin/cajeer maintenance:enable
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/0.9.6 \
  --archive=/secure/pre-upgrade-0.9.6.cjdr --key-file=/secure/backup.key --offline
php bin/cajeer migrate:validate
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
```

Database names accepted by backup/restore are ASCII letters, digits, underscore, dot and hyphen (first character letter, digit or underscore); libpq connection-string-like input is refused. Backup keys must be canonical regular files with one link and private permissions.

No new SQL migration. All 64 previous checksums are unchanged; upgrade advances marker only after validation. Update deployment units and configure managed backups explicitly, not automatically during upgrade. Full CI/production acceptance is mandatory; the native tests use a fake pg_dump stream for archive tests, and the PostgreSQL upgrade/recovery integration uses real pg_dump/pg_restore when configured.
