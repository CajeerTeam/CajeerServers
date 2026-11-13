<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Bounded, deterministic inventory of the authenticated PBS token's visible scope. */
final class PbsDiscovery
{
    private const LIMIT=2500;
    private const MAX_BYTES=700000;
    private const MAX_REQUESTS=100;
    private static function invalid():never {throw new ConnectorTransportException('connector_protocol_error');}
    public static function rows(mixed $data):array
    {
        if(!is_array($data)||!array_is_list($data)||count($data)>50000)self::invalid();
        foreach($data as $row)if(!$row instanceof \stdClass)self::invalid();
        return $data;
    }
    private static function id(mixed $v):string
    {if(!is_string($v)||!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/D',$v))self::invalid();return $v;}
    private static function ns(mixed $v):string
    {if(!is_string($v)||strlen($v)>263||($v!==''&&!preg_match('~^[A-Za-z0-9_][A-Za-z0-9._-]{0,31}(?:/[A-Za-z0-9_][A-Za-z0-9._-]{0,31}){0,6}$~D',$v)))self::invalid();return $v;}
    private static function number(mixed $v):?string
    {if($v===null)return null;if(is_int($v)&&$v>=0)$v=(string)$v;if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,19})$/D',$v))self::invalid();return $v;}
    private static function time(mixed $v):?int
    {if($v===null)return null;if(!is_int($v)||$v<0||$v>253402300799)self::invalid();return $v;}
    private static function text(mixed $v,int $max=256):?string
    {return is_string($v)&&strlen($v)<=$max&&preg_match('//u',$v)&&!preg_match('/[\x00-\x1f\x7f]/',$v)?$v:null;}
    private static function boolean(mixed $v):?bool
    {return match($v){true,1,'1'=>true,false,0,'0'=>false,default=>null};}
    private static function identity(\stdClass $r):array
    { $type=$r->{'backup-type'}??null;if(!in_array($type,['vm','ct','host'],true))self::invalid();
      // Host backup IDs may contain dots and hyphens; never used as URL segments.
      $id=self::id($r->{'backup-id'}??null);return ['backup_type'=>$type,'backup_id'=>$id]; }
    private static function job(string $kind,\stdClass $r,?string $store=null):array
    {
        $store=self::id($store??$r->store??null);
        $id=$kind==='gc'?$store:self::id($r->id??null);
        $status=self::text($r->{'last-run-state'}??null,128);
        // Error strings can contain file names or secrets. Expose only a finite outcome.
        $outcome=$status===null?null:match(true){$status==='OK'=>'ok',str_starts_with($status,'WARNINGS')=>'warning',strtolower($status)==='running'=>'running',in_array(strtolower($status),['unknown','never','created'],true)=>'unknown',default=>'error'};
        return ['kind'=>$kind,'id'=>$id,'store'=>$store,'namespace'=>self::ns($r->ns??''),'schedule'=>self::text($r->schedule??null),
            'disabled'=>self::boolean($r->disable??null),'last_run'=>self::time($r->{'last-run-endtime'}??null),'next_run'=>self::time($r->{'next-run'}??null),
            'last_outcome'=>$outcome,'upid'=>self::text($r->{'last-run-upid'}??($kind==='gc'?($r->upid??null):null),512),'remote'=>self::text($r->remote??null,128),
            'remote_store'=>self::text($r->{'remote-store'}??null,128),'direction'=>self::text($r->{'sync-direction'}??null,16),
            'gc_removed_bytes'=>$kind==='gc'?self::number($r->{'removed-bytes'}??null):null,'gc_pending_bytes'=>$kind==='gc'?self::number($r->{'pending-bytes'}??null):null,
            'keep_hourly'=>self::number($r->{'keep-hourly'}??null),'keep_last'=>self::number($r->{'keep-last'}??null),'keep_daily'=>self::number($r->{'keep-daily'}??null),'keep_weekly'=>self::number($r->{'keep-weekly'}??null),'keep_monthly'=>self::number($r->{'keep-monthly'}??null),'keep_yearly'=>self::number($r->{'keep-yearly'}??null)];
    }
    public static function collect(callable $get,array $stores,string $version):array
    {
        $out=['version'=>$version,'complete'=>true,'scope'=>'token_visible','datastores'=>[],'namespaces'=>[],'groups'=>[],'snapshots'=>[],'jobs'=>[],'issues'=>[]];
        $bytes=0;$count=0;$requests=0;$deadline=hrtime(true)+30_000_000_000;$seen=[];$limited=false;
        $issue=function(string $section,string $reason,?string $store=null,?string $ns=null)use(&$out):void {
            $out['complete']=false;if(count($out['issues'])<200)$out['issues'][]=['section'=>$section,'reason'=>$reason,'store'=>$store,'namespace'=>$ns];
        };
        $append=function(string $section,array $row,array $identity)use(&$out,&$bytes,&$count,&$seen,&$limited,$issue):bool{
            $key=$section.':'.json_encode($identity,JSON_THROW_ON_ERROR);if(isset($seen[$key]))self::invalid();
            $size=strlen(json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))+32;
            if($count>=self::LIMIT||$bytes+$size>self::MAX_BYTES){if(!$limited)$issue('inventory','result_limit');$limited=true;return false;}
            $seen[$key]=true;$bytes+=$size;$count++;$out[$section][]=$row;return true;
        };
        $read=function(string $path,string $section,?string $store=null,?string $ns=null)use($get,&$requests,$deadline,$issue,&$limited):mixed{
            if($limited||$requests>=self::MAX_REQUESTS||hrtime(true)>=$deadline){if(!$limited)$issue('inventory','request_limit');$limited=true;return null;}
            $requests++;
            try{$data=$get($path);if($data===null)self::invalid();return $data;}catch(ConnectorTransportException $e){
                // Authentication expiry, bad TLS, malformed responses and transport errors fail the run.
                if(!in_array($e->httpStatus,[403,404],true))throw $e;
                $issue($section,$e->httpStatus===403?'permission_denied':'not_available',$store,$ns);return null;
            }
        };
        // Discover job state even when a datastore contains many backups.
        foreach(['sync','verify','prune'] as $kind){
            $data=$read('/admin/'.$kind,'jobs:'.$kind);if($data===null)continue;
            foreach(self::rows($data) as $r){$job=self::job($kind,$r);if(!$append('jobs',$job,[$kind,$job['id']]))break;}
        }
        $names=[];foreach($stores as $r){$store=self::id($r->store??null);if(isset($names[$store]))self::invalid();$names[$store]=$store;}
        ksort($names,SORT_STRING);
        foreach(array_values($names) as $store){
            if($limited)break;
            $base='/admin/datastore/'.rawurlencode($store);
            $status=$read($base.'/status','capacity',$store);
            if($status!==null&&(!$status instanceof \stdClass||!isset($status->total,$status->used,$status->avail)))self::invalid();
            $row=['store'=>$store,'total'=>self::number($status->total??null),'used'=>self::number($status->used??null),'available'=>self::number($status->avail??null),'capacity_visible'=>$status!==null];
            if(!$append('datastores',$row,[$store]))break;
            $gc=$read($base.'/gc','jobs:gc',$store);
            if($gc!==null){if(!$gc instanceof \stdClass)self::invalid();$job=self::job('gc',$gc,$store);if(!$append('jobs',$job,['gc',$store]))break;}
            $list=$read($base.'/namespace?max-depth=7','namespaces',$store);
            $namespaces=[''=>''];
            if($list!==null)foreach(self::rows($list) as $r){$ns=self::ns($r->ns??null);$namespaces[$ns]=$ns;}
            ksort($namespaces,SORT_STRING);
            foreach(array_values($namespaces) as $ns){
                if($limited)break;
                if(!$append('namespaces',['store'=>$store,'namespace'=>$ns],[$store,$ns]))break;
                $query=$ns===''?'':'?ns='.rawurlencode($ns);
                $groups=$read($base.'/groups'.$query,'groups',$store,$ns);
                if($groups!==null)foreach(self::rows($groups) as $r){
                    $id=self::identity($r);$row=['store'=>$store,'namespace'=>$ns]+$id+['last_backup'=>self::time($r->{'last-backup'}??null),'backup_count'=>self::number($r->{'backup-count'}??null)];
                    if(!$append('groups',$row,[$store,$ns,$id['backup_type'],$id['backup_id']]))break;
                }
                $snapshots=$read($base.'/snapshots'.$query,'snapshots',$store,$ns);
                if($snapshots!==null)foreach(self::rows($snapshots) as $r){
                    $id=self::identity($r);$time=self::time($r->{'backup-time'}??null);if($time===null)self::invalid();
                    $verify=$r->verification??null;$verification=$verify instanceof \stdClass?($verify->state??null):null;
                    $row=['store'=>$store,'namespace'=>$ns]+$id+['backup_time'=>$time,'size'=>self::number($r->size??null),'protected'=>self::boolean($r->protected??null),
                        'verification'=>in_array($verification,['ok','failed'],true)?$verification:null];
                    if(!$append('snapshots',$row,[$store,$ns,$id['backup_type'],$id['backup_id'],$time]))break;
                }
            }
        }
        if($limited&&$out['complete'])$issue('inventory','result_limit');
        $out['counts']=array_map(fn($k)=>count($out[$k]),['datastores','namespaces','groups','snapshots','jobs']);
        $out['counts']=array_combine(['datastores','namespaces','groups','snapshots','jobs'],$out['counts']);
        return $out;
    }
}
