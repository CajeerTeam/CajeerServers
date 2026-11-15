<?php
declare(strict_types=1);

namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use PDO;
use RuntimeException;

/** Offline, bounded repair of deterministic manual provenance only. Never rewrites history or imported overrides. */
final class CmdbRepair
{
    private const MISSING = <<<'SQL'
SELECT a.id::text AS asset_id,a.version,f.field
FROM inventory_assets a CROSS JOIN (VALUES ('name'),('status'),('os_family'),('ip_addresses'),('tags'),('environment')) f(field)
WHERE a.workspace_id=:ws AND a.source='manual' AND NOT EXISTS (
 SELECT 1 FROM inventory_field_provenance p WHERE p.asset_id=a.id AND p.field=f.field)
ORDER BY a.id,f.field LIMIT 10001
SQL;

    public function __construct(private readonly DatabaseManager $db,private readonly AuditLogger $audit) {}

    /** Return counts and a plan fingerprint, never resource contents. */
    public function preview(string $workspace):array
    {
        $workspace=self::workspace($workspace);
        return $this->db->transaction(function(PDO $pdo)use($workspace):array{
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout = '15s'");
            return $this->plan($pdo,$workspace)['report'];
        });
    }

    /** Caller must hold the offline maintenance lock. A changed plan is rejected, never guessed. */
    public function apply(string $workspace,string $confirmation):array
    {
        $workspace=self::workspace($workspace);
        if(!preg_match('/^[a-f0-9]{64}$/D',$confirmation))throw new RuntimeException('Invalid repair confirmation.');
        return $this->db->transaction(function(PDO $pdo)use($workspace,$confirmation):array{
            $pdo->exec("SET LOCAL lock_timeout = '10s'");
            $pdo->exec("SET LOCAL statement_timeout = '30s'");
            // Lock scope and both tables against concurrent SQL writers, including scripts outside the app.
            (new InventoryRepository())->lockWorkspace($pdo,$workspace);
            $pdo->exec('LOCK TABLE inventory_assets, inventory_field_provenance IN SHARE ROW EXCLUSIVE MODE');
            $plan=$this->plan($pdo,$workspace);
            if(!hash_equals($plan['report']['confirmation'],$confirmation))throw new RuntimeException('Repair plan changed; generate a new preview.');
            $stmt=$pdo->prepare("INSERT INTO inventory_field_provenance(asset_id,field,mode,source) VALUES(:id,:field,'manual','manual') ON CONFLICT DO NOTHING");
            $count=0;
            foreach($plan['rows'] as $row){
                $stmt->execute(['id'=>$row['asset_id'],'field'=>$row['field']]);
                $count+=$stmt->rowCount();
            }
            if($count!==count($plan['rows']))throw new RuntimeException('Repair plan no longer matches the database.');
            $this->audit->record($pdo,null,'inventory.integrity.repaired','success','workspace',$workspace,['reason'=>'offline_manual_provenance','count'=>$count]);
            return [...$plan['report'],'applied'=>true,'repaired'=>$count];
        });
    }

    private static function workspace(string $workspace):string
    {
        if(!InfrastructureAsset::isUuid($workspace))throw new RuntimeException('Invalid repair workspace UUID.');
        return strtolower($workspace);
    }

    private function plan(PDO $pdo,string $workspace):array
    {
        if($this->db->driver()!=='pgsql')throw new RuntimeException('CMDB repair requires PostgreSQL.');
        $q=$pdo->prepare('SELECT 1 FROM workspaces WHERE id=:ws');$q->execute(['ws'=>$workspace]);
        if(!$q->fetchColumn())throw new RuntimeException('Repair workspace not found.');
        $identity=$pdo->query('SELECT installation_id::text FROM cajeer_installation WHERE singleton_id=1')->fetchColumn();
        if(!is_string($identity)||!InfrastructureAsset::isUuid($identity))throw new RuntimeException('Repair requires a valid installation identity.');
        $stmt=$pdo->prepare(self::MISSING);$stmt->execute(['ws'=>$workspace]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>10000)throw new RuntimeException('Repair exceeds 10000 rows; investigate database integrity manually.');
        $digest=hash('sha256',json_encode(['manual-provenance-v1',$identity,$workspace,$rows],JSON_THROW_ON_ERROR));
        return ['rows'=>$rows,'report'=>['workspace_id'=>$workspace,'repair'=>'manual_provenance','missing'=>count($rows),'confirmation'=>$digest,'applied'=>false]];
    }
}
