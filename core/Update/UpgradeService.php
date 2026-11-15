<?php
declare(strict_types=1);

namespace Cajeer\Servers\Update;

use Cajeer\Servers\Backup\BackupService;
use Cajeer\Servers\Backup\PostgresStream;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Inventory\CmdbIntegrity;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Observability\Diagnostics;
use Cajeer\Servers\Credentials\CredentialsVault;
use PDO;
use RuntimeException;

/** Offline, resumable schema upgrade. Code deployment and rollback remain operator-controlled. */
final class UpgradeService
{
    private Maintenance $maintenance;
    private InstallState $installation;
    public function __construct(private readonly Application $app)
    {
        $this->maintenance = new Maintenance($app->basePath());
        $this->installation = new InstallState($app->basePath());
    }

    public function status(): array { return ['maintenance'=>$this->maintenance->active(), 'requires_upgrade'=>$this->maintenance->requiresUpgrade(), 'upgrade'=>$this->maintenance->journal()]; }

    public function enable(): array { return $this->maintenance->exclusive(function () { $this->maintenance->enable(); return $this->status(); }, 10); }

    public function disable(): array
    {
        return $this->maintenance->exclusive(function () {
            $journal=$this->maintenance->journal();
            if ($journal!==null && $journal['phase']!=='complete') throw new RuntimeException('Incomplete upgrade; resume or recover offline.');
            if ($this->maintenance->requiresUpgrade()) throw new RuntimeException('Installation version differs from running code; apply the upgrade first.');
            $this->verify();
            $this->maintenance->disable();
            return $this->status();
        }, 10);
    }

    public function run(string $previous, string $archive, string $key): array
    {
        return $this->maintenance->exclusive(function () use ($previous,$archive,$key) {
            if (!$this->maintenance->active()) throw new RuntimeException('Enable maintenance before upgrading. Stop all old web/worker processes first.');
            $old = realpath($previous);
            if ($old === false || $old === realpath($this->app->basePath()) || !is_dir($old)) throw new RuntimeException('A separate retained source release directory is required.');
            $from = trim((string) file_get_contents($old . '/VERSION'));
            $to = $this->app->version();
            if ($from !== '0.9.8' || $to !== '0.9.9') throw new RuntimeException('Supported upgrade is 0.9.8 to 0.9.9.');
            if (!str_starts_with($archive,'/') || !str_starts_with($key,'/')) throw new RuntimeException('Backup and key paths must be absolute.');
            $db = $this->app->container()->get(DatabaseManager::class);
            if ($db->driver() !== 'pgsql') throw new RuntimeException('Upgrade requires PostgreSQL.');
            $pdo = $db->connection();
            // Same session lock as MigrationRunner, retained across backup, migration and verification.
            if (!in_array($pdo->query('SELECT pg_try_advisory_lock(1128352282,1397904979)')->fetchColumn(), [true,1,'1','t'], true)) throw new RuntimeException('Another migration/upgrade is running.');
            try {
                $identity = $this->identity($pdo);
                $runner = $this->app->container()->get(MigrationRunner::class);
                $validation = $runner->validate();
                if (!$validation['valid']) throw new RuntimeException('Migration history is inconsistent.');
                $oldFiles = $this->sourceFiles($old);
                $rows = array_column($validation['rows'], null, 'version');
                foreach ($oldFiles as $version=>$checksum) {
                    if (($rows[$version]['status'] ?? '') !== 'applied' || !hash_equals($checksum,$rows[$version]['checksum'])) throw new RuntimeException('Source release migrations are not fully applied: '.$version);
                }
                $fingerprint = hash('sha256',json_encode(array_column($validation['rows'],'checksum','version'),JSON_THROW_ON_ERROR));
                $expected = ['from'=>$from,'to'=>$to,'installation_id'=>$identity['id'],'database'=>$identity['database'],'source'=>$old,'archive'=>$archive,'schema_sha256'=>$fingerprint];
                $journal = $this->maintenance->journal();
                $prior = null;
                if ($journal !== null && $journal['phase']==='complete' && ($journal['to']??null)===$from
                    && ($journal['installation_id']??null)===$identity['id'] && ($journal['database']??null)===$identity['database']
                    && ($this->installation->record()['version']??null)===$from) {
                    $prior=$journal; $journal=null;
                }
                if ($journal === null) {
                    if (!$this->maintenance->requiresUpgrade()) throw new RuntimeException('Installation is already at the running release version.');
                    $markerVersion=$this->installation->record()['version'] ?? '';
                    if ($markerVersion !== $from) throw new RuntimeException('Installation marker is not compatible with the source release.');
                    if (file_exists($archive) || is_link($archive)) throw new RuntimeException('New upgrade backup path must not exist.');
                    foreach ($rows as $version=>$row) if ($row['status']==='applied' && !isset($oldFiles[$version])) throw new RuntimeException('Database is ahead of the source release.');
                    $journal = $expected + ['phase'=>'backup','upgrade_id'=>bin2hex(random_bytes(16)),'started_at'=>gmdate(DATE_ATOM)];
                    if ($prior!==null) $this->maintenance->archiveCompleted($prior);
                    $this->maintenance->save($journal);
                }
                foreach ($expected as $field=>$value) if (($journal[$field] ?? null) !== $value) throw new RuntimeException('Upgrade inputs differ from the persisted journal: '.$field);
                $backup = new BackupService($this->app);
                if ($journal['phase'] === 'backup') {
                    // If interrupted after publication, no migration has run yet; authenticate the published archive before resuming.
                    if (!file_exists($archive)) $backup->create($archive,$key,false,$from,$journal['upgrade_id']);
                    $report = $backup->verify($archive,$key);
                    $this->checkBackup($report,$journal);
                    $journal['backup_sha256'] = hash_file('sha256',$archive) ?: throw new RuntimeException('Cannot hash backup.');
                    $journal['phase']='migrate'; $this->maintenance->save($journal);
                } else {
                    if (!is_file($archive) || !hash_equals((string)($journal['backup_sha256'] ?? ''),(string)hash_file('sha256',$archive))) throw new RuntimeException('Upgrade backup missing or changed.');
                    $this->checkBackup($backup->verify($archive,$key),$journal);
                }
                if ($journal['phase'] === 'migrate') {
                    $runner->run(false,10,true);
                    $journal['phase']='verify'; $this->maintenance->save($journal);
                }
                $this->verify();
                $this->installation->finish($identity['id'],$identity['created']);
                $journal['phase']='complete'; $journal['completed_at']=gmdate(DATE_ATOM);
                $this->maintenance->save($journal);
                // Explicit maintenance:disable after operator checks deployed Admin and workers.
                return $this->status();
            } finally { $pdo->query('SELECT pg_advisory_unlock(1128352282,1397904979)'); }
        }, 10);
    }

