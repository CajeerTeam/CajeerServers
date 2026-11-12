<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\{CredentialInput,CredentialsVault,VaultCrypto};
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\{RateLimiter,Rbac};
use PDO;

/** Atomic signed inbox -> existing connector execution queue. All writes use workspace-first locks. */
final class ConnectorEventService
{
    private const KEY_TYPE='connector_webhook';
    public function __construct(private readonly DatabaseManager $db,private readonly ConnectorBindingService $bindings,
        private readonly InventoryRepository $inventory,private readonly Rbac $rbac,private readonly ConnectorExecutionService $execution,
        private readonly CredentialsVault $vault,private readonly VaultCrypto $crypto,private readonly RateLimiter $limiter,private readonly AuditLogger $audit) {}
    private function q(PDO $p,string $sql,array $args=[]):\PDOStatement {$q=$p->prepare($sql);$q->execute($args);return $q;}
    private function invalid():never {throw new HttpException(401,'Invalid webhook credentials.','invalid_signature');}
    private function missing():never {throw new HttpException(404,'Connector webhook not found.','not_found');}
    private function source(PDO $p,string $id,bool $lock=false):?array
    {
        $r=$this->q($p,"SELECT id,target_id,workspace_id,grant_id,actor_id,key_version,created_at,revoked_at,encode(secret_ciphertext,'hex') AS cipher FROM connector_webhook_sources WHERE id=:id".($lock?' FOR UPDATE':''),['id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);
        return $r?:null;
    }
    private function access(PDO $p,string $actor,string $target,bool $write):array
    {
        $this->rbac->requireUserPermission($actor,$write?'connectors.credentials.manage':'connectors.targets.read',$p);
        if (!$write) $this->rbac->requireUserPermission($actor,'inventory.assets.read',$p);
        $t=$this->bindings->show($actor,$target);
        if ($write) {$this->inventory->lockWorkspace($p,$t['workspace_id']);$this->inventory->workspaceRole($p,$t['workspace_id'],$actor,true);}
        return $t;
    }
    private function metadata(array $s):array {unset($s['cipher'],$s['secret_ciphertext']);return $s;}
    private function key():string {return bin2hex(random_bytes(32));}
    public function create(string $actor,string $target,string $grant):array
    {
        $grant=CredentialInput::uuid($grant);
        $this->vault->check();
        return $this->db->transaction(function(PDO $p)use($actor,$target,$grant):array {
            $t=$this->access($p,$actor,$target,true);$g=$this->execution->eventGrant($p,$actor,$target,$grant);
            if ($g['workspace_id']!==$t['workspace_id']) $this->missing();
            if ((int)$this->q($p,'SELECT count(*) FROM connector_webhook_sources WHERE target_id=:t AND revoked_at IS NULL',['t'=>$target])->fetchColumn()>=10) throw new HttpException(429,'Too many webhook sources.','rate_limited');
            $id=$p->query('SELECT gen_random_uuid()::text')->fetchColumn();$key=$this->key();
            $cipher=$this->crypto->seal($key,$id,$t['workspace_id'],self::KEY_TYPE);
            $s=$this->q($p,"INSERT INTO connector_webhook_sources(id,target_id,workspace_id,grant_id,actor_id,secret_ciphertext) VALUES(:id,:t,:w,:g,:a,decode(:c,'hex')) RETURNING id,target_id,workspace_id,grant_id,actor_id,key_version,created_at,revoked_at",['id'=>$id,'t'=>$target,'w'=>$t['workspace_id'],'g'=>$grant,'a'=>$actor,'c'=>bin2hex($cipher)])->fetch(PDO::FETCH_ASSOC);
            $this->audit->record($p,$actor,'connector.webhook.created','success','connector_webhook_source',$id,['source'=>$target]);
            return [...$s,'secret'=>$key];
        });
    }
    public function sources(string $actor,string $target):array
    {
        $p=$this->db->connection();$this->access($p,$actor,$target,false);
        return $this->q($p,'SELECT id,target_id,workspace_id,grant_id,actor_id,key_version,created_at,revoked_at FROM connector_webhook_sources WHERE target_id=:t ORDER BY created_at DESC LIMIT 100',['t'=>$target])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function change(string $actor,string $target,string $id,bool $rotate):array
    {
        if ($rotate) $this->vault->check();
        return $this->db->transaction(function(PDO $p)use($actor,$target,$id,$rotate):array {
            $this->access($p,$actor,$target,true);$s=$this->source($p,$id,true);
            if (!$s || $s['target_id']!==$target || $s['revoked_at']!==null) $this->missing();
            if ($rotate) {
                $this->execution->eventGrant($p,$actor,$target,$s['grant_id']);
                $key=$this->key();$c=$this->crypto->seal($key,$id,$s['workspace_id'],self::KEY_TYPE);
                $this->q($p,"UPDATE connector_webhook_sources SET secret_ciphertext=decode(:c,'hex'),key_version=key_version+1 WHERE id=:id",['c'=>bin2hex($c),'id'=>$id]);
            } else {$this->q($p,'UPDATE connector_webhook_sources SET revoked_at=clock_timestamp() WHERE id=:id',['id'=>$id]);}
            $this->audit->record($p,$actor,$rotate?'connector.webhook.rotated':'connector.webhook.revoked','success','connector_webhook_source',$id,['source'=>$target]);
            $row=$this->metadata($this->source($p,$id));return $rotate?[...$row,'secret'=>$key]:$row;
        });
    }
    public function ingest(string $id,HttpRequest $r):array
    {
        $id=CredentialInput::uuid($id);
        if ($r->bodyTruncated || strlen($r->body)>16384) throw new HttpException(413,'Event exceeds 16 KiB.','payload_too_large');
        if (strtolower(trim(explode(';',$r->header('Content-Type',''),2)[0]))!=='application/json') throw new HttpException(415,'JSON required.','invalid_content_type');
        $s=$this->source($this->db->connection(),$id);if (!$s || $s['revoked_at']!==null) $this->invalid();
        if (!$this->db->transaction(fn(PDO $p):bool=>$this->limiter->hit($p,'connector_webhook',$id,120,60))) throw new HttpException(429,'Webhook rate limit exceeded.','rate_limited',['Retry-After'=>'60']);
        $this->vault->check();
        return $this->db->transaction(function(PDO $p)use($id,$r,$s):array {
            $this->inventory->lockWorkspace($p,$s['workspace_id']);$s=$this->source($p,$id,true);
            if (!$s || $s['revoked_at']!==null) $this->invalid();
            $key=$this->crypto->open(hex2bin($s['cipher']),$id,$s['workspace_id'],self::KEY_TYPE);
            try {ConnectorEventPayload::verify($r->body,$key,$id,$r->header('X-Cajeer-Timestamp',''),$r->header('X-Cajeer-Signature',''));} finally {sodium_memzero($key);}
            $e=ConnectorEventPayload::parse($r->body);
            $existing=$this->q($p,'SELECT id,run_id,body_digest FROM connector_events WHERE source_id=:s AND event_id=:e',['s'=>$id,'e'=>$e['event_id']])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if (!hash_equals($existing['body_digest'],$e['digest'])) throw new HttpException(409,'Event ID already used for another payload.','event_conflict');
                return ['id'=>$existing['id'],'run_id'=>$existing['run_id'],'deduplicated'=>true];
            }
            $g=$this->execution->eventGrant($p,$s['actor_id'],$s['target_id'],$s['grant_id']);
            if ($g['workspace_id']!==$s['workspace_id']) $this->invalid();
            $event=$p->query('SELECT gen_random_uuid()::text')->fetchColumn();
            $run=$this->execution->dispatchEvent($p,$g,$event,1)['id'];
            $this->q($p,'INSERT INTO connector_events(id,source_id,event_id,event_type,resource_id,body_digest,run_id) VALUES(:id,:s,:e,:t,:r,:d,:run)',['id'=>$event,'s'=>$id,'e'=>$e['event_id'],'t'=>$e['type'],'r'=>$e['resource_id'],'d'=>$e['digest'],'run'=>$run]);
            $this->q($p,'INSERT INTO connector_event_dispatches(event_id,run_id) VALUES(:e,:r)',['e'=>$event,'r'=>$run]);
            $this->audit->record($p,null,'connector.event.received','success','connector_event',$event,['source'=>$id,'reason'=>$e['type']]);
            return ['id'=>$event,'run_id'=>$run,'deduplicated'=>false];
        });
    }
    public function events(string $actor,string $target,int $limit=50,int $offset=0):array
    {
        $p=$this->db->connection();$this->access($p,$actor,$target,false);
        if ($limit<1 || $limit>100 || $offset<0 || $offset>10000) throw new HttpException(422,'Invalid pagination.','validation_failed');
        $rows=$this->q($p,"SELECT e.id,e.source_id,e.event_id,e.event_type,e.resource_id,e.received_at,e.run_id,e.dispatch_count,CASE WHEN r.cancelled_at IS NOT NULL THEN 'cancelled' WHEN r.result IS NOT NULL THEN 'succeeded' WHEN r.error_code IS NOT NULL OR j.status IN ('failed','completed') OR j.id IS NULL THEN 'dead_letter' WHEN j.status='running' THEN 'running' ELSE 'queued' END AS status,r.error_code,(SELECT jsonb_agg(jsonb_build_object('run_id',d.run_id,'dispatched_at',d.dispatched_at) ORDER BY d.dispatched_at,d.run_id) FROM connector_event_dispatches d WHERE d.event_id=e.id) AS dispatches FROM connector_events e JOIN connector_webhook_sources s ON s.id=e.source_id JOIN connector_runs r ON r.id=e.run_id LEFT JOIN queue_jobs j ON j.id=r.id WHERE s.target_id=:t ORDER BY e.received_at DESC,e.id LIMIT $limit OFFSET $offset",['t'=>$target])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {$row['dispatches']=json_decode($row['dispatches'],true,16,JSON_THROW_ON_ERROR);}
        return $rows;
    }
    public function redrive(string $actor,string $target,string $id,string $grant):array
    {
        $grant=CredentialInput::uuid($grant);
        return $this->db->transaction(function(PDO $p)use($actor,$target,$id,$grant):array {
            $this->access($p,$actor,$target,true);
            $e=$this->q($p,"SELECT e.*,s.target_id,s.revoked_at,r.finished_at,r.result,r.error_code,r.cancelled_at,j.status AS queue_status FROM connector_events e JOIN connector_webhook_sources s ON s.id=e.source_id JOIN connector_runs r ON r.id=e.run_id LEFT JOIN queue_jobs j ON j.id=r.id WHERE e.id=:id FOR UPDATE OF e,s",['id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);
            if (!$e || $e['target_id']!==$target || $e['revoked_at']!==null) $this->missing();
            if ($e['result']!==null || ($e['cancelled_at']===null && $e['error_code']===null && !in_array($e['queue_status'],['failed','completed',null],true)) || (int)$e['dispatch_count']>=10) throw new HttpException(409,'Event cannot be redriven.','conflict');
            $g=$this->execution->eventGrant($p,$actor,$target,$grant);$attempt=(int)$e['dispatch_count']+1;
            $run=$this->execution->dispatchEvent($p,$g,$id,$attempt)['id'];
            $this->q($p,'UPDATE connector_events SET run_id=:r,dispatch_count=:n WHERE id=:id',['r'=>$run,'n'=>$attempt,'id'=>$id]);
            $this->q($p,'INSERT INTO connector_event_dispatches(event_id,run_id) VALUES(:e,:r)',['e'=>$id,'r'=>$run]);
            $this->audit->record($p,$actor,'connector.event.redriven','success','connector_event',$id,['source'=>$e['source_id'],'count'=>$attempt]);
            return ['id'=>$id,'run_id'=>$run,'dispatch_count'=>$attempt];
        });
    }
}
