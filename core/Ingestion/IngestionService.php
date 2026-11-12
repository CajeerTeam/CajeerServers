<?php
declare(strict_types=1);
namespace Cajeer\Servers\Ingestion;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Collector\WebhookCollector;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorEventPayload,ConnectorExecutionService,ConnectorTransportException};
use Cajeer\Servers\Credentials\{CredentialInput,CredentialsVault,VaultCrypto};
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Metrics\MetricStore;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\{RateLimiter,Rbac};
use PDO;
use PDOException;

/** Durable inbox: database-only processing, atomic data/result commit, bounded retries. */
final class IngestionService
{
    public const KEY_TYPE='data_ingestion';
    public function __construct(private readonly DatabaseManager $db,private readonly ConnectorBindingService $bindings,
        private readonly InventoryRepository $inventory,private readonly Rbac $rbac,private readonly ConnectorExecutionService $execution,
        private readonly CredentialsVault $vault,private readonly VaultCrypto $crypto,private readonly RateLimiter $limiter,private readonly AuditLogger $audit){}
    private function q(PDO $p,string $sql,array $args=[]):\PDOStatement{$q=$p->prepare($sql);$q->execute($args);return $q;}
    private function missing():never{throw new HttpException(404,'Ingestion resource not found.','not_found');}
    private function denied():never{throw new HttpException(401,'Invalid ingestion credentials.','invalid_signature');}
    private function source(PDO $p,string $id,bool $lock=false):?array
    { $r=$this->q($p,"SELECT *,encode(secret_ciphertext,'hex') AS cipher FROM data_ingestion_sources WHERE id=:id".($lock?' FOR UPDATE':''),['id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);return $r?:null; }
    private function publicSource(array $s):array{unset($s['cipher'],$s['secret_ciphertext']);return $s;}
    private function access(PDO $p,string $actor,string $target,bool $write):array
    {
        $this->rbac->requireUserPermission($actor,$write?'ingestion.manage':'ingestion.read',$p);
        $this->rbac->requireUserPermission($actor,'inventory.assets.read',$p);
        $this->rbac->requireUserPermission($actor,'connectors.targets.read',$p);
        $t=$this->bindings->show($actor,$target);
        if($write){$this->inventory->lockWorkspace($p,$t['workspace_id']);$this->inventory->workspaceRole($p,$t['workspace_id'],$actor,true);}
        return $t;
    }
    private function live(PDO $p,array $s):array
    {
        if($s['revoked_at']!==null)throw new HttpException(403,'Ingestion source revoked.','ingestion_revoked');
        $t=$this->execution->ingestionContext($p,$s['actor_id'],$s['target_id'],$s['grant_id']);
        if($t['workspace_id']!==$s['workspace_id'])$this->missing();return $t;
    }
    public function create(string $actor,string $target,string $grant,string $name,string $kind):array
    {
        if(!in_array($kind,WebhookCollector::KINDS,true)||strlen($name)<1||strlen($name)>120||!preg_match('/^[^\x00-\x1f\x7f]+$/uD',$name))throw new HttpException(422,'Invalid ingestion source.','validation_failed');
        $grant=CredentialInput::uuid($grant);$this->vault->check();
        return $this->db->transaction(function(PDO $p)use($actor,$target,$grant,$name,$kind){
            $t=$this->access($p,$actor,$target,true);$this->execution->ingestionContext($p,$actor,$target,$grant);
            if((int)$this->q($p,'SELECT count(*) FROM data_ingestion_sources WHERE workspace_id=:w',['w'=>$t['workspace_id']])->fetchColumn()>=100||(int)$this->q($p,'SELECT count(*) FROM data_ingestion_sources WHERE target_id=:t AND revoked_at IS NULL',['t'=>$target])->fetchColumn()>=10)throw new HttpException(429,'Ingestion source quota exceeded.','rate_limited');
            $id=$p->query('SELECT gen_random_uuid()::text')->fetchColumn();$key=bin2hex(random_bytes(32));$cipher=$this->crypto->seal($key,$id,$t['workspace_id'],self::KEY_TYPE);
            $this->q($p,"INSERT INTO data_ingestion_sources(id,workspace_id,target_id,grant_id,actor_id,name,kind,secret_ciphertext) VALUES(:id,:w,:t,:g,:a,:n,:k,decode(:c,'hex'))",['id'=>$id,'w'=>$t['workspace_id'],'t'=>$target,'g'=>$grant,'a'=>$actor,'n'=>$name,'k'=>$kind,'c'=>bin2hex($cipher)]);
            $this->audit->record($p,$actor,'ingestion.source.created','success','data_ingestion_source',$id,['source'=>$target]);return [...$this->publicSource($this->source($p,$id)),'secret'=>$key];
        });
    }
    public function sources(string $actor,string $target):array
    { $p=$this->db->connection();$this->access($p,$actor,$target,false);return $this->q($p,'SELECT id,workspace_id,target_id,grant_id,actor_id,name,kind,key_version,created_at,revoked_at FROM data_ingestion_sources WHERE target_id=:t ORDER BY created_at DESC,id LIMIT 100',['t'=>$target])->fetchAll(PDO::FETCH_ASSOC); }
    public function change(string $actor,string $target,string $id,bool $rotate):array
    {
        if($rotate)$this->vault->check();return $this->db->transaction(function(PDO $p)use($actor,$target,$id,$rotate){
            $this->access($p,$actor,$target,true);$s=$this->source($p,$id,true);if(!$s||$s['target_id']!==$target||$s['revoked_at']!==null)$this->missing();
            if($rotate){if($s['actor_id']!==$actor)throw new HttpException(403,'Only grant owner may rotate.','permission_denied');$this->live($p,$s);$key=bin2hex(random_bytes(32));$cipher=$this->crypto->seal($key,$id,$s['workspace_id'],self::KEY_TYPE);$this->q($p,"UPDATE data_ingestion_sources SET secret_ciphertext=decode(:c,'hex'),key_version=key_version+1 WHERE id=:id",['c'=>bin2hex($cipher),'id'=>$id]);}
            else $this->q($p,'UPDATE data_ingestion_sources SET revoked_at=clock_timestamp() WHERE id=:id',['id'=>$id]);
            $this->audit->record($p,$actor,$rotate?'ingestion.source.rotated':'ingestion.source.revoked','success','data_ingestion_source',$id);$row=$this->publicSource($this->source($p,$id));return $rotate?[...$row,'secret'=>$key]:$row;
        });
    }
    private function verify(array $s,HttpRequest $r):void
    {
        $key=$this->crypto->open(hex2bin($s['cipher']),$s['id'],$s['workspace_id'],self::KEY_TYPE);
        try{ConnectorEventPayload::verify($r->body,$key,$s['id'],$r->header('X-Cajeer-Timestamp',''),$r->header('X-Cajeer-Signature',''));}finally{sodium_memzero($key);}
    }
    public function ingest(string $id,HttpRequest $r):array
    {
        if($r->bodyTruncated||strlen($r->body)>WebhookCollector::MAX_BYTES)throw new HttpException(413,'Batch exceeds 256 KiB.','payload_too_large');
        if(strtolower(trim(explode(';',$r->header('Content-Type',''),2)[0]))!=='application/json')throw new HttpException(415,'JSON required.','invalid_content_type');
        $s=$this->source($this->db->connection(),$id);if(!$s||$s['revoked_at']!==null)$this->denied();
        $this->vault->check();$this->verify($s,$r);
        if(!$this->db->transaction(fn(PDO $p)=>$this->limiter->hit($p,'data_ingestion',$id,60,60)))throw new HttpException(429,'Ingestion rate limit exceeded.','rate_limited',['Retry-After'=>'60']);
        return $this->db->transaction(function(PDO $p)use($id,$r,$s){
            $this->inventory->lockWorkspace($p,$s['workspace_id']);$s=$this->source($p,$id,true);if(!$s||$s['revoked_at']!==null)$this->denied();
            $this->verify($s,$r);
            $this->live($p,$s);
            $collector=new WebhookCollector();$batch=$collector->envelope($r->body,$s['kind']);
            $existing=$this->q($p,'SELECT id,body_digest,status FROM data_ingestion_inbox WHERE source_id=:s AND batch_id=:b',['s'=>$id,'b'=>$batch['batch_id']])->fetch(PDO::FETCH_ASSOC);
            if($existing){if(!hash_equals($existing['body_digest'],$batch['digest']))throw new HttpException(409,'Batch ID already used for another payload.','ingestion_conflict');return ['id'=>$existing['id'],'status'=>$existing['status'],'deduplicated'=>true];}
            $batch=$collector->normalize($batch,$id);
            $counts=$this->q($p,"SELECT count(*) AS total,COALESCE(sum(payload_bytes),0) AS bytes,count(*) FILTER(WHERE status='queued') AS pending,count(*) FILTER(WHERE status='queued' AND source_id=:s) AS source_pending FROM data_ingestion_inbox WHERE workspace_id=:w",['s'=>$id,'w'=>$s['workspace_id']])->fetch(PDO::FETCH_ASSOC);
            $payload=json_encode($batch['records'],JSON_THROW_ON_ERROR);
            if((int)$counts['bytes']+strlen($payload)>268435456||(int)$counts['total']>=10000||(int)$counts['pending']>=500||(int)$counts['source_pending']>=100)throw new HttpException(429,'Ingestion inbox capacity exceeded.','rate_limited',['Retry-After'=>'60']);
            $bid=$this->q($p,'INSERT INTO data_ingestion_inbox(source_id,workspace_id,target_id,batch_id,body_digest,kind,payload,payload_bytes) VALUES(:s,:w,:t,:b,:d,:k,CAST(:p AS jsonb),:bytes) RETURNING id',['s'=>$id,'w'=>$s['workspace_id'],'t'=>$s['target_id'],'b'=>$batch['batch_id'],'d'=>$batch['digest'],'k'=>$s['kind'],'p'=>$payload,'bytes'=>strlen($payload)])->fetchColumn();
            return ['id'=>$bid,'status'=>'queued','deduplicated'=>false];
        });
    }
    private function page(int $limit,int $offset):void{if($limit<1||$limit>100||$offset<0||$offset>10000)throw new HttpException(422,'Invalid pagination.','validation_failed');}
    public function batches(string $actor,string $target,int $limit=50,int $offset=0):array
    { $this->page($limit,$offset);$p=$this->db->connection();$this->access($p,$actor,$target,false);$rows=$this->q($p,"SELECT id,source_id,batch_id,kind,status,received_at,finished_at,attempts,dispatch_count,error_code,result FROM data_ingestion_inbox WHERE target_id=:t ORDER BY received_at DESC,id LIMIT $limit OFFSET $offset",['t'=>$target])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['result']=$row['result']===null?null:json_decode($row['result'],true,32,JSON_THROW_ON_ERROR);}unset($row);return $rows; }
    public function batch(string $actor,string $target,string $id):array
    {
        $p=$this->db->connection();$this->access($p,$actor,$target,false);$b=$this->q($p,'SELECT * FROM data_ingestion_inbox WHERE target_id=:t AND id=:id',['t'=>$target,'id'=>$id])->fetch(PDO::FETCH_ASSOC);if(!$b)$this->missing();
        // Only normalized data, never raw signed bodies or credentials.
        $b['payload']=json_decode($b['payload'],true,32,JSON_THROW_ON_ERROR);$b['result']=$b['result']===null?null:json_decode($b['result'],true,32,JSON_THROW_ON_ERROR);$b['history']=$this->q($p,'SELECT dispatch,attempt,started_at,finished_at,outcome,error_code FROM data_ingestion_attempts WHERE batch_id=:id ORDER BY dispatch,attempt',['id'=>$id])->fetchAll(PDO::FETCH_ASSOC);return $b;
    }
    public function events(string $actor,string $target,int $limit=50,int $offset=0):array
    { $this->page($limit,$offset);$p=$this->db->connection();$this->access($p,$actor,$target,false);return $this->q($p,"SELECT id,source_id,event_id,type,severity,message,observed_at,received_at FROM data_events WHERE target_id=:t ORDER BY observed_at DESC,id LIMIT $limit OFFSET $offset",['t'=>$target])->fetchAll(PDO::FETCH_ASSOC); }
    public function redrive(string $actor,string $target,string $id):array
    {
        return $this->db->transaction(function(PDO $p)use($actor,$target,$id){$this->access($p,$actor,$target,true);$b=$this->q($p,'SELECT * FROM data_ingestion_inbox WHERE target_id=:t AND id=:id FOR UPDATE',['t'=>$target,'id'=>$id])->fetch(PDO::FETCH_ASSOC);if(!$b)$this->missing();
            if($b['status']!=='failed'||(int)$b['dispatch_count']>=10)throw new HttpException(409,'Batch cannot be redriven.','conflict');$s=$this->source($p,$b['source_id'],true);$this->live($p,$s);
            $counts=$this->q($p,"SELECT count(*) AS pending,count(*) FILTER(WHERE source_id=:s) AS source_pending FROM data_ingestion_inbox WHERE workspace_id=:w AND status='queued'",['s'=>$s['id'],'w'=>$s['workspace_id']])->fetch(PDO::FETCH_ASSOC);
            if((int)$counts['pending']>=500||(int)$counts['source_pending']>=100)throw new HttpException(429,'Ingestion inbox capacity exceeded.','rate_limited',['Retry-After'=>'60']);
            $this->q($p,"UPDATE data_ingestion_inbox SET status='queued',attempts=0,dispatch_count=dispatch_count+1,available_at=clock_timestamp(),finished_at=NULL,error_code=NULL WHERE id=:id",['id'=>$id]);$this->audit->record($p,$actor,'ingestion.batch.redriven','success','data_ingestion_batch',$id);return ['id'=>$id,'status'=>'queued','dispatch_count'=>(int)$b['dispatch_count']+1];});
    }
    /** One bounded transaction per batch. No external side effects: process crash rolls back claim and data together. */
    public function work(int $limit=20):array
    {
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Ingestion worker limit 1..100.');$done=0;
        for($i=0;$i<$limit;$i++){
            $did=$this->db->transaction(function(PDO $p){
                $p->exec("SET LOCAL statement_timeout='20s'");$p->exec("SET LOCAL lock_timeout='3s'");
                // Workspace-first ordering, skip busy workspaces instead of blocking ingestion.
                $ws=$p->query("SELECT w.id FROM workspaces w WHERE EXISTS(SELECT 1 FROM data_ingestion_inbox b WHERE b.workspace_id=w.id AND b.status='queued' AND b.available_at<=clock_timestamp()) ORDER BY (SELECT min(b.available_at) FROM data_ingestion_inbox b WHERE b.workspace_id=w.id AND b.status='queued' AND b.available_at<=clock_timestamp()),w.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetchColumn();if(!$ws)return false;
                $b=$this->q($p,"SELECT * FROM data_ingestion_inbox WHERE workspace_id=:w AND status='queued' AND available_at<=clock_timestamp() ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED",['w'=>$ws])->fetch(PDO::FETCH_ASSOC);if(!$b)return false;
                $started=$p->query('SELECT clock_timestamp()::text')->fetchColumn();$attempt=(int)$b['attempts']+1;$code=null;$result=null;$status='succeeded';
                try{$result=$this->db->transaction(function(PDO $p)use($b){$s=$this->source($p,$b['source_id'],true);$t=$this->live($p,$s);$rows=json_decode($b['payload'],true,32,JSON_THROW_ON_ERROR);
                    $result=match($b['kind']){'metrics'=>MetricStore::ingest($p,$t,$rows,$b['received_at'],$s['id']),'events'=>$this->storeEvents($p,$b,$rows),'observations'=>['observations'=>count($rows),'applied'=>false]};
                    $this->live($p,$s);return $result;});
                }catch(HttpException|ConnectorTransportException $e){$status='failed';$code=$e instanceof HttpException?$e->errorCode:'metric_invalid';}
                catch(PDOException $e){$state=(string)($e->errorInfo[0]??$e->getCode());if(!in_array($state,['40001','40P01','55P03','57014'],true))throw $e;$status=$attempt<5?'queued':'failed';$code='ingestion_database_retry';}
                $outcome=$status==='queued'?'retry_scheduled':$status;
                $this->q($p,'INSERT INTO data_ingestion_attempts(batch_id,dispatch,attempt,started_at,outcome,error_code) VALUES(:id,:d,:a,CAST(:start AS timestamptz),:o,:c)',['id'=>$b['id'],'d'=>$b['dispatch_count'],'a'=>$attempt,'start'=>$started,'o'=>$outcome,'c'=>$code]);
                $this->q($p,"UPDATE data_ingestion_inbox SET status=:s,attempts=:a,error_code=:c,result=CAST(:r AS jsonb),finished_at=CASE WHEN :state='queued' THEN NULL ELSE clock_timestamp() END,available_at=clock_timestamp()+(:delay*interval '1 second') WHERE id=:id",['s'=>$status,'a'=>$attempt,'c'=>$code,'r'=>$result===null?null:json_encode($result,JSON_THROW_ON_ERROR),'state'=>$status,'delay'=>$status==='queued'?30*(2**($attempt-1)):0,'id'=>$b['id']]);return true;
            },3);if(!$did)break;$done++;
        }return ['processed'=>$done];
    }
    private function storeEvents(PDO $p,array $b,array $rows):array
    {
        // Batch SQL, not one round trip per event. The workspace/source locks serialize ingestion.
        foreach($rows as &$r)$r['digest']=hash('sha256',json_encode([$r['event_id'],$r['type'],$r['message'],$r['severity'],$r['observed_at']],JSON_THROW_ON_ERROR));
        unset($r);$data=json_encode($rows,JSON_THROW_ON_ERROR);
        $records="jsonb_to_recordset(CAST(:data AS jsonb)) AS d(event_id text,type text,severity text,message text,observed_at timestamptz,digest text)";
        $scope=['data'=>$data,'s'=>$b['source_id']];
        $conflict=$this->q($p,"SELECT 1 FROM $records JOIN data_events e ON e.source_id=CAST(:s AS uuid) AND e.event_id=d.event_id WHERE e.digest<>d.digest LIMIT 1",$scope)->fetchColumn();
        if($conflict)throw new HttpException(409,'Event ID already used for another event.','event_conflict');
        $missing=(int)$this->q($p,"SELECT count(*) FROM $records LEFT JOIN data_events e ON e.source_id=CAST(:s AS uuid) AND e.event_id=d.event_id WHERE e.id IS NULL",$scope)->fetchColumn();
        $count=(int)$this->q($p,'SELECT count(*) FROM data_events WHERE workspace_id=:w',['w'=>$b['workspace_id']])->fetchColumn();
        if($count+$missing>100000)throw new HttpException(422,'Workspace event quota exceeded.','ingestion_quota');
        $inserted=$this->q($p,"INSERT INTO data_events(source_id,workspace_id,target_id,event_id,type,severity,message,observed_at,digest) SELECT CAST(:s AS uuid),CAST(:w AS uuid),CAST(:t AS uuid),d.event_id,d.type,d.severity,d.message,d.observed_at,d.digest FROM $records ON CONFLICT(source_id,event_id) DO NOTHING",[...$scope,'w'=>$b['workspace_id'],'t'=>$b['target_id']])->rowCount();
        return ['events'=>count($rows),'inserted'=>$inserted,'deduplicated'=>count($rows)-$inserted];
    }
    public function retain(int $limit=1000):array
    {
        if($limit<1||$limit>10000)throw new \InvalidArgumentException('Ingestion retention limit 1..10000.');return $this->db->transaction(function(PDO $p)use($limit){
            $b=$this->q($p,"DELETE FROM data_ingestion_inbox WHERE id IN(SELECT id FROM data_ingestion_inbox WHERE status<>'queued' AND finished_at<clock_timestamp()-interval '30 days' ORDER BY finished_at LIMIT $limit FOR UPDATE SKIP LOCKED)")->rowCount();
            $e=$this->q($p,"DELETE FROM data_events WHERE id IN(SELECT id FROM data_events WHERE received_at<clock_timestamp()-interval '30 days' ORDER BY received_at LIMIT $limit FOR UPDATE SKIP LOCKED)")->rowCount();
            // Remove only retired sources with no remaining dependent data; audit entries remain.
            $s=$this->q($p,"DELETE FROM data_ingestion_sources WHERE id IN(SELECT s.id FROM data_ingestion_sources s WHERE s.revoked_at<clock_timestamp()-interval '30 days' AND NOT EXISTS(SELECT 1 FROM data_ingestion_inbox b WHERE b.source_id=s.id) AND NOT EXISTS(SELECT 1 FROM data_events e WHERE e.source_id=s.id) AND NOT EXISTS(SELECT 1 FROM metric_series m WHERE m.ingestion_source_id=s.id) ORDER BY s.revoked_at LIMIT $limit FOR UPDATE OF s SKIP LOCKED)")->rowCount();return ['batches_deleted'=>$b,'events_deleted'=>$e,'sources_deleted'=>$s];});
    }
}
