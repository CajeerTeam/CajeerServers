#!/usr/bin/env php
<?php
declare(strict_types=1);
$app=null;
try {
    $app=require dirname(__DIR__).'/core/bootstrap.php';
    $exit=(new \Cajeer\Servers\Cli\CommandRunner($app))->run([__FILE__,'connector:tick',...array_slice($argv,1)]);
} catch(Throwable) {fwrite(STDERR,"Connector scheduler failed.\n");$exit=1;}
finally {$app?->terminate();}
exit($exit);
