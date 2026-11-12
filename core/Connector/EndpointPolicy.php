<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use RuntimeException;

/** Resolve once, enforce operator network policy, and return a pinned address for transport. */
final class EndpointPolicy
{
    private array $networks=[];
    public function __construct(array $cidrs=[], private readonly ?\Closure $resolver=null,private readonly bool $requireAllowlist=false)
    {
        foreach ($cidrs as $cidr) {
            if (!is_string($cidr)) throw new RuntimeException('Invalid connector CIDR.');
            $parts=explode('/',$cidr);
            $packed=@inet_pton($parts[0]);
            if (count($parts)!==2 || $packed===false || !ctype_digit($parts[1]) || (int)$parts[1]>strlen($packed)*8)
                throw new RuntimeException('Invalid connector CIDR.');
            $this->networks[]=[$packed,(int)$parts[1]];
        }
    }
    private static function matches(string $address,string $network,int $bits):bool
    {
        if (strlen($address)!==strlen($network)) return false;
        $bytes=intdiv($bits,8); $remainder=$bits%8;
        return substr($address,0,$bytes)===substr($network,0,$bytes)
            && ($remainder===0 || ((ord($address[$bytes])^ord($network[$bytes])) & (0xff << (8-$remainder)))===0);
    }
    public function permits(string $ip):bool
    {
        $packed=@inet_pton($ip);
        if ($packed===false) return false;
        // Reject IPv4-mapped IPv6 to prevent bypass of IPv4 network rules.
        if (strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") return false;
        // Cloud metadata/link-local, unspecified and multicast cannot be enabled.
        foreach (['169.254.0.0/16','0.0.0.0/8','224.0.0.0/3','fe80::/10','ff00::/8','::/128','fd00:ec2::254/128','100.100.100.200/32'] as $cidr) {
            [$net,$bits]=explode('/',$cidr);
            if (self::matches($packed,inet_pton($net),(int)$bits)) return false;
        }
        if ($this->networks!==[]) {
            foreach ($this->networks as [$net,$bits]) if (self::matches($packed,$net,$bits)) return true;
            return false;
        }
        // Production is fail-closed unless the operator defines the allowed infrastructure networks.
        if ($this->requireAllowlist) return false;
        // Local compatibility: loopback requires explicit operator opt-in.
        return !self::matches($packed,inet_pton('127.0.0.0'),8) && $packed!==inet_pton('::1');
    }
    public function resolve(string $host):string
    {
        $host=ConnectorBindingService::validateHost($host);
        if (filter_var($host,FILTER_VALIDATE_IP)) $addresses=[$host];
        elseif ($this->resolver!==null) $addresses=($this->resolver)($host);
        else {
            $addresses=@gethostbynamel($host)?:[];
            foreach (@dns_get_record($host,DNS_AAAA)?:[] as $record) if (isset($record['ipv6'])) $addresses[]=$record['ipv6'];
        }
        if (!is_array($addresses) || $addresses===[] || count($addresses)>64) throw new ConnectorTransportException('connector_transport_failed');
        foreach ($addresses as $address) if (!is_string($address)||!$this->permits($address)) throw new ConnectorTransportException('connector_trust_failed');
        return $addresses[0];
    }
}
