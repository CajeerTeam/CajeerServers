<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Isolated protocol helper: credentials on a private pipe, never argv/env/disk. */
final class HostTransport
{
    public function __construct(private readonly EndpointPolicy $policy,private readonly string $python='/usr/bin/python3',
        private readonly ?string $knownHosts=null,private readonly ?string $winrmCa=null) {}

    public function collect(ConnectorContext $context,#[\SensitiveParameter] array $credential,bool $metrics=false):array
    {
        if (!str_starts_with($this->python,'/') || !is_file($this->python) || !is_executable($this->python) || !function_exists('proc_open'))
            throw new ConnectorTransportException('connector_dependency_missing');
        $ip=$this->policy->resolve($context->host);
        if ($context->provider==='ssh' && ($this->knownHosts===null || !str_starts_with($this->knownHosts,'/') || !is_file($this->knownHosts) || !is_readable($this->knownHosts)))
            throw new ConnectorTransportException('connector_trust_failed');
        if ($context->provider==='winrm' && ($credential['auth']??null)!=='basic')
            throw new ConnectorTransportException('connector_auth_failed');
        if ($context->provider==='winrm' && $this->winrmCa!==null && (!str_starts_with($this->winrmCa,'/')||!is_file($this->winrmCa)||!is_readable($this->winrmCa)))
            throw new ConnectorTransportException('connector_trust_failed');
        $payload=json_encode(['provider'=>$context->provider,'host'=>$context->host,'ip'=>$ip,'port'=>$context->port,
            'metrics'=>$metrics,'credential_type'=>$context->credentialType,'credential'=>$credential,'known_hosts'=>$this->knownHosts,'ca_file'=>$this->winrmCa],JSON_THROW_ON_ERROR);
        $pipes=[];
        $process=@proc_open([$this->python,'-I',__DIR__.'/agents/host_transport.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,__DIR__,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        if (!is_resource($process)) { sodium_memzero($payload); throw new ConnectorTransportException('connector_dependency_missing'); }
        $output=''; $offset=0; $deadline=microtime(true)+90;
        try {
            stream_set_blocking($pipes[0],false); stream_set_blocking($pipes[1],false);
            while (true) {
                if (microtime(true)>=$deadline) throw new ConnectorTransportException('connector_transport_failed');
                $read=[$pipes[1]]; $write=isset($pipes[0])?[$pipes[0]]:[]; $except=[];
                if (@stream_select($read,$write,$except,0,200000)===false) throw new ConnectorTransportException('connector_transport_failed');
                if ($write) {
                    $n=@fwrite($pipes[0],substr($payload,$offset,16384));
                    if ($n===false) throw new ConnectorTransportException('connector_transport_failed');
                    $offset+=$n;
                    if ($offset===strlen($payload)) { fclose($pipes[0]); unset($pipes[0]); sodium_memzero($payload); }
                }
                if ($read) {
                    $chunk=fread($pipes[1],16384);
                    if ($chunk===false) throw new ConnectorTransportException('connector_transport_failed');
                    $output.=$chunk;
                    if (strlen($output)>262145) throw new ConnectorTransportException('connector_protocol_error');
                    if (feof($pipes[1])) break;
                }
            }
            try { $decoded=json_decode($output,true,16,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new ConnectorTransportException('connector_protocol_error'); }
            if (!is_array($decoded)) throw new ConnectorTransportException('connector_protocol_error');
            if (array_keys($decoded)===['error'] && in_array($decoded['error'],['connector_auth_failed','connector_transport_failed','connector_unavailable','connector_protocol_error','connector_trust_failed','connector_dependency_missing'],true))
                throw new ConnectorTransportException($decoded['error']);
            if (array_keys($decoded)!==['data'] || !is_array($decoded['data'])) throw new ConnectorTransportException('connector_protocol_error');
            return $decoded['data'];
        } finally {
            if (is_string($payload)) sodium_memzero($payload);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            // Helper is one-shot; terminate also on early parse errors or process deadline.
            $status=proc_get_status($process);
            if ($status['running']) proc_terminate($process,9);
            proc_close($process);
        }
    }
}
