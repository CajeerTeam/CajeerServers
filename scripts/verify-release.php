#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Executable release test runner. No fake pass from empty/missing tools. */
$root = dirname(__DIR__);
$strict = in_array('--strict', $argv, true);
$deployment = null;
$evidence = null;
foreach (array_slice($argv, 1) as $option) {
    if ($option === '--strict') continue;
    if ($strict && $evidence === null && str_starts_with($option, '--evidence=')) {
        $requested = substr($option, strlen('--evidence='));
        $parent = realpath(dirname($requested));
        if (!str_starts_with($requested, '/') || $parent === false || !is_dir($parent)
            || $parent === $root || str_starts_with($parent, $root.'/')
            || basename($requested) === '' || file_exists($requested) || is_link($requested)) {
            fwrite(STDERR, "FAIL: evidence must be a new file outside the source tree.\n"); exit(2);
        }
        $evidence = $parent.'/'.basename($requested);
        continue;
    }
    if ($strict && $deployment === null && str_starts_with($option, '--deployment-root=')) {
        $requested = substr($option, strlen('--deployment-root='));
        $deployment = str_starts_with($requested, '/') ? realpath($requested) : false;
        $id = getenv('CAJEER_CANDIDATE_ID') ?: '';
        if ($deployment === false || $deployment === $root || !is_file($deployment.'/bin/cajeer')
            || !preg_match('/^[a-f0-9]{64}$/D', $id)
            || !is_file($deployment.'/.release-candidate.json')
            || !hash_equals($id, (string)hash_file('sha256', $deployment.'/.release-candidate.json'))
            || trim((string)file_get_contents($deployment.'/VERSION')) !== trim((string)file_get_contents($root.'/VERSION'))) {
            fwrite(STDERR, "FAIL: separate deployment must match the trusted candidate and version.\n"); exit(2);
        }
        continue;
    }
    fwrite(STDERR, "Usage: php scripts/verify-release.php [--strict [--deployment-root=/absolute/path] [--evidence=/absolute/path]]\n"); exit(2);
}
require $root . '/core/autoload.php';
$failures = 0;
$steps = 0;
$evidenceSteps = [];
if ($evidence !== null && ($deployment === null || dirname($evidence) === $deployment || str_starts_with(dirname($evidence), $deployment.'/'))) {
    fwrite(STDERR, "FAIL: evidence requires a separate sealed deployment and an external output path.\n"); exit(2);
}
// GNU timeout supervises the entire process group, including subprocesses spawned by tests.
// Strict deployment checks must never wait indefinitely on a stalled tool or fixture.
$timeout = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout' : (is_executable('/bin/timeout') ? '/bin/timeout' : null);
if ($strict && $timeout === null) { fwrite(STDERR, "FAIL: strict verification requires GNU timeout.\n"); exit(2); }
$run = static function (string $name, array $command, string $cwd, ?array $environment = null, int $seconds = 300) use (&$failures, &$steps, &$evidenceSteps, $timeout): void {
    $steps++;
    $started = hrtime(true);
    fwrite(STDOUT, "== $name ==\n");
    if (!function_exists('proc_open')) { fwrite(STDERR, "FAIL: proc_open unavailable\n"); $failures++; return; }
    $pipes = [];
    if ($timeout !== null) $command = [$timeout, '--signal=TERM', '--kill-after=10s', $seconds . 's', ...$command];
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    if (!is_resource($process)) { fwrite(STDERR, "FAIL: cannot start $name\n"); $failures++; return; }
    stream_copy_to_stream($pipes[1], STDOUT); fclose($pipes[1]);
    $status = proc_close($process);
    $evidenceSteps[] = ['name'=>$name, 'exit'=>$status, 'elapsed_ms'=>(int)((hrtime(true)-$started)/1000000)];
    if ($status !== 0) { $reason = in_array($status,[124,137],true) ? "timeout or killed (limit {$seconds}s)" : "exit $status"; fwrite(STDERR, "FAIL: $name ($reason)\n"); $failures++; }
};

