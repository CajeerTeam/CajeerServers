# OS / Network Connection Profiles — 0.8.1

The existing SSH, WinRM and SNMP drivers now support explicit per-target trust. No remote write, arbitrary command or trust-on-first-use is added. Profiles contain non-secret trust material only; credentials remain encrypted in the existing Vault and the same authorized inventory/metrics bindings are used.

## Upgrade and configuration

1. Stop workers, apply `php bin/cajeer migrate` (0053 adds a nullable JSONB column; older migrations remain intact). Build the Admin UI and restart all workers.
2. Preserve `CONNECTOR_ALLOWED_NETWORKS`, `CONNECTOR_PYTHON`, `WINRM_CA_FILE` and legacy `SSH_KNOWN_HOSTS_FILE`. `OS_NETWORK_REQUIRE_PROFILE=false` preserves compatible behavior. After enrolling every active SSH/WinRM/SNMP target set `OS_NETWORK_REQUIRE_PROFILE=true` and restart processes. Deployment readiness reports missing/invalid profiles.
3. In Admin > Connectors select the target and OS / Network trust panel. Save using an active MFA session as workspace owner; the API also accepts `os_network_profile` on target create/PATCH. PATCH requires If-Match. Run MFA health check with the current Vault credential. Saving a profile is not a proof of remote reachability.
4. Profile edits acquire the same target execution lock as connector operations, bump target revision and invalidate existing grants/health. Reissue authorized service grants and plans as required. Identity cannot be changed or removed and a pinned target cannot change endpoint; create a new target instead. The certificate/SSH key pin can be rotated for the same host identity. SNMP engine/object identity changes require a new target. Never copy a fingerprint from an unverified network connection.

## Profile formats

SSH: SHA-256 **hex** of the SSH wire-format public key, the key algorithm, and SHA-256 of `ssh:` followed by normalized machine-id (32 lowercase hex). Obtain the public key and `/etc/machine-id` using an independent trusted administrative channel. For example, on the trusted host:

```sh
awk '{print $2}' /etc/ssh/ssh_host_ed25519_key.pub | base64 -d | sha256sum
printf 'ssh:%s' "$(tr -d '\n-' </etc/machine-id | tr '[:upper:]' '[:lower:]')" | sha256sum
```

```json
{"primary_pin":"<64 hex>","key_type":"ssh-ed25519","identity_hash":"<64 hex>"}
```

Allowed key types: ssh-ed25519, ssh-rsa (negotiated RSA-SHA2 only), ecdsa-sha2-nistp256/384/521. The pin replaces the global known_hosts lookup for that target; legacy targets still require known_hosts. The key is verified before password/public-key authentication. The identity is checked before accepting inventory or metrics. A machine-id is a secondary identity guard, not a globally unique cryptographic proof. Key rotation is explicit, not automatic.

WinRM: SHA-256 hex of the leaf DER certificate plus the SHA-256 of `winrm:` followed by lowercase MachineGuid without hyphens. Example certificate calculation from a trusted PEM copy:

```sh
openssl x509 -in winrm-leaf.pem -outform DER | sha256sum
```

```powershell
$id = (Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid').ToLower().Replace('-','')
$bytes = [Text.Encoding]::UTF8.GetBytes('winrm:' + $id)
[BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash($bytes)).Replace('-','').ToLower()
```

```json
{"primary_pin":"<64 hex>","identity_hash":"<64 hex>"}
```

CA and hostname verification remain mandatory in addition to the pin, including each WSMan request and shell cleanup. Basic over HTTPS remains the only supported WinRM authentication method. No NTLM/Kerberos downgrade.

SNMP: use a verified device configuration or an independently trusted administrative session to obtain snmpEngineID (`.1.3.6.1.6.3.10.2.1.1.0`) as 5–32 octets represented in hex (no separators) and sysObjectID (`.1.3.6.1.2.1.1.2.0`) as numeric OID with leading dot.

```json
{"engine_id":"80001f8804aabbcc","object_id":".1.3.6.1.4.1.8072.3.2.10"}
```

The example values are not trust material for your device. SNMPv3 authPriv is mandatory for a profiled target, even if legacy v2c is enabled. The Net-SNMP USM session authenticates/encrypts requests; the profile compares returned engine ID and sysObjectID before and after collection. This is **not** a pre-authentication key pin: ext-snmp discovers the authoritative engine during USM negotiation, then the application verifies the authenticated engine MIB value. Devices must grant access to both identity OIDs, otherwise the check fails closed. Use unique SNMP credentials per device. No SNMPv2c upgrade or fallback is performed.

## Verification and limits

Successful health/inspect results include bounded `connection` fields: provider, trust_mode, profile_verified, identity_verified. Legacy profiles are not marked verified. Raw machine IDs, secrets and arbitrary remote errors are not included. Existing health monitor records elapsed time and safe error category. Identity mismatch is `connector_trust_failed`; no observations or metrics are published.

Tests: `php tests/os-network-profile-smoke.php`, `python3 tests/host-transport-integration.py --ssh`, `bash tests/snmp-protocol-integration.sh`, and disposable PostgreSQL host hardware integration through the strict release runner. WinRM is also in the native gate. The SSH test needs Paramiko and cryptography; SNMP integration needs PHP ext-snmp and snmpd. The PG test requires PostgreSQL 17 and pdo_pgsql. Browser deployment and actual device acceptance remain required before stable.

The release uses the existing single provider credential purpose for inventory/metrics. Separate backup credentials and collectors are future work. OS inventory expansion and RouterOS API are not part of this release. Legacy SNMP targets without a profile can be enrolled only by confirming the actual device out of band; the old endpoint-scoped mapping cannot establish its original engine ID. Do not enroll a replacement device into an existing target.
