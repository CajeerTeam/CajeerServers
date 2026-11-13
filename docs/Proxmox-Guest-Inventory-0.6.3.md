# Proxmox VM & LXC Inventory — 0.6.3

`proxmox.guests` is an executable read-only collector for verified PVE profiles (8.x/9.x). Uses existing Connector Targets, Vault API credentials, MFA service grants and Collection Plans. Select `inspect` to save a snapshot without modifying Inventory, or `sync` to also reconcile visible nodes and guests. Requires `VM.Audit` on relevant guests and existing cluster/node read permissions. No new write privileges are needed.

## Run

1. Back up the database, stop connector workers, install changed files over 0.6.2, and run `php bin/cajeer migrate` (0039).
2. Configure verified PVE profile (CA, certificate pins, cluster identity) and read-only Vault credential. In `/collections`, select target and create a plan for `proxmox.guests` with an authorized inspect/sync grant. Use 300–900 seconds for small clusters; adjust for API load.
3. `php bin/cajeer scheduler:run` and `php bin/cajeer connector:work 20` process the plan. The new panel in `/collections` → collectors shows saved current state, filtered guests and history. `GET /api/v1/connectors/targets/{id}/proxmox-guests` reads current state; `GET .../runs?collector=proxmox.guests` lists history, `GET .../runs/{runId}` reads immutable snapshot. These use target and inventory read access and membership.

## Data and bounds

The API client checks version and cluster identity before querying `/nodes` and `/cluster/resources?type=vm`. Per visible guest it reads `/nodes/{node}/{qemu|lxc}/{vmid}/config` and `/status/current`, then rechecks the guest list's VMIDs, types and node placement before publishing. A migration or changed visible membership during the read aborts that attempt. Only GET operations are used. A failover restarts the whole read, never mixes endpoints.

Configuration: configured CPU/core/socket/vCPU, RAM/balloon/swap (MiB), CPU model, BIOS/machine, boot order, OS/arch, tags, template, QEMU disks and LXC rootfs/mount devices, NIC model/MAC/bridge/VLAN/trunks/IP, selected LXC features. Runtime: status, CPU, uptime, exact integer byte/counter strings. Pool and template come from cluster resources when reported; no extra pool permissions assumed. Volume IDs recorded only for safe storage-qualified syntax; host paths, arbitrary raw options, cloud-init secrets, descriptions, SMBIOS serials, arbitrary arguments and mount paths intentionally omitted. Disk size is reported PVE property string; actual capacity not inferred. QEMU guest-agent details not collected.

Up to 1500 visible guests, 48 detail request pairs per collection, 60-second start budget (final in-flight request and list recheck have their own transport timeouts), 4 KiB per projected detail object, 850000 bytes snapshot. Rotated detail window is best-effort; no guarantee of visiting every guest at fixed cron intervals. 403/404/node 5xx are partial detail results; 429 stops more detail requests; auth, TLS and malformed responses fail closed. Lists are never evidence of complete ACL visibility. Prior config can be displayed with original timestamp on same node and target/credential revision; stale cache dropped if size budget exceeded. Absence from latest view does not delete Inventory or history. Current state is last successful observation, not live health.

## Identity

Existing `qemu:<vmid>` / `lxc:<vmid>` external IDs and Inventory UUIDs retained; migration changes parent node. Durable identity guard remembers guest type and hash of observed QEMU SMBIOS UUID; differing known type or fingerprint aborts entire detailed run with `guest_identity_conflict`. A change may be legitimate; do not simply clear identity table. Review source and historical Inventory manually; automatic generation reassignments not supported. SMBIOS UUID can be changed, duplicated or absent; LXC has no equivalent immutable generation identifier in this release. VMID reuse with no evidence cannot be guaranteed detectable. Legacy inventory and existing power actions do not use this generation guard; keep writes disabled until dedicated action release. This is not proof of resource identity for destructive actions.

## Verification

`php tests/proxmox-guests-smoke.php`, `python3 tests/proxmox-profile-https-integration.py`, `php scripts/verify-release.php`. PostgreSQL upgrade/worker test in `tests/proxmox-profile-postgres-integration.php`; run using existing isolated test DB environment. Full production qualification additionally requires real PVE appliances, PostgreSQL, Nuxt build and browser E2E. Release remains development until strict gate passes.
