<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;
use Cajeer\Servers\Connector\ConnectorResult;
use Cajeer\Servers\Integration\ConnectorRegistry;
final readonly class MetricsCollector implements CollectorInterface
{
    public function __construct(private string $driver,private ConnectorRegistry $connectors){}
    public function name():string{return $this->driver.'.metrics';}
    public function provider():string{return $this->driver;}
    public function collect(CollectionContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {return new ConnectorResult([],false,$this->connectors->metrics($context->connector,$context->purpose,$secret));}
}
