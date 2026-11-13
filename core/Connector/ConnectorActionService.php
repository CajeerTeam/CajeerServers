<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\{CredentialInput,SecretAccessService};
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Security\Rbac;
use PDO;

/** Write operations are never service-grant operations and never retried automatically. */
final class ConnectorActionService
{
    public function __construct(private readonly DatabaseManager $db,private readonly ConnectorBindingService $bindings,
        private readonly InventoryRepository $inventory,private readonly SecretAccessService $access,
        private readonly ConnectorRegistry $registry,private readonly Rbac $rbac,private readonly AuditLogger $audit,
        private readonly bool $enabled=false) {}
    private function query(PDO $pdo,string $sql,array $args=[]):\PDOStatement
    { $s=$pdo->prepare($sql);$s->execute($args);return $s; }
    private function actor(array $p,bool $write,bool $dispatch=false):string
    {
        $this->rbac->requirePermission($p,$write?'connectors.actions.execute':'connectors.actions.read');
        $this->rbac->requirePermission($p,'connectors.targets.read');
        if ($write) {
            if ($dispatch && !$this->enabled) throw new HttpException(403,'Remote write actions are disabled.','actions_disabled');
            foreach (['connectors.credentials.manage','vault.credentials.use','inventory.assets.read'] as $scope) $this->rbac->requirePermission($p,$scope);
        }
        return $p['user']['id'];
    }
    public static function validate(array $body,string $key):void
    {
        $action=$body['action']??null;$external=$body['external_id']??null;
        $snap=is_string($action)&&is_string($external)&&ProxmoxSnapshotPolicy::matches($action,$external);
        $name=$body['snapshot']??null;$revision=$body['snapshot_revision']??null;
        $confirm=is_string($action)&&is_string($external)?$action.' '.$external:'';
        if($snap&&is_string($name))$confirm.=' '.$name;
        if(array_diff(array_keys($body),$snap?['action','external_id','confirm','snapshot','snapshot_revision']:['action','external_id','confirm'])||!is_string($action)||!is_string($external)||(!$snap&&!ProxmoxPowerPolicy::matches($action,$external))
            ||($snap&&(!ProxmoxSnapshotPolicy::name($name)||(ProxmoxSnapshotPolicy::op($action)==='create'?$revision!==null:(!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)))))
            ||($body['confirm']??null)!==$confirm||!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$key))
            throw new HttpException(422,'Action, exact resource confirmation, snapshot revision and idempotency key are required.','validation_failed');
    }
    private function driver(array $t,string $action):ConnectorActionDriver
    {
        $d=$this->registry->require($t['provider'],'inspect');
        if (!$d instanceof ConnectorActionDriver || !in_array($action,$d->actions(),true)) throw new HttpException(422,'Driver does not support this action.','capability_unavailable');
        return $d;
    }
    private function row(PDO $pdo,string $target,string $id):array
    {
        $r=$this->query($pdo,'SELECT id::text,target_id::text,workspace_id::text,actor_id::text,credential_id::text,credential_version,target_version,action,external_id,snapshot_name,snapshot_revision,status,node,remote_task,error_code,created_at::text,updated_at::text FROM connector_actions WHERE target_id=:t AND id=:id',['t'=>$target,'id'=>$id])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new HttpException(404,'Action not found.','not_found');
        return $r;
    }
    public function get(array $p,string $target,string $id):array
    { $actor=$this->actor($p,false);$this->bindings->show($actor,$target);return $this->row($this->db->connection(),$target,$id); }
    public function list(array $p,string $target,int $offset=0,?string $external=null):array
    {
        $actor=$this->actor($p,false);$this->bindings->show($actor,$target);
        if ($offset<0||$offset>10000) throw new HttpException(422,'Invalid offset.','validation_failed');
        $q=$this->query($this->db->connection(),'SELECT id::text FROM connector_actions WHERE target_id=:t'.($external===null?'':' AND external_id=:external').' ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET '.(int)$offset,['t'=>$target]+($external===null?[]:['external'=>$external]));
        return array_map(fn($id)=>$this->row($this->db->connection(),$target,$id),$q->fetchAll(PDO::FETCH_COLUMN));
    }
    private function lock(array $t,callable $fn):array
    {
        $pdo=$this->db->connection();$args=['id'=>$t['id']];
        if (!in_array($this->query($pdo,'SELECT pg_try_advisory_lock(hashtextextended(:id,4202))',$args)->fetchColumn(),[true,'t','1',1],true)) throw new HttpException(409,'Connector is busy.','connector_busy');
        $slots=[];
        try {$slots=ConnectorConcurrency::acquire($pdo,$t);return $fn();}
        finally {try {ConnectorConcurrency::release($pdo,$slots);}finally {$this->query($pdo,'SELECT pg_advisory_unlock(hashtextextended(:id,4202))',$args);}}
    }
    private function current(array $p,array $t):void
    {
        $actor=$this->actor($p,true);
        if (!$this->query($this->db->connection(),"SELECT 1 FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.id=:session AND s.user_id=:actor AND s.revoked_at IS NULL AND s.expires_at>clock_timestamp() AND s.mfa_verified=true AND u.two_factor_enabled=true AND u.is_active=true FOR SHARE OF s,u",['session'=>$p['id'],'actor'=>$actor])->fetchColumn()) throw new HttpException(403,'Active MFA session required.','mfa_required');
        $now=$this->bindings->runtimeContext($actor,$t['id'],$t['version'],'inspect');
        foreach (['credential_id','credential_version','asset_id'] as $key) if ($now[$key]!==$t[$key]) throw new HttpException(409,'Connector binding changed.','conflict');
    }
    private function resource(PDO $pdo,array $t,string $external):string
    {
        $asset=$this->query($pdo,"SELECT r.asset_id::text FROM connector_discovered_resources r JOIN inventory_assets a ON a.id=r.asset_id AND a.workspace_id=r.workspace_id AND a.deleted_at IS NULL WHERE r.target_id=:t AND r.workspace_id=:ws AND r.external_id=:e AND r.presence='seen' AND r.resource_type=:type AND a.type=r.resource_type AND r.conflict_code IS NULL",['t'=>$t['id'],'ws'=>$t['workspace_id'],'e'=>$external,'type'=>str_starts_with($external,'qemu:')?'virtual_machine':'container'])->fetchColumn();
        if (!is_string($asset)) throw new HttpException(409,'Synchronize and resolve the guest before executing an action.','resource_unavailable');
        return $asset;
    }
    private function identity(PDO $pdo,array $t,array $remote):void
    {
        if(!isset($remote['type']))return; // trusted extension drivers retain their own identity policy
        $known=$this->query($pdo,'SELECT guest_type,fingerprint FROM proxmox_guest_identity WHERE target_id=:t AND workspace_id=:w AND vmid=:v',['t'=>$t['id'],'w'=>$t['workspace_id'],'v'=>$remote['vmid']])->fetch(PDO::FETCH_ASSOC);
        if($known&&($known['guest_type']!==$remote['type']||($known['fingerprint']!==null&&$known['fingerprint']!==($remote['fingerprint']??null))))throw new HttpException(409,'Guest identity changed or cannot be verified.','guest_identity_conflict');
    }
    private function finish(array $p,array $t,string $id,string $status,?string $node,?string $task,?string $error):array
    {
        return $this->db->transaction(function(PDO $pdo)use($p,$t,$id,$status,$node,$task,$error):array {
            $this->query($pdo,'UPDATE connector_actions SET status=:s,node=:n,remote_task=:task,error_code=:e,updated_at=clock_timestamp() WHERE id=:id',['s'=>$status,'n'=>$node,'task'=>$task,'e'=>$error,'id'=>$id]);
            $this->audit->record($pdo,$p['user']['id'],'connector.action.'.$status,in_array($status,['failed','unknown'],true)?'failure':'success','connector_action',$id,['reason'=>$status]);
            return $this->row($pdo,$t['id'],$id);
        });
    }
    public function guestAccess(array $p,string $target,int $version,array $body):array
    {
        $external=$body['external_id']??null;$purpose=$body['purpose']??null;
        if(!is_string($external)||!preg_match('/^(qemu|lxc):[1-9][0-9]{0,8}$/D',$external)||!in_array($purpose,['snapshots','console'],true)||array_diff(array_keys($body),['external_id','purpose']))throw new HttpException(422,'Invalid guest access request.','validation_failed');
        $actor=$this->actor($p,true);$this->rbac->requirePermission($p,'connectors.actions.read');
        if($purpose==='console')$this->rbac->requirePermission($p,'connectors.console.open');
        $t=$this->bindings->runtimeContext($actor,$target,$version,'inspect');$driver=$this->registry->require($t['provider'],'inspect');
        if(!$driver instanceof ProxmoxConnector)throw new HttpException(422,'Guest access is unavailable for this driver.','capability_unavailable');
        return $this->lock($t,function()use($p,$t,$actor,$driver,$external,$purpose):array {
            $pdo=$this->db->connection();$asset=$this->resource($pdo,$t,$external);$result=null;
            try{$this->access->consumeForAsset($p,$t['asset_id'],$t['credential_id'],$t['purpose'],function(#[\SensitiveParameter] string $secret,string $type)use($p,$t,$driver,$external,$purpose,$asset,&$result):void {
                $this->current($p,$t);
                if($purpose==='console')$this->rbac->requirePermission($p,'connectors.console.open');
                $remote=$driver->guestAccess(new ConnectorContext($t['provider'],$t['endpoint_host'],$t['endpoint_port'],$type,$t['proxmox_profile']??null),$external,$purpose,$secret);
                $result=$this->db->transaction(function(PDO $pdo)use($p,$t,$external,$purpose,$asset,$remote):array {
                    $this->inventory->lockWorkspace($pdo,$t['workspace_id']);$this->current($p,$t);
                    if($purpose==='console')$this->rbac->requirePermission($p,'connectors.console.open',$pdo);
                    if($this->resource($pdo,$t,$external)!==$asset)throw new HttpException(409,'Guest mapping changed.','conflict');
                    $this->identity($pdo,$t,$remote);
                    $this->audit->record($pdo,$p['user']['id'],'connector.guest.'.$purpose,'success','connector_target',$t['id'],['reason'=>$external]);
                    unset($remote['fingerprint']);return $remote;
                });
            },expectedVersion:$t['credential_version']);}catch(\Throwable $e){throw $e instanceof HttpException?$e:new HttpException(502,'Live guest access unavailable.','connector_failed');}
            if(!is_array($result))throw new HttpException(502,'Live guest access unavailable.','connector_failed');return $result;
        });
    }
    public function submit(array $p,string $target,int $version,array $body,string $key):array
    {
        self::validate($body,$key);$actor=$this->actor($p,true,true);
        if(isset($body['snapshot']))$this->rbac->requirePermission($p,'connectors.snapshots.execute');$t=$this->bindings->runtimeContext($actor,$target,$version,'inspect');$driver=$this->driver($t,$body['action']);
        return $this->lock($t,function()use($p,$t,$driver,$body,$key,$actor):array {
            $pdo=$this->db->connection();$existing=$this->query($pdo,'SELECT id::text FROM connector_actions WHERE target_id=:t AND idempotency_key=:k',['t'=>$t['id'],'k'=>$key])->fetchColumn();
            if ($existing) {
                $r=$this->row($pdo,$t['id'],$existing);
                if ($r['actor_id']!==$actor||$r['action']!==$body['action']||$r['external_id']!==$body['external_id']||$r['snapshot_name']!==($body['snapshot']??null)||$r['snapshot_revision']!==($body['snapshot_revision']??null)||(int)$r['target_version']!==$t['version']) throw new HttpException(409,'Idempotency key already used.','conflict');
                return $r;
            }
            if (ConnectorHealthMonitor::isOpen($pdo,$t['id'])) throw new HttpException(423,'Connector circuit is open.','connector_circuit_open');
            $resource=$this->resource($pdo,$t,$body['external_id']);
            if ($this->query($pdo,"SELECT 1 FROM connector_actions WHERE target_id=:t AND external_id=:e AND status IN ('dispatching','submitted','unknown')",['t'=>$t['id'],'e'=>$body['external_id']])->fetchColumn()) throw new HttpException(409,'An unresolved action already exists for this guest.','action_unresolved');
            $id=null;$node=null;$task=null;$result=null;$failure=null;
            try {
                $this->access->consumeForAsset($p,$t['asset_id'],$t['credential_id'],$t['purpose'],function(#[\SensitiveParameter] string $secret,string $type)use($p,$t,$driver,$body,$key,$actor,$resource,&$id,&$node,&$task,&$result,&$failure):void {
                    try {
                        $this->current($p,$t);
                        if(isset($body['snapshot']))$this->rbac->requirePermission($p,'connectors.snapshots.execute');
                        $ctx=new ConnectorContext($t['provider'],$t['endpoint_host'],$t['endpoint_port'],$type,$t['proxmox_profile']??null);
                        $remote=isset($body['snapshot'])&&$driver instanceof ProxmoxConnector?$driver->prepareSnapshot($ctx,$body['action'],$body['external_id'],$body['snapshot'],$body['snapshot_revision']??null,$secret):$driver->prepareAction($ctx,$body['action'],$body['external_id'],$secret);
                        $node=$remote['node'];
                        // Commit durable intent before the only POST. Crash after this point is ambiguous, never retried.
                        $id=$this->db->transaction(function(PDO $pdo)use($p,$t,$body,$key,$actor,$node,$resource,$remote):string {
                            $this->inventory->lockWorkspace($pdo,$t['workspace_id']);
                            $this->query($pdo,'SELECT id FROM credentials WHERE id=:id FOR SHARE',['id'=>$t['credential_id']]);$this->current($p,$t);
                            if(isset($body['snapshot']))$this->rbac->requirePermission($p,'connectors.snapshots.execute',$pdo);
                            if ($this->resource($pdo,$t,$body['external_id'])!==$resource) throw new HttpException(409,'Guest mapping changed.','conflict');
                            $this->identity($pdo,$t,$remote);
                            $id=$this->query($pdo,"INSERT INTO connector_actions(target_id,workspace_id,actor_id,credential_id,credential_version,target_version,action,external_id,snapshot_name,snapshot_revision,idempotency_key,status,node) VALUES(:t,:w,:a,:c,:cv,:tv,:act,:ext,:snap,:rev,:k,'dispatching',:n) RETURNING id::text",['t'=>$t['id'],'w'=>$t['workspace_id'],'a'=>$actor,'c'=>$t['credential_id'],'cv'=>$t['credential_version'],'tv'=>$t['version'],'act'=>$body['action'],'ext'=>$body['external_id'],'snap'=>$body['snapshot']??null,'rev'=>$body['snapshot_revision']??null,'k'=>$key,'n'=>$node])->fetchColumn();
                            $this->audit->record($pdo,$actor,'connector.action.dispatching','success','connector_action',$id,['reason'=>$body['confirm']]);return $id;
                        });
                        // Serialize configuration/credential changes during the actual bounded remote write.
                        $task=$this->db->transaction(function(PDO $pdo)use($p,$t,$driver,$ctx,$body,$remote,$secret,$resource):string {
                            $this->inventory->lockWorkspace($pdo,$t['workspace_id']);$this->query($pdo,'SELECT id FROM credentials WHERE id=:id FOR SHARE',['id'=>$t['credential_id']]);$this->current($p,$t);
                            if(isset($body['snapshot']))$this->rbac->requirePermission($p,'connectors.snapshots.execute',$pdo);
                            if ($this->resource($pdo,$t,$body['external_id'])!==$resource) throw new HttpException(409,'Guest mapping changed.','conflict');
                            $this->identity($pdo,$t,$remote);
                            return $driver->dispatchAction($ctx,$body['action'],$remote,$secret);
                        });
                        $result=$this->finish($p,$t,$id,'submitted',$node,$task,null);
                    }catch(\Throwable $e){$failure=$e instanceof HttpException?$e:new HttpException(502,'Remote action could not be confirmed.','action_unknown');throw $e;}
                },expectedVersion:$t['credential_version']);
            }catch(\Throwable $e){
                if ($id!==null) {
                    $blocked=$task===null&&($failure instanceof ConnectorActionNotSentException||($failure instanceof HttpException&&$failure->errorCode==='guest_identity_conflict')); 
                    return $this->finish($p,$t,$id,$task!==null?'submitted':($blocked?'failed':'unknown'),$node,$task,$task!==null?null:($blocked?$failure->errorCode:'action_unknown'));
                }
                if($failure instanceof HttpException)$this->db->transaction(fn(PDO $pdo)=>$this->audit->record($pdo,$actor,'connector.action.blocked','denied','connector_target',$t['id'],['reason'=>$failure->errorCode]));
                throw $failure??($e instanceof HttpException?$e:new HttpException(502,'Action preparation failed.','connector_failed'));
            }
            return $result??throw new HttpException(502,'Action result unavailable.','action_unknown');
        });
    }
    /** Read-only collection may settle a known UPID, never dispatch or acknowledge an unknown POST.
     * Caller holds the target/workspace locks and validated the exact target and credential revisions.
     */
    public static function reconcileTasks(PDO $pdo,array $t,AuditLogger $audit,string $collectorActor):int
    {
        $scope=hash('sha256',json_encode([(int)$t['version'],$t['credential_id'],(int)$t['credential_version']],JSON_THROW_ON_ERROR));
        $q=$pdo->prepare(<<<'SQL'
UPDATE connector_actions a SET status=CASE WHEN p.state='succeeded' THEN 'succeeded' ELSE 'failed' END,
 error_code=CASE p.state WHEN 'succeeded' THEN NULL WHEN 'warning' THEN 'remote_task_warning' ELSE 'remote_task_failed' END,
 updated_at=clock_timestamp()
FROM proxmox_tasks p
WHERE a.target_id=:target AND a.workspace_id=:workspace AND a.target_version=:tv
 AND a.credential_id=:credential AND a.credential_version=:cv AND a.status='submitted'
 AND p.target_id=a.target_id AND p.workspace_id=a.workspace_id AND p.scope_hash=:scope AND p.upid=a.remote_task AND p.node=a.node
 AND p.task_type=((CASE WHEN a.external_id LIKE 'qemu:%' THEN 'qm' ELSE 'vz' END)||split_part(a.action,'.',2))
 AND p.resource_id=split_part(a.external_id,':',2) AND p.state IN ('succeeded','warning','failed')
RETURNING a.id::text,a.status,a.error_code
SQL);
        $q->execute(['target'=>$t['id'],'workspace'=>$t['workspace_id'],'tv'=>$t['version'],'credential'=>$t['credential_id'],'cv'=>$t['credential_version'],'scope'=>$scope]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r)$audit->record($pdo,$collectorActor,'connector.action.'.$r['status'],$r['status']==='succeeded'?'success':'failure','connector_action',$r['id'],['source'=>'proxmox.tasks','reason'=>$r['error_code']??'succeeded']);
        return count($rows);
    }
    public function refresh(array $p,string $target,string $id,int $version,bool $ack=false):array
    {
        $actor=$this->actor($p,true);$t=$this->bindings->runtimeContext($actor,$target,$version,'inspect');
        return $this->lock($t,function()use($p,$t,$id,$ack):array {
            $r=$this->row($this->db->connection(),$t['id'],$id);
            if ($ack) {
                if($r['snapshot_name']!==null)$this->rbac->requirePermission($p,'connectors.snapshots.execute');
                if (!in_array($r['status'],['unknown','dispatching','submitted'],true)) throw new HttpException(409,'Only unresolved actions can be acknowledged.','conflict');
                // A confirmed running UPID must not be unlocked by an acknowledgement.
                // If the original scope is gone, external verification is the only recovery path.
                $same=$r['credential_id']===$t['credential_id']&&(int)$r['credential_version']===$t['credential_version']&&(int)$r['target_version']===$t['version'];
                if($r['status']==='submitted'&&$same){
                    $remoteStatus=null;$d=$this->driver($t,$r['action']);
                    $this->access->consumeForAsset($p,$t['asset_id'],$t['credential_id'],$t['purpose'],function(#[\SensitiveParameter] string $secret,string $type)use($p,$t,$r,$d,&$remoteStatus):void {
                        $this->current($p,$t);
                        try{$remoteStatus=$d->actionStatus(new ConnectorContext($t['provider'],$t['endpoint_host'],$t['endpoint_port'],$type,$t['proxmox_profile']??null),$r['node'],$r['remote_task'],$secret);}catch(\Throwable){/* operator verified externally; task may have expired */}
                    },expectedVersion:$t['credential_version']);
                    if($remoteStatus==='submitted')throw new HttpException(409,'Remote task is still running.','action_unresolved');
                    if(in_array($remoteStatus,['succeeded','failed'],true))return $this->db->transaction(function(PDO $pdo)use($p,$t,$id,$r,$remoteStatus):array{
                        $this->inventory->lockWorkspace($pdo,$t['workspace_id']);$this->current($p,$t);
                        return $this->finish($p,$t,$id,$remoteStatus,$r['node'],$r['remote_task'],$remoteStatus==='failed'?'remote_task_failed':null);
                    });
                    return $this->db->transaction(function(PDO $pdo)use($p,$t,$id,$r):array{
                        $this->inventory->lockWorkspace($pdo,$t['workspace_id']);$this->current($p,$t);
                        return $this->finish($p,$t,$id,'acknowledged',$r['node'],$r['remote_task'],'operator_acknowledged');
                    });
                }
                return $this->db->transaction(function(PDO $pdo)use($p,$t,$id,$r):array {
                    $this->inventory->lockWorkspace($pdo,$t['workspace_id']);
                    $this->access->consumeDelegation($p,$t['asset_id'],$t['credential_id'],$t['purpose'],$t['credential_version']);
                    $this->current($p,$t);
                    return $this->finish($p,$t,$id,'acknowledged',$r['node'],$r['remote_task'],'operator_acknowledged');
                });
            }
            if ($r['status']!=='submitted') return $r;
            if ($r['credential_id']!==$t['credential_id']||(int)$r['credential_version']!==$t['credential_version']||(int)$r['target_version']!==$t['version']) throw new HttpException(409,'Original action binding changed. Verify the task externally.','conflict');
            $d=$this->driver($t,$r['action']);$status=null;
            try {$this->access->consumeForAsset($p,$t['asset_id'],$t['credential_id'],$t['purpose'],function(#[\SensitiveParameter] string $secret,string $type)use($p,$t,$r,$d,&$status):void {
                $this->current($p,$t);$status=$d->actionStatus(new ConnectorContext($t['provider'],$t['endpoint_host'],$t['endpoint_port'],$type,$t['proxmox_profile']??null),$r['node'],$r['remote_task'],$secret);
            },expectedVersion:$t['credential_version']);}catch(\Throwable $e){throw $e instanceof HttpException?$e:new HttpException(502,'Task status unavailable; action was not repeated.','connector_failed');}
            if (!in_array($status,['submitted','succeeded','failed'],true)) throw new HttpException(502,'Invalid task result.','connector_failed');
            return $this->db->transaction(function(PDO $pdo)use($p,$t,$id,$status,$r):array {
                $this->inventory->lockWorkspace($pdo,$t['workspace_id']);
                $this->current($p,$t);
                return $this->finish($p,$t,$id,$status,$r['node'],$r['remote_task'],$status==='failed'?'remote_task_failed':null);
            });
        });
    }
}