$phpFiles = [];
foreach (['core', 'app', 'api', 'bin', 'public', 'tests', 'installer', 'scripts', 'routes', 'components', 'packages', 'examples'] as $folder) {
    if (!is_dir($root . '/' . $folder)) continue;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') $phpFiles[] = $file->getPathname();
    }
}
sort($phpFiles);
$lintErrors = 0;
$lintStarted = hrtime(true);
foreach ($phpFiles as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root);
    if (!is_resource($process)) { $lintErrors++; break; }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { $lintErrors++; fwrite(STDERR, "$file: $out $err\n"); }
}
$steps++;
$evidenceSteps[] = ['name'=>'PHP lint', 'exit'=>($lintErrors > 0 || $phpFiles === []) ? 1 : 0, 'elapsed_ms'=>(int)((hrtime(true)-$lintStarted)/1000000)];
fwrite(STDOUT, sprintf("PHP lint: %d files, %d failures\n", count($phpFiles), $lintErrors));
if ($lintErrors > 0 || $phpFiles === []) $failures++;

foreach (['runtime', 'http', 'database', 'installer', 'upgrade', 'auth', 'security', 'security-hardening', 'infrastructure', 'runtime-reliability', 'performance', 'observability', 'admin', 'release', 'inventory-readiness', 'workspaces', 'inventory', 'ipam', 'topology', 'network-discovery', 'system-events', 'catalog', 'history', 'transfer', 'cmdb-integrity', 'data-integrity', 'import-dispatch', 'vault-crypto', 'vault-crud', 'vault-profiles', 'vault-access', 'vault-rotation', 'vault-bindings', 'vault-stable', 'proxmox-runtime', 'proxmox-profile', 'proxmox-cluster', 'proxmox-guests', 'proxmox-storage-network', 'proxmox-tasks', 'connector-runtime', 'connector-execution', 'connector-reconciliation', 'vmmanager-runtime', 'host-runtime', 'os-network-profile', 'linux-discovery', 'windows-discovery', 'snmp-health', 'snmp-discovery', 'connector-events', 'connector-actions', 'proxmox-snapshots', 'pbs-backup', 'connector-stability', 'collector-runtime', 'script-registry', 'script-execution', 'script-output', 'collection-plans', 'metrics', 'ingestion', 'data-exchange', 'collection-stability', 'network-configuration-backup', 'backup', 'production-operations', 'storage-isolation'] as $suite) {
    $run($suite . ' smoke', [PHP_BINARY, 'tests/' . $suite . '-smoke.php'], $root);
}
$run('Sealed candidate regressions', ['python3','tests/candidate-release-test.py'], $root);
$run('Stable distribution signature and installation regressions', ['python3','tests/release-distribution-test.py'], $root);
$run('Portable production output regressions', ['python3','tests/normalize-release-output-test.py'], $root);
$run('Dependency lock validation regressions', ['node','--test','tests/dependency-locks.test.mjs'], $root);
$run('Standalone Admin build smoke harness regressions', ['node','--test','tests/admin-build-smoke.test.mjs'], $root);
$run('Python lock audit regressions', ['python3','tests/python-lock-audit-test.py'], $root);
$run('Admin frontend static', ['node', 'tests/static-check.mjs'], $root . '/admin');
$run('Inventory navigation contract', ['node', '--experimental-strip-types', 'tests/navigation-contract.mjs'], $root . '/admin');
$run('Vault Admin UI contract', ['node', '--experimental-strip-types', 'tests/vault-ui-contract.mjs'], $root . '/admin');
$run('Connector lifecycle UI policy', ['node', '--experimental-strip-types', 'tests/connector-lifecycle-contract.mjs'], $root . '/admin');

$run('Browser staging preflight regression', ['node','--test','tests/e2e/preflight.test.mjs'], $root . '/admin');
$run('Platform real HTTP admission smoke', ['python3','tests/platform-http-e2e.py','--smoke'], $root);
$run('Browser release report policy', ['node','tests/e2e/report-contract.mjs'], $root . '/admin');
$run('Data collection navigation and UI policy', ['node','--experimental-strip-types','tests/collection-navigation-contract.mjs'], $root . '/admin');

$run('Proxmox Admin navigation policy', ['node','--experimental-strip-types','tests/proxmox-navigation-contract.mjs'], $root . '/admin');

