# RouterOS API Integration 0.8.5

RouterOS 7 HTTPS REST integration. Only fixed GET requests are implemented. No arbitrary paths, commands, POST, PATCH, PUT, DELETE, exports, file access or firewall writes.

## Deployment

1. Apply `php bin/cajeer migrate` (0057_routeros_integration). Previous migrations are unchanged.
2. Configure RouterOS `www-ssl` with a certificate matching its HTTPS hostname. Create a dedicated read-only RouterOS user group with `read` and `rest-api` policies (verify the exact policies on the installed release); do not grant write, policy, sensitive or reboot. Restrict the service to the CajeerServers management network. Never enable plain HTTP for this integration.
3. Include the router address in `CONNECTOR_ALLOWED_NETWORKS`. Set `ROUTEROS_CA_FILE` to a trusted CA PEM if necessary. System CA verification and hostname verification are always required in addition to the pin.
4. Create an Inventory router, a connector target with provider `routeros`, hostname and HTTPS port (normally 443), and a Vault credential of type `routeros_api`, `{ "username": "reader", "password": "..." }`. Bind with purpose `api` to both target and associated asset. The credential is encrypted; it is not returned to the browser.
5. Configure `os_network_profile` with `primary_pin` (64 hexadecimal SHA-256 of DER leaf certificate) and `identity_hash` (SHA-256 of UTF-8 `routeros:` followed by exact `/system/identity` name, no trailing newline). Example fingerprint commands using independently verified certificate and name:

```sh
openssl x509 -in router-leaf.pem -outform DER | sha256sum
printf '%s' 'routeros:router01' | sha256sum
```

The profile is mandatory regardless of the legacy OS_NETWORK_REQUIRE_PROFILE flag. Certificate rotation requires MFA, owner rights and If-Match. Identity hash is a name check, not an immutable device serial or cryptographic platform UUID. Renaming the device or changing endpoint requires a new target; do not enroll two devices using the same certificate/key and name. Use unique certificates.

6. Run health check, then create collection plans:

| Collector | Operation | Interval |
|---|---|---|
| routeros.inventory | inspect or sync | 300 s |
| routeros.network | inspect | 300 s |
| routeros.routing | inspect | 300 s |
| routeros.metrics | inspect | 60 s |

Inventory Sync maps a single `router` resource with external ID `device` in the target scope. It never removes unseen resources. Network and routing data are observations, not editable IPAM changes. See Collections for the three snapshot panels, search and run history. The SDK exposes `routerosDiscovery(targetId, scope)`.

## Data and safety

- Inventory: system identity/resource, routerboard, interfaces. Network: interfaces, bridge, bridge ports/VLAN, VLAN, bonding, IP addresses, pools, DHCP leases and neighbors. Routing: IPv4/IPv6 routes, routing tables, IPv4/IPv6 filters, IPv4 NAT and address lists. Rule order is preserved. Rules are metadata, not an executable configuration or a firewall simulator. Comments, scripts, passwords and arbitrary remote fields are not requested or retained.
- Device and link observations preserve RouterOS string values, including decimal byte counts and Counter64. Names/IDs within a snapshot describe associations; `.id` can change on reconfiguration/reboot and is not global identity. Metrics use raw counters; do not infer traffic rates without handling resets.
- The client accepts documented REST singleton objects or lists for system menus, and requires list responses with unique record IDs for list menus. HTTP 403/404 on optional menus records `denied`/`not_found`. Other errors fail the collection. Visible empty is distinct from unavailable. The `complete` flag indicates all requested menus responded, not proof of complete ACL visibility. CMDB completeness is always false.
- TLS pin is checked before HTTP credentials on every connection. Identity checked before and after collecting. RouterOS major version 7 and platform MikroTik are required. No redirects or automatic trust/retries. Failed, oversized, malformed or superseded collection does not replace the previous snapshot.
- Maximum 4000 rows per menu, 8000 rows per snapshot, 750000 bytes normalized JSON, 60 seconds between request starts, per-request HTTPS timeout and transport byte cap. REST doesn't provide general keyset pagination; oversized menus fail without publication. Metrics use existing 2000 sample cap. Collection state is scoped by target/credential revisions and workspace. No staging/resume, no RouterOS 6 or native API-SSL, no configuration backup/restore yet.

## Verification

`python3 tests/routeros-https-integration.py` runs the production PHP driver through real TLS with a synthetic RouterOS responder. It checks all collectors, GET-only behavior, ACL, TLS, identity, errors and counters. This is not a live RouterOS acceptance test. For a disposable PostgreSQL 17+ database configured with CAJEER_PG_TEST=1 and CAJEER_PG_TEST_DATABASE=cajeer_test_..., run the same script with `--postgres` to exercise the migration, Vault, job execution and snapshot history. Never use a production database for that test.

Reference: https://manual.mikrotik.com/docs/developer-guides/rest-api/
