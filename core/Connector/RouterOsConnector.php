<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Metrics\MetricBatch;
use Cajeer\Servers\Metrics\MetricDriver;

/** Read-only RouterOS 7 connector. The router's identity is pinned independently of its TLS certificate. */
final readonly class RouterOsConnector implements ConnectorInterface, MetricDriver
{
    public const SCOPES=['inventory','network','routing'];
    public function __construct(private HttpsTransport $https,private ?string $caFile=null){}
    public function manifest():ConnectorManifest{return new ConnectorManifest('routeros','api',['routeros_api'],ConnectorManifest::OPERATIONS,['router'],'https');}
    private function client(ConnectorContext $context,#[\SensitiveParameter] string $secret):RouterOsApiClient{return new RouterOsApiClient($this->https,$context,$secret,$this->caFile);}
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if(!$this->manifest()->supports($operation))RouterOsApiClient::invalid();
        if(in_array($operation,['inspect','sync'],true))return $this->discovery($context,'inventory',$operation,$secret);
        $api=$this->client($context,$secret);$name=$api->identity();$r=$api->resource();$api->identity();
        return new ConnectorResult([['type'=>'router','external_id'=>'device','fields'=>['name'=>$name,'status'=>'online']]],false,[],OsNetworkProfile::diagnostics($context)+['tls_pinned'=>true,'version'=>$r['version']]);
    }
    public function discovery(ConnectorContext $context,string $scope,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if(!in_array($scope,self::SCOPES,true)||!in_array($operation,['inspect','sync'],true)||($scope!=='inventory'&&$operation!=='inspect'))RouterOsApiClient::invalid();
        $api=$this->client($context,$secret);$name=$api->identity();$resource=$api->resource();
        $sections=['identity'=>[['name'=>$name]],'resource'=>[$resource]];$visibility=['identity'=>'available','resource'=>'available'];
        $menus=match($scope){'inventory'=>['hardware','interfaces'],'network'=>['interfaces','bridges','bridge_ports','bridge_vlans','vlans','bonds','ipv4','ipv6','pools','dhcp_leases','neighbors'],'routing'=>['routes4','routes6','routing_tables','firewall_filter','firewall_nat','firewall6_filter','address_lists','address6_lists']};
        foreach($menus as $menu){
            try{$sections[$menu]=$api->read($menu);$visibility[$menu]='available';}
            catch(ConnectorTransportException $e){if(!in_array($e->httpStatus,[403,404],true))throw $e;$sections[$menu]=[];$visibility[$menu]=$e->httpStatus===403?'denied':'not_found';}
        }
        $api->identity();
        // RouterOS interface names are local to the device. Resolve only exact visible names.
        if($scope==='network'){
            $byName=[];foreach($sections['interfaces'] as $i)$byName[$i['name']]=$i['.id'];
            foreach(['bridge_ports','vlans','ipv4','ipv6','neighbors'] as $menu)foreach($sections[$menu] as &$row){
                $iface=$row['interface']??null;$row['interface-id']=is_string($iface)?($byName[$iface]??null):null;
            }unset($row);
            foreach($sections['bridge_ports'] as &$row){$bridge=$row['bridge'];$row['bridge-id']=is_string($bridge)?($byName[$bridge]??null):null;}unset($row);
        }
        $counts=array_map('count',$sections);
        if(array_sum($counts)>8000)RouterOsApiClient::invalid();
        $snapshot=['scope'=>$scope,'complete'=>!in_array('denied',$visibility,true)&&!in_array('not_found',$visibility,true),'sections'=>$sections,'counts'=>$counts,'visibility'=>$visibility];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR))>750000)RouterOsApiClient::invalid();
        $items=$scope==='inventory'?[['type'=>'router','external_id'=>'device','fields'=>['name'=>$name,'status'=>'online']]]:[];
        return new ConnectorResult($items,false,[],OsNetworkProfile::diagnostics($context)+['tls_pinned'=>true,'routeros_discovery'=>$snapshot]);
    }
    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        $api=$this->client($context,$secret);$api->identity();$r=$api->resource();$interfaces=$api->read('interfaces');$api->identity();$samples=[];
        foreach(['cpu-load'=>'percent','total-memory'=>'bytes','free-memory'=>'bytes','total-hdd-space'=>'bytes','free-hdd-space'=>'bytes'] as $key=>$unit)if($r[$key]!==null)$samples[]=MetricBatch::sample('routeros.'.str_replace('-','_',$key),$r[$key],'device',[],'gauge',$unit);
        foreach($interfaces as $i){foreach(['rx-byte','tx-byte','rx-packet','tx-packet','rx-error','tx-error'] as $key){$v=$i[$key];if($v===null)continue;if(!preg_match('/^[0-9]{1,20}$/D',$v))RouterOsApiClient::invalid();$samples[]=MetricBatch::sample('routeros.interface.'.str_replace('-','_',$key),$v,'device',['interface_id'=>$i['.id']],'counter',str_ends_with($key,'byte')?'bytes':null);}}
        return MetricBatch::normalize($samples);
    }
}
