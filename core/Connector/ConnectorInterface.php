<?php
namespace Cajeer\Servers\Connector;

/** Contract for API-first connectors. */
interface ConnectorInterface
{
    public function name(): string;
}
