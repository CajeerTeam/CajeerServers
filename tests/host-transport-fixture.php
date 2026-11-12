<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,EndpointPolicy,HostTransport,LinuxConnector,WindowsConnector};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
// Only invoked by the integration harness; no secret in CLI arguments.
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$transport=new HostTransport(new EndpointPolicy($input['networks']??['127.0.0.1/32']),$input['python'],$input['known_hosts']??null,$input['ca']??null);
$registry=new ConnectorRegistry(new LinuxConnector($transport),new WindowsConnector($transport));
try {
    $context=new ConnectorContext($input['provider'],$input['host'],$input['port'],$input['type']);
    $result=$registry->execute($context,$input['operation']??'sync',$input['provider'],json_encode($input['credential'],JSON_THROW_ON_ERROR));
    echo json_encode(['items'=>$result->items,'complete'=>$result->complete],JSON_THROW_ON_ERROR);
} catch (HttpException $e) { echo json_encode(['error'=>$e->errorCode,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR); }
