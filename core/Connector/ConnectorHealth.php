<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Successful one-shot probe; persisted monitoring is introduced separately. */
final readonly class ConnectorHealth
{
    public function __construct(public string $connector_id,public int $duration_ms) {}
    public function toArray():array { return ['connector_id'=>$this->connector_id,'status'=>'healthy','duration_ms'=>$this->duration_ms]; }
}
