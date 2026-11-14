<?php
declare(strict_types=1);
namespace Cajeer\Servers\Events;

/** Untrusted log content is text, never a source of authorization. */
final class SystemEvent
{
    public static function text(mixed $value,int $max):string
    {
        if(!is_string($value)||!preg_match('//u',$value))throw new \InvalidArgumentException('Invalid event text.');
        $value=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u','',$value);
        if(strlen($value)>$max){$value=substr($value,0,$max);while(!preg_match('//u',$value))$value=substr($value,0,-1);}
        return $value;
    }
    public static function timestamp(?string $value):?string
    {
        if($value===null)return null;
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-](\d{2}):(\d{2}))$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])||(int)$m[4]>23||(int)$m[5]>59||(int)$m[6]>59||(isset($m[7])&&((int)$m[7]>23||(int)$m[8]>59)))throw new \InvalidArgumentException('Invalid event timestamp.');
        try{$d=new \DateTimeImmutable($value);}catch(\Exception){throw new \InvalidArgumentException('Invalid event timestamp.');}$errors=\DateTimeImmutable::getLastErrors();if($errors!==false)throw new \InvalidArgumentException('Invalid event timestamp.');
        $utc=$d->setTimezone(new \DateTimeZone('UTC'));if((int)$utc->format('Y')<1||(int)$utc->format('Y')>9999)throw new \InvalidArgumentException('Invalid event timestamp.');return $utc->format('Y-m-d\TH:i:s.u\Z');
    }
    public static function normalize(array $row):array
    {
        $severity=$row['severity']??null;$facility=$row['facility']??null;
        if(!is_int($severity)||$severity<0||$severity>7||($facility!==null&&(!is_int($facility)||$facility<0||$facility>23)))throw new \InvalidArgumentException('Invalid event priority.');
        return ['key'=>self::text($row['key']??'',1024),'channel'=>self::text($row['channel']??'',64),'event_at'=>self::timestamp($row['event_at']??null),'severity'=>$severity,'facility'=>$facility,'hostname'=>self::text($row['hostname']??'',255),'application'=>self::text($row['application']??'',255),'message'=>self::text($row['message']??'',4096)];
    }
    public static function syslog(string $frame):array
    {
        if(strlen($frame)>16384||!preg_match('/^<(\d{1,3})>(.*)$/sD',$frame,$m)||(int)$m[1]>191)throw new \InvalidArgumentException('Invalid syslog PRI.');
        $pri=(int)$m[1];$body=$m[2];$time=null;$host='';$app='';
        if(preg_match('/^1 (\S+) (\S+) (\S+) (\S+) (\S+) (.*)$/sD',$body,$p)){
            $time=$p[1]==='-'?null:self::timestamp($p[1]);$host=$p[2]==='-'?'':$p[2];$app=$p[3]==='-'?'':$p[3];$rest=$p[6];
            if(str_starts_with($rest,'-'))$rest=substr($rest,1);
            elseif(str_starts_with($rest,'[')){
                do { $end=null;$escaped=false;$quoted=false;for($i=1;$i<strlen($rest);$i++){ $c=$rest[$i];if($escaped){$escaped=false;continue;}if($c==='\\'){$escaped=true;continue;}if($c==='"')$quoted=!$quoted;if($c===']'&&!$quoted){$end=$i;break;} }if($end===null)throw new \InvalidArgumentException('Invalid structured data.');$rest=substr($rest,$end+1); }while(str_starts_with($rest,'['));
            }else throw new \InvalidArgumentException('Invalid structured data.');
            if($rest!==''&&!str_starts_with($rest,' '))throw new \InvalidArgumentException('Invalid syslog separator.');$body=substr($rest,1);
        }elseif(preg_match('/^[A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2} (\S+) (.*)$/sD',$body,$p)){
            // RFC3164 has no year or timezone: preserve receipt time separately.
            $host=$p[1];$body=$p[2];if(preg_match('/^([^ :\[]+)(?:\[\d+\])?:\s?(.*)$/sD',$body,$p)){$app=$p[1];$body=$p[2];}
        }else throw new \InvalidArgumentException('Unsupported syslog header.');
        return self::normalize(['key'=>bin2hex(random_bytes(16)),'channel'=>'syslog','event_at'=>$time,'severity'=>$pri%8,'facility'=>intdiv($pri,8),'hostname'=>$host,'application'=>$app,'message'=>$body]);
    }
}
