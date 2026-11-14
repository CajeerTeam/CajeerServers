<?php
declare(strict_types=1);
namespace Cajeer\Servers\Events;
use Cajeer\Servers\Database\DatabaseManager;

/** Dedicated listener. Source identity is configured out of band, never from a message header. */
final class SyslogReceiver
{
    public function __construct(private readonly \Closure $sink){}
    public static function forDatabase(DatabaseManager $db):self
    {
        return new self(static function(array $source,array $events)use($db):void{
            $db->transaction(static fn($pdo)=>EventStore::receive($pdo,$source,$events));
        });
    }
    public static function config(string $path):array
    {
        if(!is_file($path)||is_link($path)||((fileperms($path)&0077)!==0)||filesize($path)>65536)throw new \InvalidArgumentException('Use a private syslog configuration file.');
        $c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($c)||!in_array($c['mode']??null,['tls','tcp','udp'],true)||!filter_var($c['bind']??'',FILTER_VALIDATE_IP)||!is_int($c['port']??null)||$c['port']<1024||$c['port']>65535||!is_array($c['sources']??null)||count($c['sources'])<1||count($c['sources'])>512)throw new \InvalidArgumentException('Invalid listener configuration.');
        if($c['mode']!=='tls'&&($c['allow_insecure']??false)!==true)throw new \InvalidArgumentException('Explicit legacy transport opt-in required.');
        foreach($c['sources'] as $key=>$target){if(!is_string($key)||($c['mode']==='tls'?!preg_match('/^[a-f0-9]{64}$/D',$key):!filter_var($key,FILTER_VALIDATE_IP))||!is_array($target)||!is_string($target['target_id']??null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$target['target_id'])||!is_int($target['target_version']??null)||$target['target_version']<1)throw new \InvalidArgumentException('Invalid syslog source mapping.');}
        if($c['mode']==='tls')foreach(['cert','key','ca'] as $f)if(!is_string($c[$f]??null)||!is_readable($c[$f]))throw new \InvalidArgumentException('TLS file unavailable.');
        return $c;
    }
    private static function ip(string $peer):string
    { $p=strrpos($peer,':');return trim($p===false?$peer:substr($peer,0,$p),'[]'); }
    private function persist(array $source,array $frames):bool
    {
        $events=[];foreach($frames as $frame){try{$events[]=SystemEvent::syslog($frame);}catch(\InvalidArgumentException){fwrite(STDERR,"Rejected malformed syslog frame\n");}}
        if(!$events)return true;
        try{($this->sink)($source,$events);return true;}catch(EventRejected){fwrite(STDERR,"Syslog source rejected or quota reached\n");return false;}

    }
    /** Nonblocking handshake: a silent client cannot block the receive loop. */
    private static function handshake($socket,array $sources):array|false|null
    {
        $status=@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLSv1_2_SERVER|STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
        if($status===0)return null;
        if($status!==true)return false;
        $cert=stream_context_get_options($socket)['ssl']['peer_certificate']??null;
        $key=$cert?(openssl_x509_fingerprint($cert,'sha256')?:''):'';
        return $sources[$key]??false;
    }
    public function run(array $c):void
    {
        $tls=$c['mode']==='tls';$udp=$c['mode']==='udp';$host=str_contains($c['bind'],':')?'['.$c['bind'].']':$c['bind'];
        $context=stream_context_create($tls?['ssl'=>['local_cert'=>$c['cert'],'local_pk'=>$c['key'],'cafile'=>$c['ca'],'verify_peer'=>true,'verify_peer_name'=>false,'allow_self_signed'=>false,'capture_peer_cert'=>true,'disable_compression'=>true,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_SERVER|STREAM_CRYPTO_METHOD_TLSv1_3_SERVER]]:[]);
        $server=@stream_socket_server(($udp?'udp':'tcp').'://'.$host.':'.$c['port'],$errno,$err,$udp?STREAM_SERVER_BIND:STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
        if(!$server)throw new \RuntimeException('Syslog listener bind failed.');
        stream_set_blocking($server,false);$clients=[];$window=time();$received=0;$accepted=0;$perSource=[];
        $close=static function(int $k)use(&$clients):void {fclose($clients[$k]['socket']);unset($clients[$k]);};
        try{while(true){
            if(time()!==$window){$window=time();$received=0;$accepted=0;$perSource=[];}
            $now=microtime(true);
            foreach($clients as $k=>$v){
                if($now-$v['last']>($v['source']===null?3:30)){$close($k);continue;}
                if($v['source']===null&&$now>=$v['next_handshake']){
                    $source=self::handshake($v['socket'],$c['sources']);
                    if($source===false){$close($k);continue;}
                    if($source!==null){$clients[$k]['source']=$source;$clients[$k]['last']=$now;}
                    else $clients[$k]['next_handshake']=$now+0.05;
                }
            }
            $read=[$server];foreach($clients as $v)if($v['source']!==null)$read[]=$v['socket'];$write=[];$except=[];
            // Pending TLS connections are retried on a bounded timer; never block in crypto negotiation.
            if(@stream_select($read,$write,$except,0,100000)===false)continue;
            foreach($read as $s){
                if(!is_resource($s))continue;
                if($s===$server){
                    if($udp){
                        $frame=stream_socket_recvfrom($server,16385,0,$peer);$source=$c['sources'][self::ip($peer??'')]??null;
                        if(is_string($frame)&&$source!==null&&$received<200&&($perSource[$source['target_id']]??0)<100){$received++;$perSource[$source['target_id']]=($perSource[$source['target_id']]??0)+1;$this->persist($source,[$frame]);}
                        continue;
                    }
                    $sock=@stream_socket_accept($server,0,$peer);if(!$sock)continue;
                    if(count($clients)>=32||$accepted++>=16){fclose($sock);continue;}
                    $source=$tls?null:($c['sources'][self::ip($peer??'')]??null);
                    if(!$tls&&$source===null){fclose($sock);continue;}
                    stream_set_blocking($sock,false);$clients[(int)$sock]=['socket'=>$sock,'source'=>$source,'framer'=>new SyslogFramer(),'last'=>microtime(true),'next_handshake'=>0.0];continue;
                }
                $k=(int)$s;if(!isset($clients[$k]))continue;$chunk=fread($s,8192);
                if($chunk===false||($chunk===''&&feof($s))){$close($k);continue;}if($chunk==='')continue;
                try{$frames=$clients[$k]['framer']->push($chunk);}catch(\InvalidArgumentException){$close($k);continue;}
                $clients[$k]['last']=microtime(true);$source=$clients[$k]['source'];$sourceId=$source['target_id'];
                if($received+count($frames)>200||($perSource[$sourceId]??0)+count($frames)>100){$close($k);continue;}
                $received+=count($frames);$perSource[$sourceId]=($perSource[$sourceId]??0)+count($frames);
                if(!$this->persist($source,$frames))$close($k);
            }
        }}finally{foreach($clients as $v)fclose($v['socket']);fclose($server);}
    }
}
