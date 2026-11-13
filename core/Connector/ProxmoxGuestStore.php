<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use PDO;

/** Current read model plus last known guest configurations; raw snapshots stay in the existing run history. */
final class ProxmoxGuestStore
{
    /** Never carry data between credential/target revisions or different guest identities. */
    public static function merge(array $snapshot,?array $previous,string $observedAt):array
    {
        $old=[];foreach($previous['guests']??[] as $guest)$old[$guest['external_id']]=$guest;
        foreach($snapshot['guests'] as &$guest){
            $prior=$old[$guest['external_id']]??null;
            if($guest['config_state']==='available'){
                $guest['config_observed_at']=$observedAt;
            }elseif($prior!==null&&$prior['node']===$guest['node']){
                $guest['last_config']=$prior['config']??$prior['last_config']??null;
                $guest['last_config_observed_at']=$prior['config_observed_at']??$prior['last_config_observed_at']??null;
            }
        }
        unset($guest);
        // Current observations always take precedence over optional stale detail cache.
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR))>850000){
            foreach($snapshot['guests'] as &$guest){unset($guest['last_config'],$guest['last_config_observed_at']);}unset($guest);
        }
        return $snapshot;
    }
    public static function identityConflict(array $guest,?array $prior):bool
    {
        if($prior===null)return false;
        $fingerprint=$guest['config']['identity_fingerprint']??null;
        return $prior['guest_type']!==$guest['type']||($prior['fingerprint']!==null&&$fingerprint!==null&&$prior['fingerprint']!==$fingerprint);
    }
    /** Atomic generation guard. Never silently reassign a known VMID to a different guest type or SMBIOS identity. */
    public static function guardIdentities(PDO $pdo,array $target,array $snapshot):void
    {
        $q=$pdo->prepare('SELECT vmid,guest_type,fingerprint FROM proxmox_guest_identity WHERE target_id=:id AND workspace_id=:ws');
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);$known=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'vmid');
        $insert=$pdo->prepare('INSERT INTO proxmox_guest_identity(target_id,workspace_id,vmid,guest_type,fingerprint) VALUES(:id,:ws,:vmid,:type,:fingerprint) ON CONFLICT(target_id,vmid) DO UPDATE SET fingerprint=COALESCE(proxmox_guest_identity.fingerprint,EXCLUDED.fingerprint) WHERE proxmox_guest_identity.workspace_id=EXCLUDED.workspace_id AND proxmox_guest_identity.guest_type=EXCLUDED.guest_type');
        foreach($snapshot['guests'] as $guest){
            if(self::identityConflict($guest,$known[$guest['vmid']]??null))throw new \Cajeer\Servers\Http\Exception\HttpException(409,'Guest identity changed; review VMID reuse before synchronization.','guest_identity_conflict');
            $insert->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'vmid'=>$guest['vmid'],'type'=>$guest['type'],'fingerprint'=>$guest['config']['identity_fingerprint']??null]);
            if($insert->rowCount()!==1)throw new \RuntimeException('Guest identity scope mismatch.');
        }
    }
    /** Caller owns the workspace lock and has revalidated the grant, credentials and target. */
    public static function save(PDO $pdo,array $target,array $snapshot,string $observedAt):void
    {
        $q=$pdo->prepare('SELECT target_version,credential_id::text,credential_version,observed_at,snapshot FROM proxmox_guest_state WHERE target_id=:id AND workspace_id=:ws FOR UPDATE');
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);$old=$q->fetch(PDO::FETCH_ASSOC);
        $same=$old&&(int)$old['target_version']===$target['version']&&$old['credential_id']===$target['credential_id']&&(int)$old['credential_version']===$target['credential_version'];
        if($same&&new \DateTimeImmutable($old['observed_at'])>new \DateTimeImmutable($observedAt))throw new \Cajeer\Servers\Http\Exception\HttpException(409,'Guest snapshot superseded.','snapshot_stale');
        self::guardIdentities($pdo,$target,$snapshot);
        $merged=self::merge($snapshot,$same?json_decode($old['snapshot'],true,32,JSON_THROW_ON_ERROR):null,$observedAt);
        $json=json_encode($merged,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>900000)throw new \RuntimeException('Guest state exceeds storage limit.');
        $q=$pdo->prepare(<<<'SQL'
INSERT INTO proxmox_guest_state(target_id,workspace_id,target_version,credential_id,credential_version,observed_at,snapshot)
VALUES(:id,:ws,:v,:credential,:cv,CAST(:observed AS timestamptz),CAST(:snapshot AS jsonb))
ON CONFLICT(target_id) DO UPDATE SET target_version=EXCLUDED.target_version,credential_id=EXCLUDED.credential_id,
 credential_version=EXCLUDED.credential_version,observed_at=EXCLUDED.observed_at,snapshot=EXCLUDED.snapshot
WHERE proxmox_guest_state.workspace_id=EXCLUDED.workspace_id
SQL);
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'v'=>$target['version'],'credential'=>$target['credential_id'],'cv'=>$target['credential_version'],'observed'=>$observedAt,'snapshot'=>$json]);
        if($q->rowCount()!==1)throw new \RuntimeException('Guest state scope mismatch.');
    }
    /** Current view is invalidated by profile edits, rebinding, rotation or disabling the target. */
    public static function read(PDO $pdo,array $target):array
    {
        $q=$pdo->prepare(<<<'SQL'
SELECT to_char(s.observed_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS observed_at,s.snapshot,
 CASE WHEN t.state='active' AND t.proxmox_profile IS NOT NULL AND t.version=s.target_version
 AND EXISTS(SELECT 1 FROM connector_credential_bindings b JOIN credentials c ON c.id=b.credential_id AND c.workspace_id=b.workspace_id
            JOIN inventory_asset_credential_bindings a ON a.workspace_id=b.workspace_id AND a.credential_id=b.credential_id AND a.purpose=b.purpose
            JOIN inventory_assets asset ON asset.id=a.asset_id AND asset.workspace_id=a.workspace_id AND asset.deleted_at IS NULL
            WHERE b.connector_id=t.id AND b.workspace_id=t.workspace_id AND a.asset_id=t.asset_id AND b.purpose='api' AND c.id=s.credential_id
              AND c.version=s.credential_version AND c.state='active' AND c.deleted_at IS NULL AND c.vault_format='cjev1')
 THEN 1 ELSE 0 END AS current
FROM proxmox_guest_state s JOIN connector_targets t ON t.id=s.target_id AND t.workspace_id=s.workspace_id
WHERE s.target_id=:id AND s.workspace_id=:ws
SQL);
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['state'=>'not_collected','observed_at'=>null,'snapshot'=>null];
        if((int)$row['current']!==1)return ['state'=>'stale','observed_at'=>$row['observed_at'],'snapshot'=>null];
        return ['state'=>'available','observed_at'=>$row['observed_at'],'snapshot'=>json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR)];
    }
}
