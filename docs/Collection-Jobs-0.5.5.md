# Collection Jobs, History & Scheduling — 0.5.5

## Upgrade

Back up PostgreSQL and stop the scheduler and connector workers. Overlay the changed files on 0.5.4; run `php bin/cajeer migrate` (0032), `php bin/cajeer vault:check`, and `php scripts/verify-release.php`. Rebuild the admin and SDK with the documented dependency procedure. Do not enable production before the strict release gate on staging.

## Plan lifecycle

In `/connectors`, select a configured target and its Vault binding, open «Планы сбора», choose an actual compatible collector and inspect/sync operation, set a schedule and grant TTL (60–2592000 seconds), and submit fresh password/TOTP. The application calls the existing Vault authorize-use endpoint, then creates the plan and consumes that grant atomically. No secret is stored in the plan. The dedicated service grant pins actor, workspace, target and credential revisions, asset and purpose, and one operation. The plan fixes the collector and schedule. It cannot be used as a generic connector grant or for webhook ingestion.

The plan is immutable except for schedule enabled/disabled state. To change collector, schedule, binding or extend expiration, revoke the old grant and create a new plan with MFA. Pause only stops future scheduled dispatches; manual runs and previously queued work remain allowed. Cancel individual runs to block their commit; revoke the grant to stop all further use including in-flight commits. Already transmitted network requests cannot be recalled. Expired or invalid plans are disabled on the next due scheduler tick. Resuming requires a live grant and its original actor. Workspace managers may pause or revoke.

## REST (relative to /api/v1)

* `GET /connectors/targets/{id}/collection-plans?limit=50&offset=0`
* `POST /connectors/targets/{id}/collection-plans` with session CSRF, target If-Match and a fresh Vault use grant: `{"name":"Inventory","collector":"proxmox.inventory","operation":"sync","ttl_seconds":86400,"interval_seconds":300,"jitter_seconds":10}`.
* Instead of interval use `"cron":"*/5 * * * *","timezone":"Europe/Helsinki"`. Exactly one schedule required; optional jitter is 0–30 seconds and delays scheduled delivery only. Cron uses existing POSIX five-field semantics and IANA timezones. Missed occurrences coalesce; no backlog replay. The first run is at the next interval/cron occurrence; use a manual run for immediate collection.
* `POST /connectors/targets/{id}/collection-plans/{planId}/state` body `{"enabled":false}` or `true`.
* `POST /connectors/targets/{id}/collection-plans/{planId}/runs` body `{}` plus `Idempotency-Key`. Optional `redrive_of` is a failed or cancelled run of this plan; one successor per prior run; a repeated identical request returns that successor. A different key for an already-redriven run returns 409. A further failure can itself be redriven.
* `GET /connectors/targets/{id}/runs?plan_id={planId}` for filtered history; `GET .../runs/{runId}` returns the full result and attempt_history. `POST .../runs/{runId}/cancel` cancels.

## Execution and history

Scheduler and queue insert are atomic under workspace locks; SKIP LOCKED allows parallel scheduling in different workspaces. One pending scheduled run per plan; manual submissions use existing 10 pending per grant bound. Existing target, workspace, provider concurrency limits and circuit breaker apply. `php bin/cajeer scheduler:run` or `php bin/cajeer connector:tick` schedules work; `php bin/cajeer connector:work 20` executes. No new worker or broker needed. The existing worker uses 300s leases and 240s process deadline, up to five attempts, bounded exponential backoff for transient errors, no retry for terminal authorization/trust errors. The queue trigger records attempts atomically with reservation/status changes, including expired leases, retry categories, completed/cancelled/failed results. Existing historical jobs from before migration have no reconstructed attempt records. A previously committed result recovered after a lease expiry remains authoritative; history describes delivery attempts, not just remote requests.

CLI: `collection:plans <actor> <target>`, `collection:plan-run <actor> <target> <plan> <key>`, `collection:plan-redrive <actor> <target> <plan> <key> <run>`, `collection:plan-pause <actor> <target> <plan>`, `collection:plan-resume <actor> <target> <plan>`. Use `collection:status` / `connector:cancel` for existing runs. CLI is a trusted local operator interface and cannot issue new grants. TypeScript client exposes collectionPlans, createCollectionPlan, setCollectionPlanEnabled and runCollectionPlan.

## Scope and verification

This release schedules executable named connector collectors (Proxmox, VMmanager, SSH, WinRM, SNMP, installed custom). Arbitrary signed scripts remain one-shot and session/MFA-bound as in 0.5.3/0.5.4; unattended sandbox/remote script delegation is not enabled. Metrics/time-series and event ingestion remain later milestones. No automatic raw artifact retention added. PostgreSQL integration `tests/collection-plans-postgres-integration.php` requires CAJEER_PG_TEST=1 and isolated cajeer_test_* database; it creates/drops a temporary schema, uses actual Vault and queue with deterministic SNMP session fixture. It exercises scheduling without a session, grant isolation, pause/resume, dedupe, retry history, lease recovery, cancel, redrive, and revocation. Run on staging with the strict gate; do not infer success from smoke tests.
