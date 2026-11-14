# Debian / Ubuntu Discovery 0.8.2

Apply `php bin/cajeer migrate` (0054_linux_discovery.sql) after backing up the database and stopping workers. Rebuild Admin UI and restart workers. Existing SSH targets, Vault credentials, `ssh.inventory` and `ssh.metrics` remain compatible. The new scopes require the individual SSH trust profile introduced in 0.8.1; configure host key pin, key type and machine identity hash, then perform a health check.

The target needs Python 3, read access to `/etc/os-release`, `/etc/machine-id`, `/proc`, relevant sysfs files, and `dpkg-query`, `systemctl`, `lsblk`, `findmnt`, `df`, `ip`. No persistent agent, sudo, arbitrary command endpoint or system changes are required. Python/Paramiko and cryptography are needed on the worker as before. Commands use fixed argument lists and controlled locale; credentials are never passed to remote commands.

Create independent Inspect plans from Collections → Plans:

| Collector | Data | Suggested seconds |
|---|---|---:|
| ssh.system | OS, kernel, architecture, hostname, CPU model/socket/core/thread count, RAM/swap, uptime, readable DMI manufacturer/model | 300 |
| ssh.software | Installed dpkg packages, version, architecture | 3600 |
| ssh.services | systemd unit files and loaded service states | 300 |
| ssh.storage | Block devices, parent kernel device, type, bytes, filesystem, mounted filesystem capacity | 300 |
| ssh.network | Interfaces, MAC, MTU, addresses/prefixes, routes | 300 |

Use `ssh.inventory` Sync separately to maintain the existing CMDB asset and `ssh.metrics` Inspect for time-series metrics. Detailed scopes are Inspect only: they persist inventory details without changing manual CMDB attributes or IPAM or marking resources missing.

## Data and security

The fixed remote Python probe validates distribution ID and checks machine identity before/after collecting. HostTransport independently checks machine identity against the configured profile. PHP validates sections, fields, bounds, address syntax and duplicate identities. Raw machine-id is not stored in detailed results. Numeric byte counts are decimal strings. Package architecture is part of package identity. A unit file may have unknown runtime state if not loaded. Missing optional DMI fields and capacity values are null. No package descriptions, process arguments, service environment, mount options or network filesystem source strings are collected.

Each scope is stored atomically with the authorized run result. Current state is scoped by target, Workspace, target version, credential ID and credential version. A stale run cannot overwrite a newer observation. The API hides stale snapshots after target edits, credential rotation/unbinding or disabling the target. Run history remains accessible under existing history authorization. The UI shows observation time, searchable paginated tables, and historical runs distinguished from current state.

GET `/api/v1/connectors/targets/{id}/linux-discovery?scope=software` (default `system`) returns `{data:{state,observed_at,snapshot}}` with `available`, `stale`, or `not_collected`. Requires `connectors.targets.read`, `inventory.assets.read` and Workspace membership. Use existing runs API with `collector=ssh.software` for history. The SDK exposes `linuxDiscovery(id, scope)`.

## Limits

27-second overall command budget, 10 seconds per utility, existing 35-second SSH command deadline. Maximum 10,000 rows per section and 750,000 bytes per normalized scope. Larger snapshots fail rather than publishing truncated data. This is not cross-run incremental inventory. `complete` means the fixed probe completed, not that an unprivileged account can observe every hardware resource. A missing utility, failed command, unsupported distribution or malformed output fails the scope. Containers without working systemd do not publish an empty service inventory. Filesystem capacity comes from local `df -l`; network mounts are listed without capacity and raw disk allocation is not treated as used capacity. Multipath routes are represented at route level only. Package availability scan, process listing, LVM-specific metadata, service changes and IPAM synchronization are not implemented in this release.

## Tests

`php tests/linux-discovery-smoke.php` validates registration and normalization. `python3 tests/linux-discovery-integration.py` executes actual system/software/storage/network scripts on Debian/Ubuntu and validates output in PHP; service parsing uses controlled command responses. `python3 tests/host-transport-integration.py --ssh` additionally exercises new collectors over a real local SSH session (requires Paramiko). The worker/Vault integration is run via `python3 tests/host-transport-integration.py --ssh --postgres` with the existing disposable PostgreSQL database and `pdo_pgsql`. Real SSH/systemd targets and browser deployment acceptance remain separate from local probe tests.
