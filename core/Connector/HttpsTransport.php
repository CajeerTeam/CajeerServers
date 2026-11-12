<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use RuntimeException;

/** Pinned, bounded HTTP/1.1 over TLS. No proxy, redirects, decompression or implicit retry. */
final class HttpsTransport
{
    private const MAX_HEADERS=32768;
    public function __construct(private readonly EndpointPolicy $policy=new EndpointPolicy(),
        private readonly int $timeout=8,private readonly int $maxBytes=2097152) {
        if ($timeout<1 || $timeout>60 || $maxBytes<1 || $maxBytes>16777216) throw new RuntimeException('Invalid HTTPS limits.');
    }
    public function get(string $host,int $port,string $path,#[\SensitiveParameter] string $authorization,?string $caFile=null,string $credentialHeader='Authorization'):string
    { return $this->request('GET',$host,$port,$path,$authorization,$caFile,$credentialHeader); }
    public function powerPost(string $host,int $port,string $path,#[\SensitiveParameter] string $authorization,?string $caFile=null):string
    {
        if (!preg_match('~^/api2/json/nodes/[A-Za-z0-9][A-Za-z0-9._-]{0,119}/qemu/[1-9][0-9]{0,8}/status/(start|shutdown)$~D',$path)) throw new RuntimeException('Unsupported power request.');
        return $this->request('POST',$host,$port,$path,$authorization,$caFile,'Authorization');
    }
    private static function protocol():never { throw new ConnectorTransportException('connector_protocol_error'); }
    private static function transport():never { throw new ConnectorTransportException('connector_transport_failed'); }
    private static function remaining(int $deadline):float
    { $left=($deadline-hrtime(true))/1e9; if ($left<=0) self::transport(); return $left; }
    private static function timeout($stream,int $deadline):void
    { $left=self::remaining($deadline); stream_set_timeout($stream,(int)$left,max(1,(int)(($left-(int)$left)*1000000))); }
    private static function read($stream,int $size,int $deadline):string
    {
        self::timeout($stream,$deadline); $data=@fread($stream,$size);
        if ($data===false || stream_get_meta_data($stream)['timed_out']) self::transport();
        self::remaining($deadline); return $data;
    }
    private static function line($stream,int $deadline,int &$budget):string
    {
        // Read a bounded line with an absolute deadline, including slow header senders.
        $line='';
        while (true) {
            if ($budget<=0) self::protocol();
            $byte=self::read($stream,1,$deadline); if ($byte==='') self::protocol();
            $line.=$byte; $budget--;
            if ($byte==="\n") { if (!str_ends_with($line,"\r\n")) self::protocol(); return substr($line,0,-2); }
        }
    }
    private static function exact($stream,int $bytes,int $deadline):string
    {
        $out=''; while (strlen($out)<$bytes) {
            $part=self::read($stream,min(65536,$bytes-strlen($out)),$deadline);
            if ($part==='') self::protocol(); $out.=$part;
        } return $out;
    }
    private function body($stream,int $deadline):string
    {
        $budget=self::MAX_HEADERS;
        $status=self::line($stream,$deadline,$budget);
        if (!preg_match('~^HTTP/1\.[01] ([0-9]{3})(?: [\x20-\x7e]*)?$~D',$status,$m)) self::protocol();
        if ((int)$m[1]!==200) throw ConnectorTransportException::forStatus((int)$m[1]);
        $headers=[];
        while (($line=self::line($stream,$deadline,$budget))!=='') {
            if (!preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\x00-\x08\x0a-\x1f\x7f]*)$/D',$line,$m)) self::protocol();
            $headers[strtolower($m[1])][] = trim($m[2]);
        }
        $length=$headers['content-length']??[]; $encoding=$headers['transfer-encoding']??[];
        if (count($length)>1 || count($encoding)>1 || ($length && $encoding)) self::protocol();
        if (isset($headers['content-encoding']) && $headers['content-encoding']!==['identity']) self::protocol();
        if ($length) {
            if (!preg_match('/^[0-9]{1,10}$/D',$length[0]) || (float)$length[0]>$this->maxBytes) self::protocol();
            return self::exact($stream,(int)$length[0],$deadline);
        }
        $body='';
        if ($encoding) {
            if (strtolower($encoding[0])!=='chunked') self::protocol();
            // Bound framing overhead as well as decoded bytes; no chunk extensions needed by supported APIs.
            $budget=self::MAX_HEADERS;
            while (true) {
                $line=self::line($stream,$deadline,$budget);
                if (!preg_match('/^[0-9a-fA-F]{1,8}$/D',$line)) self::protocol();
                $size=hexdec($line);
                if ($size===0) { while (self::line($stream,$deadline,$budget)!=='') {} return $body; }
                if ($size>$this->maxBytes-strlen($body)) self::protocol();
                $body.=self::exact($stream,(int)$size,$deadline);
                if (self::exact($stream,2,$deadline)!=="\r\n") self::protocol();
            }
        }
        while (!feof($stream)) {
            $body.=self::read($stream,min(65536,$this->maxBytes+1-strlen($body)),$deadline);
            if (strlen($body)>$this->maxBytes) self::protocol();
        }
        return $body;
    }
    private function request(string $method,string $host,int $port,string $path,#[\SensitiveParameter] string $authorization,?string $caFile,string $credentialHeader):string
    {
        $host=ConnectorBindingService::validateHost($host); $port=ConnectorBindingService::validatePort($port);
        if (!in_array($credentialHeader,['Authorization','x-xsrf-token'],true) || $authorization==='' || strlen($authorization)>16384 || !preg_match('~^/[A-Za-z0-9/_?.=&%:-]+$~D',$path) || str_starts_with($path,'//') || preg_match('/[\x00-\x1f\x7f]/',$authorization))
            throw new RuntimeException('Invalid HTTPS request.');
        if ($caFile!==null && (!is_file($caFile)||!is_readable($caFile))) throw new ConnectorTransportException('connector_trust_failed');
        $deadline=hrtime(true)+$this->timeout*1000000000;
        $ip=$this->policy->resolve($host);
        $bracket=static fn(string $h):string=>str_contains($h,':')?'['.$h.']':$h;
        $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'SNI_enabled'=>true,
            'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT];
        if ($caFile!==null) $ssl['cafile']=$caFile;
        $context=stream_context_create(['ssl'=>$ssl]);
        $stream=@stream_socket_client('tcp://'.$bracket($ip).':'.$port,$errno,$errstr,self::remaining($deadline),STREAM_CLIENT_CONNECT,$context);
        if ($stream===false) self::transport();
        try {
            self::timeout($stream,$deadline);
            if (@stream_socket_enable_crypto($stream,true,$ssl['crypto_method'])!==true) {
                if (stream_get_meta_data($stream)['timed_out'] || hrtime(true)>=$deadline) self::transport();
                throw new ConnectorTransportException('connector_trust_failed');
            }
            $request="$method $path HTTP/1.1\r\nHost: ".$bracket($host).":$port\r\nAccept: application/json\r\n$credentialHeader: $authorization\r\nConnection: close\r\n".($method==='POST'?"Content-Length: 0\r\n":'')."\r\n";
            try {
                $offset=0; while ($offset<strlen($request)) { self::timeout($stream,$deadline); $n=@fwrite($stream,substr($request,$offset)); if (!$n) self::transport(); $offset+=$n; }
            } finally { sodium_memzero($request); }
            return $this->body($stream,$deadline);
        } finally { fclose($stream); }
    }
    public static function jsonObject(string $body):\stdClass
    {
        try { $decoded=json_decode($body,false,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ConnectorTransportException('connector_protocol_error'); }
        if (!$decoded instanceof \stdClass) throw new ConnectorTransportException('connector_protocol_error');
        return $decoded;
    }
}
