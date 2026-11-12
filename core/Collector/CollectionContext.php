<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorContext;

/** Non-secret execution context; no mutable database or user-supplied endpoint override. */
final readonly class CollectionContext
{
    public function __construct(public ConnectorContext $connector, public string $purpose, public CollectionPlan $plan) {}
}
