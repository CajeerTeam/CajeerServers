<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Immutable non-secret connection context. */
final readonly class ConnectorContext
{
    public string $host;
    public int $port;
    public function __construct(public string $provider,string $host,int $port,public string $credentialType)
    {
        $this->host=ConnectorBindingService::validateHost($host);
        $this->port=ConnectorBindingService::validatePort($port);
    }
}
