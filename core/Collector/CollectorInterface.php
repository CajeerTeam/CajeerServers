<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorResult;

/** Trusted server-side collector. No request-supplied classes or executable code. */
interface CollectorInterface
{
    public function name(): string;
    public function provider(): string;
    public function collect(CollectionContext $context, #[\SensitiveParameter] string $secret): ConnectorResult;
}
