# CajeerServers 0.5.4 — Output Parsers & Data Normalization

## Upgrade

Apply the changed files to 0.5.3, stop the script worker during replacement, then run `php scripts/verify-release.php`. No SQL migration: the existing `script_versions.signing_bytes` and `script_executions.result` contain all required information. Existing signatures remain valid; missing `output_schema` means `document`. Old stored results are not rewritten. The sandbox, worker identity, MFA, Vault, deadlines and execution feature flag from 0.5.3 remain mandatory. Do not enable local execution until the native sandbox integration test passes on your host.

## Signed parser policy

Create a new local version using `POST /api/v1/workspaces/{workspace}/scripts/{id}/versions` with `parser` (`json`, `csv`, `key_value`, `prometheus`) and `output_schema` (`document`, `observations`, `metrics`, `events`). Prometheus requires `metrics`. Defaults are JSON/document; fixed remote collectors only accept JSON/document and produce trusted normalized observations through Connector Runtime. Both fields are covered by Ed25519 signing bytes. Sign and approve a new version for a policy change; do not modify the old signature. Template creation remains unchanged.

Example local Bash version:

```json
{"language":"bash","provider":"local","source":"printf 'metric,value,unit\\nsystem.cpu.load,2.5,percent\\n'","parser":"csv","output_schema":"metrics"}
```

The source above is a format example, not a real CPU measurement. For a real local collector, use a trusted script that reads the relevant information available inside the isolated runtime; the sandbox does not expose the host's real filesystem/network.

## Parsers

* JSON: object or array; duplicate keys (including escaped equivalents), invalid UTF-8, embedded NUL, non-finite numbers, trailing garbage and excessive nesting rejected.
* CSV: comma delimiter, first row unique named headers, double-quoted cells, doubled quotes, LF or CRLF, multiline quoted values. Every row must have the same column count. Values initially remain strings. UTF-8 BOM and nonstandard dialects are not supported.
* Key-value: `key=value` per line; comments starting with `#` and blank lines ignored, split only at first `=`, duplicate keys rejected. Returns one object, not a batch of records.
* Prometheus text: sample names, numeric values (finite only), quoted labels with `\\`, `\"`, `\n`, optional timestamp in **milliseconds**. Comments are ignored; HELP/TYPE metadata is not retained. No exemplars, native histograms, OpenMetrics seconds timestamps, NaN or infinities. This is a text sample parser, not a scraper or time-series backend.

Raw stdout is limited by the signed limit (max 256 KiB; stderr counts towards the combined execution limit). Expanded stored result is limited to 1 MiB, 2,000 rows, 64 CSV columns, 16 KiB CSV cell, JSON depth 30 and bounded node counts. Metrics have at most 16 labels per sample, 256 bytes per label value and 1,000 series per result. Invalid output fails the entire execution with `script_output_invalid`; no partial result, raw invalid payload or stderr is persisted. Error responses do not include untrusted stdout. Successful output is visible to the execution owner through existing RBAC/session restrictions, so do not emit credentials or other secrets. Retention and long-term quarantine are future features.

## Typed schemas

`document` preserves parsed values in `result.output` (legacy behavior). Other schemas also add `result.normalized` with `schema_version: 1`, `schema`, `complete: false`, `count`, and a typed array. JSON can supply a single object or array of objects; CSV supplies rows, key-value one object. Empty batches are non-authoritative and cannot delete assets. Timestamp defaults to one server UTC time per batch; explicitly supplied RFC3339 timestamps must be within 366 days past and 300 seconds future. Duplicate identities in a batch are rejected, not silently overwritten.

Observations: `external_id`, `type`, `fields` (JSON object) or flat allowlisted fields `name`, `status`, `os_family`, `environment`, `ip_addresses`, `tags`, plus optional `observed_at`. In flat CSV/key-value, use semicolon-separated addresses/tags. No owner, workspace, asset ID, arbitrary attributes or reserved connector identity. Existing Inventory ObservationInput validates names, IPs, types and allowed fields. Example:

```json
[{"external_id":"manual-host-1","type":"server","fields":{"name":"host-1","status":"online","ip_addresses":["192.0.2.1"]}}]
```

Metrics: `metric`, numeric `value`, optional `unit`, `labels` object, `resource_external_id`, `observed_at`. CSV/key-value values convert to integer where possible, otherwise finite float. For labels use a JSON object in the cell/value. JSON requires a number, not a numeric string. Series identity is metric/resource/sorted labels, samples additionally include timestamp. Floats have normal IEEE-754 precision; exact unsigned 64-bit counter storage is not implemented in this release. Example:

```json
{"metric":"system.cpu.load","value":2.5,"unit":"percent","labels":{"cpu":"total"}}
```

Events: `event_id`, `type`, `message`, optional `severity` (`debug`, `info`, `warning`, `error`, `critical`) and `observed_at`. Default severity info. Events are data only; no command dispatch. No arbitrary event payload attributes in v1.

Trusted remote script templates retain their original output (the validated Connector snapshot) and now also expose normalized observations, including the existing completeness and parent relationships. They still use inspect, not sync. SNMP uses the existing working SNMP Connector, not a new raw OID text format.

## Preview, execution, and Inventory

On `/scripts`, select an approved local version and enter a sample stdout. `POST .../validate` accepts `sample_output` alongside parameters, performs existing signature/role/session checks and parses the sample without starting a process or storing an execution. The response returns `sample` with the actual parsed/normalized data. A caller cannot change the parser policy in this request. Invalid input is a 422. The normal preview/execute API queues a real worker job; stdout is parsed before committing a success, after which the existing live permission/revocation/cancellation guard runs again. A nonzero exit code is not parsed as success.

For local typed observations, the result UI can download a JSON file (max 200 observations) using the existing Inventory Import interchange shape. Review it in Inventory Import, preview and explicitly submit through its existing permissions and history mechanisms. No automatic CMDB write from arbitrary script output, no spoofing connector identity, no automatic deletion. Remote snapshots must be applied through the existing authorized connector `sync` path. Metrics and events are persisted only as typed execution results, not separate time-series or event databases (scheduled collections and storage follow in 0.5.5/0.5.6/0.5.7).

## Verification

`php tests/script-output-smoke.php` exercises the parsers, signing compatibility, existing Inventory import, and real subprocess stdout through the production I/O parser. `php scripts/verify-release.php` includes this suite. Existing PostgreSQL tests now check approved sample parsing and persisted normalized remote results. Native sandbox, PostgreSQL and browser E2E still require an isolated staging environment; local smoke tests do not certify the sandbox or deployment.
