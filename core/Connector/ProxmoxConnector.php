<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;
use RuntimeException;

/** Read-only Proxmox VE API client. Credentials never leave this server except over verified TLS. */
final class ProxmoxConnector
{
    private const MAX_RESPONSE = 2_097_152;
    private const MAX_NODES = 256;
    private const MAX_GUESTS = 1500;

    /** Optional transport is for isolated real-response tests; production uses verified HTTPS streams. */
    public function __construct(private readonly ?\Closure $transport = null) {}

    /** @return list<array{type:string,external_id:string,fields:array<string,string>}> */
    public function discover(string $host, int $port, string $credentialType, string $plaintext, ?string $caFile = null): array
    {
        $host = ConnectorBindingService::validateHost($host);
        $port = ConnectorBindingService::validatePort($port);
        $authorization = self::authorization($credentialType, $plaintext);
        try {
            $nodes = $this->request($host, $port, '/api2/json/nodes', $authorization, $caFile);
            $guests = $this->request($host, $port, '/api2/json/cluster/resources?type=vm', $authorization, $caFile);
        } finally {
            sodium_memzero($authorization);
        }
        return self::observations($nodes, $guests);
    }

    /** @return list<array{type:string,external_id:string,fields:array<string,string>}> */
    public static function observations(array $nodes, array $guests): array
    {
        if (count($nodes) > self::MAX_NODES || count($guests) > self::MAX_GUESTS) {
            throw new RuntimeException('Remote inventory exceeds the safe import limit.');
        }
        $items = [];
        foreach ($nodes as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid Proxmox nodes response.');
            $node = self::node($row['node'] ?? null);
            $key = 'node:'.$node;
            if (isset($items[$key])) throw new RuntimeException('Duplicate remote node identity.');
            $items[$key] = ['type'=>'hypervisor_node','external_id'=>$key,
                'fields'=>['name'=>$node,'status'=>self::status($row['status']??null, false)]];
        }
        foreach ($guests as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid Proxmox guests response.');
            $type = $row['type'] ?? null;
            if (!in_array($type, ['qemu','lxc'], true)) throw new RuntimeException('Unsupported Proxmox guest type.');
            $node = self::node($row['node']??null);
            $id = $row['vmid'] ?? null;
            if ((!is_int($id) && !(is_string($id) && preg_match('/^[1-9][0-9]{0,8}$/D',$id)))
                || (int)$id < 1 || (int)$id > 999999999) throw new RuntimeException('Invalid Proxmox guest ID.');
            $key = $type.':'.(int)$id; // VMIDs are unique across the cluster even if guests migrate nodes.
            if (isset($items[$key])) throw new RuntimeException('Duplicate remote guest identity.');
            $fallback = strtoupper($type).' '.(int)$id;
            $items[$key] = ['type'=>$type==='qemu'?'virtual_machine':'container','external_id'=>$key,
                'fields'=>['name'=>self::label($row['name']??null,$fallback),
                    'status'=>self::status($row['status']??null, true)]];
        }
        return array_values($items);
    }

    private static function node(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>120 || preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/D',$value)!==1)
            throw new RuntimeException('Invalid Proxmox node identity.');
        return $value;
    }
    private static function label(mixed $value, string $fallback): string
    {
        if (!is_string($value) || trim($value)==='') return $fallback;
        $value=trim($value);
        return strlen($value)<=255 && preg_match('//u',$value) && !preg_match('/[\x00-\x1f\x7f]/',$value) ? $value : $fallback;
    }
    private static function status(mixed $value, bool $guest): string
    {
        return match($value) {
            'online', 'running' => 'online',
            'offline', 'stopped' => 'offline',
            'paused', 'suspended' => $guest ? 'maintenance' : 'unknown',
            default => 'unknown',
        };
    }
    private static function authorization(string $type, string $plaintext): string
    {
        $value = CredentialProfile::decodeForInternalUse($type,$plaintext);
        if ($type==='proxmox_api' && is_array($value)) {
            $tokenId=$value['token_id']; $tokenSecret=$value['token_secret'];
        } elseif ($type==='api_token' && is_string($value) && str_contains($value,'=')) {
            [$tokenId,$tokenSecret]=explode('=',$value,2);
        } else {
            throw new RuntimeException('Proxmox API credentials required.');
        }
        if (preg_match('/^[a-zA-Z0-9._@-]+![a-zA-Z0-9._-]{1,128}$/D',$tokenId)!==1
            || $tokenSecret==='' || strlen($tokenSecret)>8192 || preg_match('/[\x00-\x20\x7f]/',$tokenSecret)) {
            throw new RuntimeException('Invalid Proxmox token format.');
        }
        return 'PVEAPIToken='.$tokenId.'='.$tokenSecret;
    }

    /** @return list<array<string,mixed>> */
    private function request(string $host,int $port,string $path,string $authorization,?string $caFile):array
    {
        $endpoint='https://'.(str_contains($host,':')?'['.$host.']':$host).':'.$port.$path;
        if ($this->transport!==null) {
            $body=($this->transport)($endpoint,$authorization,$caFile);
        } else {
            if ($caFile!==null && (!is_file($caFile) || !is_readable($caFile))) {
                throw new RuntimeException('Proxmox CA certificate file is unreadable.');
            }
            $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,
                'peer_name'=>$host,'SNI_enabled'=>true];
            if ($caFile!==null) $ssl['cafile']=$caFile;
            $context=stream_context_create([
                'ssl'=>$ssl,
                'http'=>['method'=>'GET','protocol_version'=>1.1,'follow_location'=>0,
                    'ignore_errors'=>true,'timeout'=>8,
                    'header'=>"Accept: application/json\r\nAuthorization: ".$authorization."\r\nConnection: close\r\n"],
            ]);
            $body=@file_get_contents($endpoint,false,$context,0,self::MAX_RESPONSE+1);
            $first=$http_response_header[0]??'';
            if (!is_string($body) || !preg_match('~^HTTP/\d(?:\.\d)? 200(?:\s|$)~',$first)) {
                throw new RuntimeException('Proxmox API request failed.');
            }
        }
        if (!is_string($body) || strlen($body)>self::MAX_RESPONSE) throw new RuntimeException('Invalid remote response size.');
        try {$decoded=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('Invalid remote JSON.');}
        if (!is_array($decoded) || !array_key_exists('data',$decoded) || !is_array($decoded['data']) || !array_is_list($decoded['data'])) {
            throw new RuntimeException('Unexpected Proxmox API response.');
        }
        return $decoded['data'];
    }
}
