<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Immutable non-secret connection context. */
final readonly class ConnectorContext
{
    public string $host;
    public int $port;
    public ?array $proxmoxProfile;
    public function __construct(public string $provider,string $host,int $port,public string $credentialType,?array $proxmoxProfile=null)
    {
        $this->host=ConnectorBindingService::validateHost($host);
        $this->port=ConnectorBindingService::validatePort($port);
        if($proxmoxProfile!==null&&$provider!=='proxmox')throw new \InvalidArgumentException('Proxmox profile requires Proxmox provider.');
        $this->proxmoxProfile=$proxmoxProfile===null?null:ProxmoxProfile::normalize($proxmoxProfile,$this->host,$this->port);
    }
}
