<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\SecretAccessService;
use Cajeer\Servers\Credentials\CredentialVersionService;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\InventoryRepository;
use PDO;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Throwable;
use Cajeer\Servers\Collector\{CollectionPlan, CollectionResult, CollectionRuntimeService, CollectorRegistry};

/** Real, step-up authorized registered-driver discovery and CMDB reconciliation, without exposing Vault plaintext. */
final class ConnectorRuntimeService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly ConnectorBindingService $bindings,
        private readonly SecretAccessService $access,
        private readonly HistoryService $history,
        private readonly AuditLogger $audit,
        private readonly ConnectorRegistry $registry,
        private readonly ?CredentialVersionService $versions = null,
        private readonly ?CollectionRuntimeService $collections = null,
    ) {}

    public function collectionRuntime(): CollectionRuntimeService
    { return $this->collections ?? new CollectionRuntimeService(CollectorRegistry::forConnectors($this->registry)); }

    private function checkPermissions(PDO $pdo,string $actor,string $operation):void
    {
        $rbac=new Rbac($this->db,$this->audit);
        foreach (['connectors.credentials.manage','vault.credentials.use',...match($operation){
            'sync'=>['inventory.assets.create','inventory.assets.update'],
            'inspect'=>['inventory.assets.read'], 'validate_rotation'=>['vault.credentials.update'],default=>[],
        }] as $scope) $rbac->requireUserPermission($actor,$scope,$pdo);
    }

    /** The remote read may outlive the authenticated browser session. Never commit after revocation. */
    private function sessionGuard(array $principal):callable
    {
        return function(PDO $pdo) use($principal):void {
            $q=$pdo->prepare("SELECT 1 FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.id=:id AND s.user_id=:actor AND s.revoked_at IS NULL AND s.expires_at>clock_timestamp() AND s.mfa_verified=true AND u.is_active AND u.two_factor_enabled FOR SHARE OF s,u");
            $q->execute(['id'=>$principal['id']??null,'actor'=>$principal['user']['id']??null]);
            if (!$q->fetchColumn()) throw new HttpException(403,'Active MFA session required.','mfa_required');
        };
    }
    private function reconciliation(): ReconciliationService
    { return new ReconciliationService($this->inventory,$this->history,$this->audit); }

    /**
     * Check a *pending* Vault rotation against the registered driver.
     * This is a read-only operation: no CMDB update or plaintext HTTP response.
     * A fresh MFA grant is spent exactly once and proof is written only after
     * the remote API has returned a valid remote response.
     *
     * @param array<string,mixed> $principal
     */
    public function validatePending(array $principal, string $targetId, int $version, int $revision,
                                    ?string $requestId=null): array
    {
        if ($revision < 1) throw new HttpException(422,'Invalid pending revision.','validation_failed');
        if ($this->versions===null) throw new HttpException(503,'Vault validation is not configured.','vault_unavailable');
        $actor=$principal['user']['id']??null;
        if (!is_string($actor)) throw new HttpException(403,'Invalid session.','session_required');
        $target=$this->bindings->runtimeContext($actor,$targetId,$version,'validate_rotation');
        return $this->withExecutionLock($target,function()use($actor,$principal,$target,$targetId,$version,$revision,$requestId):array {
            $snapshot=$this->discoverWithGrant($target,'validate_rotation',
                fn(callable $consumer)=>$this->access->consumePendingForAsset($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],$revision,$consumer,$requestId));
            $items=$snapshot->items;
            $proof=$this->db->transaction(function(PDO $pdo)use($actor,$principal,$target,$revision,$targetId,$version,$requestId):array {
                $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
                ($this->sessionGuard($principal))($pdo);
                $this->checkPermissions($pdo,$actor,'validate_rotation');
                return $this->versions->markValidated($actor,$target['credential_id'],$revision,$target['credential_version'],$targetId,$version,$requestId);
            });
            return ['validated'=>true,'connector_id'=>$targetId,'credential_id'=>$target['credential_id'],
                'secret_version'=>$revision,'discovered'=>count($items),
                'required_targets'=>$proof['required'],'validated_targets'=>$proof['validated']];
        });
    }

    /** @param array<string,mixed> $principal */
    public function run(array $principal, string $targetId, int $version, bool $sync, ?string $requestId=null, ?CollectionPlan $plan=null, ?callable $extraGuard=null, ?callable $complete=null, ?array $expectedScope=null): array
    {
        $actor=$principal['user']['id']??null;
        if (!is_string($actor)) throw new HttpException(403,'Invalid session.','session_required');
        $operation=$sync?'sync':'inspect';
        $target=$this->bindings->runtimeContext($actor,$targetId,$version,$operation);
        if ($expectedScope!==null) foreach ($expectedScope as $key=>$value) if (($target[$key]??null)!==$value) throw new HttpException(409,'Connector execution scope changed.','conflict');
        return $this->withExecutionLock($target,function()use($actor,$principal,$target,$targetId,$operation,$requestId,$plan,$extraGuard,$complete):array {
            if (ConnectorHealthMonitor::isOpen($this->db->connection(),$targetId)) throw new HttpException(423,'Connector circuit is open. Use a manual health probe or wait for cooldown.','connector_circuit_open');
            return $this->runAuthorized($actor,$target,$operation,
                fn(callable $consumer)=>$this->access->consumeForAsset($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],$consumer,$requestId,$target['credential_version'],$extraGuard),$requestId,function(PDO $pdo)use($principal,$extraGuard):void {($this->sessionGuard($principal))($pdo);if($extraGuard!==null)$extraGuard($pdo);},$complete,$plan);
        });
    }

    /** Internal worker entry. Authorization and result fencing are supplied by the service grant broker. */
    public function runAuthorized(string $actor,array $target,string $operation,callable $consume,?string $requestId=null,
                                  ?callable $guard=null,?callable $complete=null,?CollectionPlan $plan=null):array
    {
        if (!in_array($operation,['inspect','sync','health'],true)) throw new HttpException(422,'Unsupported background operation.','validation_failed');
        if ($plan!==null) {
            if ($plan->operation!==$operation) throw new HttpException(422,'Collection operation does not match grant.','collection_invalid');
            $this->collectionRuntime()->validate($plan,$target);
            // Persisted observations are Inventory data even when the selected sink writes nothing.
            (new Rbac($this->db,$this->audit))->requireUserPermission($actor,'inventory.assets.read');
        }
        $targetId=$target['id']; $version=$target['version']; $sync=$operation==='sync';
        $this->checkPermissions($this->db->connection(),$actor,$operation);
        $started=microtime(true);
        $reconciliation=$this->reconciliation();
        $generation=$sync?$reconciliation->generation($this->db->connection()):0;
        $observedAt=($sync||$plan!==null)?(string)$this->db->connection()->query("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")->fetchColumn():'';
        try {$snapshot=$this->discoverWithGrant($target,$operation,$consume,$plan); }
        catch (HttpException $error) {
            if (in_array($error->errorCode,ConnectorHealthMonitor::ERRORS,true)) {
                try {
                    $this->db->transaction(function(PDO $pdo)use($actor,$target,$operation,$guard,$started,$error):void {
                        $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
                        if ($guard!==null) $guard($pdo);
                        $this->checkPermissions($pdo,$actor,$operation);
                        $pdo->prepare('SELECT id FROM credentials WHERE id=:id FOR SHARE')->execute(['id'=>$target['credential_id']]);
                        $latest=$this->bindings->runtimeContext($actor,$target['id'],$target['version'],$operation);
                        if ($latest['credential_id']!==$target['credential_id']||$latest['credential_version']!==$target['credential_version']) return;
                        ConnectorHealthMonitor::record($pdo,$target,(int)round((microtime(true)-$started)*1000),$error->errorCode);
                    });
                } catch (\Throwable) { /* no stale/unauthorized health write; preserve original remote failure */ }
            }
            throw $error;
        }
        $elapsed=(int)round((microtime(true)-$started)*1000);
        $items=$snapshot->items;
        $collection=$plan===null?null:(new CollectionResult($plan,$snapshot,$observedAt))->toArray();
        if ($sync && count($items)===0) {
            // An empty API result may be a temporary failure; never modify CMDB on empty discovery.
            throw new HttpException(409,'Remote inventory is empty. No changes were applied.','empty_inventory');
        }
        // Revocation/edits during remote I/O must prevent any inventory write.
        $current=$this->bindings->runtimeContext($actor,$targetId,$version,$operation);
        if ($current['credential_id']!==$target['credential_id'] || $current['asset_id']!==$target['asset_id']
            || $current['credential_version']!==$target['credential_version']) {
            throw new HttpException(409,'Connector binding changed during discovery.','conflict');
        }
        $summary=$snapshot->summary($this->registry->manifest($target['provider'],$operation));
        if (!$sync) {
            $result=$operation==='health' ? (new ConnectorHealth($targetId,$elapsed))->toArray()
                : ['connector_id'=>$targetId,'mode'=>'inspect','discovered'=>count($items),'types'=>$summary];
            if($snapshot->connection!==[])$result['connection']=$snapshot->connection;
            return $this->db->transaction(function(PDO $pdo) use($actor,$targetId,$operation,$items,$requestId,$guard,$complete,$result,$target,$snapshot,$reconciliation,$elapsed,$collection):array {
                $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
                if ($guard!==null) $guard($pdo);
                $this->checkPermissions($pdo,$actor,$operation);
                $pdo->prepare('SELECT id FROM credentials WHERE id=:id FOR SHARE')->execute(['id'=>$target['credential_id']]);
                $latest=$this->bindings->runtimeContext($actor,$targetId,$target['version'],$operation);
                if ($latest['credential_id']!==$target['credential_id'] || $latest['credential_version']!==$target['credential_version']) throw new HttpException(409,'Connector binding changed.','conflict');
                ConnectorHealthMonitor::record($pdo,$target,$elapsed,null);
                if ($operation==='inspect' && $snapshot->metricSamples===[]) $result['preview']=$reconciliation->preview($pdo,$actor,$target,$snapshot);
                if ($collection!==null && $snapshot->metricSamples!==[]) {
                    $result['metrics']=\Cajeer\Servers\Metrics\MetricStore::ingest($pdo,$target,$collection['metric_samples'],$collection['observed_at']);
                    $this->audit->record($pdo,$actor,'collection.metrics.saved','success','connector_target',$targetId,$result['metrics'],$requestId);
                }
                if ($collection!==null) $result['collection']=$collection;
                if(($collection['collector']??null)==='proxmox.cluster')ProxmoxClusterStore::save($pdo,$target,$snapshot->connection['cluster_discovery'],$collection['observed_at']);
                if(($collection['collector']??null)==='proxmox.guests')ProxmoxGuestStore::save($pdo,$target,$snapshot->connection['guest_discovery'],$collection['observed_at']);
                $this->audit->record($pdo,$actor,$operation==='health'?'connector.health.checked':'connector.inventory.inspected',
                    'success','connector_target',$targetId,['count'=>count($items)],$requestId);
                if ($complete!==null) $complete($pdo,$result);
                return $result;
            });
        }
        // The entire snapshot, identity state, observations and queue result share one commit.
        $result=$this->db->transaction(function(PDO $pdo) use($actor,$targetId,$version,$target,$requestId,$guard,$complete,$items,$summary,$snapshot,$generation,$observedAt,$reconciliation,$elapsed,$collection):array {
            $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
            $this->inventory->workspaceRole($pdo,$target['workspace_id'],$actor,true);
            $pdo->prepare('SELECT id FROM credentials WHERE id=:id FOR SHARE')->execute(['id'=>$target['credential_id']]);
            $latest=$this->bindings->runtimeContext($actor,$targetId,$version,'sync');
            if ($latest['credential_id']!==$target['credential_id'] || $latest['credential_version']!==$target['credential_version'] || $latest['asset_id']!==$target['asset_id'])
                throw new HttpException(409,'Connector was rebound or credential rotated.','conflict');
            if ($guard!==null) $guard($pdo);
            $this->checkPermissions($pdo,$actor,'sync');
            ConnectorHealthMonitor::record($pdo,$target,$elapsed,null);
            $delta=$reconciliation->apply($pdo,$actor,$target,$snapshot,$generation,$observedAt,$requestId);
            $this->audit->record($pdo,$actor,'connector.inventory.synced','success','connector_target',$targetId,
                ['count'=>count($items),'complete'=>$snapshot->complete,'blocked'=>$delta['snapshot']['blocked']],$requestId);
            $result=['connector_id'=>$targetId,'mode'=>'sync','discovered'=>count($items),'types'=>$summary,...$delta];
            if ($collection!==null) $result['collection']=$collection;
            if(($collection['collector']??null)==='proxmox.cluster')ProxmoxClusterStore::save($pdo,$target,$snapshot->connection['cluster_discovery'],$observedAt);
            if(($collection['collector']??null)==='proxmox.guests')ProxmoxGuestStore::save($pdo,$target,$snapshot->connection['guest_discovery'],$observedAt);
            if($snapshot->connection!==[])$result['connection']=$snapshot->connection;
            if ($complete!==null) $complete($pdo,$result);
            return $result;
        });
        return $result;
    }
    public function proxmoxCluster(string $actor,string $targetId):array
    {
        (new Rbac($this->db,$this->audit))->requireUserPermission($actor,'inventory.assets.read');
        $target=$this->bindings->show($actor,$targetId);
        if($target['provider']!=='proxmox')throw new HttpException(404,'Proxmox target not found.','not_found');
        return ProxmoxClusterStore::read($this->db->connection(),$target);
    }
    public function proxmoxGuests(string $actor,string $targetId):array
    {
        (new Rbac($this->db,$this->audit))->requireUserPermission($actor,'inventory.assets.read');
        $target=$this->bindings->show($actor,$targetId);
        if($target['provider']!=='proxmox')throw new HttpException(404,'Proxmox target not found.','not_found');
        return ProxmoxGuestStore::read($this->db->connection(),$target);
    }
    public function metricSeries(string $actor,string $targetId,int $limit,int $offset,?string $metric):array
    {
        (new Rbac($this->db,$this->audit))->requireUserPermission($actor,'inventory.assets.read');
        $target=$this->bindings->show($actor,$targetId);
        return \Cajeer\Servers\Metrics\MetricStore::series($this->db->connection(),$target,$limit,$offset,$metric);
    }
    public function metricQuery(string $actor,string $targetId,string $series,string $from,string $to,string $aggregation,int $step):array
    {
        (new Rbac($this->db,$this->audit))->requireUserPermission($actor,'inventory.assets.read');
        $target=$this->bindings->show($actor,$targetId);
        return \Cajeer\Servers\Metrics\MetricStore::query($this->db->connection(),$target,$series,$from,$to,$aggregation,$step);
    }
    public function resources(string $actor,string $targetId,int $limit,int $offset):array
    {
        $target=$this->bindings->show($actor,$targetId);
        return $this->reconciliation()->resources($this->db->connection(),$actor,$target,$limit,$offset);
    }
    public function resolveResource(string $actor,string $targetId,int $version,string $external,int $generation,?string $assetId,?string $requestId):array
    {
        return $this->db->transaction(function(PDO $pdo)use($actor,$targetId,$version,$external,$generation,$assetId,$requestId):array {
            $target=$this->bindings->show($actor,$targetId);
            $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
            $this->inventory->workspaceRole($pdo,$target['workspace_id'],$actor,true);
            $target=$this->bindings->show($actor,$targetId);
            if ($target['version']!==$version) throw new HttpException(412,'Connector configuration changed.','precondition_failed');
            return $this->reconciliation()->resolve($pdo,$actor,$target,$external,$generation,$assetId,$requestId);
        });
    }
    /** The Vault consumer deliberately sanitizes callback failures. Preserve only the
     * already-sanitized registry outcome, not an exception trace or remote response. */
    private function discoverWithGrant(array $target,string $operation,callable $consume,?CollectionPlan $plan=null):ConnectorResult
    {
        $result=null; $failure=null;
        try {
            $consume(function(#[\SensitiveParameter] string $secret,string $type)use($target,$operation,$plan,&$result,&$failure):void {
                try { $result=$plan===null?$this->execute($target,$operation,$type,$secret):$this->collectionRuntime()->collect($plan,$target,$type,$secret); }
                catch (HttpException $e) {
                    $failure=[$e->status,$e->getMessage(),$e->errorCode];
                    throw $e; // Vault still audits the failed trusted consumer.
                }
            });
        } catch (Throwable $e) {
            if ($failure!==null) throw new HttpException(...$failure);
            if ($e instanceof HttpException) throw $e;
            throw new HttpException(502,'Remote connector operation failed.','connector_failed');
        }
        if (!$result instanceof ConnectorResult) throw new HttpException(502,'No discovery result.','connector_failed');
        return $result;
    }

    private function execute(array $target,string $operation,string $type,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        try {
            $manifest=$this->registry->manifest($target['provider'],$operation);
            if ($type!==$target['credential_type'] || !$manifest->accepts($type,$target['purpose']))
                throw new \RuntimeException('Credential type changed.');
            $context=new ConnectorContext($target['provider'],$target['endpoint_host'],$target['endpoint_port'],$type,$target['proxmox_profile']??null);
        } catch (Throwable) {
            // No driver exception, request header or remote response body reaches HTTP or logs.
            throw new HttpException(502,'Remote connector operation failed.','connector_failed');
        }
        // Registry sanitizes every driver error; keep finite transport classification for callers.
        return $this->registry->execute($context,$operation,$target['purpose'],$secret);
    }

    public function health(array $principal,string $targetId,int $version,?string $requestId=null):array
    {
        $actor=$principal['user']['id']??null;
        if (!is_string($actor)) throw new HttpException(403,'Invalid session.','session_required');
        $target=$this->bindings->runtimeContext($actor,$targetId,$version,'health');
        return $this->withExecutionLock($target,fn()=> $this->runAuthorized($actor,$target,'health',
            fn(callable $consumer)=>$this->access->consumeForAsset($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],$consumer,$requestId,$target['credential_version']),$requestId,$this->sessionGuard($principal)));
    }

    private function withExecutionLock(array $target,callable $operation):array
    {
        $pdo=$this->db->connection();$id=$target['id'];
        $lock=$pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(:id,4202))');$lock->execute(['id'=>$id]);
        if (!in_array($lock->fetchColumn(),[true,'t','1',1],true)) throw new HttpException(409,'Connector already executing.','connector_busy');
        $slots=[];
        try {
            $current=$pdo->prepare('SELECT version,state FROM connector_targets WHERE id=:id');$current->execute(['id'=>$id]);$row=$current->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['version']!==(int)$target['version']||$row['state']!=='active')throw new HttpException(412,'Connector changed before execution.','precondition_failed');
            $slots=ConnectorConcurrency::acquire($pdo,$target);return $operation();
        }
        finally {
            try {ConnectorConcurrency::release($pdo,$slots);}
            finally {$unlock=$pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:id,4202))');$unlock->execute(['id'=>$id]);}
        }
    }

}
