<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Connector\{LinuxDiscoveryStore,WindowsDiscoveryStore,SnmpDiscoveryStore,RouterOsDiscoveryStore,ProxmoxStorageNetworkStore,VmmanagerNetworkStore};
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Builds observed topology from the authoritative current snapshots; does not replay remote requests. */
final class NetworkDiscoveryService
{
    public const PROVIDERS=['ssh','winrm','snmp','routeros','proxmox','vmmanager'];
    public function __construct(private DatabaseManager $db,private InventoryRepository $inventory,private AuditLogger $audit){}
    private static function query(PDO $pdo,string $sql,array $params):\PDOStatement {$q=$pdo->prepare($sql);$q->execute($params);return $q;}
    private static function fresh(array $view):bool {
        if($view['state']!=='available'||!is_string($view['observed_at']))return false;
        $time=strtotime($view['observed_at']);return $time!==false&&$time<=time()&&$time>=time()-900;
    }
    public static function source(PDO $pdo,array $target):array {
        $view=match($target['provider']){
            'ssh'=>LinuxDiscoveryStore::read($pdo,$target,'network'),
            'winrm'=>WindowsDiscoveryStore::read($pdo,$target,'network'),
            'routeros'=>RouterOsDiscoveryStore::read($pdo,$target,'network'),
            'snmp'=>SnmpDiscoveryStore::read($pdo,$target,'topology'),
            'proxmox'=>ProxmoxStorageNetworkStore::read($pdo,$target),
            'vmmanager'=>VmmanagerNetworkStore::read($pdo,$target),
        };
        if($target['provider']==='snmp'){
            $ips=SnmpDiscoveryStore::read($pdo,$target,'interfaces');
            if(!self::fresh($view)&&self::fresh($ips))$view=$ips;
            elseif(self::fresh($view)&&self::fresh($ips)){
                // ifIndex is not a permanent identity. Only merge matching names and MACs.
                $a=array_column($view['snapshot']['sections']['interfaces'],null,'if_index');$b=array_column($ips['snapshot']['sections']['interfaces'],null,'if_index');
                $view['snapshot']['sections']['addresses']=array_values(array_filter($ips['snapshot']['sections']['addresses'],static function($r)use($a,$b){$i=$r['if_index'];return isset($a[$i],$b[$i])&&$a[$i]['name']!==null&&$a[$i]['name']===$b[$i]['name']&&$a[$i]['mac']!==null&&$a[$i]['mac']===$b[$i]['mac'];}));
                if(strcmp($ips['observed_at'],$view['observed_at'])<0)$view['observed_at']=$ips['observed_at'];
            }
        }
        $base=['target_id'=>$target['id'],'provider'=>$target['provider'],'name'=>$target['name'],'state'=>$view['state'],'observed_at'=>$view['observed_at'],'asset_id'=>$target['asset_id']];
        if(!self::fresh($view))return $base+['state'=>$view['state'],'projection'=>null,'fresh'=>false];
        $projection=NetworkObservation::project($target,$view['snapshot'],$view['observed_at']);
        $profile=isset($target['os_network_profile'])?json_decode($target['os_network_profile'],true,16,JSON_THROW_ON_ERROR):null;
        $keys=[];foreach($projection['devices'] as $device)$keys[$device['id']]=in_array($target['provider'],['ssh','winrm'],true)?'host:'.($profile['identity_hash']??''):$device['external_id'];
        $maps=self::query($pdo,"SELECT d.external_id,a.id::text,a.name FROM connector_discovered_resources d JOIN inventory_assets a ON a.id=d.asset_id AND a.workspace_id=d.workspace_id WHERE d.workspace_id=:ws AND d.target_id=:target AND d.presence='seen' AND d.conflict_code IS NULL AND a.deleted_at IS NULL AND d.external_id IN (SELECT jsonb_array_elements_text(CAST(:keys AS jsonb)))",['ws'=>$target['workspace_id'],'target'=>$target['id'],'keys'=>json_encode(array_values($keys),JSON_THROW_ON_ERROR)])->fetchAll(PDO::FETCH_ASSOC);
        $maps=array_column($maps,null,'external_id');
        foreach($projection['devices'] as &$device){$key=$keys[$device['id']];$device['asset_id']=$maps[$key]['id']??null;$device['asset_name']=$maps[$key]['name']??null;}unset($device);
        return $base+['projection'=>$projection,'fresh'=>true];
    }
    public function graph(string $actor,string $workspace,int $limit=5,int $offset=0):array {
        $workspace=Network::uuid($workspace,'workspace_id');$pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        if($limit<1||$limit>20||$offset<0||$offset>10000)Network::invalid('pagination');
        $q=$pdo->prepare("SELECT id::text,workspace_id::text,asset_id::text,provider,name,os_network_profile FROM connector_targets WHERE workspace_id=:ws AND provider IN ('ssh','winrm','snmp','routeros','proxmox','vmmanager') ORDER BY id LIMIT :limit OFFSET :offset");
        $q->bindValue(':ws',$workspace);$q->bindValue(':limit',$limit+1,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;
        $sources=[];$projections=[];$size=0;
        foreach(array_slice($rows,0,$limit) as $t){$source=self::source($pdo,$t);$size+=strlen(json_encode($source,JSON_THROW_ON_ERROR));if($size>6000000)throw new HttpException(422,'Observation page too large; reduce limit.','validation_failed');$sources[]=$source;if($source['projection']!==null)$projections[]=$source['projection'];}
        $conflicts=NetworkObservation::conflicts($projections);
        return ['sources'=>$sources,'physical_links'=>NetworkObservation::physicalLinks($projections),'conflicts'=>$conflicts['items'],'conflicts_truncated'=>$conflicts['truncated'],'has_more'=>$more,'limit'=>$limit,'offset'=>$offset];
    }
    public function reconcile(string $actor,string $workspace,string $targetId,?string $networkId,string $expectedObservedAt,string $expectedAssetId,?string $requestId=null):array {
        $workspace=Network::uuid($workspace,'workspace_id');$targetId=Network::uuid($targetId,'target_id');$expectedAssetId=Network::uuid($expectedAssetId,'asset_id');if($networkId!==null)$networkId=Network::uuid($networkId,'network_id');
        return $this->db->transaction(function(PDO $pdo)use($actor,$workspace,$targetId,$networkId,$expectedObservedAt,$expectedAssetId,$requestId){
            $this->inventory->lockWorkspace($pdo,$workspace);$this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $t=self::query($pdo,'SELECT id::text,workspace_id::text,asset_id::text,provider,name,os_network_profile FROM connector_targets WHERE id=:id AND workspace_id=:ws AND state=\'active\' FOR UPDATE',['id'=>$targetId,'ws'=>$workspace])->fetch(PDO::FETCH_ASSOC);
            if(!$t)throw new HttpException(404,'Connector target not found.','not_found');
            if(!in_array($t['provider'],['ssh','winrm','routeros'],true))throw new HttpException(422,'This source does not support topology IPAM reconciliation.','validation_failed');
            $source=self::source($pdo,$t);$p=$source['projection'];
            if(!$source['fresh']||$p===null||!$p['complete']||$source['observed_at']!==$expectedObservedAt)throw new HttpException(409,'A fresh complete network snapshot is required.','snapshot_stale');
            // Resolve the exact existing CMDB mapping, never name or IP. The host identity is already checked by the collector.
            $profile=json_decode($t['os_network_profile'],true,16,JSON_THROW_ON_ERROR);
            $external=$t['provider']==='routeros'?'device':'host:'.$profile['identity_hash'];
            $asset=self::query($pdo,"SELECT a.id::text FROM connector_discovered_resources d JOIN inventory_assets a ON a.id=d.asset_id AND a.workspace_id=d.workspace_id WHERE d.workspace_id=:ws AND d.target_id=:target AND d.external_id=:ext AND d.presence='seen' AND d.conflict_code IS NULL AND a.deleted_at IS NULL AND a.type=:type",['ws'=>$workspace,'target'=>$targetId,'ext'=>$external,'type'=>$t['provider']==='routeros'?'router':'server'])->fetchColumn();
            if(!$asset||$asset!==$expectedAssetId)throw new HttpException(409,'The CMDB mapping is missing or changed. Refresh observations after synchronizing inventory.','conflict');
            $result=NetworkIpamReconciler::apply($pdo,$workspace,$targetId,(string)$asset,$p,$networkId);
            $this->audit->record($pdo,$actor,'inventory.network.discovery.reconciled','success','connector_target',$targetId,['count'=>$result['created']],$requestId);return $result;
        },3);
    }
}
