<?php
declare(strict_types=1);
namespace Cajeer\Servers\Metrics;
use Cajeer\Servers\Connector\ConnectorContext;
/** Fixed read-only numeric collection; never an arbitrary query/command. */
interface MetricDriver {public function metrics(ConnectorContext $context, #[\SensitiveParameter] string $secret):array;}
