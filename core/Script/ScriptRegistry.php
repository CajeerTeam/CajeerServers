<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Security\{Rbac,RateLimiter,TwoFactorManager};
use PDO;

/** Workspace-scoped immutable script versions, detached signatures and approval. Never executes user code. */
final class ScriptRegistry
{
    public function __construct(private readonly DatabaseManager $db,private readonly Rbac $rbac,private readonly AuditLogger $audit,
        private readonly TwoFactorManager $mfa,private readonly RateLimiter $limiter,private readonly ScriptTemplate $templates) {}
    private function q(PDO $p,string $sql,array $args=[]): \PDOStatement {$s=$p->prepare($sql);$s->execute($args);return $s;}
    private function missing(): never {throw new HttpException(404,'Script registry resource not found.','not_found');}
    private function conflict(): never {throw new HttpException(409,'Script state or target configuration has changed.','script_conflict');}
    private function access(PDO $p,array $principal,string $ws,string $permission,bool $lock=false):string
    {
        CredentialInput::uuid($ws);$actor=CredentialInput::uuid($principal['user']['id']??null);
        if($lock&&!$this->q($p,'SELECT id FROM workspaces WHERE id=:w FOR UPDATE',['w'=>$ws])->fetchColumn())$this->missing();
        $role=$this->q($p,'SELECT wm.role FROM workspace_members wm JOIN users u ON u.id=wm.user_id WHERE wm.workspace_id=:w AND wm.user_id=:a AND u.is_active=true'.($lock?' FOR SHARE OF wm':''),['w'=>$ws,'a'=>$actor])->fetchColumn();
        if(!$role)$this->missing();$this->rbac->requirePermission($principal,$permission,$p);
        if($permission==='scripts.approve'&&$role!=='owner'||in_array($permission,['scripts.manage','scripts.execute'],true)&&!in_array($role,['owner','manager'],true))throw new HttpException(403,'Workspace role insufficient.','permission_denied');
        if($lock) {
            $live=$this->q($p,'SELECT 1 FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.id=:s AND s.user_id=:a AND u.is_active=true AND s.revoked_at IS NULL AND s.expires_at>now() AND (NOT u.two_factor_enabled OR s.mfa_verified) FOR UPDATE OF u,s',['s'=>CredentialInput::uuid($principal['id']??null),'a'=>$actor])->fetchColumn();
            if(!$live)throw new HttpException(403,'Active session required.','session_required');
        }
        return $actor;
    }
    private function stepLimit(array $principal):void
    { $actor=CredentialInput::uuid($principal['user']['id']??null);if(!$this->db->transaction(fn(PDO $p)=>$this->limiter->hit($p,'scripts.stepup',$actor,8,900)))throw new HttpException(429,'Too many verification attempts.','rate_limited',['Retry-After'=>'900']); }
    private function step(PDO $p,array $principal,string $actor,array $input):void
    {
        $password=$input['password']??null;$totp=$input['totp']??null;
        if(!is_string($password)||strlen($password)<1||strlen($password)>1024||!is_string($totp)||!preg_match('/^[0-9]{6}$/D',$totp))ScriptVersion::invalid();
        $hash=$this->q($p,'SELECT u.password_hash FROM users u JOIN auth_sessions s ON s.user_id=u.id WHERE u.id=:a AND s.id=:s AND u.is_active=true AND u.two_factor_enabled=true AND s.mfa_verified=true AND s.revoked_at IS NULL AND s.expires_at>now() FOR UPDATE OF u,s',['a'=>$actor,'s'=>CredentialInput::uuid($principal['id']??null)])->fetchColumn();
        if(!is_string($hash)||!password_verify($password,$hash)||!$this->mfa->verifyLogin($p,$actor,$totp))throw new HttpException(403,'Fresh MFA verification required.','step_up_denied');
    }
    private function script(PDO $p,string $ws,string $id,bool $lock=false):array
    { $r=$this->q($p,'SELECT * FROM scripts WHERE workspace_id=:w AND id=:id'.($lock?' FOR UPDATE':''),['w'=>$ws,'id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);if(!$r)$this->missing();$r['etag']=CredentialInput::etag($id,(int)$r['revision']);return $r; }
    private function version(PDO $p,string $ws,string $id,string $version,bool $lock=false):array
    { $r=$this->q($p,'SELECT * FROM script_versions WHERE workspace_id=:w AND script_id=:s AND id=:v'.($lock?' FOR UPDATE':''),['w'=>$ws,'s'=>CredentialInput::uuid($id),'v'=>CredentialInput::uuid($version)])->fetch(PDO::FETCH_ASSOC);if(!$r)$this->missing();return $r; }
    private function key(PDO $p,string $ws,string $id,bool $lock=false):array
    { $r=$this->q($p,'SELECT * FROM script_signing_keys WHERE workspace_id=:w AND id=:id'.($lock?' FOR UPDATE':''),['w'=>$ws,'id'=>CredentialInput::uuid($id)])->fetch(PDO::FETCH_ASSOC);if(!$r)$this->missing();return $r; }
    private function targets(PDO $p,string $ws,array $manifest):array
    {
        $revisions=[];foreach($manifest['target_ids'] as $id){$r=$this->q($p,"SELECT version FROM connector_targets WHERE id=:id AND workspace_id=:w AND provider=:p AND state='active'",['id'=>$id,'w'=>$ws,'p'=>$manifest['provider']])->fetchColumn();if($r===false)$this->conflict();$revisions[]=['id'=>$id,'revision'=>(string)$r];}return $revisions;
    }
    public function list(array $principal,string $ws,int $offset=0):array
    { $p=$this->db->connection();$this->access($p,$principal,$ws,'scripts.read');if($offset<0||$offset>10000)ScriptVersion::invalid();return $this->q($p,"SELECT id,name,description,revision,archived_at,created_at FROM scripts WHERE workspace_id=:w ORDER BY created_at DESC,id LIMIT 50 OFFSET $offset",['w'=>$ws])->fetchAll(PDO::FETCH_ASSOC); }
    public function templates(array $principal,string $ws):array {$this->access($this->db->connection(),$principal,$ws,'scripts.read');return $this->templates->all();}
    public function create(array $principal,string $ws,array $in):array
    { return $this->db->transaction(function(PDO $p)use($principal,$ws,$in){$a=$this->access($p,$principal,$ws,'scripts.manage',true);$id=$this->q($p,'INSERT INTO scripts(workspace_id,name,description,created_by) VALUES(:w,:n,:d,:a) RETURNING id',['w'=>$ws,'n'=>CredentialInput::name($in['name']??null),'d'=>CredentialInput::description($in['description']??''),'a'=>$a])->fetchColumn();$this->audit->record($p,$a,'script.created','success','script',$id);return $this->script($p,$ws,$id);}); }
    public function update(array $principal,string $ws,string $id,int $revision,array $in,bool $archive=false):array
    { return $this->db->transaction(function(PDO $p)use($principal,$ws,$id,$revision,$in,$archive){$a=$this->access($p,$principal,$ws,'scripts.manage',true);$s=$this->script($p,$ws,$id,true);if((int)$s['revision']!==$revision)throw new HttpException(412,'Script revision changed.','precondition_failed');if($s['archived_at']!==null)$this->conflict();
        if($archive)$this->q($p,'UPDATE scripts SET archived_at=clock_timestamp(),revision=revision+1 WHERE id=:id',['id'=>$id]);
        else $this->q($p,'UPDATE scripts SET name=:n,description=:d,revision=revision+1 WHERE id=:id',['id'=>$id,'n'=>CredentialInput::name($in['name']??null),'d'=>CredentialInput::description($in['description']??'')]);
        $this->audit->record($p,$a,$archive?'script.archived':'script.updated','success','script',$id);return $this->script($p,$ws,$id);}); }
    public function detail(array $principal,string $ws,string $id):array
    { $p=$this->db->connection();$this->access($p,$principal,$ws,'scripts.read');return $this->script($p,$ws,$id); }
    public function versions(array $principal,string $ws,string $id,int $offset=0):array
    { $p=$this->db->connection();$this->access($p,$principal,$ws,'scripts.read');$this->script($p,$ws,$id);if($offset<0||$offset>10000)ScriptVersion::invalid();return $this->q($p,"SELECT v.id,v.version,v.state,v.key_id,v.created_at,v.approved_at,v.revoked_at,k.revoked_at AS key_revoked_at FROM script_versions v LEFT JOIN script_signing_keys k ON k.id=v.key_id WHERE v.script_id=:s ORDER BY v.version DESC LIMIT 50 OFFSET $offset",['s'=>$id])->fetchAll(PDO::FETCH_ASSOC); }
    public function getVersion(array $principal,string $ws,string $id,string $v):array
    { $p=$this->db->connection();$this->access($p,$principal,$ws,'scripts.read');return $this->version($p,$ws,$id,$v); }
    public function addVersion(array $principal,string $ws,string $id,array $in):array
    { return $this->db->transaction(function(PDO $p)use($principal,$ws,$id,$in){$a=$this->access($p,$principal,$ws,'scripts.manage',true);if($this->script($p,$ws,$id,true)['archived_at']!==null)$this->conflict();
        if(isset($in['template'])){if(!is_string($in['template'])||array_diff(array_keys($in),['template','target_ids']))ScriptVersion::invalid();$in=$this->templates->get($in['template'])+['target_ids'=>$in['target_ids']??[]];}
        $v=$p->query('SELECT gen_random_uuid()::text')->fetchColumn();$m=ScriptVersion::manifest($ws,$id,$v,$in);$m['target_revisions']=$this->targets($p,$ws,$m);$bytes=ScriptVersion::bytes($m);
        $this->q($p,'INSERT INTO script_versions(id,workspace_id,script_id,version,source,signing_bytes,created_by) SELECT :v,:w,:s,COALESCE(max(version),0)+1,:src,:bytes,:a FROM script_versions WHERE script_id=:lookup',['v'=>$v,'w'=>$ws,'s'=>$id,'src'=>$in['source'],'bytes'=>$bytes,'a'=>$a,'lookup'=>$id]);
        $this->audit->record($p,$a,'script.version.created','success','script_version',$v);return $this->version($p,$ws,$id,$v);}); }
    public function keys(array $principal,string $ws,int $offset=0):array
    { $p=$this->db->connection();$this->access($p,$principal,$ws,'scripts.read');if($offset<0||$offset>10000)ScriptVersion::invalid();return $this->q($p,"SELECT * FROM script_signing_keys WHERE workspace_id=:w ORDER BY created_at DESC,id LIMIT 50 OFFSET $offset",['w'=>$ws])->fetchAll(PDO::FETCH_ASSOC); }
    public function addKey(array $principal,string $ws,array $in):array
    { $this->stepLimit($principal);return $this->db->transaction(function(PDO $p)use($principal,$ws,$in){$a=$this->access($p,$principal,$ws,'scripts.approve',true);$this->step($p,$principal,$a,$in);$key=ScriptVersion::hex($in['public_key']??null,32);if($this->q($p,'SELECT 1 FROM script_signing_keys WHERE workspace_id=:w AND public_key=:k',['w'=>$ws,'k'=>$key])->fetchColumn())$this->conflict();
        $r=$this->q($p,'INSERT INTO script_signing_keys(workspace_id,name,public_key,created_by) VALUES(:w,:n,:k,:a) RETURNING *',['w'=>$ws,'n'=>CredentialInput::name($in['name']??null),'k'=>$key,'a'=>$a])->fetch(PDO::FETCH_ASSOC);$this->audit->record($p,$a,'script.key.created','success','script_key',$r['id']);return $r;}); }
    public function revokeKey(array $principal,string $ws,string $id,array $in):array
    { $this->stepLimit($principal);return $this->db->transaction(function(PDO $p)use($principal,$ws,$id,$in){$a=$this->access($p,$principal,$ws,'scripts.approve',true);$k=$this->key($p,$ws,$id,true);if($k['revoked_at']!==null)$this->conflict();$this->step($p,$principal,$a,$in);$this->q($p,'UPDATE script_signing_keys SET revoked_at=clock_timestamp() WHERE id=:id',['id'=>$id]);$this->q($p,"UPDATE script_versions SET state='revoked',revoked_at=clock_timestamp() WHERE key_id=:id AND state IN ('pending_review','approved')",['id'=>$id]);$this->audit->record($p,$a,'script.key.revoked','success','script_key',$id);return $this->key($p,$ws,$id);}); }
    public function sign(array $principal,string $ws,string $id,string $v,array $in):array
    { return $this->db->transaction(function(PDO $p)use($principal,$ws,$id,$v,$in){$a=$this->access($p,$principal,$ws,'scripts.manage',true);$s=$this->script($p,$ws,$id,true);$r=$this->version($p,$ws,$id,$v,true);if($s['archived_at']!==null||$r['state']!=='draft')$this->conflict();$k=$this->key($p,$ws,CredentialInput::uuid($in['key_id']??null),true);if($k['revoked_at']!==null)$this->conflict();$sig=ScriptVersion::hex($in['signature']??null,64);ScriptVersion::verify($r['signing_bytes'],$r['source'],$k['public_key'],$sig);
        $this->q($p,"UPDATE script_versions SET state='pending_review',key_id=:k,signature=:sig WHERE id=:v",['k'=>$k['id'],'sig'=>$sig,'v'=>$v]);$this->audit->record($p,$a,'script.version.signed','success','script_version',$v);return $this->version($p,$ws,$id,$v);}); }
    private function verified(PDO $p,string $ws,array $r):array
    { $k=$this->key($p,$ws,$r['key_id']);if($k['revoked_at']!==null)$this->conflict();$m=ScriptVersion::verify($r['signing_bytes'],$r['source'],$k['public_key'],$r['signature']);if($m['workspace_id']!==$ws||$m['script_id']!==$r['script_id']||$m['version_id']!==$r['id']||$m['target_revisions']!==$this->targets($p,$ws,$m))$this->conflict();return $m; }
    public function review(array $principal,string $ws,string $id,string $v,array $in,bool $approve):array
    { $this->stepLimit($principal);return $this->db->transaction(function(PDO $p)use($principal,$ws,$id,$v,$in,$approve){$a=$this->access($p,$principal,$ws,'scripts.approve',true);$s=$this->script($p,$ws,$id,true);$r=$this->version($p,$ws,$id,$v,true);if($r['state']==='revoked'||$approve&&($s['archived_at']!==null||$r['state']!=='pending_review'))$this->conflict();$this->step($p,$principal,$a,$in);
        if($approve){$this->verified($p,$ws,$r);$this->q($p,"UPDATE script_versions SET state='approved',approved_by=:a,approved_at=clock_timestamp() WHERE id=:v",['a'=>$a,'v'=>$v]);}else $this->q($p,"UPDATE script_versions SET state='revoked',revoked_at=clock_timestamp() WHERE id=:v",['v'=>$v]);
        $this->audit->record($p,$a,$approve?'script.version.approved':'script.version.revoked','success','script_version',$v);return $this->version($p,$ws,$id,$v);}); }
    public function validate(array $principal,string $ws,string $id,string $v,array $in):array
    { return $this->db->transaction(function(PDO $p)use($principal,$ws,$id,$v,$in){$this->access($p,$principal,$ws,'scripts.execute',true);$s=$this->script($p,$ws,$id);$r=$this->version($p,$ws,$id,$v);if($s['archived_at']!==null||$r['state']!=='approved')$this->conflict();$m=$this->verified($p,$ws,$r);$target=isset($in['target_id'])?CredentialInput::uuid($in['target_id']):null;if($m['provider']==='local'?$target!==null:!in_array($target,$m['target_ids'],true))ScriptVersion::invalid();$values=$in['parameters']??[];if(!is_array($values))ScriptVersion::invalid();ScriptVersion::parameters($m,$values);return ['valid'=>true,'version_id'=>$v,'source_sha256'=>$m['source_sha256'],'execution_enabled'=>false];}); }
}
