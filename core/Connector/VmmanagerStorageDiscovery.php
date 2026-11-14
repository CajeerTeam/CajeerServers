<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Read-only, allowlisted VMmanager storage and template catalog projections. */
final class VmmanagerStorageDiscovery
{
    private static function invalid():never {throw new ConnectorTransportException('connector_protocol_error');}
    private static function text(mixed $v,int $max=255):?string
    {return is_string($v)&&trim($v)!==''&&strlen($v)<=$max&&preg_match('//u',$v)&&!preg_match('/[\x00-\x1f\x7f]/',$v)?trim($v):null;}
    private static function number(mixed $v):?string
    {return (is_int($v)&&$v>=0)||(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,19})$/D',$v))?(string)$v:null;}
    private static function ref(mixed $v):?int
    {if($v instanceof \stdClass)$v=$v->id??null;return $v===null?null:VmmanagerApiClient::id($v);}
    /** Unknown is not an empty association. IDs are only resolved by exact remote identity. */
    private static function refs(mixed $v):?array
    {
        if($v===null)return null;
        if($v instanceof \stdClass)$v=$v->list??[$v];
        if(!is_array($v))$v=[$v];
        if(!array_is_list($v)||count($v)>2000)self::invalid();
        $ids=[];foreach($v as $r){$id=self::ref($r);if($id===null)self::invalid();$ids[$id]=$id;}
        sort($ids,SORT_NUMERIC);return array_values($ids);
    }
    private static function capacity(mixed $v):?array
    {
        if(!$v instanceof \stdClass)return null;
        $total=self::number($v->total??null);$used=self::number($v->used??null);$free=self::number($v->free??null);
        // Do not subtract allocated guest disk sizes from physical capacity. Preserve vendor values.
        return $total===null&&$used===null&&$free===null?null:['total'=>$total,'used'=>$used,'free'=>$free];
    }
    private static function unique(array $rows):array
    {
        $out=[];foreach($rows as $row){if(!$row instanceof \stdClass)self::invalid();$id=VmmanagerApiClient::id($row->id??null);if(isset($out[$id]))self::invalid();$out[$id]=$row;}
        ksort($out,SORT_NUMERIC);return $out;
    }
    private static function finish(array $snapshot):array
    {
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>750000)self::invalid();
        return $snapshot;
    }
    /** Node capacities and guest allocations have different semantics; do not aggregate shared stores. */
    public static function storage(array $storages,array $nodes,array $guests,bool $full,array $sections):array
    {
        $stores=[];$nodeRows=[];$volumes=[];$unknown=0;$unlinked=0;
        foreach(self::unique($storages) as $id=>$r){
            $stores[$id]=['id'=>$id,'name'=>self::text($r->name??null)??'Storage '.$id,
                'type'=>self::text($r->type??$r->storage_type??null,64),'state'=>self::text($r->state??null,64),
                'shared'=>is_bool($r->shared??null)?$r->shared:null,
                'cluster_ids'=>self::refs($r->clusters??$r->cluster??null),'node_ids'=>self::refs($r->nodes??$r->node??null),
                'capacity_mib'=>self::capacity($r->storage_mib??null),'visible_disks'=>0];
        }
        foreach(self::unique($nodes) as $id=>$r){
            $nodeRows[]=['id'=>$id,'name'=>self::text($r->name??null)??'Node '.$id,'cluster_id'=>self::ref($r->cluster??null),
                'capacity_mib'=>self::capacity($r->storage_mib??null),'storage_ids'=>self::refs($r->storages??$r->storage??null)];
        }
        foreach($guests as $g){
            if($g['disks']===null){$unknown++;continue;}
            foreach($g['disks'] as $disk){
                $sid=$disk['storage_id'];$linked=$sid!==null&&isset($stores[$sid]);
                if($linked)$stores[$sid]['visible_disks']++;else $unlinked++;
                $volumes[]=['key'=>'host:'.$g['id'].':disk:'.$disk['id'],'guest_id'=>$g['id'],'guest_name'=>$g['name'],
                    'node_id'=>$g['node_id'],'cluster_id'=>$g['cluster_id'],'storage_visible'=>$linked]+$disk;
                if(count($volumes)>4000)self::invalid();
            }
        }
        return self::finish(['schema_version'=>1,'complete'=>$full&&$unknown===0&&$unlinked===0&&!in_array('denied',$sections,true)&&!in_array('not_found',$sections,true),
            'visibility'=>$full?'administrator':'restricted','sections'=>$sections,'counts'=>['storages'=>count($stores),'nodes'=>count($nodeRows),'volumes'=>count($volumes)],
            'unknown_guest_disks'=>$unknown,'unlinked_volumes'=>$unlinked,'storages'=>array_values($stores),'nodes'=>$nodeRows,'volumes'=>$volumes]);
    }
    /** OS templates, VM configurations (presets), VM disk images and ISO catalogs remain distinct. */
    public static function templates(array $catalogs,array $guests,bool $full,array $sections):array
    {
        $out=[];$counts=[];$knownOs=[];
        foreach(['os','presets','images','isos'] as $kind){
            $rows=[];
            foreach(self::unique($catalogs[$kind]??[]) as $id=>$r){
                $name=self::text($r->name??null)??self::text($r->title??null)??ucfirst($kind).' '.$id;
                $rows[]=['id'=>$id,'name'=>$name,'state'=>self::text($r->state??null,64),'type'=>self::text($r->type??$r->virtualization_type??null,64),
                    'version'=>self::text($r->version??null,128),'architecture'=>self::text($r->architecture??$r->arch??null,64),
                    'os_family'=>self::text($r->family??$r->os_family??null,64),'cluster_ids'=>self::refs($r->clusters??$r->cluster??null),
                    'node_ids'=>self::refs($r->nodes??$r->node??null),'repository_id'=>self::ref($r->repository??null),
                    'os_id'=>self::ref($r->os??null),'size_mib'=>self::number($r->size_mib??null),'cpu_number'=>self::number($r->cpu_number??null),
                    'ram_mib'=>self::number($r->ram_mib??null),'hdd_mib'=>self::number($r->hdd_mib??null),
                    'enabled'=>is_bool($r->enabled??null)?$r->enabled:null];
                if($kind==='os')$knownOs[$id]=true;
            }
            $out[$kind]=$rows;$counts[$kind]=count($rows);
        }
        $links=[];$unknown=0;
        foreach($guests as $g){
            if($g['os_id']===null&&$g['os_name']===null){$unknown++;continue;}
            $links[]=['guest_id'=>$g['id'],'guest_name'=>$g['name'],'node_id'=>$g['node_id'],'cluster_id'=>$g['cluster_id'],'os_id'=>$g['os_id'],'os_name'=>$g['os_name'],'os_visible'=>$g['os_id']!==null&&isset($knownOs[$g['os_id']])];
        }
        $counts['guest_os_links']=count($links);
        return self::finish(['schema_version'=>1,'complete'=>$full&&!in_array('denied',$sections,true)&&!in_array('not_found',$sections,true),
            'visibility'=>$full?'administrator':'restricted','sections'=>$sections,'counts'=>$counts,'unknown_guest_os'=>$unknown,'guest_os_links'=>$links]+$out);
    }
}
