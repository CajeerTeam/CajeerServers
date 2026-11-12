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
use Cajeer\Servers\Integration\ConnectorRegistry;
use Throwable;

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
    ) {}

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
        $items=[];
        try {
            $this->access->consumePendingForAsset($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],
                $revision,function(string $secret,string $type) use($target,&$items):void {
                    $items=$this->execute($target,'validate_rotation',$type,$secret)->items;
                },$requestId);
        } catch (Throwable $e) {
            if ($e instanceof HttpException) throw $e;
            throw new HttpException(502,'Pending credential failed remote connector validation.','connector_validation_failed');
        }
        $proof=$this->versions->markValidated($actor,$target['credential_id'],$revision,
            $target['credential_version'],$targetId,$version,$requestId);
        return ['validated'=>true,'connector_id'=>$targetId,'credential_id'=>$target['credential_id'],
            'secret_version'=>$revision,'discovered'=>count($items),
            'required_targets'=>$proof['required'],'validated_targets'=>$proof['validated']];
    }

    /** @param array<string,mixed> $principal */
    public function run(array $principal, string $targetId, int $version, bool $sync, ?string $requestId=null): array
    {
        $actor=$principal['user']['id']??null;
        if (!is_string($actor)) throw new HttpException(403,'Invalid session.','session_required');
        $operation=$sync?'sync':'inspect';
        $target=$this->bindings->runtimeContext($actor,$targetId,$version,$operation);
        // The same target lock as background execution prevents manual sync racing a worker snapshot.
        $pdo=$this->db->connection();
        $lock=$pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(:id,4202))');
        $lock->execute(['id'=>$target['id']]);
        if (!in_array($lock->fetchColumn(),[true,'t','1',1],true)) throw new HttpException(409,'Connector already executing.','connector_busy');
        try {
            return $this->runAuthorized($actor,$target,$operation,
                fn(callable $consumer)=>$this->access->consumeForAsset($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],$consumer,$requestId),
                $requestId);
        } finally {
            $unlock=$pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:id,4202))'); $unlock->execute(['id'=>$target['id']]);
        }
    }

    /** Internal worker entry. Authorization and result fencing are supplied by the service grant broker. */
    public function runAuthorized(string $actor,array $target,string $operation,callable $consume,?string $requestId=null,
                                  ?callable $guard=null,?callable $complete=null):array
    {
        if (!in_array($operation,['inspect','sync','health'],true)) throw new HttpException(422,'Unsupported background operation.','validation_failed');
        $targetId=$target['id']; $version=$target['version']; $sync=$operation==='sync';
        $items=[]; $started=microtime(true);
        try {
            $consume(function(string $secret,string $type) use ($target,&$items,$operation):void {
                $items=$this->execute($target,$operation,$type,$secret)->items;
            });
        } catch (Throwable $e) {
            if ($e instanceof HttpException) throw $e;
            throw new HttpException(502,'Connector discovery failed. Check the remote API and trusted TLS CA.','connector_failed');
        }
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
        $summary=(new ConnectorResult($items))->summary($this->registry->require($target['provider'],$operation)->manifest());
        if (!$sync) {
            $result=$operation==='health' ? (new ConnectorHealth($targetId,(int)round((microtime(true)-$started)*1000)))->toArray()
                : ['connector_id'=>$targetId,'mode'=>'inspect','discovered'=>count($items),'types'=>$summary];
            return $this->db->transaction(function(PDO $pdo) use($actor,$targetId,$operation,$items,$requestId,$guard,$complete,$result):array {
                if ($guard!==null) $guard($pdo);
                $this->audit->record($pdo,$actor,$operation==='health'?'connector.health.checked':'connector.inventory.inspected',
                    'success','connector_target',$targetId,['count'=>count($items)],$requestId);
                if ($complete!==null) $complete($pdo,$result);
                return $result;
            });
        }
        // The observation import is a single PostgreSQL transaction, not per-item partial writes.
        $events=[];
        foreach ($items as $item) {
            $events[]=['workspace_id'=>$target['workspace_id'],
                'external_id'=>$target['provider'].':'.$targetId.':'.$item['external_id'],
                'event_key'=>'connector.'.bin2hex(random_bytes(16)),
                'type'=>$item['type'],'fields'=>$item['fields']];
        }
        $result=$this->db->transaction(function(PDO $pdo) use($actor,$targetId,$version,$target,$events,$requestId,$guard,$complete,$items,$summary):array {
            // Serialize against workspace membership and target edits before writing to the CMDB.
            $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
            $this->inventory->workspaceRole($pdo,$target['workspace_id'],$actor,true);
            $latest=$this->bindings->runtimeContext($actor,$targetId,$version,'sync');
            if ($latest['credential_id']!==$target['credential_id'] || $latest['credential_version']!==$target['credential_version'])
                throw new HttpException(409,'Connector was rebound or credential rotated.','conflict');
            if ($guard!==null) $guard($pdo);
            $records=$this->history->observeMany($actor,$events,$requestId,true);
            $this->audit->record($pdo,$actor,'connector.inventory.synced','success','connector_target',$targetId,
                ['count'=>count($records)],$requestId);
            $outcomes=['created'=>0,'applied'=>0,'unchanged'=>0,'conflicted'=>0,'stale'=>0];
            foreach ($records as $record) if (isset($outcomes[$record['outcome']])) $outcomes[$record['outcome']]++;
            $result=['connector_id'=>$targetId,'mode'=>'sync','discovered'=>count($items),'types'=>$summary,'outcomes'=>$outcomes];
            if ($complete!==null) $complete($pdo,$result);
            return $result;
        });
        return $result;
    }
    private function execute(array $target,string $operation,string $type,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        try {
            $driver=$this->registry->require($target['provider'],$operation);
            $manifest=$driver->manifest();
            if ($type!==$target['credential_type'] || !$manifest->accepts($type,$target['purpose']))
                throw new \RuntimeException('Credential type changed.');
            $context=new ConnectorContext($target['provider'],$target['endpoint_host'],$target['endpoint_port'],$type);
            return $this->registry->execute($context,$operation,$target['purpose'],$secret);
        } catch (Throwable) {
            // No driver exception, request header or remote response body reaches HTTP or logs.
            throw new HttpException(502,'Remote connector operation failed.','connector_failed');
        }
    }

    public function health(array $principal,string $targetId,int $version,?string $requestId=null):array
    {
        $actor=$principal['user']['id']??null;
        if (!is_string($actor)) throw new HttpException(403,'Invalid session.','session_required');
        $target=$this->bindings->runtimeContext($actor,$targetId,$version,'health');
        $started=microtime(true);
        try {
            $this->access->consumeForAsset($principal,$target['asset_id'],$target['credential_id'],$target['purpose'],
                function(string $secret,string $type) use($target):void { $this->execute($target,'health',$type,$secret); },$requestId);
        } catch (HttpException $e) { throw $e; } catch (Throwable) { throw new HttpException(502,'Remote connector health check failed.','connector_failed'); }
        $latest=$this->bindings->runtimeContext($actor,$targetId,$version,'health');
        if ($latest['credential_id']!==$target['credential_id'] || $latest['credential_version']!==$target['credential_version'])
            throw new HttpException(409,'Connector binding changed during health check.','conflict');
        $elapsed=(int)round((microtime(true)-$started)*1000);
        $this->db->transaction(fn(PDO $pdo)=>$this->audit->record($pdo,$actor,'connector.health.checked','success','connector_target',$targetId,['duration_ms'=>$elapsed],$requestId));
        return (new ConnectorHealth($targetId,$elapsed))->toArray();
    }

}
