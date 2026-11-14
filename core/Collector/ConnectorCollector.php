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
        if(in_array($this->id,['ssh.events','winrm.events'],true))return $this->connectors->systemEvents($context->connector,$context->plan->operation,$context->purpose,$secret,$context->previous);
        if($this->driver==='routeros'&&in_array(substr($this->id,9),\Cajeer\Servers\Connector\RouterOsConnector::SCOPES,true))return $this->connectors->routerosDiscovery($context->connector,$context->plan->operation,$context->purpose,substr($this->id,9),$secret);
        if($this->driver==='ssh'&&in_array(substr($this->id,4),\Cajeer\Servers\Connector\LinuxDiscovery::SCOPES,true))return $this->connectors->linuxDiscovery($context->connector,$context->plan->operation,$context->purpose,substr($this->id,4),$secret);
        if($this->driver==='winrm'&&in_array(substr($this->id,6),\Cajeer\Servers\Connector\WindowsDiscovery::SCOPES,true))return $this->connectors->windowsDiscovery($context->connector,$context->plan->operation,$context->purpose,substr($this->id,6),$secret);
        if($this->driver==='snmp'&&in_array(substr($this->id,5),\Cajeer\Servers\Connector\SnmpDiscovery::SCOPES,true))return $this->connectors->snmpDiscovery($context->connector,$context->plan->operation,$context->purpose,substr($this->id,5),$secret);
        if($this->id==='vmmanager.storage')return $this->connectors->vmmanagerStorage($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='vmmanager.tasks')return $this->connectors->vmmanagerTasks($context->connector,$context->plan->operation,$context->purpose,$secret,$context->previous);
        if($this->id==='vmmanager.templates')return $this->connectors->vmmanagerTemplates($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='vmmanager.network')return $this->connectors->vmmanagerNetwork($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='vmmanager.guests')return $this->connectors->vmmanagerGuests($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='vmmanager.cluster')return $this->connectors->vmmanagerCluster($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='proxmox.tasks')return $this->connectors->tasks($context->connector,$context->plan->operation,$context->purpose,$secret,$context->previous);
        if($this->id==='proxmox.storage-network')return $this->connectors->storageNetwork($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='proxmox.guests')return $this->connectors->guests($context->connector,$context->plan->operation,$context->purpose,$secret);
        if($this->id==='proxmox.cluster')return $this->connectors->cluster($context->connector,$context->plan->operation,$context->purpose,$secret);
        return $this->connectors->execute($context->connector,$context->plan->operation,$context->purpose,$secret);
    }
}
