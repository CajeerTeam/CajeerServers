# VMmanager Connection Profile (0.7.1)

Read-only VMmanager 6 Hosting/Infrastructure, VM API v3. Existing provider `vmmanager`, token types `vmmanager_api` and `api_token`, Vault credential purpose `api`. Reference: https://www.ispsystem.com/docs/vmmanager-admin/developer-section/api-guide.

## Upgrade and configuration

1. Stop workers, apply files and `php bin/cajeer migrate` (0046). No earlier migration is modified.
2. Configure operator-controlled `VMMANAGER_CA_FILE` (PEM CA chain, optional if system trust is sufficient) and `CONNECTOR_ALLOWED_NETWORKS`. Keep hostname verification enabled. Use endpoint DNS name from the certificate and port 443 unless the installation uses another HTTPS port.
3. Obtain the leaf certificate SHA-256 out of band from the administrator of the intended VMmanager platform, e.g. from a trusted copy of its leaf PEM: `openssl x509 -in vmmanager-leaf.pem -noout -fingerprint -sha256`. Remove colons. Do not blindly trust a certificate fetched from an unverified endpoint. Pin is in addition to, not instead of, CA/hostname validation. Private/self-signed certificates require an operator-trusted CA/leaf as appropriate.
4. Choose an existing stable physical node visible to the API token. Verify its numeric ID and exact name independently in VMmanager. In Admin > Connectors select the target, edit the VMmanager trust profile as workspace owner with an MFA-authenticated session and save. Bind Vault token and associated inventory asset using existing workflows. Run manual health with MFA/use grant.
5. After configuring every active VMmanager target, set `VMMANAGER_REQUIRE_PROFILE=true` and restart application/workers. This also makes deployment readiness fail if any active VMmanager target lacks a profile. Existing targets otherwise remain in CA-only mode.

API: `POST /connectors/targets` or `PATCH /connectors/targets/{id}` (If-Match for PATCH, session, MFA, CSRF and workspace owner required for profile settings). Example profile:

```json
{"vmmanager_profile":{"primary_pin":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef","anchor_id":2,"anchor_node":"vmmanager-node-02"}}
```

Replace the sample pin and node details. Saving is configuration, not live verification. `POST /connectors/targets/{id}/health` performs the authorized remote probe and returns a `connection` with `provider`, `api_version`, `endpoint_host`, `endpoint_port`, `tls_pinned`, `identity_verified`, and verified anchor fields if configured. Health status and latency are stored by existing revision-scoped monitoring. All reads use pinned HTTPS and `x-xsrf-token`; no redirects, fallback, or token disclosure. Discovery checks the anchor before any inventory and again after collection. Failure aborts the result; previous inventory remains. Rotation validation and metric collection share the same execution path.

## Rotation and identity

For a renewed certificate, verify the new fingerprint independently and update only the pin (owner/MFA/If-Match). Old service grants are invalidated by target revision; issue fresh grants after a successful health check. Pin mismatch blocks requests until explicitly corrected. Profile removal or anchor change is not allowed: create a new target for a different identity. Changing an already discovered endpoint also requires a new target. No automatic trust-on-first-use and no failover. The anchor is an additional identity guard, not a globally unique platform identifier; a cloned platform sharing a certificate and node identity cannot be distinguished by this mechanism. Choose a stable anchor and plan certificate rotation. Node renaming/deletion requires a new target.

`api_version: v3` reports the verified VM service API, not the installed release number or edition. Those are not inferred from the endpoint or an invented response; read them from VMmanager System overview. Detailed API capability discovery is scheduled for 0.7.2. A token without visibility of the anchor cannot use a pinned profile.

## Verification

`python3 tests/vmmanager-https-integration.py` exercises real HTTPS, pin mismatch before token emission, anchor mismatch/missing/changed mid-collection, mandatory mode, health, inventory, metrics and rotation. `--postgres` runs the disposable PostgreSQL/Vault/Queue test (requires the existing CAJEER_PG_TEST configuration). Run `php scripts/verify-release.php` for native regressions; full production requires strict deployment checks and real VMmanager acceptance. Channel remains development.
