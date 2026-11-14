<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;

final class WindowsConnector implements ConnectorInterface, \Cajeer\Servers\Metrics\MetricDriver
{
    public function __construct(private readonly HostTransport $transport) {}
    public function manifest():ConnectorManifest
    { return new ConnectorManifest('winrm','winrm',['winrm'],ConnectorManifest::OPERATIONS,['server'],'winrm'); }
    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try{$data=$this->transport->collect($context,$credential,true);return \Cajeer\Servers\Metrics\HostMetrics::normalize($data,$context->provider);}
        finally{foreach($credential as &$value)if(is_string($value))sodium_memzero($value);unset($value);}
    }
    public function discovery(ConnectorContext $context,string $scope,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->provider!=='winrm'||!$this->manifest()->accepts($context->credentialType,'winrm')||!in_array($scope,WindowsDiscovery::SCOPES,true)||$context->osNetworkProfile===null)OsNetworkProfile::mismatch();
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try {
            $snapshot=WindowsDiscovery::normalize($this->transport->collect($context,$credential,false,$scope),$scope);
            return new ConnectorResult([],false,[],OsNetworkProfile::diagnostics($context)+['windows_discovery'=>$snapshot]);
        } finally {foreach($credential as &$value)if(is_string($value))sodium_memzero($value);unset($value);}
    }
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if ($context->provider!=='winrm'||!$this->manifest()->supports($operation)||!$this->manifest()->accepts($context->credentialType,'winrm')) throw new \RuntimeException('Unsupported WinRM operation.');
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try { $result=HostObservation::result($this->transport->collect($context,$credential),'winrm',$operation);return new ConnectorResult($result->items,$result->complete,[],OsNetworkProfile::diagnostics($context)); }
        finally { foreach ($credential as &$value) if (is_string($value)) sodium_memzero($value); unset($value); }
    }
}
