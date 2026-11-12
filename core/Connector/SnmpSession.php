<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Read-only Net-SNMP session. No SET operation is exposed. */
final class SnmpSession
{
    private \SNMP $session;
    public function __construct(string $ip,int $port,string $type,#[\SensitiveParameter] array $credential)
    {
        if (!class_exists(\SNMP::class)) throw new ConnectorTransportException('connector_dependency_missing');
        $endpoint=str_contains($ip,':')?'['.$ip.']:'.$port:$ip.':'.$port;
        try {
            $this->session=@new \SNMP($type==='snmp_v3'?\SNMP::VERSION_3:\SNMP::VERSION_2C,$endpoint,
                $credential[$type==='snmp_v3'?'username':'community'],1000000,0);
            $this->session->exceptions_enabled=\SNMP::ERRNO_ANY;
            $this->session->valueretrieval=SNMP_VALUE_PLAIN;
            $this->session->oid_output_format=SNMP_OID_OUTPUT_NUMERIC;
            if ($type==='snmp_v3') {
                // PHP 8.4/8.5 support SHA-2 and AES128, not AES256 in ext-snmp.
                if (($credential['privacy_protocol']??null)!=='aes128') throw new ConnectorTransportException('connector_auth_failed');
                $auth=match($credential['auth_protocol']){'sha256'=>'SHA256','sha512'=>'SHA512',default=>throw new ConnectorTransportException('connector_auth_failed')};
                if (!@$this->session->setSecurity('authPriv',$auth,$credential['auth_passphrase'],'AES',$credential['privacy_passphrase']))
                    throw new ConnectorTransportException('connector_auth_failed');
            }
        } catch (ConnectorTransportException $e) {$this->close();throw $e;}
        catch (\Throwable) {$this->close();throw new ConnectorTransportException('connector_dependency_missing');}
    }
    private function call(callable $operation,bool $next=false):mixed
    {
        try { $result=$operation(); }
        catch (\SNMPException $e) {
            // Net-SNMP reports explicit USM errors as generic errors; never expose library messages.
            $message=strtolower($e->getMessage());
            if ($next && $e->getCode()===\SNMP::ERRNO_ERROR_IN_REPLY && (str_contains($message,'no more variables left in this mib view')||str_contains($message,'end of mib'))) return null;
            $auth=preg_match('/authentication|unknown user|authorization|decryption|security level|wrong digest|unknown security name/',$message);
            throw new ConnectorTransportException($auth?'connector_auth_failed':($e->getCode()===\SNMP::ERRNO_TIMEOUT?'connector_transport_failed':'connector_protocol_error'));
        } catch (\Throwable) { throw new ConnectorTransportException('connector_protocol_error'); }
        if ($result===false) throw new ConnectorTransportException('connector_protocol_error');
        return $result;
    }
    public function get(string $oid):string
    {
        $v=$this->call(fn()=>@$this->session->get($oid));
        if (!is_string($v)) throw new ConnectorTransportException('connector_protocol_error');
        return $v;
    }
    /** Array input preserves the returned OID, rather than only its value. */
    public function next(string $oid):array
    {
        $v=$this->call(fn()=>@$this->session->getnext([$oid]),true);
        if ($v===null) return [null,null];
        if (!is_array($v)||count($v)!==1||!is_string(array_key_first($v))||!is_string(reset($v))) throw new ConnectorTransportException('connector_protocol_error');
        return [array_key_first($v),reset($v)];
    }
    public function close():void { if (isset($this->session)) @$this->session->close(); }
}
