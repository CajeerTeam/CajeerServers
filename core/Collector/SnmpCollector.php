<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorResult;
use Cajeer\Servers\Integration\ConnectorRegistry;

/** Network inventory collector: reuses the bounded SNMP GET/GETNEXT driver and its Vault policy. */
final readonly class SnmpCollector implements CollectorInterface
{
    public function __construct(private ConnectorRegistry $connectors) {}
    public function name():string {return 'snmp.inventory';}
    public function provider():string {return 'snmp';}
    public function collect(CollectionContext $context, #[\SensitiveParameter] string $secret):ConnectorResult
    {return $this->connectors->execute($context->connector,$context->plan->operation,$context->purpose,$secret);}
}
