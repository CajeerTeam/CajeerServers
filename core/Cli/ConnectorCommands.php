<?php
declare(strict_types=1);
namespace Cajeer\Servers\Cli;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Connector\ConnectorExecutionService;

/** Trusted local operator commands. Grants can only be issued through authenticated MFA HTTP. */
final class ConnectorCommands
{
    public function __construct(private readonly Application $app) {}
    public function run(string $command,array $argv):int
    {
        $args=array_slice($argv,2);
        if ($command==='connector:doctor') {
            if ($args!==[] && $args!==['--json']) throw new \InvalidArgumentException('Usage: connector:doctor [--json]');
            $report=(new \Cajeer\Servers\Connector\ConnectorReadiness($this->app))->report();
            fwrite(STDOUT,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");
            return $report['ok']?0:1;
        }
        $service=$this->app->container()->get(ConnectorExecutionService::class);
        $required=static function(int $n)use($args):void {if (count($args)!==$n) throw new \InvalidArgumentException('Invalid connector command arguments. See help.');};
        switch ($command) {
            case 'collection:plans': $required(2); $result=$service->plans(...$args); break;
            case 'collection:plan-run': $required(4); $result=$service->runPlan(...$args); break;
            case 'collection:plan-redrive': $required(5); $result=$service->runPlan(...$args); break;
            case 'collection:plan-pause': $required(3); $result=$service->setPlanEnabled(...[...$args,false]); break;
            case 'collection:plan-resume': $required(3); $result=$service->setPlanEnabled(...[...$args,true]); break;
            case 'collection:list': $required(0); $result=$this->app->container()->get(\Cajeer\Servers\Collector\CollectionRuntimeService::class)->catalog(); break;
            case 'collection:run': $required(5); $result=$service->enqueue(...$args); break;
            case 'collection:status': $required(3); $result=$service->status(...$args); if ($result['collector_name']===null) throw new \RuntimeException('Run is not a named collection.'); break;
            case 'connector:run': $required(4); $result=$service->enqueue(...$args); break;
            case 'connector:status': $required(3); $result=$service->status(...$args); break;
            case 'connector:cancel': $required(3); $result=$service->cancel(...$args); break;
            case 'connector:sync':
            case 'connector:tick':
                $required(0);
                if ($this->app->config()->get('scheduler.enabled',true)!==true) throw new \RuntimeException('Scheduler disabled.');
                $result=['connector_ticks'=>$service->tick()]; break;
            case 'connector:work':
                if (count($args)>1 || (isset($args[0]) && !preg_match('/^[1-9][0-9]{0,3}$/D',$args[0]))) throw new \InvalidArgumentException('Usage: connector:work [max-jobs]');
                // A hung DNS/transport/DB call must not survive the queue lease. Process death permits lease recovery.
                if (!function_exists('pcntl_alarm')) throw new \RuntimeException('Connector worker requires CLI pcntl.');
                pcntl_signal(SIGALRM,SIG_DFL);
                $jobs=[];
                for ($i=0;$i<(int)($args[0]??1);$i++) {
                    pcntl_alarm(240);
                    try {$job=$service->once();} finally {pcntl_alarm(0);}
                    if ($job===null) break; $jobs[]=$job;
                }
                $result=['jobs'=>$jobs]; break;
            default: throw new \InvalidArgumentException('Unknown connector command.');
        }
        fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        return 0;
    }
}
