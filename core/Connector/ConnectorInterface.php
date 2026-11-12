<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Trusted server-side driver. Secrets are supplied only within the Vault consumer. */
interface ConnectorInterface
{
    public function manifest(): ConnectorManifest;
    public function execute(ConnectorContext $context, string $operation, #[\SensitiveParameter] string $secret): ConnectorResult;
}
