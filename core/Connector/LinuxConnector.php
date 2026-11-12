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
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if ($context->provider!=='ssh'||!$this->manifest()->supports($operation)||!$this->manifest()->accepts($context->credentialType,'ssh')) throw new \RuntimeException('Unsupported SSH operation.');
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try { return HostObservation::result($this->transport->collect($context,$credential),'ssh',$operation); }
        finally { foreach ($credential as &$value) if (is_string($value)) sodium_memzero($value); unset($value); }
    }
}
