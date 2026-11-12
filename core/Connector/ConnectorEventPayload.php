<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Security\SignedPayload;

/** Cajeer v1 signed refresh hints, not remote commands or authoritative asset data. */
final class ConnectorEventPayload
{
    public const TYPES=['resource.created','resource.updated','resource.deleted','inventory.refresh'];
    public static function parse(string $body):array
    {
        if (strlen($body)>16384) throw new HttpException(413,'Event exceeds 16 KiB.','payload_too_large');
        try {$p=json_decode($body,false,16,JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new HttpException(400,'Invalid event JSON.','invalid_json');}
        if (!$p instanceof \stdClass) self::invalid();
        $p=(array)$p;
        if (array_diff(array_keys($p),['event_id','type','resource_id']) || !is_string($p['event_id']??null) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$p['event_id']) || !in_array($p['type']??null,self::TYPES,true)) self::invalid();
        $resource=$p['resource_id']??null;
        if ($resource!==null && (!is_string($resource) || !preg_match('/^[A-Za-z0-9._:@\/-]{1,180}$/D',$resource))) self::invalid();
        if ($p['type']!=='inventory.refresh' && $resource===null) self::invalid();
        return ['event_id'=>$p['event_id'],'type'=>$p['type'],'resource_id'=>$resource,'digest'=>hash('sha256',$body)];
    }
    public static function verify(string $body,string $secret,string $source,string $timestamp,string $signature,?int $now=null):void
    {
        try {SignedPayload::verify($source."\n".$body,$secret,$timestamp,$signature,$now);}
        catch (\InvalidArgumentException) {throw new HttpException(401,'Invalid webhook credentials.','invalid_signature');}
    }
    private static function invalid():never {throw new HttpException(422,'Invalid connector event.','validation_failed');}
}
