<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;

/** Shared bounded PVE client. A read attempt is pinned to one endpoint; writes never fail over. */
final class ProxmoxApiClient
{
    public function __construct(private readonly HttpsTransport $https,private readonly ?string $caFile=null,private readonly ?\Closure $fixture=null,private readonly bool $requireProfile=false) {}
    public static function authorization(string $type,#[\SensitiveParameter] string $secret):string
    {
        $value=CredentialProfile::decodeForInternalUse($type,$secret);
        if($type==='proxmox_api'&&is_array($value)){ $id=$value['token_id'];$token=$value['token_secret']; }
        elseif($type==='api_token'&&is_string($value)&&str_contains($value,'=')){[$id,$token]=explode('=',$value,2);}
        else throw new \RuntimeException('Proxmox API credentials required.');
        if(!preg_match('/^[a-zA-Z0-9._@-]+![a-zA-Z0-9._-]{1,128}$/D',$id)||$token===''||strlen($token)>8192||preg_match('/[\x00-\x20\x7f]/',$token))throw new \RuntimeException('Invalid Proxmox token format.');
        return 'PVEAPIToken='.$id.'='.$token;
    }
    public function read(ConnectorContext $ctx,#[\SensitiveParameter] string $secret,callable $operation,bool $primaryOnly=false,bool $diagnostics=false):mixed
    {
        if($ctx->provider!=='proxmox'||($this->requireProfile&&$ctx->proxmoxProfile===null))throw new ConnectorTransportException('connector_trust_failed');
        $auth=self::authorization($ctx->credentialType,$secret);
        try {
            $endpoints=ProxmoxProfile::endpoints($ctx);if($primaryOnly)$endpoints=array_slice($endpoints,0,1);
            foreach($endpoints as $index=>$e){
                try{
                    $get=fn(string $path):mixed=>$this->get($e,$path,$auth);
                    $info=[];
                    if($ctx->proxmoxProfile!==null){
                        $version=$get('/api2/json/version');
                        if(!$version instanceof \stdClass||!is_string($version->version??null)||!preg_match('/^(8|9)\.[0-9]+(?:\.[0-9]+)?(?:-[0-9]+)?$/D',$version->version))throw new ConnectorTransportException('connector_protocol_error');
                        $info=ProxmoxProfile::verifyIdentity($ctx->proxmoxProfile,self::rows($get('/api2/json/cluster/status')));
                        $info+=['version'=>$version->version,'endpoint_host'=>$e['host'],'endpoint_port'=>$e['port'],'failover'=>$index>0];
                        if($diagnostics){
                            try{$permissions=$get('/api2/json/access/permissions');if(!$permissions instanceof \stdClass)throw new ConnectorTransportException('connector_protocol_error');
                                $root=$permissions->{'/' }??null;$info['root_privileges']=[];
                                foreach(['Sys.Audit','VM.Audit','VM.PowerMgmt','Datastore.Audit'] as $permission)if($root instanceof \stdClass&&in_array($root->{$permission}??null,[1,true,'1'],true))$info['root_privileges'][]=$permission;
                                $info['permissions_visible']=true;
                            }catch(ConnectorTransportException $error){if($error->category!=='connector_auth_failed')throw $error;$info['permissions_visible']=false;$info['root_privileges']=[];}
                        }
                    }
                    return $operation($get,$info);
                }catch(ConnectorTransportException $error){
                    if(!in_array($error->category,['connector_transport_failed','connector_unavailable'],true)||$index===count($endpoints)-1)throw $error;
                }
            }
            throw new ConnectorTransportException('connector_transport_failed');
        }finally{sodium_memzero($auth);}
    }
    private function get(array $e,string $path,#[\SensitiveParameter] string $auth):mixed
    {
        if(!in_array($path,['/api2/json/version','/api2/json/cluster/status','/api2/json/nodes','/api2/json/cluster/resources?type=vm','/api2/json/access/permissions'],true)&&!preg_match('~^/api2/json/nodes/[A-Za-z0-9][A-Za-z0-9._-]{0,119}/tasks/UPID%3A[A-Za-z0-9._%!-]+/status$~D',$path))throw new \RuntimeException('Unsupported PVE read operation.');
        if($this->fixture!==null){$host=$e['host'];$body=($this->fixture)('https://'.(str_contains($host,':')?'['.$host.']':$host).':'.$e['port'].$path,$auth,$this->caFile);}
        else $body=$this->https->get($e['host'],$e['port'],$path,$auth,$this->caFile,'Authorization',$e['pin']);
        if(!is_string($body)||strlen($body)>2097152)throw new ConnectorTransportException('connector_protocol_error');
        $decoded=HttpsTransport::jsonObject($body);if(!property_exists($decoded,'data'))throw new ConnectorTransportException('connector_protocol_error');return $decoded->data;
    }
    public static function rows(mixed $data):array
    {if(!is_array($data)||!array_is_list($data))throw new ConnectorTransportException('connector_protocol_error');return array_map(static function($r){if(!$r instanceof \stdClass)throw new ConnectorTransportException('connector_protocol_error');return (array)$r;},$data);}
    public function power(ConnectorContext $ctx,string $path,#[\SensitiveParameter] string $secret):mixed
    {
        // Probe primary identity before sending a single POST, outside the retry loop.
        $this->read($ctx,$secret,static function(callable $get,array $info):void {if(($info['quorate']??null)===false)throw new ConnectorTransportException('connector_unavailable');},true);
        $auth=self::authorization($ctx->credentialType,$secret);
        try{return HttpsTransport::jsonObject($this->https->powerPost($ctx->host,$ctx->port,$path,$auth,$this->caFile,$ctx->proxmoxProfile['primary_pin']??null))->data??null;}
        finally{sodium_memzero($auth);}
    }
}
