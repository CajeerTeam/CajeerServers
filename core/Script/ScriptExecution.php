<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Collector\CollectionPlan;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorRuntimeService};
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Durable, one-shot script jobs. The web process never invokes an interpreter. */
final class ScriptExecution
{
    public function __construct(private readonly DatabaseManager $db,private readonly ScriptRegistry $registry,
        private readonly ScriptRunner $runner,private readonly ConnectorRuntimeService $runtime,
        private readonly ConnectorBindingService $bindings,private readonly AuditLogger $audit,private readonly ConfigRepository $config) {}
    private function q(PDO $p,string $sql,array $args=[]):\PDOStatement {$s=$p->prepare($sql);$s->execute($args);return $s;}
    private function enabled():void {if($this->config->get('scripts.enabled')!==true)throw new HttpException(503,'Script execution is disabled.','script_execution_disabled');}
    private static function publicRow(array $r):array
    {unset($r['session_id'],$r['scope'],$r['request_hash'],$r['idempotency_key'],$r['parameters']);if(isset($r['result'])&&is_string($r['result']))$r['result']=json_decode($r['result'],false,64,JSON_THROW_ON_ERROR);return $r;}
    private function row(PDO $p,string $id,bool $lock=false):array
    { $r=$this->q($p,'SELECT * FROM script_executions WHERE id=:id'.($lock?' FOR UPDATE':''),['id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new HttpException(404,'Script execution not found.','not_found');return $r; }
    private function scope(string $actor,array $version):array
    {
        if($version['target_id']===null)return [];
        $revision=null;foreach($version['manifest']['target_revisions'] as $r)if($r['id']===$version['target_id'])$revision=(int)$r['revision'];
        if($revision===null)ScriptVersion::invalid();
        $t=$this->bindings->runtimeContext($actor,$version['target_id'],$revision,'inspect');
        return array_intersect_key($t,array_flip(['id','workspace_id','version','asset_id','credential_id','credential_version','purpose','provider']));
    }
    public function enqueue(array $principal,string $ws,string $script,string $version,array $input,string $key):array
    {
        $this->enabled();if(!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$key)||!in_array($input['mode']??null,['execute','preview'],true))ScriptVersion::invalid();
        $this->registry->limitExecution($principal);
        return $this->db->transaction(function(PDO $p)use($principal,$ws,$script,$version,$input,$key){
            $v=$this->registry->executionVersion($p,$principal,$ws,$script,$version,$input);
            $actor=$v['actor_id'];$channel=ScriptExecutionPolicy::channel($v['manifest'],$v['source']);
            $hash=hash('sha256',ScriptVersion::bytes(['script'=>$script,'version'=>$version,'target'=>$v['target_id'],'parameters'=>$v['parameters'],'mode'=>$input['mode']]));
            $existing=$this->q($p,'SELECT * FROM script_executions WHERE workspace_id=:w AND actor_id=:a AND idempotency_key=:k',['w'=>$ws,'a'=>$actor,'k'=>$key])->fetch(PDO::FETCH_ASSOC);
            if($existing){if(!hash_equals($existing['request_hash'],$hash))throw new HttpException(409,'Idempotency key already used.','script_conflict');return self::publicRow($existing);}
            // The scope is signed to a target revision and pinned to the current credential revision as well.
            $scope=$this->scope($actor,$v);
            if($channel==='connector') {
                $grant=$this->q($p,'SELECT 1 FROM vault_use_grants WHERE session_id=:s AND actor_id=:a AND workspace_id=:w AND asset_id=:asset AND credential_id=:c AND credential_version=:cv AND purpose=:purpose AND consumed_at IS NULL AND expires_at>clock_timestamp()',
                    ['s'=>$principal['id'],'a'=>$actor,'w'=>$ws,'asset'=>$scope['asset_id'],'c'=>$scope['credential_id'],'cv'=>$scope['credential_version'],'purpose'=>$scope['purpose']])->fetchColumn();
                if(!$grant)throw new HttpException(403,'Authorize the connector credential through Vault first.','step_up_required');
            }
            if((int)$this->q($p,"SELECT count(*) FROM script_executions WHERE workspace_id=:w AND state IN ('queued','running') AND (state='running' OR expires_at>clock_timestamp())",['w'=>$ws])->fetchColumn()>=10)throw new HttpException(429,'Too many pending script executions.','rate_limited');
            $this->registry->executionVersion($p,$principal,$ws,$script,$version,$input,true);
            $r=$this->q($p,'INSERT INTO script_executions(workspace_id,script_id,version_id,actor_id,session_id,target_id,scope,parameters,mode,channel,idempotency_key,request_hash,timeout_seconds) VALUES(:w,:s,:v,:a,:session,:target,CAST(:scope AS jsonb),CAST(:params AS jsonb),:mode,:channel,:k,:hash,:timeout) RETURNING *',
                ['w'=>$ws,'s'=>$script,'v'=>$version,'a'=>$actor,'session'=>$principal['id'],'target'=>$v['target_id'],'scope'=>json_encode((object)$scope,JSON_THROW_ON_ERROR),'params'=>json_encode((object)$v['parameters'],JSON_THROW_ON_ERROR),'mode'=>$input['mode'],'channel'=>$channel,'k'=>$key,'hash'=>$hash,'timeout'=>$v['manifest']['timeout_seconds']])->fetch(PDO::FETCH_ASSOC);
            $this->audit->record($p,$actor,'script.execution.queued','success','script_execution',$r['id'],['reason'=>$r['mode'],'source'=>$channel]);return self::publicRow($r);
        });
    }
    public function history(array $principal,string $ws,string $script,string $version,int $offset=0):array
    {
        $p=$this->db->connection();$actor=$this->registry->executionHistoryAccess($p,$principal,$ws);
        if($offset<0||$offset>10000)ScriptVersion::invalid();
        return $this->q($p,"SELECT id,script_id,version_id,target_id,mode,channel,state,created_at,started_at,finished_at,error_code FROM script_executions WHERE workspace_id=:w AND actor_id=:a AND script_id=:s AND version_id=:v ORDER BY created_at DESC,id LIMIT 50 OFFSET $offset",['w'=>$ws,'a'=>$actor,'s'=>$script,'v'=>$version])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function detail(array $principal,string $ws,string $script,string $version,string $id,bool $cancel=false):array
    {
        return $this->db->transaction(function(PDO $p)use($principal,$ws,$script,$version,$id,$cancel){
            $actor=$this->registry->executionHistoryAccess($p,$principal,$ws);$r=$this->row($p,$id,$cancel);
            if($r['workspace_id']!==$ws||$r['actor_id']!==$actor||$r['script_id']!==$script||$r['version_id']!==$version)throw new HttpException(404,'Script execution not found.','not_found');
            if($cancel&&in_array($r['state'],['queued','running'],true)){
                $this->q($p,"UPDATE script_executions SET state='cancelled',finished_at=clock_timestamp(),error_code='script_cancelled' WHERE id=:id",['id'=>$id]);
                $this->audit->record($p,$actor,'script.execution.cancelled','success','script_execution',$id);$r=$this->row($p,$id);
            }
            return self::publicRow($r);
        });
    }
    private function principal(array $r):array {return ['mode'=>'session','id'=>$r['session_id'],'user'=>['id'=>$r['actor_id']],'mfa_verified'=>true];}
    private function verify(PDO $p,array $r):array
    {
        $this->enabled();$v=$this->registry->executionVersion($p,$this->principal($r),$r['workspace_id'],$r['script_id'],$r['version_id'],['target_id'=>$r['target_id'],'parameters'=>json_decode($r['parameters'],true,32,JSON_THROW_ON_ERROR)]);
        $now=$this->row($p,$r['id'],true);
        if($now['state']!=='running'||!$this->q($p,'SELECT 1 FROM script_executions WHERE id=:id AND deadline_at>clock_timestamp()',['id'=>$r['id']])->fetchColumn())throw new HttpException(409,'Script execution no longer active.','script_cancelled');
         $current=$this->scope($r['actor_id'],$v);$expected=json_decode($r['scope'],true,32,JSON_THROW_ON_ERROR);ksort($current);ksort($expected);
        if(ScriptExecutionPolicy::channel($v['manifest'],$v['source'])!==$r['channel'] || $current!==$expected)throw new HttpException(409,'Execution scope changed.','script_conflict');
        return $v;
    }
    private function finish(PDO $p,array $r,array $result):void
    {
        $this->verify($p,$r);$this->q($p,"UPDATE script_executions SET state='succeeded',result=CAST(:result AS jsonb),finished_at=clock_timestamp() WHERE id=:id AND state='running'",['id'=>$r['id'],'result'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $this->audit->record($p,$r['actor_id'],'script.execution.succeeded','success','script_execution',$r['id']);
    }
    private function execute(array $r):void
    {
        $deadline=hrtime(true)+((int)$r['timeout_seconds'])*1_000_000_000;
        $guard=function()use($r,$deadline):void{if(hrtime(true)>=$deadline)throw new HttpException(503,'Script timed out.','script_timeout');$this->db->transaction(fn(PDO $p)=>$this->verify($p,$r));};
        $v=$this->db->transaction(fn(PDO $p)=>$this->verify($p,$r));
        if($r['channel']==='sandbox') {
            $result=$this->runner->run($v['source'],$v['manifest'],$v['parameters'],$guard);
            $guard();$this->db->transaction(fn(PDO $p)=>$this->finish($p,$r,$result));return;
        }
        $scope=json_decode($r['scope'],true,32,JSON_THROW_ON_ERROR);
        $plan=new CollectionPlan($v['manifest']['provider'].'.inventory','inspect');
        $this->runtime->run($this->principal($r),$r['target_id'],(int)$scope['version'],false,null,$plan,
            function(PDO $p)use($r,$deadline):void{if(hrtime(true)>=$deadline)throw new HttpException(503,'Script timed out.','script_timeout');$this->verify($p,$r);},
            function(PDO $p,array $result)use($r,$v,$deadline):void{
                if(hrtime(true)>=$deadline)throw new HttpException(503,'Script timed out.','script_timeout');
                $out=$result['collection']??null;$bytes=strlen(json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                if($out===null||$bytes>$v['manifest']['output_limit_bytes'])throw new HttpException(422,'Script output exceeds signed limit.','script_output_limit');
                // The remote snapshot was already validated by ConnectorRegistry. Keep its trusted
                // identity and completeness; do not reinterpret it as user-authored observations.
                $normalized=['schema_version'=>1,'schema'=>'observations','complete'=>$out['complete'],'count'=>$out['count'],'observations'=>$out['observations']];
                $this->finish($p,$r,['output'=>$out,'output_bytes'=>$bytes,'exit_code'=>0,'normalized'=>$normalized]);
            },$scope);
    }
    /** Remove terminal history only. Active jobs and the independent audit log are untouched. */
    public function retain(int $limit=1000):array
    {
        if($limit<1||$limit>5000)throw new \InvalidArgumentException('Script retention limit 1..5000.');
        $days=$this->config->get('scripts.history_retention_days',90);
        if(!is_int($days)||$days<7||$days>3650)throw new \RuntimeException('Invalid script history retention.');
        return $this->db->transaction(fn(PDO $p)=>['deleted'=>$this->q($p,"DELETE FROM script_executions WHERE id IN (SELECT id FROM script_executions WHERE state IN ('succeeded','failed','cancelled') AND finished_at<clock_timestamp()-(:days*interval '1 day') ORDER BY finished_at,id LIMIT $limit FOR UPDATE SKIP LOCKED)",['days'=>$days])->rowCount(),'retention_days'=>$days]);
    }
    /** One dedicated worker at a time. Never retry an abandoned script, even if outcome is unknown. */
    public function work(int $limit=20):int
    {
        $this->enabled();if(PHP_SAPI!=='cli'||!function_exists('pcntl_alarm')||!function_exists('posix_geteuid')||posix_geteuid()===0||$limit<1||$limit>100)throw new \RuntimeException('Run script worker as a dedicated non-root CLI user with pcntl.');
        $p=$this->db->connection();if(!in_array($p->query('SELECT pg_try_advisory_lock(735193031)')->fetchColumn(),[true,'t',1,'1'],true))return 0;
        $count=0;$oldAsync=pcntl_async_signals(true);$oldHandler=pcntl_signal_get_handler(SIGALRM);
        try {
            $p->exec("UPDATE script_executions SET state='failed',finished_at=clock_timestamp(),error_code='script_worker_lost' WHERE state='running'");
            $p->exec("UPDATE script_executions SET state='failed',finished_at=clock_timestamp(),error_code='script_expired' WHERE state='queued' AND expires_at<=clock_timestamp()");
            while($count<$limit){
                $r=$this->db->transaction(fn(PDO $p)=>$p->query("WITH next AS (SELECT id FROM script_executions WHERE state='queued' AND expires_at>clock_timestamp() ORDER BY created_at,id LIMIT 1 FOR UPDATE SKIP LOCKED) UPDATE script_executions e SET state='running',started_at=clock_timestamp(),deadline_at=clock_timestamp()+(e.timeout_seconds+15)*interval '1 second' FROM next WHERE e.id=next.id RETURNING e.*")->fetch(PDO::FETCH_ASSOC));
                if(!$r)break;$count++;
                try {
                    pcntl_signal(SIGALRM,static function(){throw new HttpException(503,'Script timed out.','script_timeout');});pcntl_alarm((int)$r['timeout_seconds']+2);
                    $this->execute($r);
                } catch(\Throwable $e) {
                    pcntl_alarm(0);$code=$e instanceof HttpException?$e->errorCode:'script_execution_failed';if(!preg_match('/^[a-z0-9_]{1,80}$/D',$code))$code='script_execution_failed';
                    $this->db->transaction(function(PDO $p)use($r,$code){$changed=$this->q($p,"UPDATE script_executions SET state='failed',finished_at=clock_timestamp(),error_code=:code WHERE id=:id AND state='running'",['id'=>$r['id'],'code'=>$code])->rowCount();if($changed)$this->audit->record($p,$r['actor_id'],'script.execution.failed','failure','script_execution',$r['id'],['reason'=>$code]);});
                } finally {pcntl_alarm(0);}
            }
        } finally {pcntl_alarm(0);pcntl_signal(SIGALRM,$oldHandler);pcntl_async_signals($oldAsync);$p->query('SELECT pg_advisory_unlock(735193031)');}
        return $count;
    }
}
