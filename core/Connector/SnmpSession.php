<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** One private process per session: Net-SNMP's USM cache is process-global.
 * A fresh process prevents a previous user's localized keys authenticating a
 * subsequent session with different credentials (including Vault rotation).
 */
final class SnmpSession
{
    private const MAX_FRAME = 262144;
    private const TIMEOUT_NS = 5_000_000_000;
    private mixed $process = null;
    private array $pipes = [];
    private string $buffer = '';

    private static function binary(): string
    {
        if (PHP_SAPI === 'cli') return PHP_BINARY;
        $versioned = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        return is_executable($versioned) ? $versioned : PHP_BINDIR.'/php';
    }

    public function __construct(string $ip, int $port, string $type, #[\SensitiveParameter] array $credential)
    {
        $binary = self::binary();
        if (!function_exists('proc_open') || !is_file($binary) || !is_executable($binary))
            throw new ConnectorTransportException('connector_dependency_missing');
        // Never inherit credentials or Net-SNMP configuration/persistent USM keys.
        $this->process = @proc_open([$binary, '-d', 'display_errors=0', '-d', 'log_errors=0', __DIR__.'/agents/snmp_session.php'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']], $this->pipes, __DIR__,
            ['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','MIBS'=>'','SNMPCONFPATH'=>'/dev/null','SNMP_PERSISTENT_DIR'=>'/dev/null']);
        if (!is_resource($this->process)) throw new ConnectorTransportException('connector_dependency_missing');
        try {
            foreach ($this->pipes as $pipe) stream_set_blocking($pipe, false);
            $data = $this->exchange(['op'=>'open','ip'=>$ip,'port'=>$port,'type'=>$type,'credential'=>$credential]);
            if ($data !== true) throw new ConnectorTransportException('connector_protocol_error');
        } catch (\Throwable $e) { $this->close(); throw $e; }
    }

    private function exchange(#[\SensitiveParameter] array $request): mixed
    {
        if (!is_resource($this->process)) throw new ConnectorTransportException('connector_transport_failed');
        $payload = json_encode($request, JSON_THROW_ON_ERROR)."\n";
        $offset = 0; $deadline = hrtime(true) + self::TIMEOUT_NS;
        try {
            if (strlen($payload) > self::MAX_FRAME) throw new ConnectorTransportException('connector_protocol_error');
            while ($offset < strlen($payload) || !str_contains($this->buffer, "\n")) {
                if (hrtime(true) >= $deadline) throw new ConnectorTransportException('connector_transport_failed');
                $read = [$this->pipes[1]]; $write = $offset < strlen($payload) ? [$this->pipes[0]] : []; $except = [];
                if (@stream_select($read,$write,$except,0,100000) === false) throw new ConnectorTransportException('connector_transport_failed');
                if ($write) {
                    $n = @fwrite($this->pipes[0], substr($payload,$offset,16384));
                    if ($n === false) throw new ConnectorTransportException('connector_transport_failed');
                    $offset += $n;
                }
                if ($read) {
                    $chunk = @fread($this->pipes[1],16384);
                    if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) throw new ConnectorTransportException('connector_transport_failed');
                    $this->buffer .= $chunk;
                    if (strlen($this->buffer) > self::MAX_FRAME) throw new ConnectorTransportException('connector_protocol_error');
                }
            }
            [$line,$this->buffer] = explode("\n",$this->buffer,2);
            if ($this->buffer !== '') throw new ConnectorTransportException('connector_protocol_error');
            $reply = json_decode($line,true,16,JSON_THROW_ON_ERROR);
            if (is_array($reply) && array_keys($reply) === ['error'] && in_array($reply['error'], ['connector_auth_failed','connector_transport_failed','connector_protocol_error','connector_dependency_missing'],true))
                throw new ConnectorTransportException($reply['error']);
            if (!is_array($reply) || array_keys($reply) !== ['data']) throw new ConnectorTransportException('connector_protocol_error');
            return $reply['data'];
        } catch (\JsonException) { $this->close(); throw new ConnectorTransportException('connector_protocol_error'); }
        catch (\Throwable $e) { $this->close(); throw $e; }
        finally { sodium_memzero($payload); }
    }
    private static function bytes(mixed $value): string
    {
        if (!is_string($value) || ($decoded = base64_decode($value,true)) === false)
            throw new ConnectorTransportException('connector_protocol_error');
        return $decoded;
    }
    private static function pair(mixed $value): array
    {
        if ($value === [null,null]) return $value;
        if (!is_array($value) || !array_is_list($value) || count($value)!==2 || !is_string($value[0]))
            throw new ConnectorTransportException('connector_protocol_error');
        return [$value[0],self::bytes($value[1])];
    }
    public function get(string $oid): string { return self::bytes($this->exchange(['op'=>'get','oid'=>$oid])); }
    public function next(string $oid): array { return self::pair($this->exchange(['op'=>'next','oid'=>$oid])); }
    public function nextBatch(array $oids): ?array
    {
        if (count($oids)<2 || count($oids)>16) throw new \InvalidArgumentException('Invalid SNMP batch.');
        $data = $this->exchange(['op'=>'batch','oids'=>array_values($oids)]);
        if ($data===null) return null;
        if (!is_array($data) || !array_is_list($data) || count($data)!==count($oids)) throw new ConnectorTransportException('connector_protocol_error');
        return array_map(self::pair(...),$data);
    }
    public function close(): void
    {
        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $this->pipes = [];
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) @proc_terminate($this->process,9);
            proc_close($this->process);
        }
        $this->process = null; $this->buffer = '';
    }
    public function __destruct() { $this->close(); }
}
