<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Kernel\Application;

/** Operator preflight. No remote requests or secret decryption. Never emits configuration values. */
final class ConnectorReadiness
{
    public function __construct(private readonly Application $app) {}
    public function report():array
    {
        $checks=[];
        $probe=static function(string $name,callable $fn,string $detail)use(&$checks):void {
            try {$ok=$fn()===true;} catch(\Throwable) {$ok=false;}
            $checks[]=['name'=>$name,'ok'=>$ok,'detail'=>$detail];
        };
        $cfg=$this->app->config();
        $probe('connector_registry',function():bool {
            $r=$this->app->container()->get(ConnectorRegistry::class);
            foreach(['proxmox','vmmanager','ssh','winrm','snmp'] as $provider)
                foreach(ConnectorManifest::OPERATIONS as $operation) $r->require($provider,$operation);
            foreach(['inspect','health','validate_rotation'] as $operation)$r->require('prometheus',$operation);
            foreach(['proxmox','vmmanager','ssh','winrm','snmp','prometheus'] as $provider)if(!$r->supportsMetrics($provider))return false;
            return true;
        },'Inventory drivers and Prometheus metrics must be registered.');
        $probe('connector_networks',function()use($cfg):bool {
            $networks=$cfg->get('connectors.allowed_networks',[]);
            if(!is_array($networks)||$networks===[])return false;
            new EndpointPolicy($networks,null,true); return true;
        },'Configure a valid non-empty CONNECTOR_ALLOWED_NETWORKS.');
        $probe('connector_php',static fn():bool=>extension_loaded('openssl')&&extension_loaded('sodium')&&extension_loaded('pdo_pgsql')&&function_exists('pcntl_alarm'), 'CLI requires openssl, sodium, pdo_pgsql and pcntl.');
        $probe('connector_snmp',static fn():bool=>extension_loaded('snmp')&&class_exists(\SNMP::class), 'SNMP driver requires ext-snmp with Net-SNMP.');
        $probe('connector_ssh',fn():bool=>self::python((string)$cfg->get('connectors.python','/usr/bin/python3')), 'Configured Python must import Paramiko.');
        foreach(['prometheus_ca_file','proxmox_ca_file','vmmanager_ca_file','winrm_ca_file','ssh_known_hosts'] as $key) {
            $probe('connector_'.$key,static function()use($cfg,$key):bool {$p=$cfg->get('connectors.'.$key,'');return $p===''||(is_string($p)&&is_file($p)&&is_readable($p));},'Configured trust file must be readable.');
        }
        $probe('connector_db_integrity',function():bool {
            $pdo=$this->app->container()->get(DatabaseManager::class)->connection();
            $queries=[
                "SELECT count(*) FROM metric_series s JOIN connector_targets t ON t.id=s.target_id JOIN inventory_assets a ON a.id=s.asset_id WHERE s.workspace_id<>t.workspace_id OR s.workspace_id<>a.workspace_id",
                "SELECT count(*) FROM connector_runs r LEFT JOIN queue_jobs j ON j.id=r.id JOIN connector_service_grants g ON g.id=r.grant_id WHERE j.id IS NULL OR j.queue<>'connectors' OR j.type<>'connector.execute' OR j.payload<>'[]'::jsonb OR r.target_id<>g.target_id OR r.workspace_id<>g.workspace_id",
                "SELECT count(*) FROM queue_jobs j WHERE j.queue='connectors' AND NOT EXISTS(SELECT 1 FROM connector_runs r WHERE r.id=j.id)",
                'SELECT count(*) FROM connector_webhook_sources s JOIN connector_service_grants g ON g.id=s.grant_id WHERE s.target_id<>g.target_id OR s.workspace_id<>g.workspace_id',
                "SELECT count(*) FROM connector_runs r JOIN collection_plans p ON p.grant_id=r.grant_id WHERE r.collection_plan_id IS DISTINCT FROM p.id OR r.collector_name IS DISTINCT FROM p.collector_name",
                "SELECT count(*) FROM connector_runs r JOIN collection_plans p ON p.id=r.collection_plan_id WHERE r.grant_id<>p.grant_id",
                "SELECT count(*) FROM collection_plans p JOIN connector_service_grants g ON g.id=p.grant_id WHERE g.interval_seconds IS NOT NULL OR g.operation NOT IN ('inspect','sync')",
                'SELECT count(*) FROM connector_event_dispatches d JOIN connector_events e ON e.id=d.event_id JOIN connector_webhook_sources s ON s.id=e.source_id JOIN connector_runs r ON r.id=d.run_id WHERE r.target_id<>s.target_id OR r.workspace_id<>s.workspace_id',
            ];
            foreach($queries as $sql) if((string)$pdo->query($sql)->fetchColumn()!=='0')return false;
            return true;
        },'Connector run, queue, grant and event scopes must be consistent; migrations must be applied.');
        $probe('connector_proxmox_profiles',function()use($cfg):bool {
            $pdo=$this->app->container()->get(DatabaseManager::class)->connection();
            $q=$pdo->query("SELECT endpoint_host,endpoint_port,proxmox_profile,state FROM connector_targets WHERE provider='proxmox'");
            while($row=$q->fetch(\PDO::FETCH_ASSOC)){
                if($row['proxmox_profile']===null){if($row['state']==='active'&&$cfg->get('connectors.proxmox_require_profile',false)===true)return false;continue;}
                ProxmoxProfile::normalize(json_decode($row['proxmox_profile'],true,32,JSON_THROW_ON_ERROR),$row['endpoint_host'],(int)$row['endpoint_port']);
            }
            return true;
        },'Stored Proxmox profiles must be valid; mandatory profile mode requires trust for every active PVE target.');
        $probe('connector_ssh_trust',function()use($cfg):bool {
            $pdo=$this->app->container()->get(DatabaseManager::class)->connection();
            $n=$pdo->query("SELECT count(*) FROM connector_targets WHERE provider='ssh' AND state='active'")->fetchColumn();
            $p=$cfg->get('connectors.ssh_known_hosts','');return (string)$n==='0'||(is_string($p)&&is_file($p)&&is_readable($p));
        },'Active SSH targets require an operator-owned known_hosts file.');
        return ['ok'=>!in_array(false,array_column($checks,'ok'),true),'checks'=>$checks];
    }
    private static function python(string $binary):bool
    {
        if(!function_exists('proc_open')||$binary===''||!is_file($binary)||!is_executable($binary))return false;
        $p=@proc_open([$binary,'-I','-c','import paramiko'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($p))return false;
        $until=hrtime(true)+5000000000;
        do {$status=proc_get_status($p);if(!$status['running']){$exit=$status['exitcode'];proc_close($p);return $exit===0;}usleep(50000);}while(hrtime(true)<$until);
        proc_terminate($p,9);proc_close($p);return false;
    }
}
