<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\SecretAccessService;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\InventoryRepository;
use PDO;
use Throwable;

/** Real, step-up authorized Proxmox discovery and CMDB reconciliation, without exposing Vault plaintext. */
final class ConnectorRuntimeService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly ConnectorBindingService $bindings,
        private readonly SecretAccessService $access,
        private readonly HistoryService $history,
        private readonly AuditLogger $audit,
        private readonly ProxmoxConnector $proxmox,
        private readonly ?string $caFile = null,
    ) {}

    /** @param array<string,mixed> $principal */
    public function run(array $principal, string $targetId, int $version, bool $sync, ?string $requestId=null): array
    {
        $actor=$principal['user']['id']??null;
        if (!is_string($actor)) throw new HttpException(403,'Invalid session.','session_required');
        $target=$this->bindings->runtimeContext($actor,$targetId,$version);
        // The Vault grants authorization only once, for this exact asset, credential and purpose.
        $items=[];
        try {
            $this->access->consumeForAsset($principal,$target['asset_id'],$target['credential_id'],'api',
                function(string $secret,string $type) use ($target,&$items):void {
                    $items=$this->proxmox->discover($target['endpoint_host'],$target['endpoint_port'],$type,$secret,$this->caFile);
                },$requestId);
        } catch (Throwable $e) {
            // Do not return endpoint errors, remote response bodies, or plaintext from any adapter.
            if ($e instanceof HttpException) throw $e;
            throw new HttpException(502,'Connector discovery failed. Check the remote API and trusted TLS CA.','connector_failed');
        }
        if ($sync && count($items)===0) {
            // An empty API result may be a temporary failure; never modify CMDB on empty discovery.
            throw new HttpException(409,'Remote inventory is empty. No changes were applied.','empty_inventory');
        }
        // Revocation/edits during remote I/O must prevent any inventory write.
        $current=$this->bindings->runtimeContext($actor,$targetId,$version);
        if ($current['credential_id']!==$target['credential_id'] || $current['asset_id']!==$target['asset_id']
            || $current['credential_version']!==$target['credential_version']) {
            throw new HttpException(409,'Connector binding changed during discovery.','conflict');
        }
        $summary=['hypervisor_node'=>0,'virtual_machine'=>0,'container'=>0];
        foreach ($items as $item) $summary[$item['type']]++;
        if (!$sync) {
            $this->db->transaction(fn(PDO $pdo) => $this->audit->record($pdo,$actor,'connector.inventory.inspected',
                'success','connector_target',$targetId,['count'=>count($items)],$requestId));
            return ['connector_id'=>$targetId,'mode'=>'inspect','discovered'=>count($items),'types'=>$summary];
        }
        // The observation import is a single PostgreSQL transaction, not per-item partial writes.
        $events=[];
        foreach ($items as $item) {
            $events[]=['workspace_id'=>$target['workspace_id'],
                'external_id'=>'proxmox:'.$targetId.':'.$item['external_id'],
                'event_key'=>'pve.'.bin2hex(random_bytes(16)),
                'type'=>$item['type'],'fields'=>$item['fields']];
        }
        $result=$this->db->transaction(function(PDO $pdo) use($actor,$targetId,$version,$target,$events,$requestId):array {
            // Serialize against workspace membership and target edits before writing to the CMDB.
            $this->inventory->lockWorkspace($pdo,$target['workspace_id']);
            $this->inventory->workspaceRole($pdo,$target['workspace_id'],$actor,true);
            $latest=$this->bindings->runtimeContext($actor,$targetId,$version);
            if ($latest['credential_id']!==$target['credential_id'] || $latest['credential_version']!==$target['credential_version'])
                throw new HttpException(409,'Connector was rebound or credential rotated.','conflict');
            $records=$this->history->observeMany($actor,$events,$requestId,true);
            $this->audit->record($pdo,$actor,'connector.inventory.synced','success','connector_target',$targetId,
                ['count'=>count($records)],$requestId);
            return $records;
        });
        $outcomes=['created'=>0,'applied'=>0,'unchanged'=>0,'conflicted'=>0,'stale'=>0];
        foreach ($result as $record) if (isset($outcomes[$record['outcome']])) $outcomes[$record['outcome']]++;
        return ['connector_id'=>$targetId,'mode'=>'sync','discovered'=>count($items),'types'=>$summary,'outcomes'=>$outcomes];
    }
}
