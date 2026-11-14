# Advanced SNMP Discovery 0.8.4

## Installation

Apply migration `0056_snmp_discovery.sql` with `php bin/cajeer migrate`. Rebuild Admin UI and restart workers. Configure an existing SNMP target with an SNMPv3 authPriv SHA256/SHA512 + AES128 credential, its own engine ID and sysObjectID profile (see OS-Network-Connection-Profiles-0.8.1.md). Grant read access to standard MIBs on the device. No write permissions are needed.

Create Inspect plans for `snmp.device` (3600 s), `snmp.interfaces` (300 s), `snmp.topology` (300 s). The existing `snmp.inventory` Sync and `snmp.metrics` are unchanged. Open Collections → Collectors for the target; select a scope and inspect the latest snapshot, visibility table, and historical successful runs. The API is GET `/api/v1/connectors/targets/{id}/snmp-discovery?scope=device|interfaces|topology` and requires target and inventory read access, workspace membership. The TypeScript method is `snmpDiscovery(id, scope)`.

## Data and interpretation

- Device: sysName, sysDescr, sysObjectID, sysUpTime, ENTITY-MIB physical index, parent, class, model, manufacturer, serial and revisions.
- Interfaces: IF-MIB/IF-X-MIB ifIndex, name/description, alias, type, MTU, physical address, speed, admin/oper status, counter discontinuity ticks; IPv4/IPv6 address-to-interface joins from IP-MIB. ifHighSpeed is converted from Mbit/s to decimal bps. The saturated ifSpeed is not treated as an exact speed.
- Topology: BRIDGE-MIB bridge port → ifIndex; Q-BRIDGE current VLAN egress/untagged bitmaps, PVID and static names. PortList bits start at bridge port 1, not ifIndex. Current VLAN timeMark is preserved; static names alone do not imply active membership. LLDP local chassis and local/remote port tables and chassis/port identifiers retain the full remote index and timeMark. Local port maps to ifIndex only by unique exact ifName or Ethernet MAC, never by numeric coincidence. No automatic cross-device links are asserted.
- `visibility` reports each column as `observed` or `not_visible_or_empty`. An empty subtree cannot distinguish ACL masking, unsupported MIB and genuinely empty table. A missing field is null. A snapshot is always conservative (`complete=false`); no deletion of CMDB objects or IPAM updates is performed.

## Bounds and safety

Fixed numeric OIDs, no SET, no arbitrary OIDs, no community fallback. Identity is checked in the authenticated SNMPv3 session before and after collection. A decrease in sysUpTime (including wrap) rejects the snapshot. The interface count and required columns are checked; all walks require strictly increasing OIDs and stop at subtree boundaries. Multi-varbind GETNEXT batches up to 16 columns; ambiguous responses or unsupported batches fall back to single GETNEXT. Transport/auth failure is not converted into an empty MIB. Up to 2048 rows per column, 24000 column values per scope, 512 bytes per value, 10000 VLAN membership rows, 750000 bytes normalized JSON and 60 seconds (per-request timeout may add a small overrun). Failure retains the previous snapshot. Historical successful runs remain separate from the current scope. Profile/revision changes invalidate current snapshots.

No GETBULK, inter-run resume, per-VLAN SNMP contexts, LLDPv2-MIB, prefix discovery, forwarding database, vendor-specific MIBs or hardware-to-CMDB auto-linking in this release. Legacy inventory/metrics retain their existing 64-row limit; advanced discovery expands the dedicated scopes only. Devices that exceed limits need narrower SNMP views or a future resumable collector; do not treat a failed scope as empty. SNMP observations are not an atomic remote snapshot; changes during the walk can still occur.

## Verification

`php tests/snmp-discovery-smoke.php` exercises production normalization and registry/runtime with a deterministic GETNEXT session. `bash tests/snmp-protocol-integration.sh` additionally needs ext-snmp and snmpd and checks the same advanced scopes via a real SNMPv3 session. `php scripts/verify-release.php` runs the new smoke suite. The strict gate includes native SNMP and requires deployment infrastructure. `bash tests/snmp-protocol-integration.sh --postgres` additionally executes the isolated PostgreSQL/Vault/worker upgrade and snapshot-history scenario (CAJEER_PG_TEST=1, CAJEER_PG_TEST_DATABASE=cajeer_test_*). Test against real switches exposing ENTITY, Q-BRIDGE and LLDP before production certification.
