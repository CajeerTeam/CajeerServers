<?php
declare(strict_types=1);
namespace Cajeer\Servers\Integration;

use Cajeer\Servers\Connector\ConnectorInterface;
use Cajeer\Servers\Connector\ConnectorManifest;
use Cajeer\Servers\Connector\ConnectorContext;
use Cajeer\Servers\Connector\ConnectorResult;
use Cajeer\Servers\Connector\ConnectorTransportException;
use Cajeer\Servers\Http\Exception\HttpException;
use LogicException;

/** Register actual driver instances from DI, never instantiate classes from request data. */
final class ConnectorRegistry
{
    private array $drivers=[];
    public function __construct(ConnectorInterface ...$drivers) { foreach ($drivers as $driver) $this->register($driver); }
    public function register(ConnectorInterface $driver): void
    {
        $provider=$driver->manifest()->provider;
        if (isset($this->drivers[$provider])) throw new LogicException('Duplicate connector provider.');
        $this->drivers[$provider]=$driver;
    }
    public function has(string $provider): bool { return isset($this->drivers[$provider]); }
    public function require(string $provider,string $operation): ConnectorInterface
    {
        $driver=$this->drivers[$provider]??null;
        if ($driver===null) throw new HttpException(501,'No executable driver is registered for this provider.','provider_unavailable');
        if (!$driver->manifest()->supports($operation)) throw new HttpException(422,'Connector operation is not supported.','capability_unavailable');
        return $driver;
    }
    public function execute(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        $manifest=$driver->manifest();
        if (!$manifest->accepts($context->credentialType,$purpose)) throw new HttpException(403,'Credential not supported by driver.','permission_denied');
        try { return $driver->execute($context,$operation,$secret)->validated($manifest); }
        catch (ConnectorTransportException $e) { throw new HttpException(502,$e->getMessage(),$e->category); }
        catch (\Throwable) { throw new HttpException(502,'Remote connector operation failed.','connector_failed'); }
    }
    /** @return array<string,ConnectorManifest> */
    public function manifests(): array { return array_map(static fn(ConnectorInterface $d)=>$d->manifest(),$this->drivers); }
}
