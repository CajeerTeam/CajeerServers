<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Disk snapshots only. No force, skiplock, RAM restore or implicit power changes. */
final class ProxmoxSnapshotPolicy
{
    public const ACTIONS=['vm.snapshot.create','vm.snapshot.delete','vm.snapshot.rollback','lxc.snapshot.create','lxc.snapshot.delete','lxc.snapshot.rollback'];
    public const ALL_ACTIONS=[...ProxmoxPowerPolicy::ACTIONS,...self::ACTIONS];
    /** One canonical mapping for dispatch validation and persisted UPID reconciliation. */
    public static function taskWorkers():array
    {
        $workers=[];
        foreach(self::ALL_ACTIONS as $action){
            $parts=explode('.',$action);
            $worker=count($parts)===2?$parts[1]:match($parts[2]){'create'=>'snapshot','delete'=>'delsnapshot','rollback'=>'rollback'};
            $workers[$action]=($parts[0]==='vm'?'qm':'vz').$worker;
        }
        return $workers;
    }
    public static function name(mixed $name):bool
    {return is_string($name)&&preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D',$name)===1&&!in_array(strtolower($name),['current','vzdump','pending'],true);}
    public static function matches(string $action,string $external):bool
    {return in_array($action,self::ACTIONS,true)&&ProxmoxPowerPolicy::matches(str_starts_with($action,'vm.')?'vm.start':'lxc.start',$external);}
    public static function op(string $action):string {if(!in_array($action,self::ACTIONS,true))throw new \InvalidArgumentException('Invalid snapshot action.');return explode('.',$action)[2];}
    /** Strict projection; current is a pseudo snapshot, never a mutation target. */
    public static function rows(mixed $data):array
    {
        $rows=ProxmoxApiClient::rows($data);if(count($rows)>4096)throw new ConnectorTransportException('connector_protocol_error');$out=[];
        foreach($rows as $r){
            $name=$r['name']??null;if((!is_string($name)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D',$name))||isset($out[$name]))throw new ConnectorTransportException('connector_protocol_error');
            $parent=$r['parent']??null;if($parent!==null&&(!is_string($parent)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D',$parent)))throw new ConnectorTransportException('connector_protocol_error');
            $time=$r['snaptime']??null;if($time!==null&&(!is_int($time)||$time<0))throw new ConnectorTransportException('connector_protocol_error');
            $state=$r['snapstate']??null;if($state!==null&&(!is_string($state)||strlen($state)>64))throw new ConnectorTransportException('connector_protocol_error');
            $description=$r['description']??'';if(!is_string($description))throw new ConnectorTransportException('connector_protocol_error');
            $out[$name]=['name'=>$name,'parent'=>$parent,'created_at'=>$time,'state'=>$state,'description'=>$description,'revision'=>hash('sha256',json_encode([$name,$parent,$time,$state,$description],JSON_THROW_ON_ERROR))];
        }
        ksort($out);return array_values($out);
    }
    public static function check(string $action,string $name,?string $revision,array $rows,string $state):void
    {
        $op=self::op($action);$existing=null;foreach($rows as $r)if($r['name']===$name)$existing=$r;
        if($op==='create'&&$existing!==null)ProxmoxPowerPolicy::deny('snapshot_exists','Snapshot name already exists.');
        if($op!=='create'&&($existing===null||$existing['revision']!==$revision||$existing['state']!==null))ProxmoxPowerPolicy::deny('snapshot_conflict','Snapshot is missing, changed or incomplete. Reload the list.');
        if($op==='rollback'&&$state!=='stopped')ProxmoxPowerPolicy::deny('guest_state_conflict','Stop the guest before rollback.');
    }
    public static function consoleUrl(ConnectorContext $ctx,string $node,string $type,int $id):string
    {
        $host=ConnectorBindingService::validateHost($ctx->host);$port=ConnectorBindingService::validatePort($ctx->port);
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D',$node)||!in_array($type,['qemu','lxc'],true)||$id<1||$id>999999999)throw new \InvalidArgumentException('Invalid console resource.');
        return 'https://'.(str_contains($host,':')?'['.$host.']':$host).':'.$port.'/?'.http_build_query(['console'=>$type==='qemu'?'kvm':'lxc','vmid'=>$id,'node'=>$node,$type==='qemu'?'novnc':'xtermjs'=>1],'','&',PHP_QUERY_RFC3986);
    }
}
