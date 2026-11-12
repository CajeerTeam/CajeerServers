<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Cross-process, nonblocking PostgreSQL session locks; released on process/connection death. */
final class ConnectorConcurrency
{
    private static function lock(PDO $pdo,string $key,bool $unlock=false):bool
    {
        $q=$pdo->prepare(($unlock?'SELECT pg_advisory_unlock':'SELECT pg_try_advisory_lock').'(hashtextextended(:key,4606))');
        $q->execute(['key'=>$key]);return in_array($q->fetchColumn(),[true,'t','1',1],true);
    }
    public static function acquire(PDO $pdo,array $target):array
    {
        $held=[];
        try {
            // Stable order; try-locks never wait on another operation. Limits are global across PHP workers.
            foreach (['provider:'.$target['provider']=>8,'workspace:'.$target['workspace_id']=>4] as $prefix=>$limit) {
                $slot=null;
                for ($i=0;$i<$limit;$i++) if (self::lock($pdo,$prefix.':'.$i)) {$slot=$prefix.':'.$i;break;}
                if ($slot===null) throw new HttpException(409,'Connector concurrency limit reached.','connector_busy');
                $held[]=$slot;
            }
            return $held;
        } catch (\Throwable $e) {self::release($pdo,$held);throw $e;}
    }
    public static function release(PDO $pdo,array $held):void
    { foreach (array_reverse($held) as $key) self::lock($pdo,$key,true); }
}
