<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Inventory\InventoryReadiness;
use Cajeer\Servers\Inventory\CmdbIntegrity;
use Cajeer\Servers\Kernel\Application;

/** Manual inventory upgrade inspection; no HTTP route or secret-bearing export. */
final class InventoryCommands
{
    public function __construct(private readonly Application $app) {}

    /** @param list<string> $argv */
    public function run(string $command, array $argv): int
    {
        $args = array_slice($argv, 2);
        $allowed = match ($command) {
            'inventory:preflight' => ['--json', '--strict'],
            'inventory:integrity' => ['--json', '--deep'],
            default => ['--apply', '--json'],
        };
        if (count(array_diff($args, $allowed)) || count($args) !== count(array_unique($args))) {
            fwrite(STDERR, "Invalid options. inventory:preflight [--json] [--strict]; inventory:constraints [--apply] [--json]; inventory:integrity [--json] [--deep]\n");
            return 2;
        }
        if ($command === 'inventory:integrity') {
            $report = $this->app->container()->get(CmdbIntegrity::class)->inspect(in_array('--deep', $args, true));
            if (in_array('--json', $args, true)) {
                fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            } else {
                fwrite(STDOUT, $report['ok'] ? "CMDB INTEGRITY: OK\n" : "CMDB INTEGRITY: FAILED\n");
                foreach ($report['checks'] as $check) {
                    fwrite(STDOUT, sprintf("[%s] %s (%d)\n", $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['count']));
                }
            }
            return $report['ok'] ? 0 : 1;
        }
        $service = $this->app->container()->get(InventoryReadiness::class);
        if ($command === 'inventory:constraints' && !in_array('--apply', $args, true)) {
            $report = $service->inspect(true);
            $report['note'] = 'Read-only report. Pass --apply to validate existing PostgreSQL constraints.';
        } elseif ($command === 'inventory:constraints') {
            $result = $service->validateConstraints();
            $report = $service->inspect(true);
            $report['validated_now'] = $result['validated'];
        } else {
            $report = $service->inspect(in_array('--strict', $args, true));
        }
        if (in_array('--json', $args, true)) {
            fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            fwrite(STDOUT, sprintf("Inventory: %d rows; %s\n", $report['rows'], $report['ok'] ? 'READY' : 'BLOCKED'));
            foreach ($report['checks'] as $check) {
                fwrite(STDOUT, sprintf("[%s] %s%s\n", $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['count'] ? ' (' . $check['count'] . ')' : ''));
            }
            if (isset($report['validated_now'])) fwrite(STDOUT, 'Validated: ' . count($report['validated_now']) . "\n");
        }
        return $report['ok'] ? 0 : 1;
    }
}
