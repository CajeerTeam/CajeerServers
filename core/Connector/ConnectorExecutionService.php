<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\{CredentialInput, CredentialsVault, SecretAccessService, VaultCrypto};
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Queue\{PostgresQueueDriver, QueuedJob, QueueReservation};
use Cajeer\Servers\Security\Rbac;
use PDO;
use Throwable;
use Cajeer\Servers\Collector\{CollectionPlan,CollectionSchedule};

/** Durable service grants, atomic queue submission and fenced connector execution. */
final class ConnectorExecutionService
{
    public const QUEUE='connectors';
    public const TYPE='connector.execute';
    public function __construct(private readonly DatabaseManager $db, private readonly ConnectorBindingService $bindings,
        private readonly InventoryRepository $inventory, private readonly Rbac $rbac, private readonly SecretAccessService $access,
        private readonly CredentialsVault $vault, private readonly VaultCrypto $crypto, private readonly AuditLogger $audit,
        private readonly ConnectorRuntimeService $runtime) {}

    private function query(PDO $pdo,string $sql,array $args=[]):\PDOStatement
    { $q=$pdo->prepare($sql); $q->execute($args); return $q; }
    private function deny():never { throw new HttpException(403,'Service grant is invalid, expired or revoked.','service_grant_denied'); }
    private function permissions(string $actor,string $operation,PDO $pdo):void
    {
        foreach (['connectors.credentials.manage','vault.credentials.use',...($operation==='sync'?['inventory.assets.create','inventory.assets.update']:($operation==='inspect'?['inventory.assets.read']:[]))] as $p)
            $this->rbac->requireUserPermission($actor,$p,$pdo);
    }
    public function issue(array $principal,string $targetId,int $version,string $operation,int $ttl,?int $interval=null):array
    {
        if (!in_array($operation,['inspect','sync','health'],true) || $ttl<60 || $ttl>2592000 || ($interval!==null && ($interval<60 || $interval>86400)))
            throw new HttpException(422,'Invalid service grant limits.','validation_failed');
        $actor=CredentialInput::uuid($principal['user']['id']??null);
        $target=$this->bindings->runtimeContext($actor,$targetId,$version,$operation);
        $this->permissions($actor,$operation,$this->db->connection());
        return $this->db->transaction(function(PDO $pdo)use($actor,$principal,$target,$operation,$ttl,$interval):array {
            $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
            // Claim the one-use MFA grant in the SAME transaction as delegation; never decrypt on issuance.
            $this->access->consumeDelegation($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],$target['credential_version']);
            $current=$this->bindings->runtimeContext($actor,$target['id'],$target['version'],$operation);
            if ($current['credential_id']!==$target['credential_id'] || $current['credential_version']!==$target['credential_version']) $this->deny();
            $this->permissions($actor,$operation,$pdo);
            $count=$this->query($pdo,'SELECT count(*) FROM connector_service_grants WHERE target_id=:id AND revoked_at IS NULL AND expires_at>clock_timestamp()',['id'=>$target['id']])->fetchColumn();
            if ((int)$count>=20) throw new HttpException(429,'Too many active service grants.','rate_limited');
            $row=$this->query($pdo,"INSERT INTO connector_service_grants(workspace_id,target_id,actor_id,asset_id,credential_id,purpose,target_version,credential_version,operation,expires_at,interval_seconds,next_due_at)
                VALUES(:ws,:target,:actor,:asset,:cred,:purpose,:tv,:cv,:op,clock_timestamp()+(:ttl*interval '1 second'),:interval,CASE WHEN CAST(:scheduled AS integer) IS NULL THEN NULL ELSE clock_timestamp() END) RETURNING *",
                ['ws'=>$target['workspace_id'],'target'=>$target['id'],'actor'=>$actor,'asset'=>$target['asset_id'],'cred'=>$target['credential_id'],'purpose'=>$target['purpose'],'tv'=>$target['version'],'cv'=>$target['credential_version'],'op'=>$operation,'ttl'=>$ttl,'interval'=>$interval,'scheduled'=>$interval])->fetch(PDO::FETCH_ASSOC);
            $this->audit->record($pdo,$actor,'connector.service_grant.issued','success','connector_target',$target['id'],['reason'=>$operation]);
            return $row;
        });
    }
    private function grant(PDO $pdo,string $id,bool $lock=false):array
    {
        $row=$this->query($pdo,'SELECT *, (revoked_at IS NULL AND expires_at>clock_timestamp()) AS live FROM connector_service_grants WHERE id=:id'.($lock?' FOR UPDATE':''),['id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);
        if (!$row) $this->deny(); return $row;
    }
    private function context(PDO $pdo,array $g):array
    {
        if (!in_array($g['live'],[true,'t','1',1],true)) $this->deny();
        $this->permissions($g['actor_id'],$g['operation'],$pdo);
        // Recheck live MFA-enabled owner and exact scoped credential. No browser session is fabricated.
        $q=$this->query($pdo,"SELECT 1 FROM users u JOIN inventory_credential_scopes s ON s.credential_id=:cred AND s.workspace_id=:ws WHERE u.id=:actor AND u.is_active AND u.two_factor_enabled",['cred'=>$g['credential_id'],'ws'=>$g['workspace_id'],'actor'=>$g['actor_id']]);
        if (!$q->fetchColumn()) $this->deny();
        $t=$this->bindings->runtimeContext($g['actor_id'],$g['target_id'],(int)$g['target_version'],$g['operation']);
        foreach (['workspace_id','asset_id','credential_id','purpose'] as $k) if ($t[$k]!==$g[$k]) $this->deny();
        if ($t['credential_version']!==(int)$g['credential_version']) $this->deny();
        return $t;
    }
    public function grants(string $actor,string $target):array
    {
        $this->rbac->requireUserPermission($actor,'connectors.credentials.read');
        $this->bindings->show($actor,$target);
        return $this->query($this->db->connection(),'SELECT g.*,p.id AS collection_plan_id FROM connector_service_grants g LEFT JOIN collection_plans p ON p.grant_id=g.id WHERE g.target_id=:id ORDER BY g.created_at DESC LIMIT 100',['id'=>$target])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function revoke(string $actor,string $target,string $grant):void
    {
        $this->rbac->requireUserPermission($actor,'connectors.credentials.manage');
        $target=CredentialInput::uuid($target); $grant=CredentialInput::uuid($grant);
        $t=$this->bindings->show($actor,$target);
        $this->db->transaction(function(PDO $pdo)use($actor,$target,$grant,$t):void {
            $this->inventory->lockWorkspace($pdo,$t['workspace_id']); $this->inventory->workspaceRole($pdo,$t['workspace_id'],$actor,true);
            $g=$this->grant($pdo,$grant,true); if ($g['target_id']!==$target) $this->deny();
            $this->query($pdo,'UPDATE connector_service_grants SET revoked_at=COALESCE(revoked_at,clock_timestamp()) WHERE id=:id',['id'=>$grant]);
            $this->query($pdo,'UPDATE collection_plans SET enabled=false WHERE grant_id=:id',['id'=>$grant]);
            $this->audit->record($pdo,$actor,'connector.service_grant.revoked','success','connector_target',$target);
        });
    }
    public function enqueue(string $actor,string $target,string $grant,string $key,?string $collector=null):array
    {
        $target=CredentialInput::uuid($target); $actor=CredentialInput::uuid($actor);
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$key)) throw new HttpException(422,'Invalid idempotency key.','validation_failed');
        return $this->db->transaction(function(PDO $pdo)use($actor,$target,$grant,$key,$collector):array {
            $t=$this->bindings->show($actor,$target); $this->inventory->lockWorkspace($pdo,$t['workspace_id']); $this->inventory->workspaceRole($pdo,$t['workspace_id'],$actor,true);
            $g=$this->grant($pdo,$grant,true); if ($g['target_id']!==$target || $g['actor_id']!==$actor) $this->deny();
            $this->rejectPlanGrant($pdo,$g['id']);
            $this->permissions($actor,$g['operation'],$pdo); $context=$this->context($pdo,$g);
            if ($collector!==null) {
                $this->rbac->requireUserPermission($actor,'inventory.assets.read',$pdo);
                $this->runtime->collectionRuntime()->validate(new CollectionPlan($collector,$g['operation']),$context);
            }
            return $this->enqueueLocked($pdo,$g,'manual:'.$key,$collector);
        });
    }
    /** Push ingestion uses a live, unscheduled inspect grant; never obtains its plaintext secret. */
    public function ingestionContext(PDO $pdo,string $actor,string $target,string $grant):array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Ingestion authorization requires transaction.');
        $g=$this->grant($pdo,$grant,true);
        if ($g['actor_id']!==$actor || $g['target_id']!==$target || $g['operation']!=='inspect' || $g['interval_seconds']!==null) $this->deny();
        $this->rejectPlanGrant($pdo,$g['id']);
        $this->rbac->requireUserPermission($actor,'ingestion.write',$pdo);
        $this->rbac->requireUserPermission($actor,'connectors.targets.read',$pdo);
        return $this->context($pdo,$g);
    }
    /** Only server-side event dispatcher calls this within its durable inbox transaction. */
    public function eventGrant(PDO $pdo,string $actor,string $target,string $grant):array
    {
        $g=$this->grant($pdo,$grant,true);
        if ($g['actor_id']!==$actor || $g['target_id']!==$target || $g['operation']!=='sync') $this->deny();
        $this->rejectPlanGrant($pdo,$g['id']);
        $this->context($pdo,$g);
        return $g;
    }
    public function dispatchEvent(PDO $pdo,array $g,string $eventId,int $attempt):array
    {
        if (!$pdo->inTransaction() || $g['operation']!=='sync' || $attempt<1 || $attempt>10) throw new \LogicException('Invalid event dispatch.');
        return $this->enqueueLocked($pdo,$g,'event:'.CredentialInput::uuid($eventId).':'.$attempt);
    }
    private function enqueueLocked(PDO $pdo,array $g,string $key,?string $collector=null,?string $planId=null,?string $redrive=null,int $delay=0):array
    {
        $existing=$this->query($pdo,'SELECT id,collector_name,collection_plan_id,redrive_of FROM connector_runs WHERE grant_id=:g AND idempotency_key=:k',['g'=>$g['id'],'k'=>$key])->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['collector_name']!==$collector || $existing['collection_plan_id']!==$planId || $existing['redrive_of']!==$redrive) throw new HttpException(409,'Idempotency key belongs to a different collection plan.','conflict');
            return ['id'=>$existing['id'],'deduplicated'=>true];
        }
        $pending=$this->query($pdo,"SELECT count(*) FROM connector_runs r JOIN queue_jobs j ON j.id=r.id WHERE r.grant_id=:g AND r.finished_at IS NULL AND j.status IN ('queued','running')",['g'=>$g['id']])->fetchColumn();
        if ((int)$pending>=10) throw new HttpException(429,'Too many pending connector runs.','rate_limited');
        $job=QueuedJob::create(self::TYPE,[],self::QUEUE,5);
        $this->query($pdo,'INSERT INTO connector_runs(id,grant_id,target_id,workspace_id,idempotency_key,collector_name,collection_plan_id,redrive_of) VALUES(:id,:g,:t,:w,:k,:collector,:plan,:redrive)',['id'=>$job->id,'g'=>$g['id'],'t'=>$g['target_id'],'w'=>$g['workspace_id'],'k'=>$key,'collector'=>$collector,'plan'=>$planId,'redrive'=>$redrive]);
        (new PostgresQueueDriver($this->db))->push($job,$delay);
        $this->audit->record($pdo,$g['actor_id'],'connector.run.enqueued','success','connector_target',$g['target_id'],['reason'=>$g['operation']]);
        return ['id'=>$job->id,'deduplicated'=>false];
    }
    /** Fixed-interval scheduler: coalesce missed ticks, atomic queue insert, no Redis dual-write. */
    public function tick(int $limit=25):int
    {
        if ($limit<1 || $limit>200) throw new \InvalidArgumentException('Invalid scheduler batch.');
        $count=0;
        for ($i=0;$i<$limit;$i++) {
            $did=$this->db->transaction(function(PDO $pdo):bool {
                // Acquire the workspace before the grant, matching issue/revoke/worker/event lock order.
                // SKIP LOCKED lets other workspaces progress instead of waiting behind a remote action.
                $candidate=$pdo->query("SELECT g.id FROM connector_service_grants g JOIN workspaces w ON w.id=g.workspace_id WHERE g.revoked_at IS NULL AND g.expires_at>clock_timestamp() AND g.next_due_at<=clock_timestamp() ORDER BY g.next_due_at,g.id FOR UPDATE OF w SKIP LOCKED LIMIT 1")->fetchColumn();
                if ($candidate===false) return false;
                $g=$this->query($pdo,"SELECT *,true AS live FROM connector_service_grants WHERE id=:id AND revoked_at IS NULL AND expires_at>clock_timestamp() AND next_due_at<=clock_timestamp() FOR UPDATE",['id'=>$candidate])->fetch(PDO::FETCH_ASSOC);
                if (!$g) return true;
                $this->query($pdo,"UPDATE connector_service_grants SET next_due_at=clock_timestamp()+interval_seconds*interval '1 second' WHERE id=:id",['id'=>$g['id']]);
                try {$this->context($pdo,$g);} catch(HttpException) {
                    $this->query($pdo,'UPDATE connector_service_grants SET revoked_at=clock_timestamp() WHERE id=:id',['id'=>$g['id']]);
                    $this->audit->record($pdo,$g['actor_id'],'connector.service_grant.invalidated','denied','connector_target',$g['target_id']);
                    return true;
                }
                $pending=$this->query($pdo,"SELECT 1 FROM connector_runs r JOIN queue_jobs j ON j.id=r.id WHERE r.grant_id=:g AND r.finished_at IS NULL AND r.cancelled_at IS NULL AND j.status IN ('queued','running') LIMIT 1",['g'=>$g['id']])->fetchColumn();
                if (!$pending && !ConnectorHealthMonitor::isOpen($pdo,$g['target_id'])) $this->enqueueLocked($pdo,$g,'schedule:'.$g['next_due_at']);
                return true;
            });
            if (!$did) break; $count++;
        }
        return $count+$this->tickPlans($limit);
    }
    private function rejectPlanGrant(PDO $pdo,string $grant):void
    {
        if ($this->query($pdo,'SELECT 1 FROM collection_plans WHERE grant_id=:g',['g'=>$grant])->fetchColumn()) $this->deny();
    }
    private function planRow(PDO $pdo,string $target,string $id,bool $lock=false):array
    {
        $row=$this->query($pdo,'SELECT p.*,g.target_id,g.workspace_id,g.actor_id,g.operation,g.expires_at,g.revoked_at FROM collection_plans p JOIN connector_service_grants g ON g.id=p.grant_id WHERE p.id=:id AND g.target_id=:t'.($lock?' FOR UPDATE OF p':''),['id'=>CredentialInput::uuid($id),'t'=>$target])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new HttpException(404,'Collection plan not found.','not_found');
        return $row;
    }
    private function planAccess(PDO $pdo,string $actor,string $target,bool $write):array
    {
        $this->rbac->requireUserPermission($actor,$write?'connectors.credentials.manage':'connectors.targets.read',$pdo);
        $this->rbac->requireUserPermission($actor,'inventory.assets.read',$pdo);
        $t=$this->bindings->show($actor,$target);
        if ($write) {$this->inventory->lockWorkspace($pdo,$t['workspace_id']);$this->inventory->workspaceRole($pdo,$t['workspace_id'],$actor,true);}
        return $t;
    }
    public function createPlan(array $principal,string $target,int $version,array $body):array
    {
        $actor=CredentialInput::uuid($principal['user']['id']??null);
        $target=CredentialInput::uuid($target);
        $name=$body['name']??null;
        if (!is_string($name)||trim($name)===''||strlen($name)>120||preg_match('/[\x00-\x1f\x7f]/',$name)) throw new HttpException(422,'Invalid plan name.','collection_invalid');
        $plan=new CollectionPlan($body['collector'],$body['operation']);
        $schedule=new CollectionSchedule($body['interval_seconds']??null,$body['cron']??null,$body['timezone']??'UTC');
        $jitter=$body['jitter_seconds']??0;
        if (!is_int($jitter)||$jitter<0||$jitter>30) throw new HttpException(422,'Invalid collection jitter.','collection_invalid');
        $schedule->next(new \DateTimeImmutable('now'));
        return $this->db->transaction(function(PDO $pdo)use($principal,$actor,$target,$version,$body,$name,$plan,$schedule,$jitter):array {
            $t=$this->planAccess($pdo,$actor,$target,true);
            $ctx=$this->bindings->runtimeContext($actor,$target,$version,$plan->operation);
            $this->runtime->collectionRuntime()->validate($plan,$ctx);
            if ((int)$this->query($pdo,'SELECT count(*) FROM collection_plans p JOIN connector_service_grants g ON g.id=p.grant_id WHERE g.target_id=:t AND g.revoked_at IS NULL AND g.expires_at>clock_timestamp()',['t'=>$target])->fetchColumn()>=20) throw new HttpException(429,'Too many collection plans.','rate_limited');
            // Fresh MFA delegation and plan creation are one transaction. Dedicated grant never has a generic schedule.
            $g=$this->issue($principal,$target,$version,$plan->operation,$body['ttl_seconds']);
            $id=$this->query($pdo,'INSERT INTO collection_plans(grant_id,name,collector_name,interval_seconds,cron_expression,timezone,next_due_at,jitter_seconds) VALUES(:g,:n,:c,:i,:cron,:tz,:due,:jitter) RETURNING id',['g'=>$g['id'],'n'=>trim($name),'c'=>$plan->collector,'i'=>$schedule->interval,'cron'=>$schedule->cron,'tz'=>$schedule->timezone,'due'=>$schedule->next(new \DateTimeImmutable((string)$pdo->query('SELECT clock_timestamp()')->fetchColumn())),'jitter'=>$jitter])->fetchColumn();
            $this->audit->record($pdo,$actor,'collection.plan.created','success','connector_target',$target,['reason'=>$plan->collector]);
            return $this->planRow($pdo,$target,$id);
        });
    }
    public function plans(string $actor,string $target,int $limit=50,int $offset=0):array
    {
        $pdo=$this->db->connection();$this->planAccess($pdo,$actor,$target,false);
        if ($limit<1||$limit>100||$offset<0||$offset>10000) throw new HttpException(422,'Invalid pagination.','validation_failed');
        $q=$pdo->prepare('SELECT p.id FROM collection_plans p JOIN connector_service_grants g ON g.id=p.grant_id WHERE g.target_id=:t ORDER BY p.created_at DESC,p.id DESC LIMIT :lim OFFSET :off');
        $q->bindValue('t',$target);$q->bindValue('lim',$limit,PDO::PARAM_INT);$q->bindValue('off',$offset,PDO::PARAM_INT);$q->execute();
        return array_map(fn($id)=>$this->planRow($pdo,$target,$id),$q->fetchAll(PDO::FETCH_COLUMN));
    }
    public function setPlanEnabled(string $actor,string $target,string $id,bool $enabled):array
    {
        $target=CredentialInput::uuid($target);$id=CredentialInput::uuid($id);
        return $this->db->transaction(function(PDO $pdo)use($actor,$target,$id,$enabled):array {
            $this->planAccess($pdo,$actor,$target,true);$p=$this->planRow($pdo,$target,$id,true);
            if ($enabled) {
                if ($p['actor_id']!==$actor) $this->deny();
                $g=$this->grant($pdo,$p['grant_id'],true);$ctx=$this->context($pdo,$g);
                $this->runtime->collectionRuntime()->validate(new CollectionPlan($p['collector_name'],$g['operation']),$ctx);
            }
            if (in_array($p['enabled'],[true,'t',1,'1'],true)===$enabled) return $p;
            $due=$enabled?(new CollectionSchedule($p['interval_seconds']===null?null:(int)$p['interval_seconds'],$p['cron_expression'],$p['timezone']))->next(new \DateTimeImmutable((string)$pdo->query('SELECT clock_timestamp()')->fetchColumn())):$p['next_due_at'];
            $this->query($pdo,'UPDATE collection_plans SET enabled=:e,next_due_at=:due WHERE id=:id',['e'=>$enabled?'true':'false','due'=>$due,'id'=>$id]);
            $this->audit->record($pdo,$actor,'collection.plan.'.($enabled?'resumed':'paused'),'success','connector_target',$target);
            return $this->planRow($pdo,$target,$id);
        });
    }
    public function runPlan(string $actor,string $target,string $id,string $key,?string $redrive=null):array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$key)) throw new HttpException(422,'Invalid idempotency key.','validation_failed');
        $target=CredentialInput::uuid($target);$id=CredentialInput::uuid($id);
        return $this->db->transaction(function(PDO $pdo)use($actor,$target,$id,$key,$redrive):array {
            $this->planAccess($pdo,$actor,$target,true);$p=$this->planRow($pdo,$target,$id,true);
            if ($p['actor_id']!==$actor) $this->deny();
            $g=$this->grant($pdo,$p['grant_id'],true);$ctx=$this->context($pdo,$g);
            $this->runtime->collectionRuntime()->validate(new CollectionPlan($p['collector_name'],$g['operation']),$ctx);
            if ($redrive!==null) {
                $redrive=CredentialInput::uuid($redrive);
                $r=$this->runRow($pdo,$redrive);
                if ($r['collection_plan_id']!==$id || !in_array($r['status'],['failed','cancelled'],true)) throw new HttpException(409,'Only failed or cancelled runs of this plan can be redriven.','conflict');
                $prior=$this->query($pdo,'SELECT id,idempotency_key FROM connector_runs WHERE redrive_of=:id',['id'=>$redrive])->fetch(PDO::FETCH_ASSOC);
                if ($prior && $prior['idempotency_key']!=='plan:'.$key) throw new HttpException(409,'Run already redriven.','conflict');
            }
            return $this->enqueueLocked($pdo,$g,'plan:'.$key,$p['collector_name'],$id,$redrive);
        });
    }
    private function tickPlans(int $limit):int
    {
        $count=0;
        for ($i=0;$i<$limit;$i++) {
            $did=$this->db->transaction(function(PDO $pdo):bool {
                $candidate=$pdo->query("SELECT p.id,g.target_id FROM collection_plans p JOIN connector_service_grants g ON g.id=p.grant_id JOIN workspaces w ON w.id=g.workspace_id WHERE p.enabled AND p.next_due_at<=clock_timestamp() ORDER BY p.next_due_at,p.id LIMIT 1 FOR UPDATE OF w SKIP LOCKED")->fetch(PDO::FETCH_ASSOC);
                if (!$candidate) return false;
                $p=$this->planRow($pdo,$candidate['target_id'],$candidate['id'],true);
                if (!in_array($p['enabled'],[true,'t',1,'1'],true) || new \DateTimeImmutable($p['next_due_at'])>new \DateTimeImmutable((string)$pdo->query('SELECT clock_timestamp()')->fetchColumn())) return true;
                $g=$this->grant($pdo,$p['grant_id'],true);
                try {
                    $ctx=$this->context($pdo,$g);$this->rbac->requireUserPermission($g['actor_id'],'inventory.assets.read',$pdo);
                    $this->runtime->collectionRuntime()->validate(new CollectionPlan($p['collector_name'],$g['operation']),$ctx);
                    $due=(new CollectionSchedule($p['interval_seconds']===null?null:(int)$p['interval_seconds'],$p['cron_expression'],$p['timezone']))->next(new \DateTimeImmutable((string)$pdo->query('SELECT clock_timestamp()')->fetchColumn()));
                } catch(HttpException $e) {
                    $this->query($pdo,'UPDATE collection_plans SET enabled=false WHERE id=:id',['id'=>$p['id']]);
                    $this->audit->record($pdo,$g['actor_id'],'collection.plan.invalidated','denied','connector_target',$g['target_id'],['reason'=>$e->errorCode]);
                    return true;
                }
                $this->query($pdo,'UPDATE collection_plans SET next_due_at=:due WHERE id=:id',['due'=>$due,'id'=>$p['id']]);
                $pending=$this->query($pdo,"SELECT 1 FROM connector_runs WHERE collection_plan_id=:id AND finished_at IS NULL LIMIT 1",['id'=>$p['id']])->fetchColumn();
                if (!$pending && !ConnectorHealthMonitor::isOpen($pdo,$g['target_id'])) $this->enqueueLocked($pdo,$g,'plan-schedule:'.$p['next_due_at'],$p['collector_name'],$p['id'],null,random_int(0,(int)$p['jitter_seconds']));
                return true;
            });
            if (!$did) break;$count++;
        }
        return $count;
    }
    private function runRow(PDO $pdo,string $id,bool $detail=true):array
    {
        $r=$this->query($pdo,"SELECT r.id,r.grant_id,r.target_id,r.workspace_id,r.collector_name,r.collection_plan_id,r.redrive_of,r.created_at,r.finished_at,r.cancelled_at,r.error_code,".($detail?"r.result":"r.result #- '{collection,observations}' #- '{collection,metric_samples}'")." AS result,g.operation,CASE WHEN r.cancelled_at IS NOT NULL THEN 'cancelled' WHEN r.result IS NOT NULL THEN 'succeeded' WHEN r.error_code IS NOT NULL OR j.status IN ('failed','completed') OR j.id IS NULL THEN 'failed' WHEN j.status='running' THEN 'running' ELSE 'queued' END AS status,COALESCE(j.attempts,0) AS attempts FROM connector_runs r JOIN connector_service_grants g ON g.id=r.grant_id LEFT JOIN queue_jobs j ON j.id=r.id WHERE r.id=:id",['id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new HttpException(404,'Connector run not found.','not_found');
        if ($r['status']==='failed' && $r['error_code']===null) $r['error_code']='lease_exhausted';
        unset($r['idempotency_key']);
        if (is_string($r['result'])) $r['result']=json_decode($r['result'],true,32,JSON_THROW_ON_ERROR);
        if ($detail && $r['collector_name']!==null) $r['attempt_history']=$this->query($pdo,'SELECT attempt,started_at,finished_at,outcome,error_code FROM collection_run_attempts WHERE run_id=:id ORDER BY attempt',['id'=>$id])->fetchAll(PDO::FETCH_ASSOC);
        return $r;
    }
    public function runs(string $actor,string $target,int $limit=50,int $offset=0,?string $planId=null):array
    {
        $this->rbac->requireUserPermission($actor,'connectors.targets.read');
        $this->rbac->requireUserPermission($actor,'inventory.assets.read');
        $this->bindings->show($actor,$target);
        if ($limit<1 || $limit>100 || $offset<0 || $offset>10000) throw new HttpException(422,'Invalid pagination.','validation_failed');
        $pdo=$this->db->connection();
        if ($planId!==null) $this->planRow($pdo,$target,$planId);
        $q=$pdo->prepare('SELECT id FROM connector_runs WHERE target_id=:id'.($planId!==null?' AND collection_plan_id=:plan':'').' ORDER BY created_at DESC,id DESC LIMIT :lim OFFSET :off');
        if ($planId!==null) $q->bindValue('plan',$planId);
        $q->bindValue('id',$target); $q->bindValue('lim',$limit,PDO::PARAM_INT); $q->bindValue('off',$offset,PDO::PARAM_INT); $q->execute();
        return array_map(fn(string $id)=>$this->runRow($pdo,$id,false),$q->fetchAll(PDO::FETCH_COLUMN));
    }
    public function status(string $actor,string $target,string $id):array
    {
        $this->rbac->requireUserPermission($actor,'connectors.targets.read');
        $this->rbac->requireUserPermission($actor,'inventory.assets.read');
        $target=CredentialInput::uuid($target);
        $this->bindings->show($actor,$target); $r=$this->runRow($this->db->connection(),$id);
        if ($r['target_id']!==$target) throw new HttpException(404,'Connector run not found.','not_found'); return $r;
    }
    public function cancel(string $actor,string $target,string $id):array
    {
        $this->rbac->requireUserPermission($actor,'connectors.credentials.manage');
        $target=CredentialInput::uuid($target); $id=CredentialInput::uuid($id);
        $t=$this->bindings->show($actor,$target);
        return $this->db->transaction(function(PDO $pdo)use($actor,$target,$id,$t):array {
            $this->inventory->lockWorkspace($pdo,$t['workspace_id']); $this->inventory->workspaceRole($pdo,$t['workspace_id'],$actor,true);
            $this->query($pdo,"SELECT id FROM queue_jobs WHERE id=:id AND queue='connectors' FOR UPDATE",['id'=>$id]);
            $row=$this->runRow($pdo,$id);
            if ($row['target_id']!==$target) throw new HttpException(404,'Connector run not found.','not_found');
            if ($row['collector_name']!==null) $this->rbac->requireUserPermission($actor,'inventory.assets.read',$pdo);
            if (!in_array($row['status'],['queued','running'],true)) return $row;
            $changed=$this->query($pdo,'UPDATE connector_runs SET cancelled_at=clock_timestamp(),finished_at=clock_timestamp() WHERE id=:id AND finished_at IS NULL',['id'=>$id]);
            if ($changed->rowCount()===1) {
                // Queued work can be removed from delivery immediately. A running worker retains its lease,
                // sees the durable cancellation at the commit fence and acknowledges it without remote retry.
                $this->query($pdo,"UPDATE queue_jobs SET status='completed',result=CAST(:result AS jsonb),finished_at=clock_timestamp(),updated_at=clock_timestamp() WHERE id=:id AND queue='connectors' AND status='queued'",['id'=>$id,'result'=>'{"status":"cancelled"}']);
                $this->audit->record($pdo,$actor,'connector.run.cancelled','success','connector_target',$target);
            }
            return $this->runRow($pdo,$id);
        });
    }
    /** Check the exact live reservation, grant and binding under locks before committing effects. */
    private function fence(PDO $pdo,QueueReservation $r,array $g):array
    {
        $this->inventory->lockWorkspace($pdo,$g['workspace_id']);
        // Revoking a webhook blocks pending runs and in-flight commits, not just future ingestion.
        $source=$this->query($pdo,'SELECT s.revoked_at FROM connector_event_dispatches d JOIN connector_events e ON e.id=d.event_id JOIN connector_webhook_sources s ON s.id=e.source_id WHERE d.run_id=:id FOR SHARE OF s',['id'=>$r->job->id])->fetch(PDO::FETCH_ASSOC);
        if ($source && $source['revoked_at']!==null) $this->deny();
        $fresh=$this->grant($pdo,$g['id'],true);
        $plan=$this->query($pdo,'SELECT id,collector_name FROM collection_plans WHERE grant_id=:g',['g'=>$g['id']])->fetch(PDO::FETCH_ASSOC);
        if ($plan) {
            $bound=$this->query($pdo,'SELECT 1 FROM connector_runs WHERE id=:id AND collection_plan_id=:p AND collector_name=:c',['id'=>$r->job->id,'p'=>$plan['id'],'c'=>$plan['collector_name']])->fetchColumn();
            if (!$bound) $this->deny();
        }
        $q=$this->query($pdo,"SELECT 1 FROM queue_jobs WHERE id=:id AND queue='connectors' AND status='running' AND lease_token=:receipt AND reserved_until>clock_timestamp() FOR UPDATE",['id'=>$r->job->id,'receipt'=>$r->receipt]);
        if (!$q->fetchColumn()) throw new HttpException(409,'Connector lease lost.','lease_lost');
        $row=$this->runRow($pdo,$r->job->id); if ($row['finished_at']!==null) throw new HttpException(409,'Run already finished.','run_finished');
        $this->query($pdo,'SELECT id FROM users WHERE id=:id FOR SHARE',['id'=>$g['actor_id']]);
        $this->query($pdo,'SELECT id FROM credentials WHERE id=:id FOR SHARE',['id'=>$g['credential_id']]);
        return $this->context($pdo,$fresh);
    }
    public function execute(QueueReservation $r):array
    {
        if ($r->job->queue!==self::QUEUE || $r->job->type!==self::TYPE || $r->job->payload!==[]) throw new \RuntimeException('Invalid connector reservation.');
        $pdo=$this->db->connection(); $row=$this->runRow($pdo,$r->job->id);
        if ($row['finished_at']!==null) return $row['result']??['status'=>$row['status']];
        $g=$this->grant($pdo,$row['grant_id']);
        $plan=$row['collector_name']===null?null:new CollectionPlan($row['collector_name'],$g['operation']);
        // Session advisory lock prevents overlapping remote operations per target, including lease recovery.
        $locked=$this->query($pdo,'SELECT pg_try_advisory_lock(hashtextextended(:id,4202))',['id'=>$g['target_id']])->fetchColumn();
        if (!in_array($locked,[true,'t','1',1],true)) throw new \RuntimeException('Connector busy.');
        $slots=[];
        try {
            $this->vault->check();
            $material=$this->db->transaction(function(PDO $pdo)use($r,$g,&$slots,$plan):array {
                $t=$this->fence($pdo,$r,$g);
                if ($plan!==null) {
                    $this->rbac->requireUserPermission($g['actor_id'],'inventory.assets.read',$pdo);
                    $this->runtime->collectionRuntime()->validate($plan,$t);
                }
                if (ConnectorHealthMonitor::isOpen($pdo,$g['target_id'])) throw new HttpException(423,'Connector circuit is open. Try after cooldown.','connector_circuit_open');
                // Session locks survive the transaction and bound remote execution across workers.
                if ($slots===[]) $slots=ConnectorConcurrency::acquire($pdo,$t);
                $cipher=$this->query($pdo,"SELECT encode(encrypted_payload,'hex') FROM credentials WHERE id=:id AND version=:v AND state='active' AND deleted_at IS NULL FOR SHARE",['id'=>$g['credential_id'],'v'=>$g['credential_version']])->fetchColumn();
                if (!is_string($cipher)) $this->deny();
                $this->audit->record($pdo,$g['actor_id'],'vault.credential.service_accessed','success','credential',$g['credential_id'],['reason'=>$g['operation']]);
                return [$t,hex2bin($cipher)];
            });
            [$t,$cipher]=$material; unset($material);
            try {
                return $this->runtime->runAuthorized($g['actor_id'],$t,$g['operation'],function(callable $consumer)use(&$cipher,$g,$t):void {
                    $plain=$this->crypto->open($cipher,$g['credential_id'],$g['workspace_id'],$t['credential_type']);
                    try {$consumer($plain,$t['credential_type']);} finally {sodium_memzero($plain);}
                },null,function(PDO $pdo)use($r,$g,$plan):void {
                    $this->fence($pdo,$r,$g);
                    if ($plan!==null) $this->rbac->requireUserPermission($g['actor_id'],'inventory.assets.read',$pdo);
                },function(PDO $pdo,array $result)use($r,$g):void {
                    // Same transaction as Inventory writes; replays after a lost queue ACK return stored outcome.
                    $this->fence($pdo,$r,$g);
                    $this->query($pdo,'UPDATE connector_runs SET result=CAST(:result AS jsonb),finished_at=clock_timestamp() WHERE id=:id',['result'=>json_encode($result,JSON_THROW_ON_ERROR),'id'=>$r->job->id]);
                },$plan);
            } finally {if (is_string($cipher)) sodium_memzero($cipher);}
        } finally {
            try {ConnectorConcurrency::release($pdo,$slots);}
            finally {$this->query($pdo,'SELECT pg_advisory_unlock(hashtextextended(:id,4202))',['id'=>$g['target_id']]);}
        }
    }
    /** Queue ACKs carry delivery metadata only. The authoritative result is connector_runs.result,
     * atomically committed with Inventory. Large previews must never exceed the generic 64 KiB ACK cap. */
    private function acknowledge(QueueReservation $r):array
    {
        $pdo=$this->db->connection();
        $row=$this->runRow($pdo,$r->job->id);
        if ($row['finished_at']===null) throw new \LogicException('Cannot acknowledge unfinished connector run.');
        $status=$row['status'];
        $q=$this->query($pdo,"UPDATE queue_jobs SET status='completed',result=CAST(:result AS jsonb),lease_token=NULL,reserved_until=NULL,finished_at=clock_timestamp(),updated_at=clock_timestamp() WHERE id=:id AND queue='connectors' AND status='running' AND lease_token=:receipt AND reserved_until>clock_timestamp()",[
            'id'=>$r->job->id,'receipt'=>$r->receipt,'result'=>json_encode(['run_id'=>$r->job->id,'status'=>$status],JSON_THROW_ON_ERROR)]);
        // A lost/expired receipt does not invalidate a committed domain outcome. The next reservation
        // or recovery sweep can acknowledge it; never touch another worker's live lease.
        return ['id'=>$r->job->id,'status'=>$status,'acknowledged'=>$q->rowCount()===1];
    }
    /** Recover committed results even after the final lease expired. Never repeat remote work. */
    private function recoverDeliveries(PDO $pdo):void
    {
        $pdo->exec(<<<'SQL'
WITH recover AS (
 SELECT j.id FROM queue_jobs j JOIN connector_runs r ON r.id=j.id
 WHERE j.queue='connectors' AND r.finished_at IS NOT NULL
 AND (j.status='failed' OR (j.status='running' AND j.reserved_until<=clock_timestamp()) OR j.status='queued')
 ORDER BY j.id LIMIT 100 FOR UPDATE OF j SKIP LOCKED
)
UPDATE queue_jobs j SET status='completed',result=jsonb_build_object('run_id',r.id,'status',CASE WHEN r.cancelled_at IS NOT NULL THEN 'cancelled' WHEN r.result IS NOT NULL THEN 'succeeded' ELSE 'failed' END),lease_token=NULL,reserved_until=NULL,finished_at=clock_timestamp(),updated_at=clock_timestamp()
FROM connector_runs r JOIN recover ON recover.id=r.id WHERE r.id=j.id
SQL);
        $pdo->exec("UPDATE connector_runs r SET finished_at=COALESCE(j.finished_at,clock_timestamp()),error_code='lease_exhausted' FROM queue_jobs j WHERE j.id=r.id AND j.queue='connectors' AND j.status IN ('failed','completed') AND r.finished_at IS NULL");
    }
    /** Dedicated PG worker with bounded lease and retry. Terminal authorization failures are not retried. */
    public function once():?array
    {
        $pdo=$this->db->connection();
        $this->recoverDeliveries($pdo);
        $queue=new PostgresQueueDriver($this->db); $r=$queue->reserve(self::QUEUE,300);
        // reserve marks exhausted leases failed; reconcile domain state immediately afterwards.
        $this->recoverDeliveries($pdo);
        if ($r===null) return null;
        try {$this->execute($r);} catch(Throwable $e) {
            $current=$this->runRow($pdo,$r->job->id);
            if ($current['finished_at']!==null) return $this->acknowledge($r);
            $remoteAuth=$e instanceof HttpException && in_array($e->errorCode,['connector_auth_failed','connector_trust_failed','connector_dependency_missing'],true);
            $remoteCode=$e instanceof HttpException && in_array($e->errorCode,[...ConnectorHealthMonitor::ERRORS,'connector_circuit_open','connector_busy','snapshot_stale','empty_inventory','conflict','precondition_failed','service_grant_denied','permission_denied','asset_unavailable','inventory_binding_required','not_found','provider_unavailable','capability_unavailable','collection_invalid','collection_result_too_large','metric_invalid','metric_quota','metric_conflict','lease_lost'],true)?$e->errorCode:null;
            // Stale config, revoked grants and empty/superseded snapshots are not transient transport failures.
            $terminal=$remoteAuth || in_array($remoteCode,['snapshot_stale','empty_inventory','conflict','precondition_failed','service_grant_denied','permission_denied','asset_unavailable','inventory_binding_required','not_found','provider_unavailable','capability_unavailable','collection_invalid','collection_result_too_large','metric_invalid','metric_quota','metric_conflict','connector_circuit_open'],true)
                || ($remoteCode!==null && ConnectorHealthMonitor::isOpen($pdo,$current['target_id']))
                || ($e instanceof HttpException && $e->status<500 && $e->status!==429 && $e->status!==409);
            if ($terminal || $r->attempt >= $r->job->maxAttempts) {
                $this->db->transaction(function(PDO $pdo)use($r,$terminal,$remoteCode):void {
                    $q=$this->query($pdo,"SELECT 1 FROM queue_jobs WHERE id=:id AND lease_token=:receipt AND status='running' AND reserved_until>clock_timestamp() FOR UPDATE",['id'=>$r->job->id,'receipt'=>$r->receipt]);
                    if (!$q->fetchColumn()) return;
                    $code=$remoteCode??($terminal?'authorization_denied':'execution_failed');
                    $changed=$this->query($pdo,'UPDATE connector_runs SET finished_at=clock_timestamp(),error_code=:code WHERE id=:id AND finished_at IS NULL',['id'=>$r->job->id,'code'=>$code]);
                    if ($changed->rowCount()===1) {
                        $run=$this->runRow($pdo,$r->job->id); $grant=$this->grant($pdo,$run['grant_id']);
                        $this->audit->record($pdo,$grant['actor_id'],'connector.run.failed','failure','connector_target',$grant['target_id'],['reason'=>$code]);
                    }
                });
                $row=$this->runRow($pdo,$r->job->id);
                if ($row['finished_at']!==null) return $this->acknowledge($r);
                return ['id'=>$r->job->id,'status'=>'lease_lost','acknowledged'=>false];
            }
            // Only release a live receipt. Lease loss is not a failure of the next reservation.
            $released=$this->query($pdo,"UPDATE queue_jobs SET status='queued',available_at=clock_timestamp()+CAST(:delay AS integer)*interval '1 second',last_error=:error,lease_token=NULL,reserved_until=NULL,updated_at=clock_timestamp() WHERE id=:id AND queue='connectors' AND status='running' AND lease_token=:receipt AND reserved_until>clock_timestamp()",['id'=>$r->job->id,'receipt'=>$r->receipt,'delay'=>min(300,15*2**$r->attempt),'error'=>$remoteCode??'execution_failed']);
            return ['id'=>$r->job->id,'status'=>$released->rowCount()===1?'retry_scheduled':'lease_lost'];
        }
        return $this->acknowledge($r);
    }
}
