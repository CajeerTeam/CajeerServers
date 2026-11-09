<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;
use PDOException;

/** Transactional PostgreSQL IPAM. Workspace locks serialize modifications with asset transfers. */
final class IpamService
{
    private const TABLE = [
        'network'=>'inventory_networks',
        'interface'=>'inventory_interfaces',
        'address'=>'inventory_ip_addresses',
    ];
    private const COLUMNS = [
        'network'=>['name','cidr','vlan_id','description'],
        'interface'=>['asset_id','name','mac_address'],
        'address'=>['network_id','address','interface_id','hostname','description'],
    ];
    private const PROJECTIONS = [
        'network'=>'id::text,workspace_id::text,name,cidr::text,vlan_id,description,version,created_at::text,updated_at::text',
        'interface'=>'id::text,workspace_id::text,asset_id::text,name,mac_address::text,version,created_at::text,updated_at::text',
        'address'=>'id::text,workspace_id::text,network_id::text,address::text,interface_id::text,hostname,description,version,created_at::text,updated_at::text',
    ];
    public function __construct(private readonly DatabaseManager $db,private readonly InventoryRepository $inventory,private readonly AuditLogger $audit) {}

    private static function table(string $kind):string { return self::TABLE[$kind] ?? throw new \LogicException('Invalid IPAM table.'); }

