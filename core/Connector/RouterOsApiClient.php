<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** RouterOS 7 REST: fixed GET-only menus and property lists, never console commands. */
final class RouterOsApiClient
{
    private const MENUS = [
        'identity'=>['system/identity','name'],
        'resource'=>['system/resource','version,platform,board-name,architecture-name,cpu,cpu-count,cpu-load,total-memory,free-memory,total-hdd-space,free-hdd-space,uptime'],
        'hardware'=>['system/routerboard','routerboard,model,serial-number,current-firmware,upgrade-firmware'],
        'interfaces'=>['interface','.id,name,type,mac-address,mtu,actual-mtu,running,disabled,rx-byte,tx-byte,rx-packet,tx-packet,rx-error,tx-error'],
        'bridges'=>['interface/bridge','.id,name,mac-address,vlan-filtering,disabled'],
        'bridge_ports'=>['interface/bridge/port','.id,bridge,interface,pvid,disabled,hw'],
        'bridge_vlans'=>['interface/bridge/vlan','.id,bridge,vlan-ids,tagged,untagged,current-tagged,current-untagged,dynamic'],
        'vlans'=>['interface/vlan','.id,name,interface,vlan-id,running,disabled'],
        'bonds'=>['interface/bonding','.id,name,slaves,mode,running,disabled'],
        'ipv4'=>['ip/address','.id,address,network,interface,actual-interface,disabled,dynamic,invalid'],
        'ipv6'=>['ipv6/address','.id,address,interface,disabled,dynamic,invalid,advertise'],
        'pools'=>['ip/pool','.id,name,ranges,next-pool'],
        'dhcp_leases'=>['ip/dhcp-server/lease','.id,address,mac-address,server,status,dynamic,disabled'],
        'neighbors'=>['ip/neighbor','.id,interface,address,address6,mac-address,identity,platform,version,interface-name'],
        'routes4'=>['ip/route','.id,dst-address,gateway,immediate-gw,routing-table,distance,scope,target-scope,active,dynamic,disabled'],
        'routes6'=>['ipv6/route','.id,dst-address,gateway,immediate-gw,routing-table,distance,scope,target-scope,active,dynamic,disabled'],
        'routing_tables'=>['routing/table','.id,name,fib,disabled'],
        'firewall_filter'=>['ip/firewall/filter','.id,chain,action,protocol,src-address,dst-address,src-port,dst-port,in-interface,out-interface,in-interface-list,out-interface-list,connection-state,disabled,dynamic,invalid,log,packets,bytes'],
        'firewall_nat'=>['ip/firewall/nat','.id,chain,action,protocol,src-address,dst-address,src-port,dst-port,in-interface,out-interface,to-addresses,to-ports,disabled,dynamic,invalid,packets,bytes'],
        'firewall6_filter'=>['ipv6/firewall/filter','.id,chain,action,protocol,src-address,dst-address,src-port,dst-port,in-interface,out-interface,connection-state,disabled,dynamic,invalid,packets,bytes'],
        'address_lists'=>['ip/firewall/address-list','.id,list,address,disabled,dynamic'],
        'address6_lists'=>['ipv6/firewall/address-list','.id,list,address,disabled,dynamic'],
    ];
    private string $auth;
    private int $deadline;
    public function __construct(private readonly HttpsTransport $https,private readonly ConnectorContext $context,#[\SensitiveParameter] string $secret,private readonly ?string $caFile)
    {
        if($context->provider!=='routeros'||$context->credentialType!=='routeros_api'||$context->osNetworkProfile===null)OsNetworkProfile::mismatch();
        $cred=\Cajeer\Servers\Credentials\CredentialProfile::decodeForInternalUse('routeros_api',$secret);
        if(!is_array($cred)||str_contains($cred['username'],':'))throw new ConnectorTransportException('connector_auth_failed');
        $this->auth='Basic '.base64_encode($cred['username'].':'.$cred['password']);
        foreach($cred as &$value)sodium_memzero($value);unset($value);
        $this->deadline=hrtime(true)+60_000_000_000;
    }
    public function __destruct(){if(isset($this->auth))sodium_memzero($this->auth);}
    /** Only selected scalar properties survive; menu order is preserved for firewall rules. */
    public function read(string $menu):array
    {
        if(!isset(self::MENUS[$menu]))throw new \LogicException('Unknown RouterOS menu.');
        if(hrtime(true)>=$this->deadline)throw new ConnectorTransportException('connector_transport_failed');
        [$path,$properties]=self::MENUS[$menu];
        $body=$this->https->get($this->context->host,$this->context->port,'/rest/'.$path.'?.proplist='.rawurlencode($properties),$this->auth,$this->caFile,'Authorization',$this->context->osNetworkProfile['primary_pin']);
        try{$data=json_decode($body,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){self::invalid();}
        if($data instanceof \stdClass){if(isset($data->error)||isset($data->message)||!in_array($menu,['identity','resource','hardware'],true))self::invalid();$data=[$data];}
        if(!is_array($data)||count($data)>4000)self::invalid();
        $out=[];$ids=[];$fields=explode(',',$properties);
        foreach($data as $row){
            if(!$row instanceof \stdClass||isset($row->error)||isset($row->message))self::invalid();
            $clean=[];foreach($fields as $key){
                $v=$row->{$key}??null;
                if($v===null){$clean[$key]=null;continue;}
                if(!is_string($v)||strlen($v)>1024||!preg_match('/^[^\x00-\x1f\x7f]*$/uD',$v))self::invalid();
                $clean[$key]=$v;
            }
            if(in_array('.id',$fields,true)){
                $id=$clean['.id'];if(!is_string($id)||!preg_match('/^\*[0-9A-Fa-f]+$/D',$id)||isset($ids[$id]))self::invalid();$ids[$id]=true;
            }
            $out[]=$clean;
        }
        if($menu==='interfaces'){
            $names=[];foreach($out as $r){$name=$r['name'];if(!is_string($name)||trim($name)===''||isset($names[$name]))self::invalid();$names[$name]=true;}
        }
        return $out;
    }
    public function identity():string
    {
        $rows=$this->read('identity');$name=$rows[0]['name']??null;
        if(count($rows)!==1||!is_string($name)||trim($name)===''||strlen($name)>128||!hash_equals($this->context->osNetworkProfile['identity_hash'],hash('sha256','routeros:'.$name)))OsNetworkProfile::mismatch();
        return $name;
    }
    public function resource():array
    {
        $rows=$this->read('resource');$r=$rows[0]??[];
        if(count($rows)!==1||!preg_match('/^7\.[0-9]+(?:[.0-9]*)(?:[a-zA-Z0-9]+)?(?: \([^)]{1,64}\))?$/D',$r['version']??'')||($r['platform']??null)!=='MikroTik')self::invalid();
        foreach(['cpu-count','cpu-load','total-memory','free-memory','total-hdd-space','free-hdd-space'] as $key)if($r[$key]!==null&&!preg_match('/^[0-9]{1,20}$/D',$r[$key]))self::invalid();
        if($r['cpu-load']!==null&&(float)$r['cpu-load']>100)self::invalid();
        return $r;
    }
    public static function invalid():never{throw new ConnectorTransportException('connector_protocol_error');}
}
