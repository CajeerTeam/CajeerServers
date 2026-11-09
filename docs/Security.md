# CajeerServers 0.1.6 — RBAC, TOTP MFA, signed webhooks and audit

## Deployment

Apply the **0.1.6 patch over 0.1.5**, back up PostgreSQL and run `php bin/cajeer migrate` then `php bin/cajeer migrate:validate`. Migration `0005_security_audit.sql` is mandatory before any 0.1.6 auth request: it seeds administrator permissions, creates MFA/recovery/rate-limit/webhook tables, and sets PostgreSQL `audit_log` to append-only. Do **not** replace the entire project with the patch ZIP.

Requirements: PHP 8.4+, ext-sodium, ext-pdo_pgsql, PostgreSQL 17+, a strong 32+ byte `APP_KEY`, and HTTPS from client through the front controller in production. Keep `APP_KEY` backed up securely. Rotating it without re-encrypting existing secrets invalidates enrolled authenticators and webhook secrets. Configure Nginx/FPM so trusted HTTPS requests are represented correctly by `$_SERVER['HTTPS']`; never trust arbitrary forwarded headers.

## Role-based access control

Version 0.1.6 enforces **permissions from PostgreSQL** on every privileged request. The installer-created `administrator` role receives the seven builtin permissions during migration:

- `security.users.read`, `security.users.manage`
- `security.roles.read`, `security.roles.manage`, `security.roles.grant_administrator`
- `security.audit.read`, `security.webhooks.manage`

A newly created user has **no roles and no privileges**. Users/roles may be created and assigned through `/api/v1/security/users`, `/api/v1/security/roles`, and `/api/v1/security/users/{id}/roles/{slug}`. Creation and mutation require an authenticated cookie session, CSRF header and the corresponding RBAC permission. A role manager cannot delegate privileges they do not already hold; changing administrator membership additionally requires `security.roles.grant_administrator`. The current administrator may not revoke their own administrator role or deactivate their own account; the last active administrator cannot be deactivated.

All security endpoints are **session-only**. A bearer API token cannot mutate users, roles, sessions, 2FA settings or webhook credentials, regardless of its scopes. `/api/v1/auth/*` self-service endpoints from 0.1.5 remain in place. 0.1.6 does not enable destructive infrastructure control APIs.

### Example (after login)

```bash
# Save csrf_token from POST /api/v1/auth/login, and the server's HttpOnly session cookie.
curl --cookie "__Host-cajeer_session=<session>" \
  -H "X-CSRF-Token: <csrf_token>" -H 'Content-Type: application/json' \
  -d '{"email":"operator@example.test","name":"Operator","password":"replace-with-unique-strong-password"}' \
  https://servers.example.test/api/v1/security/users
```

The token/cookie shown here are placeholders; use the cookie issued by the server. For development HTTP the cookie name is `cajeer_session`.

## TOTP 2FA

1. Log in normally (`POST /api/v1/auth/login`) and obtain the session cookie/CSRF header.
2. `POST /api/v1/auth/mfa/enroll` with `{"password":"current password"}` and `X-CSRF-Token`. The response contains a one-time `otpauth_url`/base32 secret; scan it with a TOTP authenticator over a private HTTPS connection.
3. `POST /api/v1/auth/mfa/confirm` with `{"code":"123456"}` using the same session/CSRF. Store the returned **10 recovery codes** securely. Codes are **never returned again** and are stored only as keyed hashes.
4. Future login requires `email`, `password`, and `mfa_code` (six-digit TOTP or one-time recovery code). TOTP counter reuse is rejected. Recovery codes are single-use.
5. `POST /api/v1/auth/mfa/disable` requires `password` and a valid MFA/recovery code plus CSRF. It revokes every session and API token, requiring fresh login.

The TOTP secret is encrypted with XChaCha20-Poly1305 and associated user identity. Enabling MFA invalidates old sessions (except the confirming one) and all existing API tokens. New tokens can be created only from the MFA-verified session. If the authenticator and recovery codes are both lost, no bypass/reset endpoint is exposed by this release; recovery requires a controlled, audited offline administrative procedure.

## Signed webhook inbox

Only administrators with `security.webhooks.manage` can create/revoke sources via `/api/v1/security/webhooks`. The generated source secret is returned **once**. An external sender POSTs a JSON object of at most 256 KiB to `/api/v1/webhooks/{id}/events` with:

- `X-Cajeer-Timestamp`: current Unix seconds, maximum ±300 s clock skew.
- `X-Cajeer-Signature`: `sha256=<lowercase-hex HMAC-SHA256>`, computed with the source secret over `v1.<timestamp>.<exact raw request body>`.

On success the response is HTTP **202** and `{ "id": "...", "status": "received" }`. The event is persisted in PostgreSQL, with a **unique replay digest** and source revocation check in the same transaction. The event is **not executed, not automatically applied to hosts**, and has no access to infrastructure operations. Invalid signatures return 401, replays 409, and per-source excess requests 429. Incoming events may be viewed (metadata only) via `/api/v1/security/webhooks/events` by authorized administrators.

Example signature generation in PHP:

```php
$timestamp = time();
$body = json_encode(['event' => 'device.status', 'status' => 'ok'], JSON_THROW_ON_ERROR);
$signature = 'sha256=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $body, $sourceSecret);
// POST the exact $body, timestamp and signature to the source URL.
```

## Audit and rate limits

Security decisions and successful changes are recorded in `audit_log`. New events use a metadata allowlist and never accept request bodies, passwords, raw tokens, MFA secrets or webhook signatures. A PostgreSQL trigger blocks UPDATE/DELETE on this table; operators must plan retention and privacy obligations explicitly (e.g., controlled archive/partitioning) rather than deleting audit rows through the application. `/api/v1/security/audit?limit=50` requires `security.audit.read`.

PostgreSQL-based limits: login 30 attempts/IP/15 minutes in addition to the existing 6 failures per email/IP; MFA enrollment 5/15 minutes and confirmation/disable 10/15 minutes per account; webhooks 120 events/source/minute. These are application-level limits, not a substitute for a reverse-proxy firewall/DoS protection. Expired rate-limit rows require periodic maintenance as part of 0.1.7 scheduler work.

## Testing

```bash
php tests/runtime-smoke.php
php tests/http-smoke.php
php tests/database-smoke.php
php tests/installer-smoke.php
php tests/auth-smoke.php
php tests/security-smoke.php
# Run on a disposable PostgreSQL 17+ database, never production:
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_security \
  CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='...' \
  php tests/security-postgres-integration.php
```

The native smoke suite validates functional cryptography, routes and fail-closed behavior, but it does **not** replace successful PostgreSQL integration, production TLS/proxy verification, code review or security testing.
