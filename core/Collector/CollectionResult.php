<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorResult;
use Cajeer\Servers\Http\Exception\HttpException;

/** Bounded normalized snapshot, not raw output or credentials. */
final readonly class CollectionResult
{
    public const MAX_BYTES=2097152;
    public function __construct(public CollectionPlan $plan,public ConnectorResult $snapshot,public string $observedAt)
    {
        $encoded=json_encode($this->toArray(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($encoded)>self::MAX_BYTES) throw new HttpException(422,'Collection result exceeds storage limit.','collection_result_too_large');
    }
    public function toArray():array
    {
        return ['schema_version'=>1,'collector'=>$this->plan->collector,'sink'=>$this->plan->sink(),'observed_at'=>$this->observedAt,
            'complete'=>$this->snapshot->complete,'count'=>count($this->snapshot->items)+count($this->snapshot->metricSamples),'observations'=>$this->snapshot->items,...($this->snapshot->metricSamples===[]?[]:['metric_samples'=>\Cajeer\Servers\Metrics\MetricBatch::normalize($this->snapshot->metricSamples,$this->observedAt)])];
    }
}
