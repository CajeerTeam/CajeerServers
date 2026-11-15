<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Inventory\InventoryReadiness;
use Cajeer\Servers\Inventory\CmdbIntegrity;
use Cajeer\Servers\Inventory\CmdbRepair;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Update\Maintenance;
use Cajeer\Servers\Kernel\Application;

/** Manual inventory upgrade inspection; no HTTP route or secret-bearing export. */
final class InventoryCommands
{
    public function __construct(private readonly Application $app) {}

    /** @param list<string> $argv */
    public function run(string $command, array $argv): int
    {
        $args = array_slice($argv, 2);
        if ($command==='inventory:repair') return $this->repair($args);
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

    private function repair(array $args):int
    {
        $workspace=null;$confirmation=null;$apply=false;
        foreach($args as $arg){
            if(str_starts_with($arg,'--workspace=') && $workspace===null)$workspace=substr($arg,12);
            elseif(str_starts_with($arg,'--confirm=') && $confirmation===null)$confirmation=substr($arg,10);
            elseif($arg==='--apply' && !$apply)$apply=true;
            else throw new \RuntimeException('Invalid or duplicate repair option.');
        }
        if($workspace===null || ($apply!==($confirmation!==null)))throw new \RuntimeException('Usage: inventory:repair --workspace=UUID [--apply --confirm=SHA256]');
        $maintenance=new Maintenance($this->app->basePath());
        return $maintenance->exclusive(function()use($maintenance,$workspace,$confirmation,$apply):int{
            if(!$maintenance->active() || $maintenance->requiresUpgrade())throw new \RuntimeException('Repair requires maintenance on the fully upgraded installation.');
            $journal=$maintenance->journal();
            if($journal!==null && $journal['phase']!=='complete')throw new \RuntimeException('Finish the upgrade before repairing data.');
            $container=$this->app->container();
            $service=new CmdbRepair($container->get(DatabaseManager::class),$container->get(AuditLogger::class));
            $report=$apply?$service->apply($workspace,$confirmation):$service->preview($workspace);
            fwrite(STDOUT,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");
            return 0;
        },10);
    }

}
