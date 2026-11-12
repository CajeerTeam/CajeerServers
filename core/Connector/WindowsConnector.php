<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;

final class WindowsConnector implements ConnectorInterface
{
    public function __construct(private readonly HostTransport $transport) {}
    public function manifest():ConnectorManifest
    { return new ConnectorManifest('winrm','winrm',['winrm'],ConnectorManifest::OPERATIONS,['server'],'winrm'); }
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if ($context->provider!=='winrm'||!$this->manifest()->supports($operation)||!$this->manifest()->accepts($context->credentialType,'winrm')) throw new \RuntimeException('Unsupported WinRM operation.');
        $credential=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        try { return HostObservation::result($this->transport->collect($context,$credential),'winrm',$operation); }
        finally { foreach ($credential as &$value) if (is_string($value)) sodium_memzero($value); unset($value); }
    }
}
