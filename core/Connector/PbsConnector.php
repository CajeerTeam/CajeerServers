<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;

/** Read-only PBS 3/4 API adapter. Never forwards remote paths or raw configuration. */
final readonly class PbsConnector implements ConnectorInterface
{
    public function __construct(private HttpsTransport $https, private ?string $caFile=null) {}
    public function manifest(): ConnectorManifest
    { return new ConnectorManifest('pbs','api',['api_token'],['inspect','health','validate_rotation'],['other'],'https'); }

    public static function authorization(#[\SensitiveParameter] string $secret): string
    {
        $token=CredentialProfile::decodeForInternalUse('api_token',$secret);
        if(!is_string($token)||!preg_match('/^[A-Za-z0-9._-]{1,128}@[A-Za-z0-9._-]{1,64}![A-Za-z0-9._-]{1,128}:[A-Za-z0-9._~-]{1,256}$/D',$token))
            throw new ConnectorTransportException('connector_auth_failed');
        return 'PBSAPIToken='.$token;
    }
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        if($context->provider!=='pbs'||$context->credentialType!=='api_token'||!$this->manifest()->supports($operation))throw new ConnectorTransportException('connector_auth_failed');
        $auth=self::authorization($secret);
        try {
            $get=function(string $path)use($context,&$auth):mixed {
                $body=$this->https->get($context->host,$context->port,'/api2/json'.$path,$auth,$this->caFile);
                $json=HttpsTransport::jsonObject($body);
                if(!property_exists($json,'data'))throw new ConnectorTransportException('connector_protocol_error');
                return $json->data;
            };
            $version=$get('/version');
            if(!$version instanceof \stdClass||!is_string($version->version??null)||!preg_match('/^[34]\.[0-9]+(?:\.[0-9]+)?(?:-[0-9]+)?$/D',$version->version))throw new ConnectorTransportException('connector_protocol_error');
            $stores=PbsDiscovery::rows($get('/admin/datastore'));
            if($operation!=='inspect')return new ConnectorResult([['type'=>'other','external_id'=>'pbs-server','fields'=>['name'=>'Proxmox Backup Server','status'=>'online']]],false);
            $snapshot=PbsDiscovery::collect($get,$stores,$version->version);
            return new ConnectorResult([],false,[],['pbs_discovery'=>$snapshot]);
        }finally{sodium_memzero($auth);}
    }
}
