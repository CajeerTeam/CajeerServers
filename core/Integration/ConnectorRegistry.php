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
    private array $metadata=[];
    public function __construct(ConnectorInterface ...$drivers) { foreach ($drivers as $driver) $this->register($driver); }
    public function register(ConnectorInterface $driver): void
    {
        $manifest=$driver->manifest();
        $provider=$manifest->provider;
        if (isset($this->drivers[$provider])) throw new LogicException('Duplicate connector provider.');
        if ($provider!=='proxmox' && $driver instanceof \Cajeer\Servers\Connector\ConnectorActionDriver) throw new LogicException('Only the built-in Proxmox driver can register write actions.');
        $this->drivers[$provider]=$driver;
        $this->metadata[$provider]=$manifest;
    }
    public function has(string $provider): bool { return isset($this->drivers[$provider]); }
    public function require(string $provider,string $operation): ConnectorInterface
    {
        $driver=$this->drivers[$provider]??null;
        if ($driver===null) throw new HttpException(501,'No executable driver is registered for this provider.','provider_unavailable');
        // Driver capabilities must not change after registration (including between grant and execution).
        try { $manifest=$driver->manifest(); } catch (\Throwable) { throw new HttpException(503,'Connector driver is not available.','provider_unavailable'); }
        if ($manifest != $this->metadata[$provider]) throw new HttpException(503,'Connector driver metadata changed.','provider_unavailable');
        if (!$manifest->supports($operation)) throw new HttpException(422,'Connector operation is not supported.','capability_unavailable');
        return $driver;
    }
    public function manifest(string $provider,string $operation): ConnectorManifest
    {
        $this->require($provider,$operation);
        return $this->metadata[$provider];
    }
    public function execute(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        $manifest=$this->metadata[$context->provider];
        if (!$manifest->accepts($context->credentialType,$purpose)) throw new HttpException(403,'Credential not supported by driver.','permission_denied');
        try { $result=$driver->execute($context,$operation,$secret); }
        catch (ConnectorTransportException $e) { throw new HttpException(502,$e->getMessage(),$e->category); }
        catch (\Throwable) { throw new HttpException(502,'Remote connector operation failed.','connector_failed'); }
        try {
            $result=$result->validated($manifest);
            if (in_array($operation,['health','validate_rotation'],true) && $result->items===[]) throw new LogicException('Empty probe.');
            return $result;
        }
        catch (\Throwable) { throw new HttpException(502,'Remote connector returned an invalid or unsupported response.','connector_protocol_error'); }
    }
    public function supportsVmmanagerCluster():bool
    {return ($this->drivers['vmmanager']??null) instanceof \Cajeer\Servers\Connector\VmmanagerConnector;}
    public function vmmanagerCluster(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        if(!$driver instanceof \Cajeer\Servers\Connector\VmmanagerConnector||!in_array($operation,['inspect','sync'],true)||!$this->metadata['vmmanager']->accepts($context->credentialType,$purpose))throw new HttpException(403,'VMmanager cluster collection not permitted.','permission_denied');
        try{return $driver->cluster($context,$secret)->validated($this->metadata['vmmanager']);}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Invalid VMmanager cluster discovery result.','connector_protocol_error');}
    }
    public function vmmanagerGuests(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        if(!$driver instanceof \Cajeer\Servers\Connector\VmmanagerConnector||!in_array($operation,['inspect','sync'],true)||!$this->metadata['vmmanager']->accepts($context->credentialType,$purpose))throw new HttpException(403,'VMmanager guest collection not permitted.','permission_denied');
        try{return $driver->guests($context,$secret)->validated($this->metadata['vmmanager']);}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Invalid VMmanager guest discovery result.','connector_protocol_error');}
    }
    public function supportsClusterDiscovery():bool
    {return ($this->drivers['proxmox']??null) instanceof \Cajeer\Servers\Connector\ProxmoxConnector;}
    public function cluster(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        if(!$driver instanceof \Cajeer\Servers\Connector\ProxmoxConnector||!in_array($operation,['inspect','sync'],true)||!$this->metadata['proxmox']->accepts($context->credentialType,$purpose))throw new HttpException(403,'Cluster collection not permitted.','permission_denied');
        try{return $driver->cluster($context,$secret)->validated($this->metadata['proxmox']);}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Invalid cluster discovery result.','connector_protocol_error');}
    }
    public function guests(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        if(!$driver instanceof \Cajeer\Servers\Connector\ProxmoxConnector||!in_array($operation,['inspect','sync'],true)||!$this->metadata['proxmox']->accepts($context->credentialType,$purpose))throw new HttpException(403,'Guest collection not permitted.','permission_denied');
        try{return $driver->guests($context,$secret)->validated($this->metadata['proxmox']);}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Invalid guest discovery result.','connector_protocol_error');}
    }
    public function storageNetwork(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        if(!$driver instanceof \Cajeer\Servers\Connector\ProxmoxConnector||!in_array($operation,['inspect','sync'],true)||!$this->metadata['proxmox']->accepts($context->credentialType,$purpose))throw new HttpException(403,'Storage/network collection not permitted.','permission_denied');
        try{return $driver->storageNetwork($context,$secret)->validated($this->metadata['proxmox']);}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Invalid storage/network discovery result.','connector_protocol_error');}
    }
    public function tasks(ConnectorContext $context,string $operation,string $purpose,#[\SensitiveParameter] string $secret,array $previous):ConnectorResult
    {
        $driver=$this->require($context->provider,$operation);
        if(!$driver instanceof \Cajeer\Servers\Connector\ProxmoxConnector||$operation!=='inspect'||!$this->metadata['proxmox']->accepts($context->credentialType,$purpose))throw new HttpException(403,'Task collection not permitted.','permission_denied');
        try{return $driver->tasks($context,$secret,$previous)->validated($this->metadata['proxmox']);}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Invalid task history result.','connector_protocol_error');}
    }
    public function supportsMetrics(string $provider):bool
    {return ($this->drivers[$provider]??null) instanceof \Cajeer\Servers\Metrics\MetricDriver;}
    public function metrics(ConnectorContext $context,string $purpose,#[\SensitiveParameter] string $secret):array
    {
        $driver=$this->require($context->provider,'inspect');
        if(!$driver instanceof \Cajeer\Servers\Metrics\MetricDriver||!$this->metadata[$context->provider]->accepts($context->credentialType,$purpose)) throw new HttpException(403,'Metrics collection not permitted.','permission_denied');
        try {$samples=\Cajeer\Servers\Metrics\MetricBatch::normalize($driver->metrics($context,$secret));if($samples===[])throw new ConnectorTransportException('connector_protocol_error');return $samples;}
        catch(ConnectorTransportException $e){throw new HttpException(502,$e->getMessage(),$e->category);}
        catch(\Throwable){throw new HttpException(502,'Metric collection failed.','connector_failed');}
    }
    public function actions(string $provider):array
    {
        $driver=$this->drivers[$provider]??null;
        return $provider==='proxmox' && $driver instanceof \Cajeer\Servers\Connector\ConnectorActionDriver?$driver->actions():[];
    }
    /** @return array<string,ConnectorManifest> */
    public function manifests(): array { return $this->metadata; }
}
