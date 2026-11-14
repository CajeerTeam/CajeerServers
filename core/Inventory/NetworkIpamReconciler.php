<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use PDO;
use Cajeer\Servers\Http\Exception\HttpException;

/** Additive IPAM import. The caller owns the workspace lock and has checked the snapshot fence. */
final class NetworkIpamReconciler
{
    private const TABLE=['network'=>'inventory_networks','interface'=>'inventory_interfaces','address'=>'inventory_ip_addresses'];
    private array $report=['created'=>0,'unchanged'=>0,'conflicts'=>0,'skipped'=>0,'issues'=>[]];
    private array $seen=[];
    private function __construct(private PDO $pdo,private string $ws,private string $target){}
    private function query(string $sql,array $params):\PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($params);return $q;}
    private function one(string $sql,array $params):?array {return $this->query($sql,$params)->fetch(PDO::FETCH_ASSOC)?:null;}
    private function issue(string $kind,string $key,string $code,bool $conflict=true):void {
        $this->report[$conflict?'conflicts':'skipped']++;if(count($this->report['issues'])<250)$this->report['issues'][]=['kind'=>$kind,'key'=>$key,'code'=>$code];
    }
    private function scope():array{return ['ws'=>$this->ws,'target'=>$this->target];}
    private function owned(string $kind,string $key,array $fields):?string {
        $m=$this->one('SELECT object_id::text,object_version FROM network_discovery_ipam_import WHERE workspace_id=:ws AND target_id=:target AND kind=:kind AND external_key=:key',$this->scope()+['kind'=>$kind,'key'=>$key]);if(!$m)return null;
        $this->seen[$kind.':'.$key]=true;
        $r=$this->one('SELECT * FROM '.self::TABLE[$kind].' WHERE workspace_id=:ws AND id=:id FOR UPDATE',['ws'=>$this->ws,'id'=>$m['object_id']]);
        if(!$r||(int)$r['version']!==(int)$m['object_version']){$this->issue($kind,$key,'locally_changed_or_deleted');return '';}
        foreach($fields as $field=>$value){$actual=$r[$field]??null;if($field==='address'&&is_string($actual))$actual=explode('/',$actual)[0];
            if(($actual===null)!==($value===null)||($actual!==null&&(string)$actual!==(string)$value)){$this->issue($kind,$key,'remote_changed');return '';}}
        $this->report['unchanged']++;return $m['object_id'];
    }
    private function create(string $kind,string $key,array $fields):string {
        $values=['workspace_id'=>$this->ws]+$fields;$cols=array_keys($values);
        $r=$this->one('INSERT INTO '.self::TABLE[$kind].'('.implode(',',$cols).') VALUES ('.implode(',',array_map(fn($v)=>':'.$v,$cols)).') RETURNING id::text,version',$values);
        $this->query('INSERT INTO network_discovery_ipam_import(target_id,workspace_id,kind,external_key,object_id,object_version) VALUES(:target,:ws,:kind,:key,:id,:version)',$this->scope()+['kind'=>$kind,'key'=>$key,'id'=>$r['id'],'version'=>$r['version']]);
        $this->report['created']++;$this->seen[$kind.':'.$key]=true;return $r['id'];
    }
    /** Reject non-assignable/local-only addresses. Private infrastructure ranges remain allowed. */
    public static function assignable(string $ip):bool {
        $b=@inet_pton($ip);if($b===false)return false;
        if(strlen($b)===4)return ord($b[0])!==0&&ord($b[0])!==127&&ord($b[0])<224&&!(ord($b[0])===169&&ord($b[1])===254);
        return $b!==str_repeat("\0",16)&&$b!==str_repeat("\0",15)."\1"&&ord($b[0])!==255&&!(ord($b[0])===254&&(ord($b[1])&192)===128)&&!str_starts_with($b,str_repeat("\0",10)."\xff\xff");
    }
    public static function networkOrBroadcast(string $ip,string $cidr):bool {
        [$base,$prefix]=explode('/',$cidr,2);$b=@inet_pton($ip);$n=@inet_pton($base);$bits=(int)$prefix;
        if($b===false||$n===false||strlen($b)!==4||strlen($n)!==4||$bits>=31)return false;
        $last='';for($i=0;$i<4;$i++){$k=max(0,min(8,$bits-$i*8));$last.=chr(ord($n[$i])|((1<<(8-$k))-1));}
        return $b===$n||$b===$last;
    }
    public static function apply(PDO $pdo,string $workspace,string $target,string $asset,array $p,?string $networkId):array {
        if(!$p['complete']||$p['target_id']!==$target||!in_array($p['provider'],['ssh','winrm','routeros'],true)||!$pdo->inTransaction())throw new \LogicException('Invalid reconciliation context.');
        return (new self($pdo,$workspace,$target))->run($asset,$p,$networkId);
    }
    private function run(string $asset,array $p,?string $networkId):array {
        $chosen=null;if($networkId!==null){$chosen=$this->one('SELECT id::text,cidr::text,vlan_id FROM inventory_networks WHERE workspace_id=:ws AND id=:id FOR UPDATE',['ws'=>$this->ws,'id'=>$networkId]);if(!$chosen)throw new HttpException(404,'IPAM network not found.','not_found');}
        $nets=[];$nics=[];$macs=[];$names=[];$ips=[];$used=[];
        if(count($p['interfaces'])+count($p['addresses'])>3000)throw new HttpException(422,'Too many IPAM observations for one reconciliation.','validation_failed');
        foreach($p['interfaces'] as $i){if($i['mac']!==null)$macs[$i['mac']]=($macs[$i['mac']]??0)+1;$names[$i['name']]=($names[$i['name']]??0)+1;}
        foreach($p['addresses'] as $a){$ips[$a['address']]=($ips[$a['address']]??0)+1;if($a['issue']===null&&$a['network_id']!==null&&$a['interface_id']!==null&&self::assignable($a['address']))$used[$a['network_id']]=true;}
        $networkById=array_column($p['networks'],null,'id');
        $candidates=array_values(array_filter($p['networks'],fn($n)=>isset($used[$n['id']])));$overlap=[];
        // Source limits cap the candidate set; avoid quadratic work on pathological hosts.
        if(count($candidates)>512)throw new HttpException(422,'Too many candidate networks for one reconciliation.','validation_failed');
        foreach($candidates as $i=>$n)for($j=$i+1;$j<count($candidates);$j++)if(Network::overlaps($n['cidr'],$candidates[$j]['cidr'])){$overlap[$n['id']]=true;$overlap[$candidates[$j]['id']]=true;}
        if($chosen!==null&&!array_filter($candidates,fn($n)=>$n['cidr']===$chosen['cidr']&&($n['vlan_id']===null||$n['vlan_id']===(int)$chosen['vlan_id'])))throw new HttpException(422,'Selected IPAM network does not match an observed CIDR/VLAN.','validation_failed');
        foreach($candidates as $n){$key=$n['id'];$fields=['cidr'=>$n['cidr'],'vlan_id'=>$n['vlan_id']];
            if(isset($overlap[$key])||str_ends_with($n['cidr'],'/0')){$this->issue('network',$key,'overlapping_or_default_network');continue;}
            $owned=$this->owned('network',$key,$fields);if($owned!==null){if($owned!=='')$nets[$key]=$owned;continue;}
            // Explicit operator selection can reuse a matching subnet but never take ownership or edit it.
            if($chosen!==null&&$chosen['cidr']===$n['cidr']&&($n['vlan_id']===null||$n['vlan_id']===(int)$chosen['vlan_id'])){$nets[$key]=$chosen['id'];continue;}
            if($this->one('SELECT id FROM inventory_networks WHERE workspace_id=:ws AND cidr && CAST(:cidr AS cidr)',['ws'=>$this->ws,'cidr'=>$n['cidr']])){$this->issue('network',$key,'local_network_overlap');continue;}
            $nets[$key]=$this->create('network',$key,['name'=>'Discovered '.$n['cidr'],'description'=>'Network discovery']+$fields);
        }
        foreach($p['interfaces'] as $i){$key=$i['id'];$name=$i['name'];$mac=$i['mac'];
            if(trim($name)===''||strlen($name)>120||preg_match('/[\x00-\x1f\x7f]/',$name)||$names[$name]>1||($mac!==null&&$macs[$mac]>1)){$this->issue('interface',$key,'ambiguous_interface');continue;}
            if($name==='lo'||$mac===null||(hexdec(substr($mac,0,2))&1)!==0){$this->issue('interface',$key,'no_stable_mac',false);continue;}
            $fields=['asset_id'=>$asset,'name'=>$name,'mac_address'=>$mac];$owned=$this->owned('interface',$key,$fields);if($owned!==null){if($owned!=='')$nics[$key]=$owned;continue;}
            if($this->one('SELECT id FROM inventory_interfaces WHERE workspace_id=:ws AND ((asset_id=:asset AND name=:name) OR mac_address=CAST(:mac AS macaddr))',['ws'=>$this->ws,'asset'=>$asset,'name'=>$name,'mac'=>$mac])){$this->issue('interface',$key,'local_interface_collision');continue;}
            $nics[$key]=$this->create('interface',$key,$fields);
        }
        foreach($p['addresses'] as $a){$key=$a['id'];$ip=$a['address'];if($a['issue']!==null||!self::assignable($ip)){$this->issue('address',$key,$a['issue']??'local_only_address',false);continue;}
            if($ips[$ip]>1){$this->issue('address',$key,'duplicate_address');continue;}
            $observedNetwork=$networkById[$a['network_id']??'']??null;if($observedNetwork&&self::networkOrBroadcast($ip,$observedNetwork['cidr'])){$this->issue('address',$key,'network_or_broadcast_address',false);continue;}
            $net=$nets[$a['network_id']??'']??null;$nic=$nics[$a['interface_id']??'']??null;
            if(!$net||!$nic){$this->issue('address',$key,'network_or_interface_not_imported',false);continue;}
            $fields=['network_id'=>$net,'address'=>$ip,'interface_id'=>$nic];$owned=$this->owned('address',$key,$fields);if($owned!==null)continue;
            if($this->one('SELECT id FROM inventory_ip_addresses WHERE workspace_id=:ws AND address=CAST(:ip AS inet)',['ws'=>$this->ws,'ip'=>$ip])){$this->issue('address',$key,'local_address_collision');continue;}
            $this->create('address',$key,$fields+['description'=>'Network discovery']);
        }
        $retained=(int)$this->query("SELECT count(*) FROM network_discovery_ipam_import WHERE workspace_id=:ws AND target_id=:target AND (kind||':'||external_key) NOT IN (SELECT jsonb_array_elements_text(CAST(:seen AS jsonb)))",$this->scope()+['seen'=>json_encode(array_keys($this->seen),JSON_THROW_ON_ERROR)])->fetchColumn();
        return $this->report+['retained_unseen'=>$retained,'applied'=>true];
    }
}
