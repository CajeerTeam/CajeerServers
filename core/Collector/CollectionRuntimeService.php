<?php
declare(strict_types=1);
namespace Cajeer\Servers\Collector;

use Cajeer\Servers\Connector\ConnectorContext;
use Cajeer\Servers\Connector\ConnectorResult;

/** Used only from the existing authorized Connector Runtime; never opens Vault itself. */
final readonly class CollectionRuntimeService
{
    public function __construct(private CollectorRegistry $registry) {}
    public function catalog():array {return $this->registry->catalog();}
    public function validate(CollectionPlan $plan,array $target):void
    { $this->registry->require($plan->collector,$target['provider'],$plan->operation);
      if($plan->collector==='proxmox.cluster'&&empty($target['proxmox_profile']))throw new \Cajeer\Servers\Http\Exception\HttpException(422,'Cluster discovery requires a verified Proxmox profile.','collection_invalid'); }
    public function collect(CollectionPlan $plan,array $target,string $type,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $this->validate($plan,$target);
        if ($type!==$target['credential_type']) throw new \Cajeer\Servers\Http\Exception\HttpException(403,'Collector credential changed.','permission_denied');
        $context=new ConnectorContext($target['provider'],$target['endpoint_host'],$target['endpoint_port'],$type,$target['proxmox_profile']??null);
        return $this->registry->execute(new CollectionContext($context,$target['purpose'],$plan),$secret);
    }
}
