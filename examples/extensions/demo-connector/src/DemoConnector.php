<?php
declare(strict_types=1);
namespace Cajeer\DemoConnector;
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorManifest,ConnectorResult};
use Cajeer\ServersSdk\HttpConnector;
/** Reads a real HTTPS inventory API. No demo/fabricated assets. */
final class DemoConnector extends HttpConnector
{
    public function manifest():ConnectorManifest {return new ConnectorManifest('custom','api',['api_token'],['health','inspect','sync','validate_rotation'],['server'],'https');}
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        $data=$this->json($context,'/inventory/v1/servers',$secret)->data??null;
        if (!is_array($data)||count($data)>2000) throw new \RuntimeException('Invalid server list.');
        $items=[];
        foreach ($data as $row) {
            if (!$row instanceof \stdClass || !is_string($row->id??null)||!preg_match('/^[A-Za-z0-9._-]{1,120}$/D',$row->id)||!is_string($row->name??null)||!in_array($row->status??null,['online','offline','unknown'],true)) throw new \RuntimeException('Invalid server observation.');
            $items[]=['type'=>'server','external_id'=>'server:'.$row->id,'fields'=>['name'=>$row->name,'status'=>$row->status]];
        }
        return new ConnectorResult($items,false); // No proof that endpoint is exhaustive.
    }
}
