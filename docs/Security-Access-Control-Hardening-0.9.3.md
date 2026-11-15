# 0.9.3 — Security & Access Control Hardening

## Upgrade from 0.9.2 (offline, single host)

Retain a complete separate copy of the working 0.9.2 release, including its 60 original migrations. Stop old HTTP, scheduler, workers and external writers before installing 0.9.3. Preserve `.env`, installation marker, storage and independent application/Vault keys. Apply the existing authenticated upgrade workflow:

```sh
php bin/cajeer maintenance:enable
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/0.9.2 \
  --archive=/secure/pre-upgrade-0.9.2.cjdr --key-file=/secure/backup.key --offline
php bin/cajeer migrate:validate
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
```

Migration 0061 revokes every existing connector service grant, disables existing collection plans and deletes step-up grants. It is intentionally conservative: earlier versions did not permanently invalidate grants on privilege loss, and history cannot reliably distinguish safe grants. Existing snapshots, run history, credential ciphertext and configuration backups are retained. Before upgrading, inventory affected schedules and ingestion/webhook sources. After upgrade, a currently authorized owner/manager must complete fresh password + TOTP authorization, issue new service grants and recreate plans (old plans remain disabled). Reconfigure sources that reference old grants using fresh authorized grants; merely re-enabling an old plan does not work. Existing queued connector jobs referencing revoked grants fail authorization; in-flight remote requests cannot be undone but commit fences reject revoked authorization. Back up before upgrade; isolated restore uses the existing recovery mechanism.

## Authorization changes

- Runtime and PostgreSQL agree on the 58 built-in permissions, including VMmanager actions, system events and configuration backup scopes. Unknown role scopes fail closed.
- Workspace role downgrade/removal, role removal, account deactivation, password change and MFA removal revoke delegations in the same transaction as the security change. Re-promotion/reactivation does not resurrect them. Dedicated plans are disabled.
- Vault step-up checks live MFA session and bound asset/credential under a workspace lock; the eight-attempt limit is per actor, not per session. MFA mutations recheck the live session and active account while locking user then session.
- Restricted collector permissions are checked on queue admission, run, commit, list/detail, plan operations and direct discovery. Backup requires both read and create scopes; event reads require `system.events.read`; detailed network collectors require `inventory.network.read` (network sync additionally requires manage). Full run exports require network, event and configuration read permissions, including existing artifact downloads. Ordinary inventory can still contain network summary fields by design.
- Global RBAC is not workspace membership. A global administrator outside a workspace cannot read its target or backup. Run history filters restricted rows before pagination. Audit records denied RBAC decisions after transaction completion so a rollback does not discard the event; database unavailability can prevent audit persistence and is logged.
- Vault key material must differ from APP_KEY even with alternate base64 representation, including rotation keys. Key files must be regular, private, service-owned (where POSIX is available), not symlinks or hardlinks. Existing key material is not automatically rotated.
- Connector endpoint policy rejects IPv4-mapped/compatible IPv6, standard NAT64, 6to4 and Teredo ranges even with broad allowlists, plus existing link-local/metadata ranges. Use explicit native addresses and restrict network egress separately. Operator-specific NAT64 prefixes are not automatically discoverable.
- Application log messages/context redact common credentials, private PEM material, authorization/cookie headers and secret assignments. HTTP access logging uses route templates, not arbitrary path values. Do not put secrets in URLs or logs; configure proxy logs separately.

## Verification

`php tests/security-hardening-smoke.php` and `php scripts/verify-release.php` exercise native regressions. Existing PostgreSQL 17/18 CI covers security, Vault bindings, workspace membership, RouterOS and upgrade/recovery; added assertions check registry parity, audit persistence after rollback, stale MFA sessions, permanent grant revocation and restricted backup history/direct reads. Run the existing PostgreSQL fixtures only against disposable databases. Full production security acceptance requires real database tests, Composer/Admin locked build, browser E2E, dependency audits and external penetration testing; this change is not a certification of the entire platform.
