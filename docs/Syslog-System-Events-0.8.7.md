# Syslog & System Events 0.8.7

## Upgrade

Stop workers, apply `php bin/cajeer migrate` (0059), rebuild Admin UI, restart workers. Grant `system.events.read` only to trusted log readers; administrator receives it automatically. Logs may contain personal data or secrets emitted by the source. They are never inserted into collection-run results or audit metadata. Avoid collecting sensitive channels and use encrypted database storage/backups as appropriate.

## Listener

Run as a non-root service account with PostgreSQL access and a private configuration file (0600, readable by service user). A listener binds one transport/address/port. Run separate service instances/configs for different transports. Example `/etc/cajeer/syslog.json` (replace certificate paths, fingerprint, target UUID and exact target version):

```json
{
  "mode": "tls", "bind": "192.0.2.10", "port": 6514,
  "cert": "/etc/cajeer/syslog-server.pem", "key": "/etc/cajeer/syslog-server.key",
  "ca": "/etc/cajeer/syslog-client-ca.pem",
  "sources": {
    "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef": {
      "target_id": "11111111-1111-4111-8111-111111111111", "target_version": 2
    }
  }
}
```

`php bin/cajeer syslog:listen /etc/cajeer/syslog.json` or install `deploy/systemd/cajeer-syslog.service` (adjust `/opt/cajeer` and user). TLS requires client certificates chaining to the configured CA plus SHA-256 leaf certificate pin in `sources`; obtain pins from independently trusted certificates (`openssl x509 -in client.pem -outform DER | sha256sum`). Clients must verify the listener certificate and hostname. Mapping is exact target UUID + version; target must be active and of type ssh/winrm/snmp/routeros. A target revision change or certificate rotation requires operator update and listener restart. Header hostname is untrusted display text, never routing identity.

For TCP/UDP legacy, set `mode` to `tcp` or `udp`, `allow_insecure` to true, and map exact sender IPs instead of fingerprints. Restrict to a dedicated trusted management network and firewall; no authenticated identity or delivery guarantee. UDP may be spoofed. Use unprivileged ports >=1024. RFC5424 with RFC6587 octet counting recommended; newline framing also supported for TCP/TLS. RFC3164 timestamps have no year/zone and are stored as unknown; reception time remains authoritative. RFC5424 structured-data is parsed to locate the message but not stored. UTF-8 text only; max 16KiB wire frame, 4096 bytes stored message. Unsupported/malformed messages rejected. 32 simultaneous clients, nonblocking TLS handshake with 3s deadline, 30s idle timeout, 16 connection accepts/s, 200 frames/s global per listener, 100 frames/s per target; excess stream connections closed, UDP dropped. Database inserts batched per read; no durable spool or application acknowledgement. DB errors fail the service and systemd restarts it. Sender must buffer/retry if loss matters; Syslog duplicates intentionally retained since identical messages can be distinct events. No claim of exactly-once transport.

## Polling system events

`ssh.events` and `winrm.events` Inspect plans require pinned trust profiles and existing Vault bindings. Use 60s interval. Linux reads `journalctl` (read permission, Python3, systemd journal), initial last 24h in chronological order, then verifies previous journal cursor anchor and reads next 100. Windows uses fixed PowerShell `Get-WinEvent` for System and Application, initially last 24h, then exact RecordId plus UTC timestamp anchor per channel; max 50/channel. No Security channel, arbitrary XPath, or write operations. Pollers truncate message text to 1024 characters and do not collect full event XML/structured fields. Binary journal messages are not decoded. Windows message rendering may be unavailable and falls back to Event ID. Event times normalized UTC. Cursors and inserts commit atomically with successful collection job. No cursor update on remote failure. Rotation/target version change starts a new 24h scope; stable keys deduplicate retained records. If anchor is missing (log cleared/vacuumed), fail closed; operator investigates, changes target revision (e.g. name) to explicitly start a new recent scope. Older unobserved data may no longer exist. No claim of complete remote history, including limited ACL visibility.

## Reading and retention

In Collections → selected target → Collectors → System events, filter by source, severity, received UTC date range, message substring. API `GET /api/v1/connectors/targets/{id}/system-events?limit=50&source=syslog&severity=3` returns `next_before` for pagination. The server checks session, `system.events.read`, `connectors.targets.read` and target workspace membership. Collection history only includes counts and backlog flag. 100,000 retained events per target; quota prevents unbounded growth and rejects further inserts until pruning; no cursor advances on quota failure. Run `php bin/cajeer system-events:retain 30` (1–365 days) or enable the retention timer. Deletes up to 10,000 rows per invocation based on reception time. Deleting a target cascades events and cursor. Syslog event messages and journal messages are not automatically redacted, so review source-side log policies.

## Verification

`php tests/system-events-smoke.php`, `python3 tests/syslog-receiver-integration.py`, `python3 tests/linux-events-integration.py`, `python3 tests/host-transport-integration.py`. Socket tests use actual local UDP/TCP/TLS and generated disposable certificates with an isolated test sink; no PostgreSQL required. Windows fixture checks WSMan transport and exact script, not real Windows execution. PostgreSQL integration requires disposable test database (see CI). Real deployment/Windows/Linux acceptance and browser tests remain separate.
