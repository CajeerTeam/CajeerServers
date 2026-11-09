<?php
declare(strict_types=1);

/** Minimal RESP2 protocol fixture for binary-safe RedisClient smoke tests. NOT a Redis emulator. */
$port=(int)($argv[1]??0);
$server=@stream_socket_server('tcp://127.0.0.1:'.$port,$errno,$error);
if(!$server)exit(1);
$socket=@stream_socket_accept($server,10);
if(!$socket)exit(2);
stream_set_timeout($socket,5);
$data=[];
$reply=static function(string $text)use($socket):void{fwrite($socket,$text);};
while(!feof($socket)) {
 $first=fgets($socket);if($first===false)break;
 if(!preg_match('/^\*(\d+)\r\n$/D',$first,$m))break;
 $args=[];
 for($i=0;$i<(int)$m[1];$i++){
  $len=fgets($socket);
  if(!preg_match('/^\$(\d+)\r\n$/D',(string)$len,$match))break 2;
  $wanted=(int)$match[1]+2;$v='';
  while(strlen($v)<$wanted) { $chunk=fread($socket,$wanted-strlen($v));if(!$chunk)break 2;$v.=$chunk; }
  $args[]=substr($v,0,-2);
 }
 switch(strtoupper($args[0]??'')){
 case 'AUTH': $reply(end($args)==='fixture-secret'?'+OK' . "\r\n":'-ERR invalid password' . "\r\n");break;
 case 'SELECT':$reply('+OK' . "\r\n");break;
 case 'PING':$reply('+PONG' . "\r\n");break;
 case 'SET':$data[$args[1]]=$args[2];$reply('+OK' . "\r\n");break;
 case 'GET':
  if(!array_key_exists($args[1],$data)){$reply("$-1\r\n");break;}
  $v=$data[$args[1]];$reply('$'.strlen($v)."\r\n".$v."\r\n");break;
 case 'DEL':$present=array_key_exists($args[1],$data);unset($data[$args[1]]);$reply(':'.(int)$present."\r\n");break;
 case 'EXISTS':$reply(':'.(int)array_key_exists($args[1],$data)."\r\n");break;
 default:$reply('-ERR unsupported fixture command' . "\r\n");
 }
}
fclose($socket);fclose($server);
