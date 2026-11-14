# VMmanager Integration Stable — 0.8.0

This release hardens the existing VMmanager 6 Hosting/Infrastructure integration. It introduces no schema migration and retains the 0.7.9 API and stored action receipts. The release channel remains `development` until the strict deployment gate passes. A successful local TLS fixture run is not proof of a live hypervisor or a production deployment.

## Action safety corrections

- Server-side action submission now requires a **complete**, current guest observation, no more than 900 seconds old and not dated in the future. The client-side restriction is no longer the sole check. Refresh `vmmanager.guests` via Sync before dispatch.
- An action can be settled from a Consul task only when its returned resource matches the exact `host:<id>`. Missing or conflicting resource evidence keeps the action submitted and locked. A resource mismatch is isolated from normal task history collection; the worker can continue observing other actions. The distinct `action_task_identity_unverified` error also prevents ordinary acknowledgement from unlocking an action with an unverified task in the same credential scope. Operators must investigate unresolved actions; never re-send a possibly executed command. Historical receipts remain compatible.
- IPAM discovery flags a referenced pool that is not visible, does not expose network membership, excludes the address network, or conflicts with the known userspace. These records are retained for diagnosis but not imported into local IPAM. Existing entries are retained for operator review; no ownership is reassigned.
- All other preflight, MFA/Vault, pinned TLS, idempotency and task/live-state checks remain in effect. The external race between the last GET and POST still exists; VMmanager does not expose an atomic compare-and-set power operation through this integration.

## Read-only live acceptance

Create a disposable VMmanager 6 installation with both KVM and LXD guests, full catalog visibility, an allocated KVM NIC address in an IP pool, a linked storage disk, OS/preset/image/ISO and a completed task for the KVM guest. Use a non-delegated administrative integration token, because the verifier also checks complete discovery and action preflight. Use a dedicated token and a trusted CA and independently obtained leaf SHA-256 pin; no password login or remote mutation is performed by this command.

Save the credential as a raw token (or existing Vault-compatible `vmmanager_api` JSON) in a regular private file with permissions 0600. Keep it out of the repository. Example `/etc/cajeer/vmmanager-acceptance.json` (replace every example value):

```json
{
  "allowed_networks": ["192.0.2.0/24"],
  "target": {
    "host": "vmmanager.example.org",
    "port": 443,
    "ca_file": "/etc/cajeer/vmmanager-ca.pem",
    "credential_type": "api_token",
    "secret_file": "/etc/cajeer/vmmanager-token",
    "profile": {
      "primary_pin": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
      "anchor_id": 1,
      "anchor_node": "node-1"
    }
  },
  "expect": {
    "owner_id": 7,
    "kvm_host": 21,
    "lxd_host": 22,
    "storage_id": 1,
    "os_id": 1,
    "preset_id": 1,
    "image_id": 1,
    "iso_id": 1,
    "network_id": 1,
    "pool_id": 1,
    "address": "192.0.2.21",
    "task_id": 9
  }
}
```

Run:

```sh
php scripts/verify-vmmanager-live.php /etc/cajeer/vmmanager-acceptance.json
```

The command uses the real driver and checks ten areas: authentication and trust, capabilities, clusters, both guests and their placement/configuration, network/pool/NIC association, linked storage volume, templates and OS link, task history and cursor continuation, KVM preflight and LXD preflight. It verifies explicit expected IDs and relationships; HTTP 200 or an empty list is not sufficient. The task probe starts immediately before the expected completed numeric task ID and makes a second collector call with its saved cursor. Preflight checks start for stopped guests or stop for running guests; it does not dispatch a command. A quiet dedicated lab is required for preflight. The report contains only finite check names/status/error categories, not remote response bodies or tokens. Exit 0: all checks passed; 1: probe or expected evidence failed; 2: invalid configuration/credential file. The existing discovery size/time limits apply.

## Release and upgrade

1. Stop workers, back up PostgreSQL and deploy the changed files over 0.7.9. No new migration is needed. Run `php bin/cajeer migrate` to ensure all existing migrations are applied (latest 0052).
2. Resolve and review Composer/npm lockfiles if absent, run the existing dependency/build scripts, and deploy the Nuxt/PHP runtime. Keep `VMMANAGER_REQUIRE_PROFILE=true`; enable write actions only for approved operators.
3. Test `vmmanager.guests` Sync, `vmmanager.tasks` Inspect, network/IPAM Sync, the dashboard and action reconciliation in disposable staging. Verify RBAC, MFA, idempotency, rejected incomplete snapshots and token rotation. Exercise start/stop/restart for both types using the application's Safe Action Dispatcher only on dedicated test guests, with explicit operator approval. The live CLI does not verify actual power mutations.
4. Run the full strict gate, with existing PVE/PBS acceptance and VMmanager config:

```sh
CAJEER_PROXMOX_ACCEPTANCE_CONFIG=/etc/cajeer/proxmox-acceptance.json \
CAJEER_VMMANAGER_ACCEPTANCE_CONFIG=/etc/cajeer/vmmanager-acceptance.json \
php scripts/verify-release.php --strict
```

The gate also requires isolated PostgreSQL and Redis, dependency locks, Nuxt build/typecheck, deployed HTTP and Playwright staging with the existing E2E settings. The native gate runs the new acceptance CLI against a local TLS fixture, including wrong pin, wrong identity, identity change across probes, storage, IP, task, unsafe token file and empty allowlist cases; fixture success never satisfies the separate live config requirement by itself. See `docs/VMmanager-Admin-UI-E2E-0.7.9.md` for staging setup. Do not promote the release channel until actual operator-owned live acceptance, staging and all production checks pass.
