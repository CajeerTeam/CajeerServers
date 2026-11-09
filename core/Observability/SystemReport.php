<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;

/** Safe machine-readable report; no env values, filesystem paths, hosts, DSNs or credentials. */
final class SystemReport
{
    public function __construct(private readonly Application $app, private readonly ConfigRepository $config,
        private readonly RuntimeDiagnostics $runtime, private readonly Diagnostics $diagnostics) {}

    public function generate(bool $deep = false): array
    {
        $runtime = $this->runtime->run();
        $dependencies = $this->diagnostics->run($deep);
        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'application' => ['name' => 'CajeerServers', 'version' => $this->app->version(),
                'environment' => $this->config->get('app.env', 'production')],
            'runtime' => ['ready' => $runtime['ok'], 'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI, 'memory_usage_bytes' => memory_get_usage(true),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'checks' => array_map(static fn (array $item): array => ['name' => $item['name'], 'ok' => $item['ok']], $runtime['checks'])],
            'dependencies' => $dependencies,
        ];
    }
}
