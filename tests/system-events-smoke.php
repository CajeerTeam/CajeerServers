<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Events\{SystemEvent,SyslogFramer,EventDiscovery,SyslogReceiver};
use Cajeer\Servers\Connector\ConnectorTransportException;
$n=0;$ok=static function($v,$label)use(&$n){if(!$v)throw new RuntimeException('FAIL '.$label);$n++;};
$reject=static function(callable $f)use($ok){try{$f();}catch(InvalidArgumentException|ConnectorTransportException){$ok(true,'rejected');return;}throw new RuntimeException('Accepted invalid input');};
$a=SystemEvent::syslog('<34>1 2026-11-14T13:00:00+01:00 host sshd 1 ID [meta a="a\]b"][other x="y"] hello');
$ok($a['severity']===2&&$a['facility']===4&&$a['message']==='hello'&&$a['event_at']==='2026-11-14T12:00:00.000000Z','RFC5424');
$b=SystemEvent::syslog('<13>Nov  7 08:00:00 host app[12]: hello');$ok($b['application']==='app'&&$b['event_at']===null&&$b['message']==='hello','RFC3164');
$ok(SystemEvent::syslog('<14>1 - - - - - - hi')['message']==='hi','NILVALUE');
foreach(['<192>bad','<12>1 0000-01-01T00:00:00Z h a - - - bad','<12>1 2026-01-01T24:00:00Z h a - - - bad','<12>1 2026-02-30T00:00:00Z h a - - - bad','<12>1 - h a - - [x y="bad" hi','<12>1 - h a - - -oops','<12>1 - h a - - - '.str_repeat('x',16384)] as $x)$reject(fn()=>SystemEvent::syslog($x));
$f=new SyslogFramer();$raw='<13>Nov  7 08:00:00 host app: hello';$packet=strlen($raw).' '.$raw;$ok($f->push(substr($packet,0,5))===[],'partial length');$ok($f->push(substr($packet,5).$packet)===[$raw,$raw],'octet concatenated');
$f=new SyslogFramer();$ok($f->push($raw."\n".$raw."\r\n")===[$raw,$raw],'newline frames');$reject(fn()=>(new SyslogFramer())->push('999999 abc'));$reject(fn()=>(new SyslogFramer())->push('<'.str_repeat('x',17000)));
$event=['key'=>'s=abc;i=1','channel'=>'journal','event_at'=>'2026-11-14T12:00:00Z','severity'=>6,'facility'=>null,'hostname'=>'host','application'=>'systemd','message'=>'log'];
$batch=EventDiscovery::normalize('ssh',['events'=>[$event],'cursor'=>['journal'=>$event['key']],'more'=>false],[]);$ok($batch['cursor']['journal']===$event['key']&&count($batch['events'])===1,'journal cursor');
$reject(fn()=>EventDiscovery::normalize('ssh',['events'=>[$event],'cursor'=>['journal'=>'wrong'],'more'=>false],[]));$reject(fn()=>EventDiscovery::normalize('ssh',['events'=>[$event,$event],'cursor'=>['journal'=>$event['key']],'more'=>false],[]));$reject(fn()=>EventDiscovery::normalize('ssh',['events'=>[$event],'cursor'=>['journal'=>$event['key']],'more'=>false],['journal'=>$event['key']]));
$w=array_replace($event,['key'=>'System:7:2026-11-14T12:00:00.000000Z','channel'=>'System']);$cursor=['System'=>['id'=>'7','at'=>'2026-11-14T12:00:00.000000Z']];$ok(EventDiscovery::normalize('winrm',['events'=>[$w],'cursor'=>$cursor,'more'=>true],[])['more'],'Windows cursor');$reject(fn()=>EventDiscovery::normalize('winrm',['events'=>[$w],'cursor'=>$cursor,'more'=>false],$cursor));$reject(fn()=>EventDiscovery::cursor('winrm',['Security'=>['id'=>'1','at'=>'2026-11-14T12:00:00Z']]));
$ok(strlen(SystemEvent::text(str_repeat('😀',2000),4095))===4092,'UTF8 boundary');$ok(SystemEvent::text("\x00a\x1b",10)==='a','control bytes');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');$root=dirname(__DIR__);$app=(new \Cajeer\Servers\Kernel\Application($root))->boot();try{$registry=$app->container()->get(\Cajeer\Servers\Collector\CollectionRuntimeService::class);$catalog=array_column($registry->catalog(),null,'name');foreach(['ssh.events','winrm.events'] as $c)$ok($catalog[$c]['operations']===['inspect']&&$catalog[$c]['resource_types']===[],'read-only collector');$res=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest('GET','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/system-events'));$ok(in_array($res->status,[401,503],true),'unauthenticated read rejected');}finally{$app->terminate();}
$sql=file_get_contents($root.'/migrations/pgsql/0059_system_events.sql');$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split($sql))>=6,'migration parser');
echo "System events smoke: $n PASS\n";
