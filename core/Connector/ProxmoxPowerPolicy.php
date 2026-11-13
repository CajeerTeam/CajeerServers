<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;

/** Closed vocabulary and live safety checks for direct (non-HA) guest power actions. */
final class ProxmoxPowerPolicy
{
    public const ACTIONS=['vm.start','vm.shutdown','vm.stop','vm.reboot','lxc.start','lxc.shutdown','lxc.stop','lxc.reboot'];
    public static function matches(string $action,string $external):bool
    {
        return in_array($action,self::ACTIONS,true)&&preg_match('/^(qemu|lxc):[1-9][0-9]{0,8}$/D',$external)===1
            && (str_starts_with($action,'vm.')===str_starts_with($external,'qemu:'));
    }
    public static function operation(string $action):string
    { if(!in_array($action,self::ACTIONS,true))throw new \InvalidArgumentException('Unsupported power action.');return explode('.',$action)[1]; }
    public static function deny(string $code,string $message):never {throw new HttpException(409,$message,$code);}
    /** Returns only identity/state evidence, not raw config or secrets. */
    public static function inspect(string $action,string $type,int $id,mixed $config,mixed $status):array
    {
        if(!$config instanceof \stdClass||!$status instanceof \stdClass)throw new ConnectorTransportException('connector_protocol_error');
        if(!self::matches($action,$type.':'.$id))throw new \InvalidArgumentException('Invalid guest action.');
        foreach([$config,$status] as $data){
            if(isset($data->lock)&&$data->lock!=='')self::deny('guest_locked','Guest has a Proxmox configuration lock.');
            if(isset($data->template)&&!in_array($data->template,[0,'0',false],true))self::deny('guest_template','Templates cannot be powered.');
        }
        if(isset($status->vmid)&&!in_array($status->vmid,[$id,(string)$id],true))throw new ConnectorTransportException('connector_protocol_error');
        // HA requests enqueue another HA operation rather than confirming a guest transition.
        // Do not send direct power actions when HA ownership is unknown or managed.
        if(isset($status->ha)){
            if(!$status->ha instanceof \stdClass||!in_array($status->ha->managed??null,[0,'0',false],true))self::deny('guest_ha_managed','HA-managed or ambiguous guests require the Proxmox HA manager.');
        }
        $state=$status->status??null;$op=self::operation($action);
        if(!in_array($state,['running','stopped'],true))self::deny('guest_state_conflict','Guest state is not safe for this action.');
        if($type==='qemu'&&$state==='running'&&($status->qmpstatus??null)!=='running')self::deny('guest_state_conflict','QEMU is paused or its running state is unverified.');
        if(($op==='start'&&$state!=='stopped')||($op!=='start'&&$state!=='running'))self::deny('guest_state_conflict','Guest is not in the required state for this action.');
        $projected=ProxmoxGuestDiscovery::config($config,$type);
        // PVE config digest is a revision fence between prepare and the POST. It is not a generation ID.
        $digest=$config->digest??null;
        if(!is_string($digest)||!preg_match('/^[a-fA-F0-9]{40,64}$/D',$digest))throw new ConnectorTransportException('connector_protocol_error');
        return ['state'=>$state,'digest'=>strtolower($digest),'fingerprint'=>$projected['identity_fingerprint']??null];
    }
}
