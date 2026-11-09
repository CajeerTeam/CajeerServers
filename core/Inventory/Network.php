<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Http\Exception\HttpException;

/** Strict IPv4/IPv6 CIDR normalization and containment without external dependencies. */
final class Network
{
    public static function cidr(mixed $value):string
    {
        if (!is_string($value) || !preg_match('~^([^/]+)/([0-9]{1,3})$~D',$value,$m)) self::invalid('cidr');
        $binary=@inet_pton($m[1]);
        if ($binary===false) self::invalid('cidr');
        $bits=strlen($binary)*8;$prefix=(int)$m[2];
        if ($prefix>$bits) self::invalid('cidr');
        $masked='';
        for ($i=0;$i<strlen($binary);$i++) {
            $remaining=$prefix-$i*8;
            $mask=$remaining>=8?255:($remaining<=0?0:(255 << (8-$remaining))&255);
            $masked.=chr(ord($binary[$i])&$mask);
        }
        // Fail rather than silently changing an entered network address.
        if ($binary!==$masked) self::invalid('cidr');
        return inet_ntop($masked).'/'.$prefix;
    }

    public static function host(mixed $value):string
    {
        if (!is_string($value) || strlen($value)>45 || str_contains($value,'/') || filter_var($value,FILTER_VALIDATE_IP)===false) self::invalid('address');
        return inet_ntop(inet_pton($value));
    }

    public static function contains(string $cidr,string $address):bool
    {
        [$net,$prefix]=explode('/',$cidr,2);
        $n=inet_pton($net);$ip=inet_pton($address);
        if ($n===false || $ip===false || strlen($n)!==strlen($ip)) return false;
        $bits=(int)$prefix;
        for ($i=0;$i<strlen($n);$i++) {
            $rest=$bits-$i*8;
            if ($rest<=0) break;
            $mask=$rest>=8?255:(255 << (8-$rest))&255;
            if ((ord($n[$i])&$mask)!==(ord($ip[$i])&$mask)) return false;
        }
        return true;
    }

    public static function overlaps(string $first,string $second):bool
    {
        [$a,$ap]=explode('/',$first,2);[$b,$bp]=explode('/',$second,2);
        if (strlen(inet_pton($a))!==strlen(inet_pton($b))) return false;
        return self::contains($first,$b) || self::contains($second,$a);
    }

    public static function name(mixed $value,string $field='name'):string
    {
        if (!is_string($value) || trim($value)==='' || strlen(trim($value))>120 || preg_match('/[\x00-\x1f\x7f]/',$value)) self::invalid($field);
        return trim($value);
    }

    public static function description(mixed $value):string
    {
        if (!is_string($value) || strlen($value)>1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) self::invalid('description');
        return $value;
    }

    public static function uuid(mixed $value,string $field='id'):string
    {
        if (!is_string($value) || !InfrastructureAsset::isUuid($value)) self::invalid($field);
        return strtolower($value);
    }

    public static function invalid(string $field):never
    {
        throw new HttpException(422,'Invalid IPAM field: '.$field,'validation_failed',errors:[['field'=>$field,'message'=>'Invalid value.']]);
    }
}
