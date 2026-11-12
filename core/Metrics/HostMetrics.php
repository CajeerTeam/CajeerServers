<?php
declare(strict_types=1);
namespace Cajeer\Servers\Metrics;
/** Validate the fixed host protocol separately from signed inventory templates. */
final class HostMetrics
{
    public static function normalize(array $data,string $provider):array
    {
        if(!in_array($provider,['ssh','winrm'],true)||count($data)!==7||array_diff(['identity','cpu_ratio','memory_total','memory_available','disk_total','disk_available','network'],array_keys($data)))MetricBatch::invalid();
        $id=strtolower($data['identity']??'');if(!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/D',$id)||trim(str_replace('-','',$id),'0')==='')MetricBatch::invalid();
        $resource='host:'.hash('sha256',$provider.':'.str_replace('-','',$id));$out=[];
        foreach(['cpu_ratio'=>['cpu.utilization','ratio'],'memory_total'=>['memory.total','bytes'],'memory_available'=>['memory.available','bytes'],'disk_total'=>['disk.total','bytes'],'disk_available'=>['disk.available','bytes']] as $key=>[$metric,$unit]){
            $v=$data[$key];if(!is_int($v)&&!is_float($v)||!is_finite((float)$v)||$v<0||($key==='cpu_ratio'&&$v>1))MetricBatch::invalid();$out[]=MetricBatch::sample('system.'.$metric,$v,$resource,[],'gauge',$unit);
        }
        if($data['memory_available']>$data['memory_total']||$data['disk_available']>$data['disk_total']||!is_array($data['network'])||!array_is_list($data['network'])||count($data['network'])>64)MetricBatch::invalid();
        foreach($data['network'] as $r){if(!is_array($r)||count($r)!==3||!is_string($r['name']??null)||$r['name']==='')MetricBatch::invalid();foreach(['rx'=>'receive','tx'=>'transmit'] as $k=>$name){$v=$r[$k]??null;if(!is_string($v)||!preg_match('/^[0-9]{1,20}$/D',$v)||(strlen($v)===20&&strcmp($v,'18446744073709551615')>0))MetricBatch::invalid();$out[]=MetricBatch::sample('system.network.'.$name,$v,$resource,['interface'=>$r['name']],'counter','bytes');}}
        return MetricBatch::normalize($out);
    }
}
