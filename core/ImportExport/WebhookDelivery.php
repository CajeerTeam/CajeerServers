<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;
use Cajeer\Servers\Connector\{HttpsTransport,ConnectorTransportException};

/** Trusted operator destination; stable delivery ID across retries and CLI restarts. */
final class WebhookDelivery
{
    public function __construct(private readonly HttpsTransport $transport){}
    public function deliver(string $url,string $exportId,array $artifact,#[\SensitiveParameter] string $secret,?string $caFile=null,int $attempts=3):array
    {
        $u=parse_url($url);if(!$u||($u['scheme']??null)!=='https'||!isset($u['host'],$u['path'])||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||isset($u['query'])||$attempts<1||$attempts>3||strlen($secret)<32||strlen($secret)>256)throw new \InvalidArgumentException('Explicit HTTPS receiver, path and delivery secret required.');
        $body=$artifact['body'];$digest=hash('sha256',$body);if(!hash_equals($digest,$artifact['sha256']))throw new \InvalidArgumentException('Artifact digest mismatch.');
        $mime=match($artifact['format']){'json'=>'application/json','csv'=>'text/csv','openmetrics'=>'application/openmetrics-text; version=1.0.0',default=>'application/octet-stream'};
        $id=hash('sha256',$exportId."\n".$url."\n".$digest);
        for($i=1;$i<=$attempts;$i++){
            try{$this->transport->postArtifact(trim($u['host'],'[]'),$u['port']??443,$u['path'],$id,$body,$mime,$secret,$caFile);return ['delivery_id'=>$id,'sha256'=>$digest,'attempts'=>$i,'status'=>'delivered'];}
            catch(ConnectorTransportException $e){if($i===$attempts||!in_array($e->category,['connector_transport_failed','connector_unavailable','connector_rate_limited'],true))throw $e;usleep(250000*2**($i-1));}
        }
        throw new \LogicException('Unreachable.');
    }
}
