<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;

/** Closed action vocabulary. No force, arbitrary body or caller-controlled remote URL. */
final class VmmanagerPowerPolicy
{
    public const ACTIONS=['vmmanager.host.start','vmmanager.host.stop','vmmanager.host.reboot'];
    public static function matches(string $action,string $external):bool
    {return in_array($action,self::ACTIONS,true)&&preg_match('/^host:[1-9][0-9]{0,9}$/D',$external)===1&&(int)substr($external,5)<=2147483647;}
    public static function operation(string $action):string
    {return match($action){self::ACTIONS[0]=>'start',self::ACTIONS[1]=>'stop',self::ACTIONS[2]=>'restart',default=>throw new \InvalidArgumentException('Unsupported VMmanager action.')};}
    public static function deny(string $code):never
    {throw new HttpException(409,'VMmanager guest is not safe for this operation.',$code);}
    public static function fingerprint(array $guest):string
    {
        foreach(['id','name','virtualization_type','node_id','cluster_id','account_id'] as $key)if(!array_key_exists($key,$guest))self::deny('guest_identity_conflict');
        if($guest['node_id']===null||$guest['cluster_id']===null||!in_array($guest['virtualization_type'],['kvm','lxd'],true))self::deny('guest_identity_conflict');
        // Stable evidence only; state is intentionally not part of the identity.
        return hash('sha256',json_encode([$guest['id'],$guest['name'],$guest['virtualization_type'],$guest['node_id'],$guest['cluster_id'],$guest['account_id'],array_column($guest['disks']??[],'id'),array_column($guest['interfaces']??[],'id')],JSON_THROW_ON_ERROR));
    }
    public static function preflight(string $action,array $guest,\stdClass $host,\stdClass $node):void
    {
        if(($host->ha_restore_on_fail??null)!==false||($host->rescue_mode??null)!==false)self::deny('guest_ha_or_rescue');
        if(($node->maintenance_mode??null)!==false||!in_array($node->state??null,['active','online','running'],true))self::deny('guest_node_unavailable');
        foreach(['locked','lock','template'] as $field)if(isset($host->$field)&&!in_array($host->$field,[false,0,'0',''],true))self::deny('guest_locked');
        $expected=self::operation($action)==='start'?'stopped':'running';
        if($guest['state']!==$expected)self::deny('guest_state_conflict');
    }
    /** Vendor task response uses Consul IDs, not the task list's numeric row ID. */
    public static function receipt(string $action,array $resource,\stdClass $response):string
    {
        $op=self::operation($action);$id=$resource['guest']['id'];
        if(isset($response->id)&&VmmanagerApiClient::id($response->id)!==$id)throw new ConnectorTransportException('connector_protocol_error');
        $first=VmmanagerApiClient::id($response->task??null);
        $second=$op==='restart'?VmmanagerApiClient::id($response->start_task??null):null;
        if($second===$first||($op!=='restart'&&isset($response->start_task)))throw new ConnectorTransportException('connector_protocol_error');
        return 'vmm:'.$id.':'.$op.':'.$first.':'.($second??0).':'.$resource['identity']['owner_id'].':'.$resource['fingerprint'];
    }
    public static function parse(string $task):array
    {
        if(!preg_match('/^vmm:([1-9][0-9]{0,9}):(start|stop|restart):([1-9][0-9]{0,9}):(0|[1-9][0-9]{0,9}):([1-9][0-9]{0,9}):([a-f0-9]{64})$/D',$task,$m))throw new ConnectorTransportException('connector_protocol_error');
        $id=VmmanagerApiClient::id($m[1]);$first=VmmanagerApiClient::id($m[3]);$second=$m[4]==='0'?null:VmmanagerApiClient::id($m[4]);
        if(($m[2]==='restart')!==($second!==null)||$second===$first)throw new ConnectorTransportException('connector_protocol_error');
        return ['host'=>$id,'op'=>$m[2],'owner'=>VmmanagerApiClient::id($m[5]),'fingerprint'=>$m[6],'tasks'=>$second!==null?[[$first,'host_stop'],[$second,'host_start']]:[[$first,'host_'.$m[2]]]];
    }
}
