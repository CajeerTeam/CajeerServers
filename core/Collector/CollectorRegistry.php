<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorResult;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;

/** Registered collectors are executable, and their provider scope is frozen at registration. */
final class CollectorRegistry
{
    private array $collectors=[];
    private array $providers=[];
    public function __construct(private readonly ConnectorRegistry $connectors, CollectorInterface ...$collectors)
    { foreach($collectors as $collector) $this->register($collector); }
    public static function forConnectors(ConnectorRegistry $connectors):self
    {
        $registry=new self($connectors);
        foreach($connectors->manifests() as $provider=>$manifest) {
            if (!$manifest->supports('inspect') && !$manifest->supports('sync')) continue;
            if($provider!=='prometheus') $registry->register($provider==='snmp'?new SnmpCollector($connectors):new ConnectorCollector($provider.'.inventory',$provider,$connectors));
            if($connectors->supportsMetrics($provider))$registry->register(new MetricsCollector($provider,$connectors));
        }
        return $registry;
    }
    public function register(CollectorInterface $collector):void
    {
        $name=$collector->name();$provider=$collector->provider();
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$name)||isset($this->collectors[$name])||!$this->connectors->has($provider)||(str_ends_with($name,'.metrics')!==($collector instanceof MetricsCollector)))
            throw new \LogicException('Invalid or duplicate collector registration.');
        $this->collectors[$name]=$collector;$this->providers[$name]=$provider;
    }
    public function catalog():array
    {
        $rows=[];
        foreach($this->collectors as $name=>$collector) {
            $provider=$this->providers[$name];$operations=[];
            foreach(['inspect','sync'] as $op) {
                try {$this->require($name,$provider,$op);$operations[]=$op;}catch(HttpException){}
            }
            if($operations!==[]) $rows[]=['name'=>$name,'provider'=>$provider,'operations'=>$operations,'resource_types'=>$this->connectors->manifests()[$provider]->resourceTypes];
        }
        return $rows;
    }
    public function require(string $name,string $provider,string $operation):CollectorInterface
    {
        $plan=new CollectionPlan($name,$operation);
        $collector=$this->collectors[$plan->collector]??null;
        if($collector===null || $this->providers[$name]!==$provider || $collector->name()!==$name || $collector->provider()!==$provider)
            throw new HttpException(422,'Collector is unavailable for this target.','collection_invalid');
        if($collector instanceof MetricsCollector&&$operation!=='inspect')throw new HttpException(422,'Metrics require inspect.','collection_invalid');
        $this->connectors->manifest($provider,$operation);
        return $collector;
    }
    public function execute(CollectionContext $context, #[\SensitiveParameter] string $secret):ConnectorResult
    {
        $p=$context->plan;$provider=$context->connector->provider;
        $collector=$this->require($p->collector,$provider,$p->operation);
        $manifest=$this->connectors->manifest($provider,$p->operation);
        if(!$manifest->accepts($context->connector->credentialType,$context->purpose))
            throw new HttpException(403,'Collector credential not permitted.','permission_denied');
        try {$result=$collector->collect($context,$secret);}
        catch(HttpException $e) {
            // Only the underlying registry's finite categories may escape a trusted collector.
            $code=$e->errorCode;
            if(!in_array($code,\Cajeer\Servers\Connector\ConnectorHealthMonitor::ERRORS,true)) $code='connector_failed';
            throw new HttpException(502,'Remote collection failed.',$code);
        }
        catch(\Throwable) {throw new HttpException(502,'Remote collection failed.','connector_failed');}
        try {if(($collector instanceof MetricsCollector)!==($result->metricSamples!==[]) || ($collector instanceof MetricsCollector&&$result->items!==[]))throw new \RuntimeException('Wrong collection result type.');return $result->validated($manifest);}catch(\Throwable){throw new HttpException(502,'Invalid collection result.','connector_protocol_error');}
    }
}
