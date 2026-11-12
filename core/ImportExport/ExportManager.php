<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Connector\ConnectorBindingService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Security\Rbac;
use PDO;
use PDOException;

/** Durable, bounded exports. Artifact and status commit together; no public storage URL. */
final class ExportManager
{
    private const META='id,workspace_id,target_id,actor_id,kind,format,from_at,to_at,series_id,status,created_at,finished_at,attempts,error_code,record_count,artifact_sha256,octet_length(artifact) AS artifact_bytes';
    public function __construct(private readonly DatabaseManager $db,private readonly ConnectorBindingService $bindings,private readonly InventoryRepository $inventory,private readonly Rbac $rbac,private readonly AuditLogger $audit,private readonly ExporterRegistry $registry){}
    private function q(PDO $p,string $sql,array $args=[]):\PDOStatement{$q=$p->prepare($sql);$q->execute($args);return $q;}
    private function missing():never{throw new HttpException(404,'Export not found.','not_found');}
    private function access(PDO $p,string $actor,string $target,?string $kind=null):array
    {
        foreach(['inventory.transfer.read','inventory.assets.read','connectors.targets.read'] as $perm)$this->rbac->requireUserPermission($actor,$perm,$p);
        if(in_array($kind,['events','observations'],true))$this->rbac->requireUserPermission($actor,'ingestion.read',$p);
        return $this->bindings->show($actor,$target);
    }
    private function present(array $r):array
    {
        foreach(['from_at','to_at','created_at','finished_at'] as $field)if(isset($r[$field]))$r[$field]=(new \DateTimeImmutable($r[$field]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        foreach(['attempts','record_count','artifact_bytes'] as $field)if(isset($r[$field]))$r[$field]=(int)$r[$field];
        return $r;
    }
    public function create(string $actor,string $target,array $input,string $key):array
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$key))throw new HttpException(422,'Invalid Idempotency-Key.','validation_failed');
        $m=ExportManifest::validate($input);$this->registry->require($m['format'],$m['kind']);$digest=hash('sha256',json_encode($m,JSON_THROW_ON_ERROR));
        return $this->db->transaction(function(PDO $p)use($actor,$target,$m,$key,$digest){
            $t=$this->access($p,$actor,$target,$m['kind']);$this->inventory->lockWorkspace($p,$t['workspace_id']);$this->access($p,$actor,$target,$m['kind']);
            $existing=$this->q($p,'SELECT '.self::META.',request_digest FROM data_export_jobs WHERE actor_id=:a AND target_id=:t AND idempotency_key=:k',['a'=>$actor,'t'=>$target,'k'=>$key])->fetch(PDO::FETCH_ASSOC);
            if($existing){if(!hash_equals($existing['request_digest'],$digest))throw new HttpException(409,'Export key already used.','idempotency_conflict');unset($existing['request_digest']);return $this->present($existing);}
            if($m['series_id']!==null&&!$this->q($p,'SELECT 1 FROM metric_series WHERE id=:s AND target_id=:t AND workspace_id=:w',['s'=>$m['series_id'],'t'=>$target,'w'=>$t['workspace_id']])->fetchColumn())$this->missing();
            $counts=$this->q($p,"SELECT count(*) AS total,count(*) FILTER(WHERE status='queued') AS pending FROM data_export_jobs WHERE workspace_id=:w",['w'=>$t['workspace_id']])->fetch(PDO::FETCH_ASSOC);
            if((int)$counts['total']>=100||(int)$counts['pending']>=10)throw new HttpException(429,'Export quota exceeded.','rate_limited');
            $id=$this->q($p,'INSERT INTO data_export_jobs(workspace_id,target_id,actor_id,idempotency_key,request_digest,kind,format,from_at,to_at,series_id) VALUES(:w,:t,:a,:k,:d,:kind,:format,:from,:to,:series_id) RETURNING id',['w'=>$t['workspace_id'],'t'=>$target,'a'=>$actor,'k'=>$key,'d'=>$digest,...$m])->fetchColumn();
            $this->audit->record($p,$actor,'data.export.queued','success','data_export_job',$id);return $this->row($p,$actor,$target,$id);
        });
    }
    private function row(PDO $p,string $actor,string $target,string $id,bool $lock=false):array
    {
        $r=$this->q($p,'SELECT '.self::META.' FROM data_export_jobs WHERE id=:id AND actor_id=:a AND target_id=:t'.($lock?' FOR UPDATE':''),['id'=>$id,'a'=>$actor,'t'=>$target])->fetch(PDO::FETCH_ASSOC);if(!$r)$this->missing();return $this->present($r);
    }
    public function list(string $actor,string $target,int $limit=50,int $offset=0):array
    {
        if($limit<1||$limit>100||$offset<0||$offset>10000)throw new HttpException(422,'Invalid pagination.','validation_failed');
        $p=$this->db->connection();$this->access($p,$actor,$target);
        // No event/observation payload is disclosed in metadata. Detail/download recheck kind-specific rights.
        return ['formats'=>$this->registry->formats(),'jobs'=>array_map($this->present(...),$this->q($p,'SELECT '.self::META." FROM data_export_jobs WHERE actor_id=:a AND target_id=:t ORDER BY created_at DESC,id LIMIT $limit OFFSET $offset",['a'=>$actor,'t'=>$target])->fetchAll(PDO::FETCH_ASSOC))];
    }
    public function show(string $actor,string $target,string $id):array
    { $p=$this->db->connection();$this->access($p,$actor,$target);$r=$this->row($p,$actor,$target,$id);$this->access($p,$actor,$target,$r['kind']);return $r; }
    public function download(string $actor,string $target,string $id):array
    {
        return $this->db->transaction(function(PDO $p)use($actor,$target,$id){$r=$this->show($actor,$target,$id);if($r['status']!=='succeeded')throw new HttpException(409,'Export is not ready.','conflict');
            if(new \DateTimeImmutable($r['finished_at'])<=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('-7 days'))throw new HttpException(410,'Export has expired.','export_expired');
            $body=$this->q($p,'SELECT artifact FROM data_export_jobs WHERE id=:id AND actor_id=:a AND target_id=:t',['id'=>$id,'a'=>$actor,'t'=>$target])->fetchColumn();if(!is_string($body)||!hash_equals($r['artifact_sha256'],hash('sha256',$body)))throw new HttpException(409,'Export unavailable.','conflict');
            $this->access($p,$actor,$target,$r['kind']);$this->audit->record($p,$actor,'data.export.downloaded','success','data_export_job',$id);return ['body'=>$body,'format'=>$r['format'],'sha256'=>$r['artifact_sha256']];});
    }
    public function cancel(string $actor,string $target,string $id):array
    {
        return $this->db->transaction(function(PDO $p)use($actor,$target,$id){$t=$this->access($p,$actor,$target);$this->inventory->lockWorkspace($p,$t['workspace_id']);$r=$this->row($p,$actor,$target,$id,true);$this->access($p,$actor,$target,$r['kind']);if($r['status']!=='queued')throw new HttpException(409,'Only queued exports can be cancelled.','conflict');$this->q($p,"UPDATE data_export_jobs SET status='cancelled',finished_at=clock_timestamp() WHERE id=:id",['id'=>$id]);$this->audit->record($p,$actor,'data.export.cancelled','success','data_export_job',$id);return $this->row($p,$actor,$target,$id);});
    }
    private function records(PDO $p,array $j,int &$count):\Generator
    {
        $args=['w'=>$j['workspace_id'],'t'=>$j['target_id'],'from'=>$j['from_at'],'to'=>$j['to_at']];
        $range='>=CAST(:from AS timestamptz) AND %s<CAST(:to AS timestamptz)';
        switch($j['kind']){
            case 'metrics':
                $sql='SELECT s.id AS series_id,s.metric,s.kind,s.unit,s.resource_external_id,s.labels,s.asset_id,s.target_version,s.ingestion_source_id,m.observed_at,m.value::text AS value FROM metric_series s JOIN metric_samples m ON m.series_id=s.id WHERE s.workspace_id=:w AND s.target_id=:t AND m.observed_at'.sprintf($range,'m.observed_at')." AND m.observed_at>=clock_timestamp()-interval '30 days'";
                if($j['series_id']!==null){$sql.=' AND s.id=:series';$args['series']=$j['series_id'];}
                if($j['format']==='openmetrics'){
                    $sql="SELECT s.id AS series_id,s.metric,s.kind,s.unit,s.resource_external_id,s.labels,s.asset_id,s.target_version,s.ingestion_source_id,m.observed_at,m.value::text AS value FROM metric_series s CROSS JOIN LATERAL (SELECT observed_at,value FROM metric_samples WHERE series_id=s.id AND observed_at>=CAST(:from AS timestamptz) AND observed_at<CAST(:to AS timestamptz) AND observed_at>=clock_timestamp()-interval '30 days' ORDER BY observed_at DESC LIMIT 1) m WHERE s.workspace_id=:w AND s.target_id=:t".($j['series_id']!==null?' AND s.id=:series':'').' ORDER BY s.kind,s.id';
                }else $sql.=' ORDER BY m.observed_at,s.id';break;
            case 'events':$sql='SELECT source_id,event_id,type,severity,message,observed_at FROM data_events WHERE workspace_id=:w AND target_id=:t AND observed_at'.sprintf($range,'observed_at').' ORDER BY observed_at,id';break;
            case 'observations':$sql="SELECT b.id AS batch_id,b.source_id,b.received_at,r.ordinal,r.value AS observation FROM data_ingestion_inbox b CROSS JOIN LATERAL jsonb_array_elements(b.payload) WITH ORDINALITY r(value,ordinal) WHERE b.workspace_id=:w AND b.target_id=:t AND b.kind='observations' AND b.status='succeeded' AND b.received_at".sprintf($range,'b.received_at').' ORDER BY b.received_at,b.id,r.ordinal';break;
            case 'runs':$sql='SELECT id,collector_name,created_at,finished_at,error_code,result FROM connector_runs WHERE workspace_id=:w AND target_id=:t AND finished_at IS NOT NULL AND created_at'.sprintf($range,'created_at').' ORDER BY created_at,id';break;
            default:throw new HttpException(422,'Invalid export kind.','validation_failed');
        }
        $limit=$j['kind']==='runs'?100:10000;
        // PDO pgsql buffers query results. A SQL-side byte guard prevents returning a huge result
        // set to PHP just to reject it later. Both budget and records share one statement snapshot.
        $order=match($j['kind']){'metrics'=>$j['format']==='openmetrics'?'kind,series_id':'observed_at,series_id','events'=>'observed_at,source_id,event_id','observations'=>'received_at,batch_id,ordinal','runs'=>'created_at,id'};
        $max=BuiltinExporter::MAX_BYTES;
        $sql="WITH selected AS MATERIALIZED ($sql LIMIT ".($limit+1)."), budget AS (SELECT count(*) AS n,COALESCE(sum(octet_length(row_to_json(selected)::text)),0) AS bytes FROM selected) SELECT CASE WHEN budget.n<=$limit AND budget.bytes<=$max THEN row_to_json(selected)::text ELSE NULL END AS data,budget.n,budget.bytes FROM selected CROSS JOIN budget ORDER BY $order";
        $q=$this->q($p,$sql,$args);
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if($row['data']===null)throw new HttpException(422,'Export exceeds row or byte limit; narrow range.','export_limit');
            $count++;
            $r=get_object_vars(json_decode($row['data'],false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING));
            foreach(['observed_at','created_at','finished_at','received_at'] as $field)if(isset($r[$field]))$r[$field]=(new \DateTimeImmutable($r[$field]))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            if(isset($r['observation'])){$obs=get_object_vars($r['observation']);unset($r['observation'],$r['ordinal']);$r=[...$r,...$obs];}
            yield $r;
        }

    }
    public function work(int $limit=10):array
    {
        if($limit<1||$limit>50)throw new \InvalidArgumentException('Export worker limit 1..50.');$done=0;
        for($i=0;$i<$limit;$i++){
            $did=$this->db->transaction(function(PDO $p){
                $p->exec("SET LOCAL statement_timeout='30s'");$p->exec("SET LOCAL lock_timeout='3s'");
                $ws=$p->query("SELECT w.id FROM workspaces w WHERE EXISTS(SELECT 1 FROM data_export_jobs j WHERE j.workspace_id=w.id AND j.status='queued' AND j.available_at<=clock_timestamp()) ORDER BY (SELECT min(j.available_at) FROM data_export_jobs j WHERE j.workspace_id=w.id AND j.status='queued' AND j.available_at<=clock_timestamp()),w.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetchColumn();if(!$ws)return false;
                $j=$this->q($p,"SELECT * FROM data_export_jobs WHERE workspace_id=:w AND status='queued' AND available_at<=clock_timestamp() ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED",['w'=>$ws])->fetch(PDO::FETCH_ASSOC);if(!$j)return false;
                $attempt=(int)$j['attempts']+1;$status='succeeded';$code=null;$body=null;$count=0;
                try{$body=$this->db->transaction(function(PDO $p)use($j,&$count){$this->access($p,$j['actor_id'],$j['target_id'],$j['kind']);$e=$this->registry->require($j['format'],$j['kind']);$rows=$this->records($p,$j,$count);$data=$e->encode($j['kind'],$rows);if($rows->valid())throw new HttpException(422,'Exporter did not consume all records.','export_invalid');
                    if(strlen($data)>BuiltinExporter::MAX_BYTES||!preg_match('//u',$data)||str_contains($data,"\0"))throw new HttpException(422,'Invalid or excessive export artifact.','export_limit');
                    $bytes=(int)$this->q($p,'SELECT COALESCE(sum(octet_length(artifact)),0) FROM data_export_jobs WHERE workspace_id=:w',['w'=>$j['workspace_id']])->fetchColumn();if($bytes+strlen($data)>67108864)throw new HttpException(429,'Workspace export storage quota exceeded.','export_limit');
                    $this->access($p,$j['actor_id'],$j['target_id'],$j['kind']);return $data;});}
                catch(HttpException $e){$status='failed';$code=$e->errorCode;}
                catch(PDOException $e){if(!in_array((string)($e->errorInfo[0]??$e->getCode()),['40001','40P01','55P03','57014'],true))throw $e;$status=$attempt<5?'queued':'failed';$code='export_database_retry';}
                catch(\Throwable){$status='failed';$code='export_failed';}
                $this->q($p,"UPDATE data_export_jobs SET status=:s,attempts=:a,error_code=:c,artifact=:b,artifact_sha256=:sha,record_count=:n,finished_at=CASE WHEN :state='queued' THEN NULL ELSE clock_timestamp() END,available_at=clock_timestamp()+(:delay*interval '1 second') WHERE id=:id",['s'=>$status,'a'=>$attempt,'c'=>$code,'b'=>$body,'sha'=>$body===null?null:hash('sha256',$body),'n'=>$body===null?null:$count,'state'=>$status,'delay'=>$status==='queued'?30*2**($attempt-1):0,'id'=>$j['id']]);if($status!=='queued')$this->audit->record($p,$j['actor_id'],'data.export.completed',$status==='succeeded'?'success':'failure','data_export_job',$j['id'],['count'=>$count,'reason'=>$code??'completed']);return true;
            },3);if(!$did)break;$done++;
        }return ['processed'=>$done];
    }
    public function retain(int $limit=100):array
    {if($limit<1||$limit>1000)throw new \InvalidArgumentException('Export retention limit 1..1000.');return $this->db->transaction(fn(PDO $p)=>['deleted'=>$this->q($p,"DELETE FROM data_export_jobs WHERE id IN(SELECT id FROM data_export_jobs WHERE status<>'queued' AND finished_at<clock_timestamp()-interval '7 days' ORDER BY finished_at LIMIT $limit FOR UPDATE SKIP LOCKED)")->rowCount()]);}
}
