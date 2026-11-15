<?php
declare(strict_types=1);
namespace Cajeer\Servers\Backup;

use RuntimeException;

/** Durable, non-secret operational status. No paths, keys, filenames or exception text. */
final class BackupStatus
{
    public function __construct(private readonly string $path) {}

    public function read(): array
    {
        clearstatcache(true, $this->path);
        if (is_link($this->path)) throw new RuntimeException('Unsafe backup status file.');
        if (!file_exists($this->path)) return ['status'=>'never','started_at'=>0,'last_success_at'=>0,'last_offsite_at'=>0,'bytes'=>0];
        if (!is_file($this->path) || filesize($this->path)>4096) throw new RuntimeException('Invalid backup status file.');
        $raw=file_get_contents($this->path);
        $row=json_decode((string)$raw,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($row) || !in_array($row['status']??null,['running','success','failed'],true)) throw new RuntimeException('Invalid backup status.');
        $result=['status'=>$row['status']];
        foreach (['started_at','last_success_at','last_offsite_at','bytes'] as $name) {
            if (!is_int($row[$name]??null) || $row[$name]<0) throw new RuntimeException('Invalid backup status value.');
            $result[$name]=$row[$name];
        }
        return $result;
    }

    public function write(array $row): void
    {
        if (is_link($this->path)) throw new RuntimeException('Unsafe backup status file.');
        $dir=dirname($this->path);
        $tmp=tempnam($dir,'.backup-status-');
        if ($tmp===false) throw new RuntimeException('Cannot allocate backup status.');
        $fd=null;
        try {
            if (!chmod($tmp,0600) || !is_resource($fd=fopen($tmp,'wb'))) throw new RuntimeException('Cannot secure backup status.');
            EncryptedArchive::writeAll($fd,json_encode($row,JSON_THROW_ON_ERROR)."\n");
            if (!fflush($fd) || !fsync($fd)) throw new RuntimeException('Cannot sync backup status.');
            fclose($fd);$fd=null;
            if (!rename($tmp,$this->path)) throw new RuntimeException('Cannot publish backup status.');
            BackupService::syncDirectory($dir);
        } finally { if(is_resource($fd)) fclose($fd); if(is_file($tmp)) unlink($tmp); }
    }
}
