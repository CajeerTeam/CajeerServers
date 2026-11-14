<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Scoped VMmanager 6 API client. No remote-provided paths or implicit retries. */
final class VmmanagerApiClient
{
    private const READS = [
        'nodes'=>'/vm/v3/node', 'guests'=>'/vm/v3/host', 'clusters'=>'/vm/v3/cluster',
        'storages'=>'/vm/v3/storage', 'os'=>'/vm/v3/os', 'presets'=>'/vm/v3/preset', 'images'=>'/vm/v3/image', 'isos'=>'/vm/v3/os/iso',
        'tasks'=>'/vm/v3/task', 'networks'=>'/ip/v3/ipnet', 'pools'=>'/ip/v3/ippool', 'addresses'=>'/ip/v3/ip',
    ];

    public function __construct(private readonly HttpsTransport $https, private readonly ?string $caFile = null) {}

    private function get(ConnectorContext $context,string $path,#[\SensitiveParameter] string $token,float $deadline): \stdClass
    {
        if ($context->provider!=='vmmanager') throw new \InvalidArgumentException('VMmanager context required.');
        if (microtime(true)>=$deadline) throw new ConnectorTransportException('connector_transport_failed');
        if (strlen($token)<8 || strlen($token)>8192 || preg_match('/[^\x21-\x7e]/D',$token)) throw new \RuntimeException('Invalid VMmanager token format.');
        $response=$this->https->get($context->host,$context->port,$path,$token,$this->caFile,'x-xsrf-token',$context->vmmanagerProfile['primary_pin']??null);
        if (microtime(true)>=$deadline) throw new ConnectorTransportException('connector_transport_failed');
        return self::decode($response);
    }

    public static function decode(string $body): \stdClass
    {
        $decoded=HttpsTransport::jsonObject($body);
        if (property_exists($decoded,'error')) {
            $code=$decoded->error instanceof \stdClass?($decoded->error->code??null):null;
            // Do not expose the message/value or guess unknown vendor codes.
            throw new ConnectorTransportException(in_array($code,[3001,'3001',5112,'5112',22013,'22013'],true)?'connector_auth_failed':'connector_protocol_error');
        }
        return $decoded;
    }

    /** Positive bounded remote identifiers, preserving canonical decimal representation. */
    public static function id(mixed $value): int
    {
        if ((!is_int($value)&&!(is_string($value)&&preg_match('/^[1-9][0-9]{0,9}$/D',$value)))||(int)$value<1||(int)$value>2147483647) throw new ConnectorTransportException('connector_protocol_error');
        return (int)$value;
    }

    /** Authentication is demonstrated by the remote service, not inferred from token syntax. */
    public function identity(ConnectorContext $context,#[\SensitiveParameter] string $token,float $deadline): array
    {
        $data=$this->get($context,'/auth/v4/whoami',$token,$deadline);
        $owner=self::id($data->owner_id??null);
        $trustee=isset($data->trustee_id)?self::id($data->trustee_id):$owner;
        if (!isset($data->roles)||!is_array($data->roles)||count($data->roles)>256) throw new ConnectorTransportException('connector_protocol_error');
        foreach($data->roles as $role) if (!is_string($role)||$role===''||strlen($role)>256||preg_match('/[\x00-\x1f\x7f]/',$role)) throw new ConnectorTransportException('connector_protocol_error');
        // No email addresses, role descriptions, session contents or tokens are returned.
        return ['owner_id'=>$owner,'trustee_id'=>$trustee,'administrator'=>in_array('@admin',$data->roles,true)];
    }

    public function page(ConnectorContext $context,string $resource,int $after,int $limit,#[\SensitiveParameter] string $token,float $deadline): array
    {
        if (!isset(self::READS[$resource])||$after<0||$after>2147483647||$limit<1||$limit>100) throw new \InvalidArgumentException('Unsupported VMmanager list request.');
        $path=self::READS[$resource].'?where=id%20GT%20'.$after.'&orderby=id%20asc&limit=0%2C'.$limit;
        $decoded=$this->get($context,$path,$token,$deadline);
        if (!isset($decoded->list)||!is_array($decoded->list)||count($decoded->list)>$limit) throw new ConnectorTransportException('connector_protocol_error');
        $last=$after;
        foreach($decoded->list as $row){
            if (!$row instanceof \stdClass) throw new ConnectorTransportException('connector_protocol_error');
            $id=self::id($row->id??null);
            if ($id<=$last) throw new \RuntimeException('VMmanager pagination returned duplicate or unordered IDs.');
            $last=$id;
        }
        return $decoded->list;
    }

    /** Advertised handler permissions are not resource-level authorization or a successful mutation. */
    public function actionCapabilities(ConnectorContext $context,#[\SensitiveParameter] string $token,float $deadline,array $identity): array
    {
        $result=['start'=>'unknown','stop'=>'unknown','restart'=>'unknown'];
        try {
            $data=$this->get($context,'/auth/v4/self/handles',$token,$deadline);
        } catch (ConnectorTransportException $e) {
            if (in_array($e->httpStatus,[403,404],true)) return $result;
            throw $e;
        }
        if (!isset($data->handles)||!$data->handles instanceof \stdClass||!isset($data->user_id)) throw new ConnectorTransportException('connector_protocol_error');
        if (!in_array(self::id($data->user_id),[$identity['owner_id'],$identity['trustee_id']],true)) throw new ConnectorTransportException('connector_auth_failed');
        $service=$data->handles->vm_v3??null;
        if (!$service instanceof \stdClass||!isset($service->post)) return $result;
        if (!is_array($service->post)||count($service->post)>8192) throw new ConnectorTransportException('connector_protocol_error');
        foreach ($service->post as $path) if (!is_string($path)||strlen($path)>1024) throw new ConnectorTransportException('connector_protocol_error');
        foreach ($result as $action=>$_) {
            $result[$action]='not_advertised';
            foreach ($service->post as $path) {
                if (preg_match('~^/host/\{[a-zA-Z_][a-zA-Z0-9_]*\}/'.$action.'/?$~D',$path)) { $result[$action]='advertised'; break; }
            }
        }
        return $result;
    }

    /** Probe actual read access. Empty lists are valid; a 404 is not proof that the service is absent. */
    public function capabilities(ConnectorContext $context,#[\SensitiveParameter] string $token,float $deadline): array
    {
        $out=['nodes'=>'available']; // caller already proved node access
        foreach(array_keys(self::READS) as $resource){
            if ($resource==='nodes') continue;
            try {
                $this->page($context,$resource,0,1,$token,$deadline);
                $out[$resource]='available';
            } catch (ConnectorTransportException $e) {
                // Never suppress an expired/revoked token, TLS error, redirect or rate limit.
                $out[$resource]=match($e->httpStatus){403=>'denied',404=>'not_found',default=>throw $e};
            }
        }
        return $out;
    }
}
