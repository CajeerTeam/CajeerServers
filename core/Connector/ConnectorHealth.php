<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Safe response for a successful probe; persisted status is maintained by ConnectorHealthMonitor. */
final readonly class ConnectorHealth
{
    public function __construct(public string $connector_id,public int $duration_ms) {}
    public function toArray():array { return ['connector_id'=>$this->connector_id,'status'=>'healthy','duration_ms'=>$this->duration_ms]; }
}
