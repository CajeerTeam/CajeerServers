<?php
declare(strict_types=1);
namespace Cajeer\Servers\Backup;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Update\Maintenance;
use RuntimeException;
use Throwable;

/** Single-host full backup. The operator must exclude non-Cajeer writers. */
final class ManagedBackup
{
    public function __construct(private readonly Application $app) {}

    public function run(string $directory, ?string $offsite, string $keyFile, int $keep, int $drainSeconds): array
    {
        if ($keep<2 || $keep>365) throw new RuntimeException('Backup retention must keep 2..365 archives.');
        $root=realpath($this->app->basePath()) ?: throw new RuntimeException('Invalid application root.');
        $maintenance=new Maintenance($root);
        $state=new InstallState($root);
        $lockPath=$state->directory().'/backup-run.lock';
        if (is_link($lockPath)) throw new RuntimeException('Unsafe backup run lock.');
        $mask=umask(0077);try {$lock=fopen($lockPath,'c+b');} finally {umask($mask);}
        if (!is_resource($lock)) throw new RuntimeException('Cannot open backup run lock.');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another managed backup is running.');
            $status=new BackupStatus($state->directory().'/backup-status.json');
            $row=$status->read();$row['status']='running';$row['started_at']=time();$status->write($row);
            $service=new BackupService($this->app);
            try {
                $directory=$this->directory($directory);
                if ($offsite!==null) {
                    $offsite=$this->directory($offsite);
                    if ($this->overlap($directory,$offsite) || stat($directory)['dev']===stat($offsite)['dev']) throw new RuntimeException('Offsite must be a separate mounted filesystem, outside primary backup directory.');
                }
                $storage=(string)$this->app->config()->get('storage.local.root','');
                $storage=$storage!==''?$storage:$this->app->basePath('storage/files');
                $storage=realpath($storage)?:$storage;
                foreach (array_filter([$directory,$offsite]) as $dir) {
                    if ($this->overlap($dir,$root) || $this->overlap($dir,$storage) || $this->overlap($dir,$keyFile)) throw new RuntimeException('Managed backup directories must be outside application, storage and backup key path.');
                }
                // Hold application-wide admission only for the consistent snapshot. External copy
                // and retention run after reopening, under the independent backup-run lock.
                $created=$maintenance->drainExclusive(function () use ($directory,$keyFile,$maintenance,$state,$service): array {
                    if ($maintenance->active() || $maintenance->requiresUpgrade()) throw new RuntimeException('Backup refused during maintenance or incomplete upgrade.');
                    $marker=$state->record();
                    if ($marker===null) throw new RuntimeException('Managed backup requires an installed system.');
                    $db=$this->app->container()->get(DatabaseManager::class);
                    $identity=$db->connection()->query('SELECT installation_id::text FROM cajeer_installation WHERE singleton_id=1')->fetchColumn();
                    if (!is_string($identity) || !hash_equals($marker['installation_id'],$identity)) throw new RuntimeException('Backup installation identity mismatch.');
                    $migration=$this->app->container()->get(\Cajeer\Servers\Database\MigrationRunner::class)->validate();
                    if (!$migration['valid']||in_array('pending',array_column($migration['rows'],'status'),true)) throw new RuntimeException('Backup requires fully applied migrations.');
                    // Do not report a usable full backup if essential recovery keys are absent.
                    (new \Cajeer\Servers\Security\SecretBox())->seal('backup-key-check','backup');
                    if ((int)$db->connection()->query('SELECT count(*) FROM vault_key_state')->fetchColumn()>0) $this->app->container()->get(\Cajeer\Servers\Credentials\CredentialsVault::class)->check();
                    $prefix='cajeer-'.$identity.'-';
                    $name=$prefix.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8)).'.cjdr';
                    $path=$directory.'/'.$name;
                    return $service->create($path,$keyFile,false,null,null,$identity)+['identity'=>$identity,'name'=>$name,'prefix'=>$prefix];
                },$drainSeconds);
                $path=$created['archive'];$identity=$created['identity'];$name=$created['name'];
                $verified=$service->verify($path,$keyFile);
                $this->checkHeader($verified,$identity,$created['database']);
                if ($offsite!==null) {
                    clearstatcache(true,$offsite);
                    if (stat($directory)['dev']===stat($offsite)['dev']) throw new RuntimeException('Offsite mount is no longer available.');
                    $this->copyVerified($service,$path,$offsite.'/'.$name,$keyFile,$identity,$created['database']);
                    $row['last_offsite_at']=time();
                }
                $removed=$this->prune($service,$directory,$created['prefix'],$name,$keyFile,$identity,$created['database'],$keep);
                $row['status']='success';$row['last_success_at']=time();$row['bytes']=(int)filesize($path);$status->write($row);
                return ['created'=>true,'archive'=>$name,'offsite_verified'=>$offsite!==null,'retention_keep'=>$keep,'removed'=>$removed,'bytes'=>$row['bytes']];
            } catch (Throwable $error) {
                $row['status']='failed';$status->write($row);throw $error;
            }
        } finally { fclose($lock); }
    }

    private function directory(string $path): string
    {
        if ($path==='' || $path[0]!=='/' || realpath($path)!==$path || !is_dir($path) || !is_writable($path)) throw new RuntimeException('Backup directory must be existing canonical writable directory.');
        $stat=stat($path);
        if (($stat['mode']&0077)!==0 || (function_exists('posix_geteuid') && $stat['uid']!==posix_geteuid())) throw new RuntimeException('Backup directory must be private and owned by service user.');
        return $path;
    }
    private function overlap(string $a,string $b): bool
    { return $a===$b || str_starts_with($a,rtrim($b,'/').'/') || str_starts_with($b,rtrim($a,'/').'/'); }

    private function checkHeader(array $report,string $identity,string $database): void
    {
        $h=$report['header']??[];
        if (($report['verified']??false)!==true || ($h['installation_id']??null)!==$identity || ($h['database']??null)!==$database || ($h['mode']??null)!=='full' || ($h['consistent_storage']??false)!==true) throw new RuntimeException('Managed backup identity or mode mismatch.');
    }

    /** Copy ciphertext only; authenticate destination before exclusive publication. */
    public function copyVerified(BackupService $service,string $source,string $target,string $key,string $identity,string $database): void
    {
        $dir=$this->directory(dirname($target));$device=stat($dir)['dev'];
        if (file_exists($target)||is_link($target)) throw new RuntimeException('Offsite target already exists.');
        $tmp=tempnam($dir,'.cajeer-copy-');$in=null;$out=null;
        if ($tmp===false) throw new RuntimeException('Cannot allocate offsite copy.');
        try {
            if (!chmod($tmp,0600) || !is_resource($in=fopen($source,'rb')) || !is_resource($out=fopen($tmp,'wb'))) throw new RuntimeException('Cannot open offsite copy streams.');
            $hash=hash_init('sha256');
            while (!feof($in)) {
                $chunk=fread($in,EncryptedArchive::CHUNK);
                if ($chunk===false) throw new RuntimeException('Backup copy read failed.');
                if ($chunk==='') continue;
                hash_update($hash,$chunk);EncryptedArchive::writeAll($out,$chunk);
            }
            if (!fflush($out)||!fsync($out)) throw new RuntimeException('Offsite copy sync failed.');
            fclose($out);$out=null;
            if (!hash_equals(hash_final($hash),(string)hash_file('sha256',$tmp))) throw new RuntimeException('Offsite copy checksum mismatch.');
            $this->checkHeader($service->verify($tmp,$key),$identity,$database);
            clearstatcache(true,$dir);
            if (stat($dir)['dev']!==$device || !link($tmp,$target)) throw new RuntimeException('Cannot publish offsite backup without overwrite.');
            unlink($tmp);BackupService::syncDirectory($dir);
        } finally { if(is_resource($in)) fclose($in);if(is_resource($out)) fclose($out);if(is_file($tmp)) unlink($tmp); }
    }

    private function prune(BackupService $service,string $dir,string $prefix,string $current,string $key,string $identity,string $database,int $keep): int
    {
        $names=[];
        foreach (new \DirectoryIterator($dir) as $entry) {
            $name=$entry->getFilename();
            if ($entry->isFile()&&!$entry->isLink()&&preg_match('/^'.preg_quote($prefix,'/').'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\.cjdr$/D',$name)) $names[]=$name;
        }
        rsort($names,SORT_STRING);
        // Verify retained archives as well as deletion candidates: a corrupt file cannot count
        // toward the minimum number of usable restore points. Always preserve this run.
        $retained=1;$removed=0;
        foreach ($names as $name) {
            if ($name===$current) continue;
            $path=$dir.'/'.$name;
            try { $this->checkHeader($service->verify($path,$key),$identity,$database); }
            catch (Throwable) { continue; }
            if ($retained<$keep) { $retained++;continue; }
            if ($removed>=20) break; // bound deletion work per run
            if (!unlink($path)) throw new RuntimeException('Managed backup retention failed.');
            $removed++;
        }
        if ($removed>0) BackupService::syncDirectory($dir);
        return $removed;
    }
}
