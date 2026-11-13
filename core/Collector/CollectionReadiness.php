<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Database\{DatabaseManager,MigrationRunner};
use Cajeer\Servers\ImportExport\ExporterRegistry;
use Cajeer\Servers\Kernel\Application;
use PDO;

/** Read-only operator diagnostics. No remote requests, secrets, script execution or repair. */
final class CollectionReadiness
{
    public function __construct(private readonly Application $app){}

    public function report():array
    {
        $checks=[];
        $probe=static function(string $name,callable $check,string $detail)use(&$checks):void{
            try{$ok=$check()===true;}catch(\Throwable){$ok=false;}
            $checks[]=['name'=>$name,'ok'=>$ok,'detail'=>$ok?'ok':$detail];
        };
        $c=$this->app->container();
        $probe('collection_registry',function()use($c):bool{
            $rows=array_column($c->get(CollectionRuntimeService::class)->catalog(),null,'name');
            foreach(['proxmox','vmmanager','ssh','winrm','snmp','prometheus'] as $provider){
                if(($rows[$provider.'.metrics']['operations']??null)!==['inspect'])return false;
                if($provider!=='prometheus'&&!in_array('sync',$rows[$provider.'.inventory']['operations']??[],true))return false;
            }
            if(($rows['proxmox.cluster']['operations']??null)!==['inspect','sync']||($rows['proxmox.guests']['operations']??null)!==['inspect','sync']||($rows['proxmox.storage-network']['operations']??null)!==['inspect','sync'])return false;
            $r=$c->get(ExporterRegistry::class);
            foreach(['json','csv','openmetrics'] as $f)$r->require($f,'metrics');
            return true;
        },'Required collectors and exporters must be registered.');
        $probe('collection_migrations',function()use($c):bool{
            $v=$c->get(MigrationRunner::class)->validate();return $v['valid']&&count($v['rows'])>=40&&array_column($v['rows'],'status')!==[]&&!array_diff(array_column($v['rows'],'status'),['applied']);
        },'All PostgreSQL migrations, including 0040, must be applied and checksum-valid.');
        $queries=[
            'proxmox_storage_network_scope'=>"SELECT 1 FROM proxmox_storage_network_state s JOIN connector_targets t ON t.id=s.target_id WHERE t.provider<>'proxmox' OR s.workspace_id<>t.workspace_id OR s.target_version>t.version LIMIT 1",
            'proxmox_cluster_scope'=>"SELECT 1 FROM proxmox_cluster_state s JOIN connector_targets t ON t.id=s.target_id WHERE t.provider<>'proxmox' OR s.workspace_id<>t.workspace_id OR s.target_version>t.version LIMIT 1",
            'proxmox_guest_scope'=>"SELECT 1 FROM proxmox_guest_state s JOIN connector_targets t ON t.id=s.target_id WHERE t.provider<>'proxmox' OR s.workspace_id<>t.workspace_id OR s.target_version>t.version LIMIT 1",
            'proxmox_guest_identity_scope'=>"SELECT 1 FROM proxmox_guest_identity s JOIN connector_targets t ON t.id=s.target_id WHERE t.provider<>'proxmox' OR s.workspace_id<>t.workspace_id LIMIT 1",
            'collection_plan_scope'=>"SELECT 1 FROM collection_plans p JOIN connector_service_grants g ON g.id=p.grant_id WHERE g.interval_seconds IS NOT NULL OR g.operation NOT IN ('inspect','sync') UNION ALL SELECT 1 FROM connector_runs r JOIN collection_plans p ON p.grant_id=r.grant_id WHERE r.collection_plan_id IS DISTINCT FROM p.id OR r.collector_name IS DISTINCT FROM p.collector_name LIMIT 1",
            'collection_redrive_scope'=>"SELECT 1 FROM connector_runs r JOIN connector_runs old ON old.id=r.redrive_of WHERE r.target_id<>old.target_id OR r.workspace_id<>old.workspace_id OR r.collection_plan_id IS DISTINCT FROM old.collection_plan_id LIMIT 1",
            'collection_ingestion_scope'=>"SELECT 1 FROM data_ingestion_sources s JOIN connector_service_grants g ON g.id=s.grant_id WHERE s.workspace_id<>g.workspace_id OR s.target_id<>g.target_id OR s.actor_id<>g.actor_id OR g.operation<>'inspect' OR g.interval_seconds IS NOT NULL OR EXISTS(SELECT 1 FROM collection_plans p WHERE p.grant_id=g.id) LIMIT 1",
            'collection_ingestion_kind'=>"SELECT 1 FROM data_ingestion_inbox b JOIN data_ingestion_sources s ON s.id=b.source_id WHERE b.kind<>s.kind UNION ALL SELECT 1 FROM data_events e JOIN data_ingestion_sources s ON s.id=e.source_id WHERE s.kind<>'events' UNION ALL SELECT 1 FROM metric_series m JOIN data_ingestion_sources s ON s.id=m.ingestion_source_id WHERE s.kind<>'metrics' OR m.labels->>'cajeer_source' IS DISTINCT FROM s.id::text LIMIT 1",
            'collection_ingestion_attempts'=>"SELECT 1 FROM data_ingestion_inbox b WHERE b.attempts<>(SELECT COALESCE(max(a.attempt),0) FROM data_ingestion_attempts a WHERE a.batch_id=b.id AND a.dispatch=b.dispatch_count) LIMIT 1",
            'collection_script_trust'=>"SELECT 1 FROM script_versions v JOIN script_signing_keys k ON k.id=v.key_id WHERE v.state IN ('approved','pending_review') AND k.revoked_at IS NOT NULL LIMIT 1",
            'collection_script_scope'=>"SELECT 1 FROM script_executions e JOIN connector_targets t ON t.id=e.target_id WHERE e.workspace_id<>t.workspace_id UNION ALL SELECT 1 FROM script_executions WHERE (channel='sandbox' AND target_id IS NOT NULL) OR (channel='connector' AND target_id IS NULL) LIMIT 1",
            'collection_export_integrity'=>"SELECT 1 FROM data_export_jobs WHERE status='succeeded' AND artifact_sha256 IS DISTINCT FROM encode(sha256(convert_to(artifact,'UTF8')),'hex') LIMIT 1",
        ];
        foreach($queries as $name=>$sql)$probe($name,function()use($c,$sql):bool{
            return $c->get(DatabaseManager::class)->transaction(static function(PDO $p)use($sql):bool{
                $p->exec('SET TRANSACTION READ ONLY');$p->exec("SET LOCAL statement_timeout='10s'");$p->exec("SET LOCAL lock_timeout='2s'");
                return $p->query($sql)->fetchColumn()===false;
            });
        },'Data collection integrity check failed or PostgreSQL unavailable.');
        // A configured root is not proof of namespace/cgroup isolation. The native integration
        // test is separately mandatory in the strict release suite, under the actual worker UID.
        $probe('collection_script_configuration',function():bool{
            $cfg=$this->app->config();if($cfg->get('scripts.enabled')!==true)return true;
            $root=$cfg->get('scripts.rootfs','');if(!is_string($root)||$root===''||realpath($root)!==$root||$root==='/')return false;
            $marker=$root.'/.cajeer-script-root';
            return is_dir($root)&&fileowner($root)===0&&(fileperms($root)&0022)===0&&is_file($marker)&&!is_link($marker)&&fileowner($marker)===0&&(fileperms($marker)&0022)===0&&file_get_contents($marker)==="cajeer-script-root-v1\n"&&is_executable('/usr/bin/bwrap')&&is_executable('/usr/bin/prlimit');
        },'Enabled scripts require a provisioned rootfs and sandbox tools; run native isolation test separately.');
        return ['ok'=>!in_array(false,array_column($checks,'ok'),true),'checks'=>$checks];
    }
}
