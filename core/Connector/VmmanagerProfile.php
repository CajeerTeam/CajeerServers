<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Http\Exception\HttpException;

/** Operator-confirmed TLS leaf SHA-256 and a secondary VMmanager node identity guard. */
final class VmmanagerProfile
{
    public static function normalize(mixed $input): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['primary_pin','anchor_id','anchor_node']) !== []) self::invalid();
        $pin=$input['primary_pin']??null; $id=$input['anchor_id']??null; $name=$input['anchor_node']??null;
        if (!is_string($pin)||!preg_match('/^[a-fA-F0-9]{64}$/D',$pin)||!is_int($id)||$id<1||$id>2147483647
            ||!is_string($name)||$name!==trim($name)||$name===''||strlen($name)>255||!preg_match('//u',$name)||preg_match('/[\x00-\x1f\x7f]/',$name)) self::invalid();
        return ['primary_pin'=>strtolower($pin),'anchor_id'=>$id,'anchor_node'=>$name];
    }
    private static function invalid(): never {throw new HttpException(422,'Invalid VMmanager connection profile.','validation_failed');}
    public static function verify(array $profile,array $rows): void
    {
        if(count($rows)!==1||$rows[0]['id']!==$profile['anchor_id']||$rows[0]['name']!==$profile['anchor_node'])
            throw new ConnectorTransportException('connector_trust_failed');
    }
}
