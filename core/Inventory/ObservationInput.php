<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Http\Exception\HttpException;

/** Strict non-executable, non-secret import observations. No provider identity is implied. */
final class ObservationInput
{
    public const FIELDS=['name','status','os_family','ip_addresses','tags','environment'];

    /** Connector identities are server-owned; imports cannot impersonate a driver. */
    public static function reserved(string $external): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:/iD',$external)===1;
    }

    /** @param array<string,mixed> $input @return array{workspace_id:string,source:string,external_id:string,event_key:string,type:string,fields:array<string,mixed>,observed_at:string,payload_hash:string} */
    public static function normalize(array $input,bool $connector=false):array
    {
        $required=['workspace_id','external_id','event_key','type','fields'];
        foreach($required as $key)if(!array_key_exists($key,$input))self::bad($key);
        foreach($input as $key=>$value)if(!in_array($key,[...$required,'source','observed_at'],true))self::bad((string)$key);
        if(!is_string($input['workspace_id']) || !InfrastructureAsset::isUuid($input['workspace_id']))self::bad('workspace_id');
        // An authenticated administrator may submit import observations, not impersonate provider connectors.
        if(($input['source']??'import')!=='import')self::bad('source');
        if(!is_string($input['external_id'])||strlen($input['external_id'])>255||trim($input['external_id'])===''||preg_match('/[\x00-\x1f\x7f]/',$input['external_id']))self::bad('external_id');
        if(!is_string($input['event_key'])||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D',$input['event_key'])!==1)self::bad('event_key');
        if(!is_string($input['type'])||!in_array($input['type'],InfrastructureAsset::TYPES,true))self::bad('type');
        $allowed=$connector?[...self::FIELDS,'attributes']:self::FIELDS;
        if(!is_array($input['fields'])||array_is_list($input['fields'])||$input['fields']===[]||count($input['fields'])>count($allowed))self::bad('fields');
        foreach($input['fields'] as $field=>$value)if(!in_array($field,$allowed,true))self::bad('fields');
        if(isset($input['fields']['attributes']) && (!is_array($input['fields']['attributes']) || array_keys($input['fields']['attributes'])!==['host_hardware'] || !is_array($input['fields']['attributes']['host_hardware'])))self::bad('attributes');
        $fields=InfrastructureAsset::changes($input['fields']);
        ksort($fields,SORT_STRING);
        if(isset($input['observed_at'])){
            if(!is_string($input['observed_at'])||strlen($input['observed_at'])>40||preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D',$input['observed_at'])!==1)self::bad('observed_at');
            try{$time=new \DateTimeImmutable($input['observed_at']);}catch(\Exception){self::bad('observed_at');}
        }else{$time=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));}
        $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        if($time->getTimestamp()>$now->getTimestamp()+300 || $time->getTimestamp()<$now->getTimestamp()-366*86400)self::bad('observed_at');
        $when=$time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $identity=['workspace_id'=>strtolower($input['workspace_id']),'source'=>'import','external_id'=>$input['external_id'],
            'event_key'=>$input['event_key'],'type'=>$input['type'],'fields'=>$fields,'observed_at'=>$when];
        // Timestamp is excluded when generated server-side so retries with the same event key stay idempotent.
        $fingerprint=$identity;
        if(!isset($input['observed_at']))$fingerprint['observed_at']=null;
        $identity['payload_hash']=hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $identity;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function overrideFields(array $input):array
    {
        if(array_keys($input)!==['fields']||!is_array($input['fields'])||$input['fields']===[]||array_is_list($input['fields']))self::bad('fields');
        foreach($input['fields'] as $field=>$value)if(!in_array($field,self::FIELDS,true))self::bad('fields');
        return InfrastructureAsset::changes($input['fields']);
    }

    private static function bad(string $field):never
    {
        throw new HttpException(422,'Invalid observation field: '.$field,'validation_failed',errors:[['field'=>$field,'message'=>'Invalid value.']]);
    }
}
