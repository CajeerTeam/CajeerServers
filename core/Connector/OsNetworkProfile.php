<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;

/** Explicit operator trust; no TOFU, no credentials in the profile. */
final class OsNetworkProfile
{
    public static function normalize(string $provider,mixed $input):array
    {
        $keys=match($provider){'ssh'=>['primary_pin','identity_hash','key_type'],'winrm','routeros'=>['primary_pin','identity_hash'],'snmp'=>['engine_id','object_id'],default=>[]};
        if(!$keys||!is_array($input)||count($input)!==count($keys)||array_diff($keys,array_keys($input)))self::invalid();
        if($provider==='snmp'){
            $engine=$input['engine_id'];$object=$input['object_id'];
            if(!is_string($engine)||!preg_match('/^(?:[0-9a-fA-F]{2}){5,32}$/D',$engine)||!is_string($object)||strlen($object)>255||!preg_match('/^\.[0-2](?:\.(?:0|[1-9][0-9]{0,9})){2,63}$/D',$object))self::invalid();
            return ['engine_id'=>strtolower($engine),'object_id'=>$object];
        }
        foreach(['primary_pin','identity_hash'] as $key)if(!is_string($input[$key])||!preg_match('/^[0-9a-fA-F]{64}$/D',$input[$key]))self::invalid();
        $profile=['primary_pin'=>strtolower($input['primary_pin']),'identity_hash'=>strtolower($input['identity_hash'])];
        if($provider==='ssh'){if(!in_array($input['key_type'],['ssh-ed25519','ssh-rsa','ecdsa-sha2-nistp256','ecdsa-sha2-nistp384','ecdsa-sha2-nistp521'],true))self::invalid();$profile['key_type']=$input['key_type'];}
        return $profile;
    }
    private static function invalid():never {throw new HttpException(422,'Invalid OS/network connection profile.','validation_failed');}
    public static function verifyHost(string $provider,array $profile,mixed $identity):void
    {
        if(!is_string($identity)||!preg_match('/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12})$/D',$identity))self::mismatch();
        $id=str_replace('-','',strtolower($identity));
        if(trim($id,'0')===''||!hash_equals($profile['identity_hash'],hash('sha256',$provider.':'.$id)))self::mismatch();
    }
    public static function verifySnmp(array $profile,string $engine,string $object):void
    {
        if(!hash_equals($profile['engine_id'],bin2hex($engine))||!hash_equals($profile['object_id'],'.'.ltrim($object,'.')))self::mismatch();
    }
    public static function diagnostics(ConnectorContext $context):array
    {
        return ['provider'=>$context->provider,'profile_verified'=>$context->osNetworkProfile!==null,
            'identity_verified'=>$context->osNetworkProfile!==null,
            'trust_mode'=>$context->osNetworkProfile!==null?match($context->provider){'ssh'=>'ssh_pin','winrm','routeros'=>'tls_pin','snmp'=>'snmpv3_identity'}:match($context->provider){'ssh'=>'known_hosts','winrm','routeros'=>'tls_ca','snmp'=>'snmp_legacy'}];
    }
    public static function mismatch():never {throw new ConnectorTransportException('connector_trust_failed');}
}
