<?php
declare(strict_types=1);
namespace Cajeer\Servers\Events;
use Cajeer\Servers\Connector\ConnectorTransportException;

final class EventDiscovery
{
    public static function cursor(string $provider,array $cursor):array
    {
        if($provider==='ssh'){
            if($cursor===[])return [];
            if(count($cursor)!==1||!is_string($cursor['journal']??null)||!preg_match('/^[A-Za-z0-9=;:_\-]{1,1024}$/D',$cursor['journal']))throw new \InvalidArgumentException('Invalid journal cursor.');
            return $cursor;
        }
        if($provider!=='winrm'||array_diff(array_keys($cursor),['System','Application']))throw new \InvalidArgumentException('Invalid event cursor.');
        $out=[];foreach($cursor as $channel=>$anchor){if(!is_array($anchor)||count($anchor)!==2||!is_string($anchor['id']??null)||!preg_match('/^[1-9][0-9]{0,17}$/D',$anchor['id'])||!is_string($anchor['at']??null))throw new \InvalidArgumentException('Invalid record cursor.');SystemEvent::timestamp($anchor['at']);$out[$channel]=['id'=>$anchor['id'],'at'=>$anchor['at']];}ksort($out);return $out;
    }
    public static function normalize(string $provider,array $data,array $previous):array
    {
        try{
            if(!is_array($data['events']??null)||!array_is_list($data['events'])||count($data['events'])>100||!is_array($data['cursor']??null)||!is_bool($data['more']??null))throw new \InvalidArgumentException();
            $previous=self::cursor($provider,$previous);$cursor=self::cursor($provider,$data['cursor']);$expected=$previous;$events=[];$keys=[];
            foreach($data['events'] as $row){$e=SystemEvent::normalize($row);if($e['event_at']===null||$e['key']===''||isset($keys[$e['key']]))throw new \InvalidArgumentException();$keys[$e['key']]=true;
                if($provider==='ssh'){if($e['channel']!=='journal'||$e['key']===($expected['journal']??null))throw new \InvalidArgumentException();$expected=self::cursor('ssh',['journal'=>$e['key']]);}
                else{$channel=$e['channel'];if(!in_array($channel,['System','Application'],true)||!preg_match('/^'.preg_quote($channel,'/').':([1-9][0-9]{0,17}):(.+)$/D',$e['key'],$m)||SystemEvent::timestamp($m[2])!==$e['event_at']||(int)$m[1]<=(int)($expected[$channel]['id']??0))throw new \InvalidArgumentException();$expected[$channel]=['id'=>$m[1],'at'=>$m[2]];}
                $events[]=$e;
            }
            if(self::cursor($provider,$expected)!==$cursor)throw new \InvalidArgumentException();return ['previous'=>$previous,'cursor'=>$cursor,'events'=>$events,'more'=>$data['more']];
        }catch(\Throwable){throw new ConnectorTransportException('connector_protocol_error');}
    }
}
