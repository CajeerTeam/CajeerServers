# VMmanager Network & IPAM Discovery 0.7.5

## Setup

Apply migration `0049_vmmanager_network_discovery.sql` with `php bin/cajeer migrate`. Use a VMmanager 6 target with a pinned TLS profile and a Vault API token. Run `vmmanager.guests` in Sync mode before network Sync so guest external IDs have exact CMDB mappings. Create `vmmanager.network` plan in Collections, choose Inspect or Sync, and schedule according to the infrastructure size (e.g. 300 seconds). The grant actor must hold the usual collection permissions plus `inventory.network.read`; Sync also requires `inventory.network.manage`. The job checks these permissions again before commit.

## Remote API and observation

Read-only GET via production `VmmanagerApiClient`: `/vm/v3/node`, `/vm/v3/host`, `/ip/v3/ipnet`, `/ip/v3/ippool`, `/ip/v3/ip`, with keyset pagination, TLS pinning, whoami and anchor checks. See ISPsystem IPmanager v3 API documentation https://www.ispsystem.ru/docs/vmmanager-admin/razrabotchiku/api/ipmanager-v2026081 . No arbitrary remote URLs, no write API calls, no raw remote payloads. The result has networks (CIDR, gateway, VLAN, userspace, parent, type, used/total/free), pools, allocated addresses/prefixes and NICs. Decimal counts remain strings. Free counts are computed only from valid documented totals and usage; free individual IPv6 addresses are not enumerated. Prefix allocations are retained as observations, not converted into IPAM hosts. Missing NIC details are not treated as proof that no NIC exists.

## Sync semantics

Inspect saves current observation and run history, never modifies IPAM. Sync also writes the native `inventory_networks`, `inventory_interfaces`, `inventory_ip_addresses` and `vmmanager_ipam_import` provenance within the same workspace-locked, grant-fenced transaction as the run. It requires a fully exhausted, non-delegated administrator observation. On scoped credentials Sync records `applied=false` without IPAM writes. ACL/transport errors abort the collection; previous successful state remains intact.

- Active non-overlapping networks (and legacy networks without a reported type) are imported. Hierarchical container networks, overlapping userspaces, conflicting local networks and existing manual networks are not adopted. Pools and gateway metadata remain in discovery because native IPAM has no pool/gateway schema.
- NICs attach only to an active, seen, conflict-free `host:<id>` mapping from the same target and workspace. Duplicate names/MACs or local collisions prevent import. No fuzzy guest matching.
- A busy address is assigned only when a unique explicitly observed NIC and a compatible network were imported. Special, locked or service addresses without NIC evidence become reservations. Uncertain states, deleted rows, prefix allocations, duplicate IPs, inconsistent scope and ambiguous NICs are not imported. A reserved address with a claimed NIC is reported as conflict.
- Existing source-owned rows are reused only if their local version is unchanged and the observed identity matches. An owned address may move to a positively identified new NIC without changing the address/network. Manual version changes, missing rows, identity changes and external-source collisions are reported; never overwritten. No stale rows are automatically deleted or unassigned. `retained_unseen` reports previously imported rows not successfully matched in this run. A manual edit relinquishes automatic management of that row; there is no automatic re-adoption. Resolve stale/manual conflicts in native IPAM; deleting a connector preserves IPAM rows but removes connector provenance.

Current state is read from `/api/v1/connectors/targets/{id}/vmmanager-network`; historical runs use the existing `/runs` API. Both require connector and network read rights plus Workspace membership (run history also requires Inventory read). Profile revision, credential binding/rotation or disabling a target invalidates the current snapshot. The Admin Collections page displays networks/pools/addresses/NICs, counts, conflict report and history. The native IPAM page exposes actual synchronized records.

## Limits and verification

The existing 60-second API deadline, 64 pages per resource, 100 entries/page, 2000 node/host rows combined and 2000 network/pool/address rows combined apply. A normalized network snapshot is bounded to 750000 bytes before Sync report; exceeding any bound or invalid pagination fails closed. No across-run staging/resume in this release. Up to 200 diagnostic entries are retained per report, with full counters. The TLS fixture exercises IPmanager API shapes; it is not a live VMmanager appliance.

Run `python3 tests/vmmanager-https-integration.py`. With a disposable PostgreSQL 17+ database and pdo_pgsql, run the same script with `--postgres` under the documented `CAJEER_PG_TEST*` configuration. It exercises migration, real worker, Vault, IPAM conflicts, assignment, idempotence, local edits and history. Run `php scripts/verify-release.php` and complete deployment verification with a real VMmanager target and Nuxt/browser build before promotion to stable.
