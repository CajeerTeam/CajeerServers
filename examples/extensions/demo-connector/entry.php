<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/packages/php-sdk/src/Connector.php';
require_once dirname(__DIR__,3).'/packages/php-sdk/src/HttpConnector.php';
require_once __DIR__.'/src/DemoConnector.php';
return static function(\Cajeer\Servers\Connector\HttpsTransport $transport,int $version):\Cajeer\Servers\Connector\ConnectorInterface {
    if ($version!==1) throw new RuntimeException('Unsupported connector SDK version.');
    $ca=getenv('DEMO_CONNECTOR_CA_FILE')?:null;
    return new \Cajeer\DemoConnector\DemoConnector($transport,$ca);
};
