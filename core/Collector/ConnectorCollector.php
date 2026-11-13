<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorResult;
use Cajeer\Servers\Integration\ConnectorRegistry;

/** Reuses the actual API, host or SNMP collector inside the registered connector. */
final readonly class ConnectorCollector implements CollectorInterface
{
    public function __construct(private string $id, private string $driver, private ConnectorRegistry $connectors) {}
    public function name(): string {return $this->id;}
    public function provider(): string {return $this->driver;}
    public function collect(CollectionContext $context, #[\SensitiveParameter] string $secret): ConnectorResult
    {
        if($this->id==='proxmox.tasks')return $this->connectors->tasks($context->connector,$context->plan->operation,$context->purpose,$secret,$context->previous);
        if($this->id==='proxmox.storage-network')return $this->connectors->storageNetwork($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='proxmox.guests')return $this->connectors->guests($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='proxmox.cluster')return $this->connectors->cluster($context->connector,$context->plan->operation,$context->purpose,$secret);
        return $this->connectors->execute($context->connector,$context->plan->operation,$context->purpose,$secret);
    }
}