$run('VMmanager navigation and dashboard policy', ['node','--experimental-strip-types','tests/vmmanager-navigation-contract.mjs'], $root . '/admin');
$run('OS / Network navigation and dashboard policy', ['node','--experimental-strip-types','tests/os-network-navigation-contract.mjs'], $root . '/admin');
$run('OS / Network staging TLS fixture', ['python3','tests/os-network-admin-fixture-integration.py'], $root);
$run('VMmanager admin fixture HTTPS integration', ['python3','tests/vmmanager-admin-fixture-integration.py'], $root);
$run('VMmanager HTTPS integration', ['python3','tests/vmmanager-https-integration.py'], $root);
$run('VMmanager power HTTPS integration', ['python3','tests/vmmanager-power-https-integration.py'], $root);

$run('RouterOS REST HTTPS integration', ['python3','tests/routeros-https-integration.py'], $root);
$run('Syslog socket integration', ['python3','tests/syslog-receiver-integration.py'], $root);
$run('Linux event probe integration', ['python3','tests/linux-events-integration.py'], $root);
$run('Linux discovery probe integration', ['python3','tests/linux-discovery-integration.py'], $root);
$run('Linux host inventory probe regression', ['python3','tests/linux-collect-regression.py'], $root);
$run('Host transport stream regression', ['python3','tests/host-transport-stream-regression.py'], $root);
$run('WinRM HTTPS protocol and trust integration', ['python3','tests/host-transport-integration.py'], $root);
$run('OS / Network live acceptance fixture', ['python3','tests/os-network-live-acceptance-integration.py'], $root, null, 240);
$run('VMmanager live acceptance fixture', ['python3','tests/vmmanager-live-acceptance-integration.py'], $root);

