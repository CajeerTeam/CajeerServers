<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;
use Cajeer\Servers\Http\Exception\HttpException;

final class BuiltinExporter implements ExporterInterface
{
    public const MAX_BYTES=4194304;
    public function __construct(private readonly string $format){if(!in_array($format,['json','csv','openmetrics'],true))throw new \InvalidArgumentException('Unknown exporter.');}
    public function name():string{return $this->format;}
    public function supports(string $kind):bool{return in_array($kind,['metrics','events','observations','runs'],true)&&($this->format!=='openmetrics'||$kind==='metrics');}
    private static function append(string &$out,string $part):void{if(strlen($out)+strlen($part)>self::MAX_BYTES)throw new HttpException(422,'Export exceeds 4 MiB; narrow range.','export_limit');$out.=$part;}
    private static function json(mixed $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function cell(mixed $v,string $key):string
    {
        if($v===null)return '';
        $v=is_array($v)||is_object($v)?self::json($v):(string)$v;
        // CSV formulas are executable in spreadsheet applications. Only validated numeric value cells are exempt.
        if(!($key==='value'&&preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D',$v))&&preg_match('/^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u',$v))$v="'".$v;
        return '"'.str_replace('"','""',$v).'"';
    }
    private static function label(string $s):string{return '"'.str_replace(["\\","\n",'"'],["\\\\","\\n",'\\"'],$s).'"';}
    public function encode(string $kind,iterable $records):string
    {
        if(!$this->supports($kind))throw new HttpException(422,'Unsupported export kind.','validation_failed');
        $out='';$n=0;
        if($this->format==='json'){
            $out='{"schema_version":1,"kind":'.self::json($kind).',"records":[';
            foreach($records as $r){self::append($out,($n++?',':'').self::json($r));}
            self::append($out,']}');return $out;
        }
        if($this->format==='csv'){
            $columns=match($kind){'metrics'=>['series_id','metric','kind','unit','resource_external_id','labels','observed_at','value','asset_id','target_version','ingestion_source_id'],'events'=>['source_id','event_id','type','severity','message','observed_at'],'observations'=>['batch_id','source_id','external_id','type','fields','observed_at'],'runs'=>['id','collector_name','created_at','finished_at','error_code','result']};
            $out=implode(',',$columns)."\r\n";
            foreach($records as $r){$row=[];foreach($columns as $k)$row[]=self::cell($r[$k]??null,$k);self::append($out,implode(',',$row)."\r\n");}return $out;
        }
        // Generic metric families avoid invalid names and normalization collisions. Exact source name and labels remain available.
        $out='';$previous=null;
        foreach($records as $r){
            if($r['kind']!==$previous){$previous=$r['kind'];self::append($out,"# TYPE cajeer_infrastructure_".$previous." ".$previous."\n");}
            $metric=$r['kind']==='counter'?'cajeer_infrastructure_counter_total':'cajeer_infrastructure_gauge';
            $labels=['series_id'=>$r['series_id'],'metric'=>$r['metric'],'resource'=>$r['resource_external_id'],'labels_json'=>self::json($r['labels']),'unit'=>$r['unit']??''];
            $parts=[];foreach($labels as $k=>$v)$parts[]=$k.'='.self::label((string)$v);
            $value=(string)$r['value'];if(!preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D',$value))throw new HttpException(422,'Invalid metric value.','metric_invalid');
            $at=(new \DateTimeImmutable($r['observed_at']))->format('U.u');
            self::append($out,$metric.'{'.implode(',',$parts).'} '.$value.' '.$at."\n");
        }self::append($out,"# EOF\n");return $out;
    }
}