    /** Restore to an empty replacement DB/storage; never overwrite the upgraded installation. */
    public function restore(string $key,string $target,string $confirmation,string $storage,string $recovery): array
    {
        return $this->maintenance->exclusive(function () use ($key,$target,$confirmation,$storage,$recovery) {
            if (!$this->maintenance->active()) throw new RuntimeException('Maintenance is required for recovery.');
            $journal=$this->maintenance->journal();
            if ($journal===null || !isset($journal['backup_sha256'])) throw new RuntimeException('No verified upgrade backup exists.');
            if ($target===$journal['database']) throw new RuntimeException('Recovery must use a new, empty database.');
            $archive=$journal['archive'];
            if (!is_file($archive) || !hash_equals($journal['backup_sha256'],(string)hash_file('sha256',$archive))) throw new RuntimeException('Upgrade backup missing or changed.');
            $backup=new BackupService($this->app);
            $this->checkBackup($backup->verify($archive,$key),$journal);
            $result=$backup->restore($archive,$key,$target,$confirmation,$storage,$recovery,true);
            // Verify the restored schema and installation identity before reporting usable recovery.
            $config=(new PostgresStream($this->app->config()))->settings();$config['database']=$target;
            $manager=new DatabaseManager(new ConfigRepository($this->app->basePath('config')),new ConnectionFactory(),$config);
            $id=$manager->connection()->query('SELECT installation_id::text FROM cajeer_installation WHERE singleton_id=1')->fetchColumn();
            $validation=(new MigrationRunner($manager,$journal['source'].'/migrations/pgsql'))->validate();
            if (!is_string($id)||!hash_equals($journal['installation_id'],$id)||!$validation['valid']||in_array('pending',array_column($validation['rows'],'status'),true)) throw new RuntimeException('Recovered database failed source identity/schema validation; keep original offline.');
            return $result + ['source_release'=>$journal['source'],'source_version'=>$journal['from'],'original_maintenance'=>true];
        },10);
    }

    private function checkBackup(array $report,array $expected): void
    {
        $header=$report['header'] ?? [];
        if (($header['mode'] ?? '') !== 'full' || ($header['database'] ?? '') !== $expected['database'] || ($header['app_version'] ?? '') !== $expected['from'] || ($header['upgrade_id'] ?? '') !== $expected['upgrade_id']) throw new RuntimeException('Backup does not match source release/database.');
    }

    private function sourceFiles(string $root): array
    {
        $files=[];
        foreach (glob($root.'/migrations/pgsql/*.sql') ?: [] as $file) {
            if (is_link($file) || !is_file($file)) throw new RuntimeException('Unsafe source migration.');
            $files[basename($file,'.sql')]=hash_file('sha256',$file) ?: throw new RuntimeException('Cannot hash source migration.');
        }
        if (count($files)!==64 || !isset($files['0064_performance_scalability'])) throw new RuntimeException('Source migration set is incomplete.');
        return $files;
    }

    private function identity(PDO $pdo): array
    {
        if ((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()<170000) throw new RuntimeException('PostgreSQL 17+ required.');
        $row=$pdo->query('SELECT installation_id::text AS id, installed_at::text AS created, current_database() AS database FROM cajeer_installation WHERE singleton_id=1')->fetch(PDO::FETCH_ASSOC);
        $marker=$this->installation->record();
        if (!is_array($row) || $marker===null || !hash_equals($row['id'],$marker['installation_id'])) throw new RuntimeException('Installation marker and database disagree; recover before upgrading.');
        return $row;
    }

    private function verify(): void
    {
        $db=$this->app->container()->get(DatabaseManager::class);
        $pdo=$db->connection(); $this->identity($pdo);
        $validation=$this->app->container()->get(MigrationRunner::class)->validate();
        if (!$validation['valid'] || in_array('pending',array_column($validation['rows'],'status'),true)) throw new RuntimeException('Schema not ready.');
        if ((int)$pdo->query('SELECT count(*) FROM vault_key_state')->fetchColumn()>0) $this->app->container()->get(CredentialsVault::class)->check();
        $integrity=$this->app->container()->get(CmdbIntegrity::class)->inspect();
        if (!$integrity['ok']) throw new RuntimeException('Post-upgrade CMDB integrity failed; inspect inventory:integrity --json.');
        $report=$this->app->container()->get(Diagnostics::class)->run(true);
        if (!$report['ok']) throw new RuntimeException('Post-upgrade readiness failed: '.implode(', ',array_keys(array_filter($report['checks'],static fn($c)=>!$c['ok']))));
    }
}
