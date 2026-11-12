<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Collector\CollectionSchedule;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
$n=0;$ok=static function(bool $b,string $s)use(&$n){if(!$b)throw new RuntimeException($s);$n++;};
$now=new DateTimeImmutable('2026-11-12T04:00:00Z');
$ok((new CollectionSchedule(300,null))->next($now)==='2026-11-12 04:05:00.000000+00:00','interval');
$ok((new CollectionSchedule(null,'*/5 * * * *'))->next($now)==='2026-11-12 04:05:00.000000+00:00','cron');
$ok((new CollectionSchedule(null,'0 8 * * *','Europe/Helsinki'))->next($now)==='2026-11-12 06:00:00.000000+00:00','timezone');
$ok((new CollectionSchedule(null,'30 3 * * *','Europe/Helsinki'))->next(new DateTimeImmutable('2026-03-29T00:00:00Z'))==='2026-03-30 00:30:00.000000+00:00','DST gap');
foreach([[null,null],[60,'* * * * *'],[59,null],[86401,null],[null,'broken'],[null,'* * * * *','+03:00']] as $args){try{new CollectionSchedule(...$args);throw new RuntimeException('accepted invalid schedule');}catch(HttpException $e){$ok($e->status===422,'invalid schedule');}}
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0032_collection_plans.sql');$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split($sql))===10,'migration parsed');
putenv("APP_ENV=local");putenv("APP_URL=http://127.0.0.1:8080");
$app=(new Application(dirname(__DIR__)))->boot();try{$id='11111111-1111-4111-8111-111111111111';$base='/api/v1/connectors/targets/'.$id.'/collection-plans';foreach([['GET',''],['POST',''],['POST','/'.$id.'/state'],['POST','/'.$id.'/runs']] as [$method,$path]){$r=$app->handle(new HttpRequest($method,$base.$path));$ok(in_array($r->status,[400,401,415,422,503],true),'protected route');}}finally{$app->terminate();}
echo "Collection plans smoke: $n PASS\n";
