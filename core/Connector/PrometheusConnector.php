<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;
use Cajeer\Servers\Credentials\CredentialProfile;
use Cajeer\Servers\Metrics\{MetricDriver,MetricBatch};
use Cajeer\Servers\Script\ScriptOutputParser;

/** Fixed /metrics HTTPS endpoint, token-authenticated, no user-supplied URL or redirects. */
final readonly class PrometheusConnector implements ConnectorInterface,MetricDriver
{
    public function __construct(private HttpsTransport $https,private ?string $caFile=null){}
    public function manifest():ConnectorManifest{return new ConnectorManifest('prometheus','api',['api_token'],['inspect','health','validate_rotation'],['other'],'https');}
    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        if($context->provider!=='prometheus'||$context->credentialType!=='api_token')throw new ConnectorTransportException('connector_auth_failed');
        $token=CredentialProfile::decodeForInternalUse('api_token',$secret);
        if(!is_string($token)||!preg_match('/^[\x21-\x7e]{1,8192}$/D',$token))throw new ConnectorTransportException('connector_auth_failed');
        try{$raw=$this->https->getText($context->host,$context->port,'/metrics','Bearer '.$token,$this->caFile);}
        finally{sodium_memzero($token);}
        try{
            // Prometheus text 0.0.4; preserve original decimal strings, not PHP floats.
            $parsed=(new ScriptOutputParser())->parse($raw,['parser'=>'prometheus','output_schema'=>'metrics']);
            $types=[];foreach(explode("\n",$raw) as $line)if(preg_match('/^# TYPE ([A-Za-z_:][A-Za-z0-9_:]*) (counter|gauge|histogram|summary|untyped)\s*$/D',$line,$m)){$types[$m[1]]=$m[2];}
            $rows=[];foreach($parsed['output'] as $row){$name=$row->metric;$base=preg_replace('/_(total|count|bucket|sum)$/D','',$name);$type=$types[$name]??$types[$base]??null;
                $kind=$type==='counter'||(in_array($type,['histogram','summary'],true)&&preg_match('/_(count|bucket)$/D',$name))?'counter':'gauge';
                $rows[]=MetricBatch::sample($name,$row->value,'',(array)$row->labels,$kind,null,$row->observed_at??null);}
            if($rows===[])MetricBatch::invalid();return MetricBatch::normalize($rows);
        }catch(\Throwable){throw new ConnectorTransportException('connector_protocol_error');}
    }
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $this->metrics($context,$secret);
        if($operation==='inspect')return new ConnectorResult([],false);
        // Successful authenticated scrape is evidence for a health/rotation probe, not a CMDB snapshot.
        return new ConnectorResult([['type'=>'other','external_id'=>'metrics-endpoint','fields'=>['name'=>'Prometheus exporter','status'=>'online']]],false);
    }
}
