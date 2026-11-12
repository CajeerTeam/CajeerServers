<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Http\Exception\HttpException;

/** The existing grant fixes target, operation and credential revision; a plan cannot expand them. */
final readonly class CollectionPlan
{
    public function __construct(public string $collector, public string $operation)
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$collector) || !in_array($operation,['inspect','sync'],true))
            throw new HttpException(422,'Invalid collection plan.','collection_invalid');
    }
    public function sink(): string { return $this->operation==='sync'?'inventory':'result'; }
}