if ($strict) {
    $run('Script sandbox integration', [PHP_BINARY,'tests/script-sandbox-integration.php'], $root);
    $run('Connector stability HTTPS integration', ['python3','tests/connector-stability-https-integration.py'], $root);
    $run('Data export HTTPS integration', ['python3','tests/data-export-https-integration.py'], $root);
    $run('Ingestion HTTPS integration', ['python3','tests/ingestion-https-integration.py'], $root);
    $run('Metrics HTTPS integration', ['python3','tests/metrics-https-integration.py'], $root);
    $run('Proxmox profile HTTPS integration', ['python3','tests/proxmox-profile-https-integration.py'], $root);
    $run('Proxmox HTTPS integration', ['python3','tests/connector-https-integration.py'], $root);
    $run('Proxmox Admin staging fixture HTTPS integration', ['python3','tests/proxmox-admin-fixture-integration.py'], $root);
    $acceptance = getenv('CAJEER_PROXMOX_ACCEPTANCE_CONFIG');
    if (!$acceptance) { fwrite(STDERR, "FAIL: strict verification requires CAJEER_PROXMOX_ACCEPTANCE_CONFIG for operator-owned PVE/PBS acceptance.\n"); $failures++; }
    else { $run('Proxmox live acceptance', [PHP_BINARY,'scripts/verify-proxmox-live.php',$acceptance], $root, null, 600); }
    $vmmanagerAcceptance=getenv('CAJEER_VMMANAGER_ACCEPTANCE_CONFIG');
    if (!$vmmanagerAcceptance) { fwrite(STDERR, "FAIL: strict verification requires CAJEER_VMMANAGER_ACCEPTANCE_CONFIG for operator-owned VMmanager acceptance.\n"); $failures++; }
    else { $run('VMmanager live acceptance', [PHP_BINARY,'scripts/verify-vmmanager-live.php',$vmmanagerAcceptance], $root, null, 900); }
    $osNetworkAcceptance=getenv('CAJEER_OS_NETWORK_ACCEPTANCE_CONFIG');
    if (!$osNetworkAcceptance) { fwrite(STDERR, "FAIL: strict verification requires CAJEER_OS_NETWORK_ACCEPTANCE_CONFIG for operator-owned SSH/WinRM/SNMP/RouterOS acceptance.\n"); $failures++; }
    else { $run('OS / Network live acceptance', [PHP_BINARY,'scripts/verify-os-network-live.php',$osNetworkAcceptance], $root, null, 3600); }
    $run('RouterOS PostgreSQL integration', ['bash','scripts/acceptance-postgres.sh','python3','tests/routeros-https-integration.py','--postgres'], $root);
    $run('PBS HTTPS integration', ['python3','tests/pbs-backup-https-integration.py'], $root);
    $run('Proxmox snapshot HTTPS integration', ['python3','tests/proxmox-snapshots-https-integration.py'], $root);
    $run('Connector actions HTTPS integration', ['python3','tests/connector-actions-https-integration.py'], $root);
    $run('TypeScript SDK integration', ['bash','tests/connector-sdk-integration.sh'], $root);
    $run('SNMP protocol integration', ['bash','tests/snmp-protocol-integration.sh'], $root);
    $run('SSH and WinRM protocol integration', ['python3','tests/host-transport-integration.py','--ssh'], $root);
    $run('VMmanager HTTPS + PostgreSQL integration', ['bash','scripts/acceptance-postgres.sh','python3','tests/vmmanager-https-integration.py','--postgres'], $root);
    $run('VMmanager power PostgreSQL integration', ['bash','scripts/acceptance-postgres.sh','python3','tests/vmmanager-power-https-integration.py','--postgres'], $root);
    if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
        fwrite(STDERR, "FAIL: strict verification requires CAJEER_PG_TEST=1 and an isolated cajeer_test_* database.\n"); $failures++;
    } else {
        $run('SNMP discovery PostgreSQL / native SNMP integration', ['bash','scripts/acceptance-postgres.sh','bash','tests/snmp-protocol-integration.sh','--postgres'], $root, null, 600);
        $run('Windows and Linux discovery PostgreSQL / WinRM and SSH integration', ['bash','scripts/acceptance-postgres.sh','python3','tests/host-transport-integration.py','--ssh','--postgres'], $root, null, 600);
        $run('PBS PostgreSQL / HTTPS integration', ['bash','scripts/acceptance-postgres.sh','python3','tests/pbs-backup-https-integration.py','--postgres'], $root);
        foreach (['database','auth','security','infrastructure','admin','inventory','workspaces','inventory-crud','ipam','topology','catalog','history','transfer','cmdb','vault','vault-crud','vault-access','vault-rotation','vault-bindings','proxmox-profile','connector-reconciliation','host-hardware','connector-health','connector-events','connector-actions','script-registry','script-execution','collection-plans','metrics','ingestion','backup'] as $name) {
            $run("PostgreSQL $name", ['bash','scripts/acceptance-postgres.sh',PHP_BINARY, 'tests/' . $name . '-postgres-integration.php'], $root);
        }
        // Strict acceptance must exercise the declared capacity profile, not the tiny CI fixture.
        $run('PostgreSQL capacity acceptance', ['bash','scripts/acceptance-postgres.sh',PHP_BINARY, 'scripts/benchmark-postgres.php', '--assets=100000', '--targets=1000', '--samples=1000000', '--events=100000', '--clients=50', '--iterations=100', '--p95-ms=500'], $root, null, 3600);
        $run('PostgreSQL install / upgrade / recovery', [PHP_BINARY, 'tests/upgrade-postgres-integration.php'], $root, null, 600);
        $run('Full platform HTTP / PostgreSQL / TLS / DR journey', ['python3','tests/platform-http-e2e.py'], $root, null, 900);
        $installerDb = getenv('CAJEER_INSTALL_PG_TEST_DATABASE') ?: '';
        if (!preg_match('/^cajeer_test_[a-z0-9_]+_test$/D', $installerDb) || $installerDb === getenv('CAJEER_PG_TEST_DATABASE')) {
            fwrite(STDERR, "FAIL: installer integration requires a separate EMPTY CAJEER_INSTALL_PG_TEST_DATABASE=cajeer_test_*_test.\n");
            $failures++;
        } else {
            $testEnv = getenv();
            $testEnv['CAJEER_PG_TEST_DATABASE'] = $installerDb;
            $testEnv['CAJEER_INSTALL_PG_TEST'] = '1';
            $run('PostgreSQL installer', [PHP_BINARY, 'tests/installer-postgres-integration.php'], $root, $testEnv);
        }
    }
    if (getenv('CAJEER_REDIS_TEST') !== '1') {
        fwrite(STDERR, "FAIL: strict verification requires real Redis integration (CAJEER_REDIS_TEST=1).\n"); $failures++;
    } else {
        $run('Redis integration', [PHP_BINARY, 'tests/infrastructure-redis-integration.php'], $root);
    }
    $run('Composer lock consistency', ['composer','validate','--strict'], $root);
    $run('PHPUnit', [$root . '/vendor/bin/phpunit', '--configuration', 'phpunit.xml.dist'], $root);
    $run('Nuxt typecheck', ['npm', 'run', 'typecheck'], $root . '/admin', null, 900);
    if ($deployment === null) {
        $run('Nuxt build', ['npm', 'run', 'build'], $root . '/admin', null, 1800);
        $run('Production release gate', [PHP_BINARY, 'bin/cajeer', 'release:check', '--strict'], $root);
    } else {
        // Use the real deployment's private .env, not the build/test process credentials.
        $runtimeEnv = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp', 'LANG' => 'C.UTF-8'];
        $run('Sealed deployment release gate', [PHP_BINARY, 'bin/cajeer', 'release:check', '--strict'], $deployment, $runtimeEnv, 600);
        $run('Sealed Nuxt SSR bundle', ['node', 'scripts/smoke-admin-build.mjs', $deployment.'/admin/.output'], $root, null, 120);
    }
    if (!getenv('CAJEER_SMOKE_URL')) {
        fwrite(STDERR, "FAIL: strict verification requires CAJEER_SMOKE_URL to test a real HTTP deployment.\n"); $failures++;
    } else {
        $run('Real deployed HTTP', [PHP_BINARY, 'scripts/smoke-http.php'], $root);
    }
    if (!getenv('E2E_ADMIN_EMAIL') || !getenv('E2E_ADMIN_PASSWORD') || !getenv('E2E_BASE_URL') || getenv('E2E_ALLOW_WRITES') !== '1' || getenv('E2E_ISOLATED_STAGING') !== '1' || getenv('E2E_CONNECTOR_UI') !== '1' || !getenv('E2E_ADMIN_TOTP_SECRET') || !getenv('E2E_CONNECTOR_FIXTURE_HOST')) {
        fwrite(STDERR, "FAIL: strict verification requires disposable E2E staging, credentials, E2E_ALLOW_WRITES=1, E2E_ISOLATED_STAGING=1, E2E_CONNECTOR_UI=1, E2E_ADMIN_TOTP_SECRET and fixture host.\n"); $failures++;
    } else {
        $run('Playwright E2E preflight', ['npm', 'run', 'test:e2e:preflight'], $root . '/admin');
        $run('Playwright E2E (all suites must pass)', ['bash','scripts/verify-browser-e2e.sh'], $root, null, 5400);
    }
}
fwrite(STDOUT, "Release verification: $steps steps; failures: $failures" . ($strict ? ' (strict)' : ' (native)') . "\n");
if ($failures === 0 && $evidence !== null) {
    if (count($evidenceSteps) !== $steps) { fwrite(STDERR, "FAIL: missing step evidence.\n"); exit(1); }
    $payload = json_encode(['format'=>1, 'version'=>trim((string)file_get_contents($root.'/VERSION')),
        'candidate_id'=>getenv('CAJEER_CANDIDATE_ID'), 'steps'=>$evidenceSteps], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    // Candidate orchestrator provides a private temporary directory. Do not overwrite evidence.
    $oldMask = umask(0077);
    $handle = @fopen($evidence, 'xb');
    umask($oldMask);
    if ($handle === false) { fwrite(STDERR, "FAIL: cannot create strict evidence.\n"); exit(1); }
    $ok = fwrite($handle, $payload) === strlen($payload) && fflush($handle) && fsync($handle);
    fclose($handle);
    if (!$ok) { @unlink($evidence); fwrite(STDERR, "FAIL: cannot persist strict evidence.\n"); exit(1); }
}
exit($failures === 0 ? 0 : 1);
