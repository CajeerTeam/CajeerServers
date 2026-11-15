<?php
declare(strict_types=1);
namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Update\UpgradeService;
use InvalidArgumentException;

final class UpgradeCommands
{
    public function __construct(private readonly Application $app) {}
    public function run(string $command,array $argv): int
    {
        $accepted=match($command) {
            'upgrade:apply'=>['previous','archive','key-file','offline'],
            'upgrade:restore'=>['key-file','target-db','confirm-target','storage-target','recovery-target','offline'],
            default=>[],
        };
        $args=[];
        foreach (array_slice($argv,2) as $arg) {
            if (!preg_match('/^--([a-z-]+)=(.+)$/D',$arg,$m) && $arg!=='--offline') throw new InvalidArgumentException('Invalid upgrade argument.');
            $name=$arg==='--offline'?'offline':$m[1];
            if (!in_array($name,$accepted,true) || isset($args[$name]) || ($name==='offline' && $arg!=='--offline')) throw new InvalidArgumentException('Repeated upgrade argument.');
            $args[$name]=$arg==='--offline'?true:$m[2];
        }
        $service=new UpgradeService($this->app);
        if ($command==='upgrade:apply' && count($args)!==4) throw new InvalidArgumentException('Required: --previous=PATH --archive=PATH --key-file=PATH --offline (all old processes stopped).');
        if ($command==='upgrade:restore' && count($args)!==6) throw new InvalidArgumentException('Required: --key-file=PATH --target-db=NAME --confirm-target=NAME --storage-target=PATH --recovery-target=PATH --offline');
        $result=match($command) {
            'maintenance:status'=>$service->status(),
            'maintenance:enable'=>$service->enable(),
            'maintenance:disable'=>$service->disable(),
            'upgrade:apply'=>$service->run($args['previous'],$args['archive'],$args['key-file']),
            'upgrade:restore'=>$service->restore($args['key-file'],$args['target-db'],$args['confirm-target'],$args['storage-target'],$args['recovery-target']),
            default=>throw new InvalidArgumentException('Unknown upgrade command.'),
        };
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        return 0;
    }
}
