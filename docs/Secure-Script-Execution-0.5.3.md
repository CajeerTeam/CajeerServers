# Secure Script Execution & Dry Run — 0.5.3

## Scope and threat model

The 0.5.2 registry now feeds a durable, one-shot execution queue. Signed, approved Bash, Python and PowerShell source can run **locally on Linux** using bubblewrap. This is an isolation boundary for trusted operator-reviewed code, not a service for arbitrary hostile tenants. The sandbox has a dedicated read-only runtime root, a new user/PID/IPC/network/UTS namespace, no host environment, no application paths, no host credentials, no host network and bounded writable tmpfs. It uses cgroup v2 and per-process rlimits; worker startup fails closed when the cgroup or rootfs is unsuitable. Use a dedicated service UID. No fallback to host interpreter, sudo, shell interpolation or arbitrary remote command is implemented.

Remote SSH/WinRM only execute the **exact current built-in Linux/Windows templates**, via the already working fixed SSH/WinRM collector. SNMP, Proxmox and VMmanager templates use named inspect collectors. Source modification (even whitespace) denies remote execution; a new template version must be signed and approved after updating a built-in template. No custom remote Bash/Python/PowerShell, NTLM/Kerberos or remote write actions. Signed source and parameter values are never sent to an arbitrary remote shell. The trusted worker has access to the application's DB and Vault for fixed connectors; the sandboxed process does not inherit those files, secrets or environment.

## Deployment

1. Back up database, stop old workers, overlay changed files and run `php bin/cajeer migrate`. Migration `0031_script_execution.sql` retains previous script versions and adds one-shot execution history.
2. On a Linux host install PHP CLI with `pdo_pgsql`, `sodium`, `pcntl`, `posix`, non-setuid bubblewrap (with `--unshare-user`, `--disable-userns`, `--size`), util-linux `prlimit`, systemd and cgroup v2. User namespaces must actually work under the deployment's security policy. Do not loosen production host security to bypass a failed sandbox.
3. Provision a dedicated runtime root, never the host `/`, application directory or `/usr`. Example for Debian bookworm (amd64):

```sh
sudo apt-get install bubblewrap debootstrap
sudo debootstrap --variant=minbase --include=python3,bash,libicu72,libssl3,zlib1g,libgssapi-krb5-2,libstdc++6 bookworm /opt/cajeer-script-root https://deb.debian.org/debian
sudo install -d -m 0755 /opt/cajeer-script-root/work
```

Install a verified PowerShell 7 package matching the rootfs distribution/architecture from the official Microsoft package repository or your trusted internal mirror, into the rootfs (not just the host); `/usr/bin/pwsh` must resolve inside it. For example copy the verified `.deb` to the rootfs `/tmp`, use `chroot ... dpkg -i /tmp/powershell.deb` as operator, install required dependencies through trusted repositories, then remove the package. Do not mount host `/proc`, `/sys`, secrets, home directories or application files into this rootfs. Unmount all nested mounts after provisioning. The rootfs and its ancestors must be root-owned and not group/world writable. Rootfs contents are operator-managed trusted runtime code; do not allow the worker or users to alter them. Create the marker after inspection:

```sh
printf 'cajeer-script-root-v1\n' | sudo tee /opt/cajeer-script-root/.cajeer-script-root >/dev/null
sudo chmod 0644 /opt/cajeer-script-root/.cajeer-script-root
```

4. Set in the application configuration and worker environment:

```dotenv
SCRIPT_EXECUTION_ENABLED=true
SCRIPT_SANDBOX_ROOTFS=/opt/cajeer-script-root
```

By default execution is disabled. The worker runs as a dedicated non-root `cajeer-script` account, not the PHP-FPM process. Create that account and provision read access to the deployed application, its configuration and runtime DB credentials. For remote fixed collectors provision the existing Vault key securely for this service UID (e.g. separate owner-only file with the same key via your secret management system); do not copy it to the sandbox root. Give writable access to required application storage/log paths only. The supplied systemd service assumes `/opt/cajeer` and `/usr/bin/php`; adapt paths to your deployment. Apply via `sudo systemctl daemon-reload` and `sudo systemctl enable --now cajeer-script-worker.timer`. The unit has `MemoryMax=768M`, `MemorySwapMax=0`, `TasksMax=96`, `CPUQuota=100%`, one job per service invocation, 130s wall-clock service cap and `KillMode=control-group`. Install both `deploy/systemd/cajeer-script-worker.*` files under `/etc/systemd/system/` after adapting them. The worker requires a dedicated PostgreSQL session (not transaction-mode PgBouncer) for its advisory lock.

Manual processing **inside a correctly configured non-root cgroup**:

```sh
php bin/run-script.php 1
# equivalent:
php bin/cajeer scripts:work 1
```

No execution runs inside PHP-FPM. Configure the timer or equivalent bounded supervisor before enabling submission. Local sandbox checks enforce effective cgroup memory <=1 GiB, swap <=256 MiB, tasks <=128 and CPU <=2 cores; production service uses stricter ceilings. Per-process virtual address cap 8 GiB, file limit 16 MiB, 128 FDs, 64 processes per UID, CPU limit signed timeout +1s, no core dumps. The outer cgroup is essential for aggregate limits. No new nested user namespaces inside the sandbox. Temporary source is stored in a private directory, never credentials. A hard worker kill may leave its private source file in the service's temporary directory; systemd PrivateTmp and cleanup on normal exit limit this. Code and inputs must not contain secrets.

