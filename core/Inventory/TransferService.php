<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Queue\QueueDriverInterface;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Security\Rbac;
use PDO;

/** Durable queue-backed bulk ingestion reusing HistoryService's idempotent reconciliation. */
final class TransferService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly HistoryService $history,
        private readonly QueueDriverInterface $queue,
        private readonly AuditLogger $audit,
        private readonly Rbac $rbac,
    ) {}

    private static function uuid(mixed $id,string $field='id'):string
    {
        if(!is_string($id)||!InfrastructureAsset::isUuid($id))throw new HttpException(422,'Invalid '.$field.'.','validation_failed');
        return strtolower($id);
    }

    /** Preview uses the actual database identity and manual-field provenance, without writes. */
    public function preview(string $actor,string $workspace,string $format,string $content):array
    {
        $workspace=self::uuid($workspace,'workspace_id');
        $rows=TransferFormat::parse($format,$content,$workspace);
        $pdo=$this->db->connection();
        $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
        $lookup=$pdo->prepare('SELECT a.id::text,a.type,a.deleted_at::text FROM inventory_assets a WHERE a.workspace_id=:ws AND a.source=\'import\' AND a.external_id=:external');
        $manual=$pdo->prepare("SELECT field FROM inventory_field_provenance WHERE asset_id=CAST(:asset AS uuid) AND mode='manual'");
        $results=[];$counts=['create'=>0,'update'=>0,'conflict'=>0,'invalid'=>0];
        foreach($rows as $i=>$row){
            $lookup->execute(['ws'=>$workspace,'external'=>$row['external_id']]);$current=$lookup->fetch(PDO::FETCH_ASSOC);
            $status='create';
            if(!is_array($current)){
                if(!isset($row['fields']['name'])){$status='invalid';}
            }elseif($current['deleted_at']!==null||$current['type']!==$row['type']){$status='conflict';}
            else{
                $manual->execute(['asset'=>$current['id']]);
                $manualFields=$manual->fetchAll(PDO::FETCH_COLUMN);
                $status=array_intersect(array_keys($row['fields']),$manualFields)?'conflict':'update';
            }
            $counts[$status]++;
            $results[]=['row'=>$i+1,'external_id'=>$row['external_id'],'action'=>$status];
        }
        return ['format'=>$format,'workspace_id'=>$workspace,'total'=>count($rows),'summary'=>$counts,'rows'=>$results];
    }

    /** Store a bounded batch in PostgreSQL, then enqueue only its opaque UUID. */
    public function submit(string $actor,string $workspace,string $format,string $content,string $idempotencyKey,?string $requestId=null):array
    {
        $workspace=self::uuid($workspace,'workspace_id');
        if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,119}$/D',$idempotencyKey)!==1)
            throw new HttpException(422,'Invalid Idempotency-Key.','validation_failed');
        $rows=TransferFormat::parse($format,$content,$workspace);
        $payload=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $fingerprint=hash('sha256',$format."\0".$payload);
        $batch=$this->db->transaction(function(PDO $pdo)use($actor,$workspace,$format,$rows,$payload,$fingerprint,$idempotencyKey,$requestId):array{
            $this->inventory->lockWorkspace($pdo,$workspace);
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_import_batches(workspace_id,actor_id,format,idempotency_key,payload_sha256,rows_payload,row_count)
VALUES(CAST(:ws AS uuid),CAST(:actor AS uuid),:format,:key,:digest,CAST(:rows AS jsonb),:row_count)
ON CONFLICT(workspace_id,actor_id,idempotency_key) DO NOTHING RETURNING id::text
SQL);
            $stmt->execute(['ws'=>$workspace,'actor'=>$actor,'format'=>$format,'key'=>$idempotencyKey,'digest'=>$fingerprint,'rows'=>$payload,'row_count'=>count($rows)]);
            $id=$stmt->fetchColumn();
            if($id===false){
                $select=$pdo->prepare('SELECT id::text,payload_sha256 FROM inventory_import_batches WHERE workspace_id=:ws AND actor_id=:actor AND idempotency_key=:key');
                $select->execute(['ws'=>$workspace,'actor'=>$actor,'key'=>$idempotencyKey]);$existing=$select->fetch(PDO::FETCH_ASSOC);
                if(!is_array($existing)||!hash_equals($existing['payload_sha256'],$fingerprint))
                    throw new HttpException(409,'Idempotency key has a different payload.','conflict');
                return ['id'=>$existing['id'],'existing'=>true];
            }
            $this->audit->record($pdo,$actor,'inventory.import.created','success','inventory_import_batch',$id,['count'=>count($rows)],$requestId);
            return ['id'=>$id,'existing'=>false];
        });
        // Durable pending row remains available for manual dispatch if the queue is offline.
        try { $this->dispatch($actor,$workspace,$batch['id']); }
        catch (\Throwable $e) {
            // Never claim the job has been queued; return pending status with retry instruction.
            return [...$this->batch($actor,$workspace,$batch['id']),'queue_available'=>false];
        }
        return [...$this->batch($actor,$workspace,$batch['id']),'queue_available'=>true];
    }

    public function dispatch(string $actor,string $workspace,string $id):array
    {
        $workspace=self::uuid($workspace,'workspace_id');$id=self::uuid($id);
        return $this->db->transaction(function(PDO $pdo)use($actor,$workspace,$id):array{
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $stmt=$pdo->prepare('SELECT id::text,status,queue_job_id::text FROM inventory_import_batches WHERE id=:id AND workspace_id=:ws FOR UPDATE');
            $stmt->execute(['id'=>$id,'ws'=>$workspace]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new HttpException(404,'Import batch not found.','not_found');
            if(in_array($row['status'],['running','completed','completed_with_errors'],true))return ['id'=>$id,'status'=>$row['status']];
            $jobId=$row['queue_job_id']??null;
            $job=$jobId!==null ? new QueuedJob($jobId,'inventory.import',['batch_id'=>$id],'default',5)
                : QueuedJob::create('inventory.import',['batch_id'=>$id],'default',5);
            $pdo->prepare('UPDATE inventory_import_batches SET queue_job_id=CAST(:job AS uuid) WHERE id=:id')->execute(['job'=>$job->id,'id'=>$id]);
            $this->queue->push($job);
            $pdo->prepare("UPDATE inventory_import_batches SET status='enqueued',updated_at=clock_timestamp() WHERE id=:id AND status IN ('pending','enqueued')")
                ->execute(['id'=>$id]);
            return ['id'=>$id,'status'=>'enqueued','job_id'=>$job->id];
        });
    }

    /** @return array<string,mixed> No raw input rows are returned. */
    public function batch(string $actor,string $workspace,string $id):array
    {
        $workspace=self::uuid($workspace,'workspace_id');$id=self::uuid($id);
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $stmt=$pdo->prepare('SELECT id::text,workspace_id::text,format,status,row_count,processed_rows,queue_job_id::text,summary::text,created_at::text,started_at::text,finished_at::text FROM inventory_import_batches WHERE id=:id AND workspace_id=:ws');
        $stmt->execute(['id'=>$id,'ws'=>$workspace]);$batch=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($batch))throw new HttpException(404,'Import batch not found.','not_found');
        $batch['summary']=json_decode($batch['summary'],true,16,JSON_THROW_ON_ERROR);
        $batch['row_count']=(int)$batch['row_count'];$batch['processed_rows']=(int)$batch['processed_rows'];
        $rows=$pdo->prepare('SELECT row_number,outcome,error_code,asset_id::text FROM inventory_import_results WHERE batch_id=:id ORDER BY row_number LIMIT 200');
        $rows->execute(['id'=>$id]);$batch['results']=$rows->fetchAll(PDO::FETCH_ASSOC);
        return $batch;
    }

    /** Queue worker only; never invoked via HTTP. Each completed row is checkpointed and retry safe. */
    public function processBatch(string $id):array
    {
        $id=self::uuid($id);
        $pdo=$this->db->connection();
        $advisory=$pdo->prepare('SELECT pg_try_advisory_lock(hashtext(:key))');
        $advisory->execute(['key'=>'cajeer-import-'.$id]);
        if(!in_array($advisory->fetchColumn(),[true,1,'1','t'],true))throw new \RuntimeException('Import batch already running.');
        try{
            $fetch=$pdo->prepare('SELECT workspace_id::text,actor_id::text,rows_payload::text,processed_rows,status FROM inventory_import_batches WHERE id=:id');
            $fetch->execute(['id'=>$id]);$batch=$fetch->fetch(PDO::FETCH_ASSOC);
            if(!is_array($batch))throw new \RuntimeException('Import batch missing.');
            if(in_array($batch['status'],['completed','completed_with_errors'],true))return ['batch_id'=>$id,'already_complete'=>true];
            $this->inventory->workspaceRole($pdo,$batch['workspace_id'],$batch['actor_id'],true);
            $principal=['mode'=>'session','user'=>['id'=>$batch['actor_id']]];
            $this->rbac->requirePermission($principal,'inventory.transfer.manage',$pdo);
            $this->rbac->requirePermission($principal,'inventory.reconcile.manage',$pdo);
            $rows=json_decode($batch['rows_payload'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($rows)||count($rows)>TransferFormat::MAX_ROWS)throw new \RuntimeException('Corrupt import batch.');
            $pdo->prepare("UPDATE inventory_import_batches SET status='running',started_at=COALESCE(started_at,clock_timestamp()),updated_at=clock_timestamp() WHERE id=:id")
                ->execute(['id'=>$id]);
            $from=(int)$batch['processed_rows'];
            for($i=$from;$i<count($rows);$i++){
                $this->inventory->workspaceRole($pdo,$batch['workspace_id'],$batch['actor_id'],true);
                $this->rbac->requirePermission($principal,'inventory.transfer.manage',$pdo);
                $this->rbac->requirePermission($principal,'inventory.reconcile.manage',$pdo);
                $row=$rows[$i];$outcome='error';$assetId=null;$code=null;
                try{
                    $result=$this->history->observe($batch['actor_id'],[
                        'workspace_id'=>$batch['workspace_id'],'source'=>'import','external_id'=>$row['external_id'],
                        'type'=>$row['type'],'fields'=>$row['fields'],'event_key'=>'import.'.$id.'.'.($i+1),
                    ]);
                    $outcome=$result['outcome'];$assetId=$result['asset_id'];
                }catch(HttpException $e){
                    if (in_array($e->status,[401,403,404],true)) throw $e;
                    $code=$e->errorCode;
                }
                // Atomic progress and result journal (no raw input values, no sensitive errors).
                $this->db->transaction(function(PDO $tx)use($id,$i,$outcome,$assetId,$code):void{
                    $stmt=$tx->prepare('INSERT INTO inventory_import_results(batch_id,row_number,outcome,error_code,asset_id) VALUES(CAST(:id AS uuid),:row,:outcome,:error,CAST(:asset AS uuid)) ON CONFLICT(batch_id,row_number) DO NOTHING');
                    $stmt->execute(['id'=>$id,'row'=>$i+1,'outcome'=>$outcome,'error'=>$code,'asset'=>$assetId]);
                    $tx->prepare('UPDATE inventory_import_batches SET processed_rows=GREATEST(processed_rows,:processed),updated_at=clock_timestamp() WHERE id=:id')
                        ->execute(['id'=>$id,'processed'=>$i+1]);
                });
            }
            $final=$this->db->transaction(function(PDO $tx)use($id,$batch):array{
                $stmt=$tx->prepare('SELECT outcome,count(*)::int AS total FROM inventory_import_results WHERE batch_id=:id GROUP BY outcome');
                $stmt->execute(['id'=>$id]);$counts=[];
                foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)$counts[$row['outcome']]=(int)$row['total'];
                $status=isset($counts['error'])?'completed_with_errors':'completed';
                $tx->prepare('UPDATE inventory_import_batches SET status=:status,summary=CAST(:summary AS jsonb),finished_at=clock_timestamp(),updated_at=clock_timestamp() WHERE id=:id')
                    ->execute(['id'=>$id,'status'=>$status,'summary'=>json_encode($counts,JSON_THROW_ON_ERROR)]);
                $this->audit->record($tx,$batch['actor_id'],'inventory.import.completed','success','inventory_import_batch',$id,['count'=>array_sum($counts)]);
                return ['batch_id'=>$id,'status'=>$status,'summary'=>$counts];
            });
            return $final;
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock(hashtext(:key))')->execute(['key'=>'cajeer-import-'.$id]);
        }
    }

    /** Safe bounded export scoped in SQL, no arbitrary attributes, credentials or soft-deleted assets. */
    public function export(string $actor,string $workspace,string $format):string
    {
        $workspace=self::uuid($workspace,'workspace_id');
        if(!in_array($format,['csv','json'],true))throw new HttpException(422,'Invalid export format.','validation_failed');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $rows=$pdo->prepare(<<<'SQL'
SELECT id::text,external_id,type,name,status,os_family,environment,ip_addresses::text,tags::text
FROM inventory_assets WHERE workspace_id=:ws AND deleted_at IS NULL
ORDER BY created_at,id LIMIT 501
SQL);
        $rows->execute(['ws'=>$workspace]);$items=$rows->fetchAll(PDO::FETCH_ASSOC);
        if(count($items)>500)throw new HttpException(413,'Export exceeds 500 records; narrow the workspace or use another export mechanism.','export_too_large');
        foreach($items as &$row){$row['ip_addresses']=json_decode($row['ip_addresses'],true,16,JSON_THROW_ON_ERROR);
            $row['tags']=json_decode($row['tags'],true,16,JSON_THROW_ON_ERROR);}unset($row);
        return TransferFormat::export($format,$items);
    }
}
