<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use RuntimeException;

/** Bounded read-only HTTPS. Pinned IP prevents DNS rebinding between policy and connection. */
final class HttpsTransport
{
    public function __construct(private readonly EndpointPolicy $policy=new EndpointPolicy(),
        private readonly int $timeout=8,private readonly int $maxBytes=2097152) {
        if ($timeout<1 || $timeout>60 || $maxBytes<1 || $maxBytes>16777216) throw new RuntimeException('Invalid HTTPS limits.');
    }
    public function get(string $host,int $port,string $path,#[\SensitiveParameter] string $authorization,?string $caFile=null,string $credentialHeader='Authorization'):string
    {
        $host=ConnectorBindingService::validateHost($host); $port=ConnectorBindingService::validatePort($port);
        if (!in_array($credentialHeader,['Authorization','x-xsrf-token'],true) || $authorization==='' || strlen($authorization)>16384 || !preg_match('~^/[A-Za-z0-9/_?.=&%:-]+$~D',$path) || str_starts_with($path,'//') || preg_match('/[\x00-\x1f\x7f]/',$authorization))
            throw new RuntimeException('Invalid HTTPS request.');
        if ($caFile!==null && (!is_file($caFile)||!is_readable($caFile))) throw new RuntimeException('Connector CA file is unreadable.');
        $ip=$this->policy->resolve($host);
        $bracket=static fn(string $h):string=>str_contains($h,':')?'['.$h.']':$h;
        $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'SNI_enabled'=>true];
        if ($caFile!==null) $ssl['cafile']=$caFile;
        $context=stream_context_create(['ssl'=>$ssl,'http'=>['method'=>'GET','protocol_version'=>1.1,
            'follow_location'=>0,'ignore_errors'=>true,'timeout'=>$this->timeout,
            'header'=>"Host: ".$bracket($host).":$port\r\nAccept: application/json\r\n$credentialHeader: $authorization\r\nConnection: close\r\n"]]);
        try {
            $stream=@fopen('https://'.$bracket($ip).':'.$port.$path,'rb',false,$context);
            if ($stream===false) throw new ConnectorTransportException('connector_transport_failed');
            try {
                $metadata=stream_get_meta_data($stream);
                if (!preg_match('~^HTTP/\d(?:\.\d)? (\d{3})(?:\s|$)~',$metadata['wrapper_data'][0]??'',$status)) throw new ConnectorTransportException('connector_protocol_error');
                if ((int)$status[1]!==200) throw ConnectorTransportException::forStatus((int)$status[1]);
                // Reject truncated fixed-length responses before JSON parsing.
                $length=null;
                foreach ($metadata['wrapper_data']??[] as $header) {
                    if (preg_match('/^Content-Length:\s*(\d+)\s*$/iD',$header,$match)) {
                        if ($length!==null || (float)$match[1]>$this->maxBytes) throw new RuntimeException('Invalid connector response length.');
                        $length=(int)$match[1];
                    }
                }
                $body=''; $deadline=microtime(true)+$this->timeout;
                while (!feof($stream)) {
                    $remaining=$deadline-microtime(true);
                    if ($remaining<=0) throw new RuntimeException('Connector HTTPS deadline exceeded.');
                    stream_set_timeout($stream,(int)$remaining,(int)(($remaining-(int)$remaining)*1000000));
                    $chunk=fread($stream,min(65536,$this->maxBytes+1-strlen($body)));
                    if ($chunk===false || stream_get_meta_data($stream)['timed_out']) throw new RuntimeException('Connector HTTPS read failed.');
                    $body.=$chunk;
                    if (strlen($body)>$this->maxBytes) throw new RuntimeException('Connector response too large.');
                }
                if ($length!==null && strlen($body)!==$length) throw new RuntimeException('Truncated connector response.');
                return $body;
            } finally { fclose($stream); }
        } finally { unset($context); }
    }
    /** Decode a JSON object without propagating remote content or parser diagnostics. */
    public static function jsonObject(string $body):\stdClass
    {
        try { $decoded=json_decode($body,false,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ConnectorTransportException('connector_protocol_error'); }
        if (!$decoded instanceof \stdClass) throw new ConnectorTransportException('connector_protocol_error');
        return $decoded;
    }
}
