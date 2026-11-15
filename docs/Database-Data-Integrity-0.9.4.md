# 0.9.4 — Database & Data Integrity

## Upgrade from 0.9.3 (single-host)

Keep an untouched complete copy of 0.9.3. Stop old HTTP/worker/scheduler processes, preserve `.env`, installation marker, storage and keys, and deploy the 0.9.4 files. The migration needs PostgreSQL 17/18 with the trusted `btree_gist` extension available in `public` (as well as existing `pgcrypto`). The database owner may install it; alternatively a DBA can run `CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public` before upgrading. If it is already installed in another schema, coordinate relocation with the DBA rather than dropping it. Allow time and storage for a GiST index build over all IPAM networks.

```sh
php bin/cajeer maintenance:enable
php bin/cajeer inventory:integrity --json
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/0.9.3 \
  --archive=/secure/pre-upgrade-0.9.3.cjdr --key-file=/secure/backup.key --offline
php bin/cajeer inventory:integrity --json --deep
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
```

Migration `0062_database_data_integrity.sql` is atomic and retains the checksums of 0001–0061. It rejects pre-existing overlapping networks, incorrect import row counts and non-contiguous import progress; it does not silently delete reservations, import rows or historical revisions. The pre-upgrade encrypted backup is retained. If migration or CMDB verification fails, leave maintenance enabled and inspect the aggregate report and PostgreSQL logs. Fix the cause with a reviewed DBA procedure or use `upgrade:restore` to recover the previous version into a separate database and storage. Do not disable constraints or overwrite migration checksums. The initial report may include missing provenance and former-workspace conflicts that 0062 explicitly handles. Invalid historical data not repaired by 0062 must be investigated; the upgrade readiness gate does not ignore it.

## Runtime changes

- PostgreSQL GiST exclusion ensures CIDRs in the same workspace cannot overlap even if an older transaction snapshot cannot see a concurrent insert. The existing network guard and IPAM API remain in place; different workspaces may use the same CIDR. The IPAM API already makes network CIDR immutable (delete/recreate after detaching reservations).
- Clearing an imported field override now changes provenance to `observed` without inventing a timestamp or actor. The value is retained until the next newer observation. Migration 0062 backfills missing six standard field rows: manual assets are `manual`, other sources `observed`; existing rows/overrides are unchanged. Missing historical imported provenance cannot be reconstructed, so backfill represents an unlocked field, not verified observation history.
- Connector attributes still have protected manual provenance under 0025/0026. Comparison ignores JSON object key order but preserves array order and scalar types (JSON numbers use their serialized representation); unrelated metadata is merged, not erased. History reads use one consistent snapshot; revision restore locks workspace/asset before retrieving the revision and rechecks optimistic version and write permissions.
- Asset transfer closes open conflicts in its old workspace and clears the observation cursor; old receipts and revisions remain immutable. A new workspace can record independent observations, including ones older than the old workspace cursor. The original manual overrides travel with the asset. The migration closes legacy mismatched conflicts and resets cursors demonstrably tied to a former workspace.
- Import workers commit observation (including asset/history/provenance), receipt and progress in the same PostgreSQL transaction. Validation failures produce error receipts; authorization failures and DB failures roll back. A retry resumes the last committed prefix. The database checks payload length, terminal progress and contiguous receipts via deferred constraint triggers. Existing queue idempotency and event keys remain unchanged. An observation from a pre-0.9.4 partially finished import is reused by event key on retry.
- `inventory:integrity` retains its count-only interface and adds reverse tag association, latest revision snapshot, revision gaps, open conflict scope, observation cursor receipt and import prefix checks. Legacy baselines may start above revision one. The upgrade now requires a successful CMDB integrity scan before advancing the installation marker. Strict release verification retains deep topology scanning.

## Bounded offline repair

This command repairs only **missing provenance rows for the six standard fields of manual assets**, including soft-deleted assets. It does not reset existing overrides, repair imported provenance, change asset values, forge revisions, detach IP addresses, or reconcile ambiguous conflicts. It is intentionally CLI-only and requires fully upgraded maintenance mode and shell/database access.

1. Stop application services, enable maintenance and make a verified backup.
2. Inspect aggregate integrity and preview a single workspace:

```sh
php bin/cajeer inventory:integrity --json
php bin/cajeer inventory:repair --workspace=YOUR_WORKSPACE_UUID
```

The report contains workspace UUID, count and a SHA-256 confirmation bound to installation identity, workspace, missing asset/field pairs and asset versions. No asset contents are printed. At most 10,000 rows per plan; larger anomalies require manual review.

3. After reviewing the exact plan, apply it with the returned confirmation:

```sh
php bin/cajeer inventory:repair --workspace=YOUR_WORKSPACE_UUID --apply --confirm=RETURNED_SHA256
php bin/cajeer inventory:integrity --json --deep
```

Apply locks the workspace and both tables, re-evaluates the plan and rejects drift. Repair plus audit event are one transaction. The audit record has action `inventory.integrity.repaired`, target workspace and reason/count; it has no application user identity because it is an offline CLI operation. Stop services/other writers before applying. The locks have bounded timeouts. Maintenance can only be disabled after readiness and integrity succeed.

## Verification

```sh
php tests/data-integrity-smoke.php
php tests/history-smoke.php
php tests/cmdb-integrity-smoke.php
php tests/transfer-smoke.php
php scripts/verify-release.php
```

Native tests exercise normalization and the repair service using a PDO double. The existing PostgreSQL 17/18 CI matrix runs the modified `cmdb-postgres-integration.php`, `history-postgres-integration.php`, `transfer-postgres-integration.php` and `upgrade-postgres-integration.php`. They test real migration replay, stale-snapshot CIDR conflict, source/destination conflict/cursor isolation, repair confirmation drift, injected receipt failure with transaction rollback, retry and actual backup/restore. Run these only against the disposable `CAJEER_PG_TEST=1` / `CAJEER_PG_TEST_DATABASE=cajeer_test_*` database configuration. The 0.9.4 migration must pass those real PostgreSQL tests before production certification.
