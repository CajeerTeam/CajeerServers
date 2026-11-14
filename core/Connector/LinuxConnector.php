<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;

final class LinuxConnector implements ConnectorInterface, \Cajeer\Servers\Metrics\MetricDriver
{
    public function __construct(private readonly HostTransport $transport) {}
    public function manifest():ConnectorManifest
    { return new ConnectorManifest('ssh','ssh',['ssh_password','ssh_private_key'],ConnectorManifest::OPERATIONS,['server'],'ssh'); }
    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try{$data=$this->transport->collect($context,$credential,true);return \Cajeer\Servers\Metrics\HostMetrics::normalize($data,$context->provider);}
        finally{foreach($credential as &$value)if(is_string($value))sodium_memzero($value);unset($value);}
    }
    public function discovery(ConnectorContext $context,string $scope,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->provider!=='ssh'||!$this->manifest()->accepts($context->credentialType,'ssh')||!in_array($scope,LinuxDiscovery::SCOPES,true)||$context->osNetworkProfile===null)OsNetworkProfile::mismatch();
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try {
            $snapshot=LinuxDiscovery::normalize($this->transport->collect($context,$credential,false,$scope),$scope);
            return new ConnectorResult([],false,[],OsNetworkProfile::diagnostics($context)+['linux_discovery'=>$snapshot]);
        } finally {foreach($credential as &$value)if(is_string($value))sodium_memzero($value);unset($value);}
    }
    public function events(ConnectorContext $context,#[\SensitiveParameter] string $secret,array $previous):ConnectorResult
    {
        if($context->provider!=='ssh'||$context->osNetworkProfile===null||!$this->manifest()->accepts($context->credentialType,'ssh'))OsNetworkProfile::mismatch();
        $previous=\Cajeer\Servers\Events\EventDiscovery::cursor('ssh',$previous);
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try {
            $batch=\Cajeer\Servers\Events\EventDiscovery::normalize('ssh',$this->transport->collect($context,$credential,false,'events',$previous),$previous);
            return new ConnectorResult([],false,[],['system_events'=>$batch]);
        } finally {foreach($credential as &$value)if(is_string($value))sodium_memzero($value);unset($value);}
    }
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if ($context->provider!=='ssh'||!$this->manifest()->supports($operation)||!$this->manifest()->accepts($context->credentialType,'ssh')) throw new \RuntimeException('Unsupported SSH operation.');
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try { $result=HostObservation::result($this->transport->collect($context,$credential),'ssh',$operation);return new ConnectorResult($result->items,$result->complete,[],OsNetworkProfile::diagnostics($context)); }
        finally { foreach ($credential as &$value) if (is_string($value)) sodium_memzero($value); unset($value); }
    }
}
