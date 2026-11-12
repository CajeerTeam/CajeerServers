<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;

/** One network appliance per target. Interface indices are attributes, not global asset identities. */
final class RouterConnector implements ConnectorInterface
{
    public function __construct(private readonly SnmpTransport $transport) {}
    public function manifest():ConnectorManifest
    {return new ConnectorManifest('snmp','snmp',$this->transport->credentialTypes(),ConnectorManifest::OPERATIONS,['router'],'snmp');}
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if (!$this->manifest()->supports($operation)||!$this->manifest()->accepts($context->credentialType,'snmp')) throw new ConnectorTransportException('connector_auth_failed');
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try {$data=$this->transport->collect($context,$credential,in_array($operation,['health','validate_rotation'],true));}
        finally {foreach($credential as &$value)if(is_string($value))sodium_memzero($value);unset($value);}
        $fields=['name'=>$data['name'],'status'=>'online'];
        if(isset($data['interfaces'])) {
            $fields['attributes']=['network_device'=>['object_id'=>$data['object_id'],'description'=>$data['description'],'interfaces'=>$data['interfaces']]];
            if ($data['addresses']!==[]) $fields['ip_addresses']=$data['addresses']; // empty/invisible IP table does not erase existing addresses
        }
        // Endpoint-scoped identity is stable over sysName changes; never infer global identity from IP/name.
        return new ConnectorResult([['type'=>'router','external_id'=>'device','fields'=>$fields]],false);
    }
}
