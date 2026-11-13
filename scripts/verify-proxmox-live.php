#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
if(PHP_SAPI!=='cli'||$argc!==2){fwrite(STDERR,"Usage: php scripts/verify-proxmox-live.php /path/to/acceptance.json\n");exit(2);}
try{
    $file=$argv[1];if(!is_file($file)||!is_readable($file)||filesize($file)>65536)throw new RuntimeException('Invalid config.');
    $config=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($config))throw new RuntimeException('Invalid config.');
    $report=(new Cajeer\Servers\Connector\ProxmoxLiveVerifier())->run($config);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";exit($report['ok']?0:1);
}catch(Throwable){fwrite(STDERR,"FAIL: acceptance configuration or private credential file invalid.\n");exit(2);}
