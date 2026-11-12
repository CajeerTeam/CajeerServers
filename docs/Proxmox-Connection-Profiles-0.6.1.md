# Proxmox VE connection profiles — 0.6.1

## Update

Stop connector workers and administrative mutations; take a verified backup. Apply migration `0037_proxmox_profiles.sql` with `php bin/cajeer migrate`, then `php bin/cajeer vault:check` and `php scripts/verify-release.php`. Existing target IDs, versions, bindings and inventory are unchanged by the migration. Build the admin from reviewed lockfiles and restart workers. The release remains `development` until the strict staging gate passes.

## Configure trust

1. Create a Proxmox target in `/connectors` with its primary DNS name and port (normally 8006). Use a management network permitted by `CONNECTOR_ALLOWED_NETWORKS` and the operator-owned CA bundle `PROXMOX_CA_FILE`. Set the real hostname covered by the leaf certificate. A pin never replaces CA and hostname validation.
2. Obtain each TLS leaf certificate through a separately authenticated administrative channel (for example directly on the PVE node, the configured pveproxy certificate, or an approved PKI inventory). Do not trust a certificate solely because it was downloaded from the proposed endpoint. For the actual PEM leaf use `openssl x509 -in leaf.pem -noout -fingerprint -sha256`; remove colons, use the 64 hex digits. Confirm cluster name and anchor node's Corosync node ID on the trusted PVE administration channel. On standalone PVE use `cluster: null`, `anchor_id: null` and the actual node name; no fallback endpoints.
3. With an owner account with MFA enabled and a verified MFA session, save the profile in `/connectors` (or POST/PATCH target API). Example (pins shown are **synthetic**, replace them):

```json
{
  "cluster": "production",
  "anchor_node": "pve-01",
  "anchor_id": 1,
  "primary_pin": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "fallbacks": [
    {"host": "pve-02.example.internal", "port": 8006, "pin": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}
  ]
}
```

The primary host and port remain the Connector Target fields. Up to two fallback endpoints are allowed, explicitly configured in order. The profile is non-secret; never put a token, password, URL, CA path or arbitrary API path in it. The same cluster-scoped credential must be authorized at every endpoint. Configure a minimal PVE API token (read-only for discovery; existing power actions additionally require the actual PVE `VM.PowerMgmt` rights). A separate write credential purpose has not yet been implemented; keep `CONNECTOR_WRITE_ACTIONS=false` for read-only deployments. The existing `proxmox_api` and compatible `api_token` Vault types are supported.

4. Attach the target to an Inventory asset and bind the Vault credential to target and asset as before. Run the existing MFA health check. It makes real calls to `/api2/json/version`, `/api2/json/cluster/status`, `/api2/json/access/permissions`, and `/api2/json/nodes`. `connection` in the response includes the negotiated version, checked endpoint, failover flag, cluster/anchor identity, number of members, quorum and a bounded allowlist of root-path privileges. This is not a complete ACL evaluation: a token can have privileges at specific resource paths without having them at `/`. If permission inspection is denied the probe reports `permissions_visible=false`; it does not grant any capability. A successful check verifies only the endpoint that served that attempt, not every configured fallback. Test each fallback independently in a controlled maintenance window by making the primary unavailable. Only PVE major versions 8 and 9 are accepted for profiled targets.

5. Reissue service grants and collection plans after a profile change (target version increases); existing grants are deliberately fenced. The target cannot switch cluster/anchor identity, and a configured profile cannot be removed via the API. For a different cluster use a new target. Before updating pins, independently verify the new leaf; profile changes are blocked if an action is unresolved. For an existing unprofiled target with historical Inventory, explicitly verify the same cluster before adding a profile; historical data has no cryptographic identity proof.

## Execution semantics

- Each read attempt authenticates TLS before sending the token, verifies version and cluster membership, then reads from that **one endpoint**. On connection/timeout/HTTP 5xx failure, restart the entire read on the next configured endpoint. Do not merge responses across endpoints. Authentication errors, invalid certificates, pin/cluster mismatch, rate limits and invalid protocol responses fail closed (no fallback). No redirects, arbitrary paths, automatic endpoint discovery or proxy. Each HTTP request has the existing bounded timeout; the full operation is additionally limited by the existing worker hard timeout. DNS resolution is not separately time-bounded.
- Health, inspect, sync, metrics, named collections and scripts using the connector all share the same profile-aware client. Pending credential validation and action preparation use the primary only. Power actions probe primary identity again, refuse a non-quorate cluster, and send only one POST. Read-only task status can fail over; the action ledger and unknown-result policy remain unchanged. No new power operations are introduced.
- Profiled inventory snapshots are deliberately marked incomplete: a visible resource list does not prove cluster-wide ACL completeness. Missing resources will not be inferred deleted by the new path. Full cluster discovery and per-section completeness are planned for 0.6.2. Existing unprofiled targets retain their old behavior until migrated.
- For migration compatibility unprofiled targets still use the existing CA/hostname/network policy without profile checks or failover. After provisioning all PVE profiles, set `PROXMOX_REQUIRE_PROFILE=true` in the common environment for web and workers to block every unprofiled PVE operation. This is an operator deployment decision; changing the flag requires restarting services.
- A cluster name and node ID are not a cryptographic cluster identifier. The actual trust anchor is the independently approved leaf pin combined with CA and hostname, with cluster metadata as an additional guard. PVE certificate renewals require explicit pin updates; there is no trust-on-first-use or automatic pin replacement.

## API / SDK

Existing `POST /api/v1/connectors/targets` and `PATCH /api/v1/connectors/targets/{id}` accept optional `proxmox_profile` (object). PATCH uses the current target `If-Match`, and writes require CSRF and the existing target management permission. Profile updates additionally require owner role and MFA. `GET /targets/{id}` and list return `proxmox_profile` or null. The existing `POST /targets/{id}/health` returns optional `connection` diagnostics. No credentials are included in these responses.

```ts
const updated = await client.updateTarget(target.id, target.etag, { proxmox_profile: profile })
// Obtain fresh Vault authorize-use for this target's asset first.
const health = await client.checkHealth(updated.id, updated.etag)
console.log(health.connection?.version, health.connection?.failover)
```

## Verification

`php tests/proxmox-profile-smoke.php` exercises profile validation, runtime and failover behavior. `python3 tests/proxmox-profile-https-integration.py` uses two independently certified local TLS endpoints, observes actual HTTP traffic and proves that a bad pin does not send Authorization or fall over. `php tests/proxmox-profile-postgres-integration.php` requires an isolated `CAJEER_PG_TEST=1`, `CAJEER_PG_TEST_DATABASE=cajeer_test_*` PostgreSQL 17/18 database; it upgrades from 0.6.0 and checks real Vault, grants and named collector persistence. `php scripts/verify-release.php --strict` and CI include both. Run on staging and a real PVE 8/9 cluster with approved test resources before production; fixture tests are not PVE appliance certification. Dependency lockfiles, PostgreSQL, full Nuxt build and browser E2E still require a complete staging environment.
