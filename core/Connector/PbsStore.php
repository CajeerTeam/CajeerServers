<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use PDO;

/** Current scoped observation; previous observations remain in immutable run history. */
final class PbsStore
{
    /** Caller owns the workspace lock and has revalidated the grant, credentials and target. */
    public static function save(PDO $pdo,array $target,array $snapshot,string $observedAt):void
    {
        $q=$pdo->prepare('SELECT target_version,credential_id::text,credential_version,observed_at,snapshot FROM pbs_backup_state WHERE target_id=:id AND workspace_id=:ws FOR UPDATE');
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);$old=$q->fetch(PDO::FETCH_ASSOC);
        $same=$old&&(int)$old['target_version']===$target['version']&&$old['credential_id']===$target['credential_id']&&(int)$old['credential_version']===$target['credential_version'];
        if($same&&new \DateTimeImmutable($old['observed_at'])>new \DateTimeImmutable($observedAt))throw new \Cajeer\Servers\Http\Exception\HttpException(409,'PBS backup snapshot superseded.','snapshot_stale');
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>900000)throw new \RuntimeException('PBS backup state exceeds storage limit.');
        $q=$pdo->prepare(<<<'SQL'
INSERT INTO pbs_backup_state(target_id,workspace_id,target_version,credential_id,credential_version,observed_at,snapshot)
VALUES(:id,:ws,:v,:credential,:cv,CAST(:observed AS timestamptz),CAST(:snapshot AS jsonb))
ON CONFLICT(target_id) DO UPDATE SET target_version=EXCLUDED.target_version,credential_id=EXCLUDED.credential_id,
 credential_version=EXCLUDED.credential_version,observed_at=EXCLUDED.observed_at,snapshot=EXCLUDED.snapshot
WHERE pbs_backup_state.workspace_id=EXCLUDED.workspace_id
SQL);
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'v'=>$target['version'],'credential'=>$target['credential_id'],'cv'=>$target['credential_version'],'observed'=>$observedAt,'snapshot'=>$json]);
        if($q->rowCount()!==1)throw new \RuntimeException('PBS backup state scope mismatch.');
    }
    /** Current view is invalidated by profile edits, rebinding, rotation or disabling the target. */
    public static function read(PDO $pdo,array $target):array
    {
        $q=$pdo->prepare(<<<'SQL'
SELECT to_char(s.observed_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS observed_at,s.snapshot,
 CASE WHEN t.state='active' AND t.provider='pbs' AND t.version=s.target_version
 AND EXISTS(SELECT 1 FROM connector_credential_bindings b JOIN credentials c ON c.id=b.credential_id AND c.workspace_id=b.workspace_id
            JOIN inventory_asset_credential_bindings a ON a.workspace_id=b.workspace_id AND a.credential_id=b.credential_id AND a.purpose=b.purpose
            JOIN inventory_assets asset ON asset.id=a.asset_id AND asset.workspace_id=a.workspace_id AND asset.deleted_at IS NULL
            WHERE b.connector_id=t.id AND b.workspace_id=t.workspace_id AND a.asset_id=t.asset_id AND b.purpose='api' AND c.id=s.credential_id
              AND c.version=s.credential_version AND c.state='active' AND c.deleted_at IS NULL AND c.vault_format='cjev1')
 THEN 1 ELSE 0 END AS current
FROM pbs_backup_state s JOIN connector_targets t ON t.id=s.target_id AND t.workspace_id=s.workspace_id
WHERE s.target_id=:id AND s.workspace_id=:ws
SQL);
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['state'=>'not_collected','observed_at'=>null,'snapshot'=>null];
        if((int)$row['current']!==1)return ['state'=>'stale','observed_at'=>$row['observed_at'],'snapshot'=>null];
        return ['state'=>'available','observed_at'=>$row['observed_at'],'snapshot'=>json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR)];
    }
}
