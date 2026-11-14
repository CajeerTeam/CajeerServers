<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Events\SyslogReceiver;
$config=SyslogReceiver::config($argv[1]);$output=$argv[2];
(new SyslogReceiver(static function(array $source,array $events)use($output):void{
    foreach($events as $event)file_put_contents($output,json_encode(['target'=>$source['target_id'],'event'=>$event],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
}))->run($config);
