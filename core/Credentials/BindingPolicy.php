<?php
declare(strict_types=1);
namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Http\Exception\HttpException;

/** Only allow a credential to be used for an operation supported by its actual type. */
final class BindingPolicy
{
    public const PROVIDERS = ['proxmox','vmmanager','ssh','winrm','snmp','custom','prometheus','pbs'];
    private const TYPE_PURPOSES = [
        'api_token'=>['api'], 'proxmox_api'=>['api'], 'vmmanager_api'=>['api'],
        'ssh_password'=>['ssh'], 'ssh_private_key'=>['ssh'], 'winrm'=>['winrm'],
        'snmp_v2c'=>['snmp'], 'snmp_v3'=>['snmp'], 'generic_secret'=>['custom'],
    ];
    private const PROVIDER_TYPES = [
        'prometheus'=>['api_token'], 'pbs'=>['api_token'], 'proxmox'=>['proxmox_api','api_token'], 'vmmanager'=>['vmmanager_api','api_token'],
        'ssh'=>['ssh_password','ssh_private_key'], 'winrm'=>['winrm'],
        'snmp'=>['snmp_v2c','snmp_v3'], 'custom'=>['generic_secret','api_token'],
    ];

    public static function purpose(mixed $value):string
    {
        if (!is_string($value) || !in_array($value,['api','ssh','winrm','snmp','custom'],true))
            throw new HttpException(422,'Unsupported credential purpose.','validation_failed');
        return $value;
    }
    public static function allows(string $type,string $purpose):bool
    {
        return in_array($purpose,self::TYPE_PURPOSES[$type]??[],true);
    }
    public static function provider(mixed $provider):string
    {
        if (!is_string($provider) || !in_array($provider,self::PROVIDERS,true))
            throw new HttpException(422,'Unsupported connector provider.','validation_failed');
        return $provider;
    }
    public static function allowsConnector(string $provider,string $type,string $purpose):bool
    {
        return self::allows($type,$purpose) && in_array($type,self::PROVIDER_TYPES[$provider]??[],true);
    }
    public static function purposesFor(string $provider):array
    {
        $provider=self::provider($provider);
        return array_values(array_unique(array_merge(...array_map(
            static fn(string $type):array=>self::TYPE_PURPOSES[$type],self::PROVIDER_TYPES[$provider]
        ))));
    }
}
