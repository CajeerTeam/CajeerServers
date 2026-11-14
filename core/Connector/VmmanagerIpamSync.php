<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use PDO;

/** Additive, provenance-aware IPAM reconciliation under the existing workspace/credential/job fence.
 * No adoption, deletion, or reassignment of manually managed records. */
final class VmmanagerIpamSync
{
    private const TABLE=['network'=>'inventory_networks','interface'=>'inventory_interfaces','address'=>'inventory_ip_addresses'];
    private array $report=['created'=>0,'updated'=>0,'unchanged'=>0,'conflicts'=>0,'skipped'=>0,'issues'=>[]];
    private array $seen=[];
    private function __construct(private PDO $pdo,private array $target) {}
    private function query(string $sql,array $params):\PDOStatement { $q=$this->pdo->prepare($sql);$q->execute($params);return $q; }
    private function one(string $sql,array $params):?array {return $this->query($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function issue(string $kind,string $key,string $code,bool $conflict=true):void
    {
        $this->report[$conflict?'conflicts':'skipped']++;
        if(count($this->report['issues'])<200)$this->report['issues'][]=['kind'=>$kind,'key'=>$key,'code'=>$code];
    }
    private function scope():array{return ['ws'=>$this->target['workspace_id'],'target'=>$this->target['id']];}
    /** Reuse only rows created by this target, unchanged by operators, with stable identity; an owned address may follow an explicit new NIC. */
    private function owned(string $kind,string $key,array $fields):?string
    {
        $params=$this->scope()+['kind'=>$kind,'key'=>$key];
        $mapping=$this->one('SELECT object_id::text,object_version FROM vmmanager_ipam_import WHERE target_id=:target AND workspace_id=:ws AND kind=:kind AND external_key=:key',$params);
        if(!$mapping)return null;
        $this->seen[$kind.':'.$key]=true;
        $row=$this->one('SELECT * FROM '.self::TABLE[$kind].' WHERE workspace_id=:ws AND id=:id FOR UPDATE',['ws'=>$params['ws'],'id'=>$mapping['object_id']]);
        if(!$row||(int)$row['version']!==(int)$mapping['object_version']){$this->issue($kind,$key,'locally_changed_or_deleted');return '';}
        $changes=[];
        foreach($fields as $field=>$value){
            // PostgreSQL inet renders host masks; compare canonical host strings.
            $actual=$row[$field]??null;if($field==='address'&&is_string($actual))$actual=explode('/',$actual)[0];
            if(($actual===null)!==($value===null)||($actual!==null&&(string)$actual!==(string)$value)){
                // Reassign an owned address only on positive evidence of a unique live interface.
                if($kind==='address'&&$field==='interface_id'&&$value!==null)$changes[$field]=$value;
                else {$this->issue($kind,$key,'remote_changed');return '';}
            }
        }
        if($changes!==[]){
            $updated=$this->one('UPDATE inventory_ip_addresses SET interface_id=:iface WHERE workspace_id=:ws AND id=:id RETURNING version',['iface'=>$changes['interface_id'],'ws'=>$params['ws'],'id'=>$mapping['object_id']]);
            $this->query('UPDATE vmmanager_ipam_import SET object_version=:version WHERE target_id=:target AND workspace_id=:ws AND kind=:kind AND external_key=:key',$params+['version'=>$updated['version']]);
            $this->report['updated']++;
        }else $this->report['unchanged']++;
        return $mapping['object_id'];
    }
    private function create(string $kind,string $key,array $fields):string
    {
        $values=['workspace_id'=>$this->target['workspace_id']]+$fields;$cols=array_keys($values);
        $row=$this->one('INSERT INTO '.self::TABLE[$kind].'('.implode(',',$cols).') VALUES ('.implode(',',array_map(static fn($v)=>':'.$v,$cols)).') RETURNING id::text,version',$values);
        $this->query('INSERT INTO vmmanager_ipam_import(target_id,workspace_id,kind,external_key,object_id,object_version) VALUES(:target,:ws,:kind,:key,:id,:version)',$this->scope()+['kind'=>$kind,'key'=>$key,'id'=>$row['id'],'version'=>$row['version']]);
        $this->report['created']++;$this->seen[$kind.':'.$key]=true;return $row['id'];
    }
    private function retained():int
    {
        return (int)$this->query("SELECT count(*) FROM vmmanager_ipam_import WHERE target_id=:target AND workspace_id=:ws AND (kind||':'||external_key) NOT IN (SELECT jsonb_array_elements_text(CAST(:seen AS jsonb)))",$this->scope()+['seen'=>json_encode(array_keys($this->seen),JSON_THROW_ON_ERROR)])->fetchColumn();
    }
    public static function apply(PDO $pdo,array $target,array $snapshot):array
    {
        $self=new self($pdo,$target);return $self->run($snapshot);
    }
    private function run(array $s):array
    {
        if(!$s['complete']){$this->issue('snapshot','all','restricted_visibility',false);return $this->report+['retained_unseen'=>$this->retained(),'applied'=>false];}
        $ws=$this->target['workspace_id'];$nets=[];$nics=[];$macCounts=[];$nameCounts=[];
        foreach($s['interfaces'] as $n){if($n['mac']!==null)$macCounts[$n['mac']]=($macCounts[$n['mac']]??0)+1;$name=$n['external_id'].':'.($n['name']??$n['key']);$nameCounts[$name]=($nameCounts[$name]??0)+1;}
        foreach($s['networks'] as $n){
            $key=(string)$n['id'];if($n['network_type']==='container'||$n['issue']!==null){$this->issue('network',$key,$n['issue']??'container_network');continue;}
            $fields=['cidr'=>$n['cidr'],'vlan_id'=>$n['vlan_id']];$owned=$this->owned('network',$key,$fields);
            if($owned!==null){if($owned!=='')$nets[$key]=$owned;continue;}
            if($this->one('SELECT id FROM inventory_networks WHERE workspace_id=:ws AND cidr && CAST(:cidr AS cidr)',['ws'=>$ws,'cidr'=>$n['cidr']])){$this->issue('network',$key,'local_network_overlap');continue;}
            $nets[$key]=$this->create('network',$key,['name'=>'VMmanager '.$n['cidr'],'description'=>'VMmanager discovered network']+$fields);
        }
        foreach($s['interfaces'] as $n){
            $key=$n['key'];$name=$n['name']??('nic-'.substr($key,strrpos($key,':')+1));
            if(strlen($name)>120||($n['mac']!==null&&$macCounts[$n['mac']]>1)||$nameCounts[$n['external_id'].':'.($n['name']??$key)]>1){$this->issue('interface',$key,'ambiguous_interface');continue;}
            $asset=$this->one("SELECT a.id::text FROM connector_discovered_resources d JOIN inventory_assets a ON a.id=d.asset_id AND a.workspace_id=d.workspace_id WHERE d.target_id=:target AND d.workspace_id=:ws AND d.external_id=:ext AND d.presence='seen' AND d.conflict_code IS NULL AND a.deleted_at IS NULL AND a.type IN ('virtual_machine','container')",$this->scope()+['ext'=>$n['external_id']]);
            if(!$asset){$this->issue('interface',$key,'guest_not_synchronized',false);continue;}
            $fields=['asset_id'=>$asset['id'],'name'=>$name,'mac_address'=>$n['mac']];$owned=$this->owned('interface',$key,$fields);
            if($owned!==null){if($owned!=='')$nics[$key]=$owned;continue;}
            if($this->one('SELECT id FROM inventory_interfaces WHERE workspace_id=:ws AND ( (asset_id=:asset AND name=:name) OR mac_address=CAST(:mac AS macaddr))',['ws'=>$ws,'asset'=>$asset['id'],'name'=>$name,'mac'=>$n['mac']])){$this->issue('interface',$key,'local_interface_collision');continue;}
            $nics[$key]=$this->create('interface',$key,$fields);
        }
        foreach($s['addresses'] as $a){
            $key=(string)$a['id'];if($a['issue']!==null){$this->issue('address',$key,$a['issue']);continue;}
            if(!in_array($a['status'],['busy','locked','special','service'],true)){$this->issue('address',$key,'unsupported_status',false);continue;}
            $network=$nets[$a['network_id']]??null;$nic=$a['nic_key']===null?null:($nics[$a['nic_key']]??null);
            if($network===null){$this->issue('address',$key,'network_not_imported');continue;}
            // Only explicit NIC observations justify assignment; never guess from a host-level IP list.
            if(($a['nic_key']!==null&&$nic===null)||($a['status']==='busy'&&$nic===null)){$this->issue('address',$key,'interface_not_imported',false);continue;}
            if($a['status']!=='busy'&&$nic!==null){$this->issue('address',$key,'reserved_address_has_nic');continue;}
            $fields=['network_id'=>$network,'address'=>$a['address'],'interface_id'=>$nic];$owned=$this->owned('address',$key,$fields);
            if($owned!==null)continue;
            if($this->one('SELECT id FROM inventory_ip_addresses WHERE workspace_id=:ws AND address=CAST(:ip AS inet)',['ws'=>$ws,'ip'=>$a['address']])){$this->issue('address',$key,'local_address_collision');continue;}
            $this->create('address',$key,$fields+['description'=>'VMmanager discovered address']);
        }
        return $this->report+['retained_unseen'=>$this->retained(),'applied'=>true];
    }
}
