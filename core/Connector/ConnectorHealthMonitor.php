<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use PDO;

/** Health and breaker state is scoped to the exact active binding; caller owns the workspace/target fence. */
final class ConnectorHealthMonitor
{
    public const ERRORS=['connector_auth_failed','connector_transport_failed','connector_unavailable','connector_protocol_error','connector_trust_failed','connector_dependency_missing','connector_rate_limited'];
    public static function outcome(?string $error,int $previous):array
    {
        if ($error===null) return ['status'=>'healthy','failures'=>0,'cooldown'=>0];
        if (!in_array($error,self::ERRORS,true)) throw new \InvalidArgumentException('Invalid health outcome.');
        $failures=min(1000000,max(0,$previous)+1);
        $auth=$error==='connector_auth_failed';
        $status=$auth?'auth_failed':(in_array($error,['connector_transport_failed','connector_unavailable'],true)&&$failures>=3?'unreachable':'degraded');
        return ['status'=>$status,'failures'=>$failures,'cooldown'=>$auth||$failures>=3?300:($error==='connector_rate_limited'?60:0)];
    }
    private static function query(PDO $pdo,string $sql,array $args):\PDOStatement { $q=$pdo->prepare($sql);$q->execute($args);return $q; }
    public static function isOpen(PDO $pdo,string $target):bool
    {
        $v=self::query($pdo,'SELECT 1 FROM connector_health_current WHERE target_id=:id AND circuit_open_until>clock_timestamp()',['id'=>$target])->fetchColumn();
        return $v!==false;
    }
    /** Within the same commit as successful run results; failures recorded only after live authorization is rechecked. */
    public static function record(PDO $pdo,array $target,int $elapsed,?string $error):void
    {
        $args=['id'=>$target['id'],'tv'=>$target['version'],'cred'=>$target['credential_id'],'cv'=>$target['credential_version']];
        $old=self::query($pdo,'SELECT consecutive_failures FROM connector_health_state WHERE target_id=:id AND target_version=:tv AND credential_id=:cred AND credential_version=:cv FOR UPDATE',$args)->fetchColumn();
        $state=self::outcome($error,(int)$old);$elapsed=max(0,min(300000,$elapsed));
        self::query($pdo,<<<'SQL'
INSERT INTO connector_health_state(target_id,target_version,credential_id,credential_version,status,consecutive_failures,last_checked_at,last_success_at,duration_ms,error_code,circuit_open_until)
VALUES(:id,:tv,:cred,:cv,:status,:failures,clock_timestamp(),CASE WHEN CAST(:success AS boolean) THEN clock_timestamp() END,:ms,:error,CASE WHEN CAST(:cooldown AS integer)>0 THEN clock_timestamp()+CAST(:seconds AS integer)*interval '1 second' END)
ON CONFLICT(target_id) DO UPDATE SET target_version=EXCLUDED.target_version,credential_id=EXCLUDED.credential_id,credential_version=EXCLUDED.credential_version,
 status=EXCLUDED.status,consecutive_failures=EXCLUDED.consecutive_failures,last_checked_at=EXCLUDED.last_checked_at,
 last_success_at=COALESCE(EXCLUDED.last_success_at,CASE WHEN connector_health_state.target_version=EXCLUDED.target_version AND connector_health_state.credential_id=EXCLUDED.credential_id AND connector_health_state.credential_version=EXCLUDED.credential_version THEN connector_health_state.last_success_at END),
 duration_ms=EXCLUDED.duration_ms,error_code=EXCLUDED.error_code,circuit_open_until=EXCLUDED.circuit_open_until
SQL,$args+['status'=>$state['status'],'failures'=>$state['failures'],'success'=>$error===null?'true':'false','ms'=>$elapsed,'error'=>$error,'cooldown'=>$state['cooldown'],'seconds'=>$state['cooldown']]);
        self::query($pdo,"INSERT INTO connector_health_metrics(provider,outcome,checks,duration_ms) VALUES(:provider,:outcome,1,:ms) ON CONFLICT(provider,outcome) DO UPDATE SET checks=connector_health_metrics.checks+1,duration_ms=connector_health_metrics.duration_ms+EXCLUDED.duration_ms",['provider'=>$target['provider'],'outcome'=>$error===null?'success':'failure','ms'=>$elapsed]);
    }
    /** Bounded labels only, no target IDs, workspace IDs, addresses or remote text. */
    public static function metrics(PDO $pdo):string
    {
        $out=['# HELP cajeer_connector_health_targets Current connector targets by health status.','# TYPE cajeer_connector_health_targets gauge',
            '# HELP cajeer_connector_circuit_open Open connector circuits by provider.','# TYPE cajeer_connector_circuit_open gauge',
            '# HELP cajeer_connector_health_checks_total Authorized remote operation outcomes.','# TYPE cajeer_connector_health_checks_total counter',
            '# HELP cajeer_connector_health_duration_seconds_total Total remote operation duration.','# TYPE cajeer_connector_health_duration_seconds_total counter'];
        foreach($pdo->query('SELECT provider,health_status,count(*) AS n FROM connector_health_current GROUP BY provider,health_status')->fetchAll(PDO::FETCH_ASSOC) as $row)
            $out[]='cajeer_connector_health_targets{provider="'.$row['provider'].'",status="'.$row['health_status'].'"} '.(int)$row['n'];
        foreach($pdo->query('SELECT provider,count(*) FILTER (WHERE circuit_open_until>clock_timestamp()) AS n FROM connector_health_current GROUP BY provider')->fetchAll(PDO::FETCH_ASSOC) as $row)
            $out[]='cajeer_connector_circuit_open{provider="'.$row['provider'].'"} '.(int)$row['n'];
        foreach($pdo->query('SELECT provider,outcome,checks,duration_ms FROM connector_health_metrics')->fetchAll(PDO::FETCH_ASSOC) as $row){
            $label='{provider="'.$row['provider'].'",outcome="'.$row['outcome'].'"}';
            $out[]='cajeer_connector_health_checks_total'.$label.' '.(int)$row['checks'];
            $out[]='cajeer_connector_health_duration_seconds_total'.$label.' '.sprintf('%.3F',(int)$row['duration_ms']/1000);
        }
        return implode("\n",$out)."\n";
    }
}