## Lifecycle / API

Base `/api/v1/workspaces/{workspace}/scripts/{script}/versions/{version}`:

- `POST /validate`: signature, approval, active MFA session, target revision and parameters; no interpreter or remote I/O. `valid` indicates eligibility checks, `execution_enabled` indicates policy/config acceptance, not proof the runner is installed. `channel` is `sandbox`, `connector`, or null.
- `POST /executions`: JSON `{"mode":"preview","parameters":{"n":42},"password":"...","totp":"123456"}`, optional `target_id` for remote; session, CSRF, `Idempotency-Key` (1–128 safe ASCII chars) required. Fresh password and TOTP required; remote additionally requires existing one-use Vault grant for exact asset/credential/purpose, issued through the connector UI or API. Two fresh TOTP codes are needed for remote Vault authorization then script step-up; both grants are short-lived. Response 202 is queued, not executed.
- `GET /executions?offset=0`: own execution history (50 rows/page, no output).
- `GET /executions/{execution}`: own result and safe error category.
- `POST /executions/{execution}/cancel` with `{}`: cancel queued/running. Local guard terminates within its polling cycle; remote read already in flight may finish but cannot commit result.

Both `preview` and `execute` perform real allowed collection and persist bounded JSON result; neither applies Inventory or metrics in 0.5.3. `validate` is the no-I/O dry run. Local code reads a single JSON object from stdin; no template interpolation or arbitrary arguments. Output must be a JSON object/array; stdout+stderr share the signed output cap (1–256 KiB), stderr is never persisted. `parameters` are typed and non-secret. Example Python:

```python
import json, sys
params = json.load(sys.stdin)
print(json.dumps({"value": params["n"] * 2}))
```

Bash can use `read -r input` and emit a JSON object; PowerShell can use `[Console]::In.ReadToEnd() | ConvertFrom-Json | ConvertTo-Json -Compress`. Local PowerShell is now allowed in newly created signed manifests. Existing remote templates remain compatible if unchanged.

A run pins version, Workspace, target configuration revision, asset, credential ID/revision, purpose, actor and session. Signature, trust key, approval, archive state, RBAC, session and scope are checked at admission, before execution and before commit; local execution also checks periodically. Credential consumption checks the expected version and script guard in the grant transaction before decryption. No passwords, TOTP, plaintext credentials, stderr or session IDs in execution results. History is restricted to the initiating actor with scripts.execute and Workspace manager/owner rights. Signed scripts are trusted operator code; output may still contain sensitive values deliberately supplied by its author. Do not include secrets in source/parameters; arbitrary result filtering is not a guarantee of redaction.

Jobs expire before start after 120s, maximum 10 pending/running per Workspace, one worker globally per database. Once claimed, a run is never automatically retried, including worker crash or uncertain outcome. On next worker start, abandoned running jobs become `failed` (`script_worker_lost`), expired jobs `script_expired`. Cancellation fences commit; remote inspection can update health/audit, not Inventory. A repeated identical key returns the existing job; a different payload under same key is rejected. Explicit new submission needs fresh MFA. No scheduled scripts/service delegation yet (0.5.5); history retention is not automated yet. The signed timeout is applied to local subprocess and remote guard/alarm; service wall-clock cap remains the final boundary for stuck remote DNS/kernel I/O.

## Verification

```sh
php scripts/verify-release.php
php tests/script-execution-smoke.php
# On disposable PostgreSQL 17+ using the existing CAJEER_PG_TEST variables, non-root:
php tests/script-execution-postgres-integration.php
```

Real sandbox test must run in a dedicated bounded non-root cgroup with provisioned rootfs and all three interpreters. `CAJEER_SCRIPT_SANDBOX_TEST=1` plus the two script environment variables are required. Example on a staging systemd host:

```sh
sudo systemd-run --wait --pipe --collect --uid=cajeer-script --gid=cajeer-script \
  -p MemoryMax=768M -p MemorySwapMax=0 -p TasksMax=96 -p CPUQuota=100% -p RuntimeMaxSec=180 \
  -p NoNewPrivileges=yes -p KillMode=control-group \
  --setenv=CAJEER_SCRIPT_SANDBOX_TEST=1 --setenv=SCRIPT_EXECUTION_ENABLED=true \
  --setenv=SCRIPT_SANDBOX_ROOTFS=/opt/cajeer-script-root \
  /usr/bin/php /opt/cajeer/tests/script-sandbox-integration.php
```

`verify-release.php --strict` requires the native sandbox and PostgreSQL execution tests in addition to previous full gates. `script-sandbox.yml` provisions a dedicated root and tests Bash/Python/PowerShell, network and host filesystem isolation, environment, timeout and cancellation. The PostgreSQL test uses a deterministic Proxmox response fixture through the actual driver, Vault, signed registry and worker; it is not an appliance test. Local smoke verifies production subprocess I/O with real Bash/Python but does not prove namespace isolation. Do not enable execution in production until native sandbox and PostgreSQL tests pass on the intended deployment, along with the existing strict gate.
