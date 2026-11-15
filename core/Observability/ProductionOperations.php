<?php
declare(strict_types=1);
namespace Cajeer\Servers\Observability;

use Cajeer\Servers\Backup\BackupStatus;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Throwable;

/** Aggregated platform health; never expose filesystem paths, connection details or tenant identifiers. */
final class ProductionOperations
{
    public function __construct(private readonly OperationalStats $stats,private readonly ConfigRepository $config,private readonly string $root) {}

    public function snapshot(?array $defaultQueue=null,?array $scheduler=null): array
    {
        $result=['checked_at'=>gmdate(DATE_ATOM),'queues'=>[],'scheduler'=>null,'disk'=>null,'backup'=>null];
        foreach (['default','connectors'] as $queue) {
            try { $row=$queue==='default' && $defaultQueue!==null ? ($defaultQueue['stats']??throw new \RuntimeException('queue unavailable')) : $this->stats->queue($queue);$result['queues'][$queue]=[
                'pending'=>(int)($row['ready']??$row['queued']??0), 'running'=>(int)$row['running'],
                'failed'=>(int)$row['failed'], 'expired_leases'=>(int)$row['expired_leases']]; }
            catch (Throwable) { $result['queues'][$queue]=null; }
        }
        try { $row=$scheduler!==null ? ($scheduler['stats']??throw new \RuntimeException('scheduler unavailable')) : $this->stats->scheduler();$result['scheduler']=['due'=>$row['tasks']['due'],'invalid'=>$row['tasks']['invalid'],'lag_seconds'=>$row['tasks']['lag_seconds'],'outbox_pending'=>$row['outbox']['pending'],'outbox_retrying'=>$row['outbox']['retrying']]; }
        catch (Throwable) { /* null means unavailable, never zero */ }
        try {
            $path=$this->config->get('storage.default')==='local' ? ((string)$this->config->get('storage.local.root','') ?: $this->root.'/storage/files') : $this->root.'/storage';
            if (is_dir($path)) {
                $free=@disk_free_space($path);$total=@disk_total_space($path);
                if ($free!==false && $total!==false && $total>0) $result['disk']=['free_bytes'=>(int)$free,'total_bytes'=>(int)$total,'free_ratio'=>$free/$total];
            }
        } catch (Throwable) {}
        try { $result['backup']=(new BackupStatus($this->root.'/storage/app/backup-status.json'))->read(); }
        catch (Throwable) {}
        $result['backup_expected']=EnvironmentLoader::getString('BACKUP_DIRECTORY')!=='';
        $result['offsite_expected']=EnvironmentLoader::getString('BACKUP_OFFSITE_DIRECTORY')!=='';
        return $result;
    }

    public static function metrics(array $data): string
    {
        $out=[];$types=[];
        $gauge=static function(string $name,int|float $value,string $labels='') use (&$out,&$types):void {
            if (!isset($types[$name])) {$out[]='# TYPE '.$name.' gauge';$types[$name]=true;}
            $out[]=$name.$labels.' '.(is_int($value)?(string)$value:sprintf('%.9F',$value));
        };
        foreach (['default','connectors'] as $queue) {
            $row=$data['queues'][$queue]??null;$label='{queue="'.$queue.'"}';
            $gauge('cajeer_ops_queue_up',$row===null?0:1,$label);
            if ($row!==null) foreach (['pending','running','failed','expired_leases'] as $field) $gauge('cajeer_ops_queue_'.$field,$row[$field],$label);
        }
        $row=$data['scheduler'];$gauge('cajeer_ops_scheduler_up',$row===null?0:1);
        if ($row!==null) foreach (['due','invalid','lag_seconds','outbox_pending','outbox_retrying'] as $field) $gauge('cajeer_ops_scheduler_'.$field,$row[$field]);
        $row=$data['disk'];$gauge('cajeer_ops_disk_up',$row===null?0:1);
        if ($row!==null) { $gauge('cajeer_ops_disk_free_bytes',$row['free_bytes']);$gauge('cajeer_ops_disk_free_ratio',$row['free_ratio']); }
        $row=$data['backup'];$gauge('cajeer_ops_backup_status_up',$row===null?0:1);
        $gauge('cajeer_ops_backup_expected',$data['backup_expected']?1:0);
        $gauge('cajeer_ops_offsite_expected',$data['offsite_expected']?1:0);
        if ($row!==null) {
            $gauge('cajeer_ops_backup_last_success_timestamp_seconds',$row['last_success_at']);
            $gauge('cajeer_ops_backup_last_offsite_timestamp_seconds',$row['last_offsite_at']);
            $gauge('cajeer_ops_backup_started_timestamp_seconds',$row['started_at']);
            $gauge('cajeer_ops_backup_bytes',$row['bytes']);
            $gauge('cajeer_ops_backup_failed',$row['status']==='failed'?1:0);
            $gauge('cajeer_ops_backup_running',$row['status']==='running'?1:0);
        }
        return implode("\n",$out)."\n";
    }
}
