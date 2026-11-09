<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

/** Validated MAC address, DNS hostname, and IP assignment metadata. */
final class IpAddress
{
    public static function mac(mixed $value):?string
    {
        if ($value===null || $value==='') return null;
        if (!is_string($value) || !preg_match('/^[a-f0-9]{2}([:-])[a-f0-9]{2}(?:\1[a-f0-9]{2}){4}$/iD',$value)) Network::invalid('mac_address');
        return strtolower(str_replace('-',':',$value));
    }

    public static function hostname(mixed $value):?string
    {
        if ($value===null || $value==='') return null;
        if (!is_string($value) || strlen($value)>253 || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/iD',$value)) Network::invalid('hostname');
        return strtolower($value);
    }
}
