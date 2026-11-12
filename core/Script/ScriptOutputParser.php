<?php
declare(strict_types=1);
namespace Cajeer\Servers\Script;

use Cajeer\Servers\Http\Exception\HttpException;

/** Bounded, deterministic parsers. Never interpret output as executable code. */
final class ScriptOutputParser
{
    public const FORMATS=['json','csv','key_value','prometheus'];
    public const MAX_BYTES=262144;
    public const MAX_ROWS=2000;
    public static function invalid(): never {throw new HttpException(422,'Invalid script output for signed parser or schema.','script_output_invalid');}
    public static function policy(array $manifest): array
    {
        $format=$manifest['parser']??'json';$schema=$manifest['output_schema']??'document';
        if(!in_array($format,self::FORMATS,true)||!in_array($schema,DataNormalizer::SCHEMAS,true)||($format==='prometheus'&&$schema!=='metrics')) self::invalid();
        if(($manifest['provider']??'local')!=='local'&&($format!=='json'||$schema!=='document'))self::invalid();
        return [$format,$schema];
    }
    public function parse(string $raw,array $manifest):array
    {
        [$format,$schema]=self::policy($manifest);
        if(strlen($raw)>min(self::MAX_BYTES,$manifest['output_limit_bytes']??self::MAX_BYTES)||!preg_match('//u',$raw)||str_contains($raw,"\0"))self::invalid();
        $value=match($format){'json'=>$this->json($raw),'csv'=>$this->csv($raw),'key_value'=>$this->keyValue($raw),'prometheus'=>$this->prometheus($raw)};
        $result=['output'=>$value,'output_bytes'=>strlen($raw),'exit_code'=>0];
        $normalized=(new DataNormalizer())->normalize($value,$format,$schema);
        if($normalized!==null)$result['normalized']=$normalized;
        // PostgreSQL jsonb accepts neither NUL in strings nor non-finite numbers. Also bound expanded results.
        self::checkTree($result);
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>1048576)self::invalid();
        return $result;
    }
    private static function checkTree(mixed $v,int $depth=0,int &$nodes=0):void
    {
        if(++$nodes>50000||$depth>36)self::invalid();
        if(is_string($v)&&str_contains($v,"\0")||is_float($v)&&!is_finite($v))self::invalid();
        if(is_array($v)||is_object($v))foreach($v as $k=>$x){if(is_string($k)&&str_contains($k,"\0"))self::invalid();self::checkTree($x,$depth+1,$nodes);}
    }
    public function json(string $raw):array|object
    {
        if(strlen($raw)>self::MAX_BYTES||!preg_match('//u',$raw)||str_contains($raw,"\0"))self::invalid();
        // PHP's native decoder silently overwrites duplicate keys. Check the JSON token stream first.
        $i=0;$nodes=0;$this->jsonValue($raw,$i,0,$nodes);$this->space($raw,$i);
        if($i!==strlen($raw))self::invalid();
        try{$value=json_decode($raw,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){self::invalid();}
        if(!is_array($value)&&!is_object($value))self::invalid();self::checkTree($value);return $value;
    }
    private function space(string $s,int &$i):void { $i+=strspn($s," \t\r\n",$i); }
    private function stringToken(string $s,int &$i):string
    {
        if(($s[$i]??'')!=='"')self::invalid();$start=$i++;
        $n=strlen($s);
        while($i<$n){$c=$s[$i++];if($c==='\\'){if($i>=$n)self::invalid();$i++;continue;}if($c==='"'){
                try{$v=json_decode(substr($s,$start,$i-$start),false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){self::invalid();}return $v;
            }if(ord($c)<32)self::invalid();}
        self::invalid();
    }
    private function jsonValue(string $s,int &$i,int $depth,int &$nodes):void
    {
        if($depth>30||++$nodes>25000)self::invalid();$this->space($s,$i);$c=$s[$i]??'';
        if($c==='{'||$c==='['){$i++;$this->space($s,$i);$end=$c==='{'?'}':']';$seen=[];if(($s[$i]??'')===$end){$i++;return;}
            while(true){if($c==='{'){$key=$this->stringToken($s,$i);if(isset($seen[$key]))self::invalid();$seen[$key]=true;$this->space($s,$i);if(($s[$i++]??'')!==':')self::invalid();}
                $this->jsonValue($s,$i,$depth+1,$nodes);$this->space($s,$i);$next=$s[$i++]??'';if($next===$end)return;if($next!==',')self::invalid();$this->space($s,$i);}
        }
        if($c==='"'){$this->stringToken($s,$i);return;}
        if(!preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/',$s,$m,0,$i))self::invalid();$i+=strlen($m[0]);
    }
    /** RFC4180-style comma CSV; strict quotes, unique header, fixed width, bounded multiline cells. */
    private function csv(string $s):array
    {
        $rows=[];$row=[];$cell='';$state='start';$n=strlen($s);
        $field=function()use(&$row,&$cell,&$state){if(strlen($cell)>16384||count($row)>=64)self::invalid();$row[]=$cell;$cell='';$state='start';};
        $record=function()use(&$rows,&$row,$field){$field();if(count($rows)>self::MAX_ROWS)self::invalid();$rows[]=$row;$row=[];};
        for($i=0;$i<$n;$i++){$c=$s[$i];
            if($state==='quoted'){if($c==='"'){if(($s[$i+1]??'')==='"'){$cell.='"';$i++;}else $state='closed';}else $cell.=$c;}
            elseif($c===','){$field();}
            elseif($c==="\n"||$c==="\r"){if($c==="\r"){if(($s[++$i]??'')!=="\n")self::invalid();}$record();}
            elseif($c==='"'&&$state==='start')$state='quoted';
            elseif($state==='closed'||$c==='"')self::invalid();
            else{$state='plain';$cell.=$c;}
            if(strlen($cell)>16384)self::invalid();
        }
        if($state==='quoted')self::invalid();if($row!==[]||$state!=='start')$record();
        $header=array_shift($rows);if(!$header||count(array_unique($header))!==count($header))self::invalid();
        foreach($header as $h)if(!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/D',$h))self::invalid();
        $out=[];foreach($rows as $r){if(count($r)!==count($header))self::invalid();$out[]=(object)array_combine($header,$r);}return $out;
    }
    private function keyValue(string $s):object
    {
        $out=[];foreach(explode("\n",$s) as $line){$line=str_ends_with($line,"\r")?substr($line,0,-1):$line;if(preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$line))self::invalid();if($line===''||str_starts_with($line,'#'))continue;
            if(!preg_match('/^([A-Za-z_][A-Za-z0-9_.-]{0,63})=(.*)$/D',$line,$m)||isset($out[$m[1]])||count($out)>=64||strlen($m[2])>16384)self::invalid();$out[$m[1]]=$m[2];}
        if(!$out)self::invalid();return (object)$out;
    }
    /** Prometheus text samples, finite values only. No exemplars or OpenMetrics histograms. */
    private function prometheus(string $s):array
    {
        $out=[];$ended=false;foreach(explode("\n",$s) as $line){$line=trim($line," \t\r");if($line==='')continue;if($ended)self::invalid();
            if($line==='# EOF'){$ended=true;continue;}if(str_starts_with($line,'#'))continue;
            if(!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]{0,127})(?=\{|[ \t])/',$line,$m))self::invalid();$i=strlen($m[0]);$labels=[];
            if(($line[$i]??'')==='{'){$i++;$i+=strspn($line," \t",$i);if(($line[$i]??'')!=='}')while(true){
                if(!preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]{0,63})[ \t]*=[ \t]*"((?:[^"\\\\\x00-\x1f]|\\\\[n"\\\\])*)"[ \t]*/',$line,$l,0,$i)||isset($labels[$l[1]])||count($labels)>=16)self::invalid();
                $labels[$l[1]]=strtr($l[2],['\\n'=>"\n",'\\"'=>'"','\\\\'=>'\\']);$i+=strlen($l[0]);if(($line[$i]??'')!==',')break;$i++;$i+=strspn($line," \t",$i);if(($line[$i]??'')==='}')break;
            }if(($line[$i++]??'')!=='}')self::invalid();}
            if(!preg_match('/\G[ \t]+([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)(?:[ \t]+([0-9]{1,13}))?$/D',$line,$v,0,$i)||count($out)>=self::MAX_ROWS)self::invalid();
            $r=['metric'=>$m[1],'value'=>$v[1],'labels'=>(object)$labels];if(isset($v[2])){$ms=(int)$v[2];$r['observed_at']=gmdate('Y-m-d\TH:i:s',intdiv($ms,1000)).sprintf('.%03dZ',$ms%1000);}$out[]=(object)$r;
        }return $out;
    }
}
