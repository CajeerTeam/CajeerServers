<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Read-only graph projection assembled from persisted CMDB relations and IPAM foreign keys. */
final class TopologyService
{
    public function __construct(private readonly DatabaseManager $db,private readonly InventoryRepository $inventory) {}

    /** @return array{nodes:array,edges:array,truncated:bool,limits:array} */
    public function graph(string $actor,string $workspace,int $assetLimit=100):array
    {
        $workspace=RelationshipService::uuid($workspace,'workspace_id');
        if ($assetLimit<1 || $assetLimit>200) throw new HttpException(422,'limit must be between 1 and 200.','validation_failed');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $nodes=[];$edges=[];$seen=[];$truncated=false;
        $select=static function(string $sql,int $limit,array $filters=[])use($pdo,$workspace):array {
            $stmt=$pdo->prepare($sql.' LIMIT :limit');
            $stmt->bindValue(':ws',$workspace,PDO::PARAM_STR);
            foreach($filters as $key=>$value)$stmt->bindValue(':'.$key,$value,PDO::PARAM_STR);
            $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
            $stmt->execute();return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $assets=$select('SELECT id::text,name,type,status FROM inventory_assets WHERE workspace_id=:ws AND deleted_at IS NULL ORDER BY name,id', $assetLimit+1);
        if(count($assets)>$assetLimit){$truncated=true;$assets=array_slice($assets,0,$assetLimit);}
        foreach($assets as $a) {
            $id='asset:'.$a['id'];$seen[$id]=true;
            $nodes[]=['id'=>$id,'entity_id'=>$a['id'],'kind'=>'asset','subtype'=>$a['type'],'label'=>$a['name'],'status'=>$a['status']];
        }
        $networks=$select('SELECT id::text,name,cidr::text FROM inventory_networks WHERE workspace_id=:ws ORDER BY name,id',201);
        if(count($networks)>200){$truncated=true;$networks=array_slice($networks,0,200);}
        foreach($networks as $net) {
            $id='network:'.$net['id'];$seen[$id]=true;
            $nodes[]=['id'=>$id,'entity_id'=>$net['id'],'kind'=>'network','subtype'=>'cidr','label'=>$net['name'].' ('.$net['cidr'].')'];
        }
        // Filter to the selected assets in SQL, so other assets never consume the graph limit.
        $assetIds='{'.implode(',',array_map(static fn(array $a):string=>$a['id'],$assets)).'}';
        $interfaces=$select('SELECT id::text,asset_id::text,name FROM inventory_interfaces WHERE workspace_id=:ws AND asset_id=ANY(CAST(:assets AS uuid[])) ORDER BY name,id',401,['assets'=>$assetIds]);
        if(count($interfaces)>400){$truncated=true;$interfaces=array_slice($interfaces,0,400);}
        foreach($interfaces as $interface) {
            $id='interface:'.$interface['id'];
            if(!isset($seen['asset:'.$interface['asset_id']]))continue;
            $seen[$id]=true;
            $nodes[]=['id'=>$id,'entity_id'=>$interface['id'],'kind'=>'interface','subtype'=>'nic','label'=>$interface['name']];
            $edges[]=['id'=>'interface_of:'.$interface['id'],'source'=>'asset:'.$interface['asset_id'],'target'=>$id,'kind'=>'interface_of','derived'=>true];
        }
        $networkIds='{'.implode(',',array_map(static fn(array $n):string=>$n['id'],$networks)).'}';
        $addresses=$select('SELECT id::text,network_id::text,interface_id::text,address::text FROM inventory_ip_addresses WHERE workspace_id=:ws AND network_id=ANY(CAST(:networks AS uuid[])) ORDER BY address,id',401,['networks'=>$networkIds]);
        if(count($addresses)>400){$truncated=true;$addresses=array_slice($addresses,0,400);}
        foreach($addresses as $address) {
            if (!isset($seen['network:'.$address['network_id']])) continue;
            $id='address:'.$address['id'];$seen[$id]=true;
            $nodes[]=['id'=>$id,'entity_id'=>$address['id'],'kind'=>'address','subtype'=>'ip','label'=>$address['address']];
            $edges[]=['id'=>'within_network:'.$address['id'],'source'=>$id,'target'=>'network:'.$address['network_id'],'kind'=>'within_network','derived'=>true];
            if($address['interface_id']!==null && isset($seen['interface:'.$address['interface_id']])) {
                $edges[]=['id'=>'address_on_interface:'.$address['id'],'source'=>'interface:'.$address['interface_id'],'target'=>$id,'kind'=>'address_on_interface','derived'=>true];
            }
        }
        $relationships=$select('SELECT id::text,source_asset_id::text,target_asset_id::text,kind FROM inventory_relationships WHERE workspace_id=:ws AND source_asset_id=ANY(CAST(:sources AS uuid[])) AND target_asset_id=ANY(CAST(:targets AS uuid[])) ORDER BY id',1001,['sources'=>$assetIds,'targets'=>$assetIds]);
        if(count($relationships)>1000){$truncated=true;$relationships=array_slice($relationships,0,1000);}
        foreach($relationships as $relation) {
            $source='asset:'.$relation['source_asset_id'];$target='asset:'.$relation['target_asset_id'];
            if(!isset($seen[$source],$seen[$target]))continue;
            $edges[]=['id'=>$relation['id'],'source'=>$source,'target'=>$target,'kind'=>$relation['kind'],'derived'=>false];
        }
        return ['nodes'=>$nodes,'edges'=>$edges,'truncated'=>$truncated,
            'limits'=>['assets'=>$assetLimit,'networks'=>200,'interfaces'=>400,'addresses'=>400,'relationships'=>1000]];
    }
}
