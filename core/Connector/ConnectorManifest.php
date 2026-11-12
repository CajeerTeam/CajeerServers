<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\BindingPolicy;
use InvalidArgumentException;

/** Validated executable driver metadata, not a promise of future capabilities. */
final readonly class ConnectorManifest
{
    public const OPERATIONS = ['inspect','sync','validate_rotation','health'];
    public function __construct(
        public string $provider,
        public string $purpose,
        public array $credentialTypes,
        public array $operations,
        public array $resourceTypes,
        public string $transport,
    ) {
        if (!in_array($provider, BindingPolicy::PROVIDERS,true) || $credentialTypes===[] || $operations===[] || $resourceTypes===[]
            || !array_is_list($credentialTypes) || !array_is_list($operations) || !array_is_list($resourceTypes)
            || array_diff($operations,self::OPERATIONS) || count(array_unique($operations))!==count($operations)
            || !in_array($transport,['https','ssh','winrm','snmp'],true)) throw new InvalidArgumentException('Invalid connector manifest.');
        foreach ($credentialTypes as $type) if (!is_string($type) || !BindingPolicy::allowsConnector($provider,$type,$purpose))
            throw new InvalidArgumentException('Invalid driver credential policy.');
        foreach ($resourceTypes as $type) if (!is_string($type) || !in_array($type,\Cajeer\Servers\Inventory\InfrastructureAsset::TYPES,true))
            throw new InvalidArgumentException('Invalid driver resource type.');
    }
    public function supports(string $operation): bool { return in_array($operation,$this->operations,true); }
    public function accepts(string $type,string $purpose): bool {
        return $purpose===$this->purpose && in_array($type,$this->credentialTypes,true);
    }
}