    private static function normalize(string $kind,array $input,bool $create):array
    {
        $allowed=match($kind){
            'network'=>$create?['name','cidr','vlan_id','description']:['name','vlan_id','description'],
            'interface'=>$create?['asset_id','name','mac_address']:['name','mac_address'],
            'address'=>$create?['network_id','address','hostname','description']:['hostname','description'],
        };
        if (!$input || array_diff(array_keys($input),$allowed)) Network::invalid('fields');
        foreach (match($kind){
            'network'=> $create?['name','cidr']:[],
            'interface'=> $create?['asset_id','name']:[],
            'address'=> $create?['network_id','address']:[],
        } as $required) if (!array_key_exists($required,$input)) Network::invalid($required);
        $out=[];
        foreach ($input as $field=>$value) {
            $out[$field]=match($field){
                'name'=>Network::name($value),
                'cidr'=>Network::cidr($value),
                'asset_id','network_id'=>Network::uuid($value,$field),
                'address'=>Network::host($value),
                'mac_address'=>IpAddress::mac($value),
                'hostname'=>IpAddress::hostname($value),
                'description'=>Network::description($value),
                'vlan_id'=>self::vlan($value),
                default=>throw new \LogicException('IPAM invalid field.'),
            };
        }
        if ($create) {
            if ($kind==='network') {$out+=['vlan_id'=>null,'description'=>''];}
            if ($kind==='interface') {$out+=['mac_address'=>null];}
            if ($kind==='address') {$out+=['interface_id'=>null,'hostname'=>null,'description'=>''];}
        }
        return $out;
    }
    private static function vlan(mixed $v):?int
    {
        if ($v===null) return null;
        if (!is_int($v) || $v<1 || $v>4094) Network::invalid('vlan_id');
        return $v;
    }
    private static function id(string $id):string{return Network::uuid($id);}
    private static function failDatabase(PDOException $e):never
    {
        $state=(string)($e->errorInfo[0]??$e->getCode());
        if (in_array($state,['23505','23503','23514','23P01'],true)) throw new HttpException(409,'IPAM conflict or related resources prevent this operation.','conflict');
        throw $e;
    }
    private static function hydrate(string $kind,array $row):array
    {
        $row['version']=(int)$row['version'];
        $row['etag']=InfrastructureAsset::etag((string)$row['id'],$row['version']);
        if ($kind==='network') $row['vlan_id']=$row['vlan_id']===null?null:(int)$row['vlan_id'];
        if ($kind==='address') $row['status']=$row['interface_id']===null?'reserved':'assigned';
        return $row;
    }
    private function raw(PDO $pdo,string $kind,string $workspace,string $id,bool $lock=false):?array
    {
        $sql='SELECT '.self::PROJECTIONS[$kind].' FROM '.self::table($kind).' WHERE workspace_id=:workspace AND id=:id'.($lock?' FOR UPDATE':'');
        $stmt=$pdo->prepare($sql);$stmt->execute(['workspace'=>$workspace,'id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::hydrate($kind,$row):null;
    }
    public function list(string $actor,string $kind,string $workspace,int $limit=50,int $offset=0):array
    {
        self::table($kind);$workspace=self::id($workspace);
        if ($limit<1 || $limit>100 || $offset<0 || $offset>10000) Network::invalid('pagination');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $stmt=$pdo->prepare('SELECT '.self::PROJECTIONS[$kind].' FROM '.self::table($kind).' WHERE workspace_id=:workspace ORDER BY created_at DESC,id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':workspace',$workspace);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();
        $data=array_map(fn(array $row)=>self::hydrate($kind,$row),$stmt->fetchAll(PDO::FETCH_ASSOC));
        $count=$pdo->prepare('SELECT count(*) FROM '.self::table($kind).' WHERE workspace_id=:workspace');$count->execute(['workspace'=>$workspace]);
        return ['data'=>$data,'pagination'=>['limit'=>$limit,'offset'=>$offset,'total'=>(int)$count->fetchColumn()]];
    }
    public function show(string $actor,string $kind,string $workspace,string $id):array
    {
        self::table($kind);$workspace=self::id($workspace);$id=self::id($id);
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        return $this->raw($pdo,$kind,$workspace,$id) ?? throw new HttpException(404,'IPAM resource not found.','not_found');
    }
    public function create(string $actor,string $kind,string $workspace,array $input,?string $requestId=null):array
    {
        $workspace=self::id($workspace);$payload=self::normalize($kind,$input,true);
        return $this->db->transaction(function(PDO $pdo)use($actor,$kind,$workspace,$payload,$requestId){
            $this->inventory->lockWorkspace($pdo,$workspace);
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $this->assertReferences($pdo,$kind,$workspace,$payload);
            $columns=['workspace_id',...array_keys($payload)];$values=['workspace_id'=>$workspace,...$payload];
            $sql='INSERT INTO '.self::table($kind).'('.implode(',',$columns).') VALUES ('.implode(',',array_map(fn($key)=>':'.$key,$columns)).') RETURNING id::text';
            try {$stmt=$pdo->prepare($sql);$stmt->execute($values);$id=(string)$stmt->fetchColumn();}
            catch(PDOException $e){self::failDatabase($e);}
            $row=$this->raw($pdo,$kind,$workspace,$id);
            if ($row===null) throw new \RuntimeException('IPAM insert did not return a resource.');
            $this->audit->record($pdo,$actor,'inventory.ipam.'.$kind.'.created','success','ipam_'.$kind,$id,[],$requestId);
            return $row;
        },3);
    }
    /** @param array<string,mixed> $patch */
    public function update(string $actor,string $kind,string $workspace,string $id,int $expected,array $patch,?string $requestId=null):array
    {
        return $this->mutation($actor,$kind,$workspace,$id,$expected,'updated',self::normalize($kind,$patch,false),$requestId);
    }
    public function delete(string $actor,string $kind,string $workspace,string $id,int $expected,?string $requestId=null):void
    {
        $this->mutation($actor,$kind,$workspace,$id,$expected,'deleted',[],$requestId);
    }
    public function assign(string $actor,string $workspace,string $id,int $expected,?string $interface,?string $requestId=null):array
    {
        return $this->mutation($actor,'address',$workspace,$id,$expected,'assigned',['interface_id'=>$interface===null?null:self::id($interface)],$requestId);
    }
    /** @return array<string,mixed> */
    private function mutation(string $actor,string $kind,string $workspace,string $id,int $expected,string $action,array $patch,?string $requestId):array
    {
        self::table($kind);$workspace=self::id($workspace);$id=self::id($id);
        if ($expected<1) throw new HttpException(400,'Invalid ETag.','invalid_precondition');
        return $this->db->transaction(function(PDO $pdo)use($actor,$kind,$workspace,$id,$expected,$action,$patch,$requestId){
            $this->inventory->lockWorkspace($pdo,$workspace);
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $old=$this->raw($pdo,$kind,$workspace,$id,true);
            if ($old===null) throw new HttpException(404,'IPAM resource not found.','not_found');
            if ($old['version']!==$expected) throw new HttpException(412,'IPAM resource changed. Reload first.','precondition_failed');
            if ($action==='assigned') {
                if (($old['interface_id']??null)===$patch['interface_id']) throw new HttpException(409,'IP assignment is unchanged.','conflict');
                $this->assertReferences($pdo,'address',$workspace,$patch);
            }
            try {
                if ($action==='deleted') {
                    $stmt=$pdo->prepare('DELETE FROM '.self::table($kind).' WHERE id=:id AND workspace_id=:workspace');
                    $stmt->execute(['id'=>$id,'workspace'=>$workspace]);
                } else {
                    $sets=[];
                    foreach(array_keys($patch) as $field) $sets[]=$field.'=:'.$field;
                    $stmt=$pdo->prepare('UPDATE '.self::table($kind).' SET '.implode(',',$sets).' WHERE id=:id AND workspace_id=:workspace');
                    $stmt->execute(['id'=>$id,'workspace'=>$workspace,...$patch]);
                }
            } catch(PDOException $e){self::failDatabase($e);}
            if ($stmt->rowCount()!==1) throw new HttpException(409,'IPAM resource changed.','conflict');
            $result=$action==='deleted'?$old:$this->raw($pdo,$kind,$workspace,$id);
            if ($result===null) throw new \RuntimeException('Updated IPAM resource missing.');
            $this->audit->record($pdo,$actor,'inventory.ipam.'.$kind.'.'.$action,'success','ipam_'.$kind,$id,[],$requestId);
            return $result;
        },3);
    }
    private function assertReferences(PDO $pdo,string $kind,string $workspace,array $payload):void
    {
        if ($kind==='interface' && isset($payload['asset_id'])) {
            $stmt=$pdo->prepare('SELECT 1 FROM inventory_assets WHERE id=:id AND workspace_id=:ws AND deleted_at IS NULL');
            $stmt->execute(['id'=>$payload['asset_id'],'ws'=>$workspace]);
            if (!$stmt->fetchColumn()) throw new HttpException(422,'Interface asset does not exist in the workspace.','validation_failed');
        }
        if ($kind==='address' && isset($payload['network_id'])) {
            $stmt=$pdo->prepare('SELECT cidr::text FROM inventory_networks WHERE id=:id AND workspace_id=:ws');
            $stmt->execute(['id'=>$payload['network_id'],'ws'=>$workspace]);$cidr=$stmt->fetchColumn();
            if (!is_string($cidr)) throw new HttpException(422,'Network not found in workspace.','validation_failed');
            if (isset($payload['address']) && !Network::contains($cidr,$payload['address'])) Network::invalid('address');
        }
        if ($kind==='address' && isset($payload['interface_id'])) {
            $stmt=$pdo->prepare('SELECT 1 FROM inventory_interfaces i JOIN inventory_assets a ON a.id=i.asset_id AND a.deleted_at IS NULL WHERE i.id=:id AND i.workspace_id=:ws');
            $stmt->execute(['id'=>$payload['interface_id'],'ws'=>$workspace]);
            if (!$stmt->fetchColumn()) throw new HttpException(422,'Interface not found in workspace.','validation_failed');
        }
    }
}
