#!/usr/bin/env php
<?php
declare(strict_types=1);
use Cajeer\Servers\Script\ScriptExecution;
if(PHP_SAPI!=='cli'||$argc>2||isset($argv[1])&&!preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D',$argv[1])) {fwrite(STDERR,"Usage: php bin/run-script.php [max-jobs 1..100]\n");exit(2);}
$app=null;$status=0;
try {
    $app=require dirname(__DIR__).'/core/bootstrap.php';
    $count=$app->container()->get(ScriptExecution::class)->work(isset($argv[1])?(int)$argv[1]:20);
    echo json_encode(['processed'=>$count],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {fwrite(STDERR,"Script worker unavailable. Check config, runtime dependencies and database.\n"); $status=1;}
finally {$app?->terminate();}
exit($status);
