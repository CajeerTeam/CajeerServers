<?php
declare(strict_types=1);
namespace Cajeer\Servers\Backup;

use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Inventory\CmdbIntegrity;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Security\SecretBox;
use RuntimeException;
use PDO;

/** Restore into an operator-created disposable DB, never activate its workers or network credentials. */
final class RecoveryDrill
{
    public function __construct(private readonly Application $app) {}

    public function run(string $archive,string $key,string $target,string $confirmation,string $storage,string $recovery): array
    {
        if (!preg_match('/^cajeer_drill_[a-z0-9_]{1,49}$/D',$target) || $target!==$confirmation) throw new RuntimeException('Drill requires an empty cajeer_drill_* database and exact confirmation.');
        $service=new BackupService($this->app);
        $report=$service->verify($archive,$key);$header=$report['header'];
        if (($header['mode']??null)!=='full' || ($header['consistent_storage']??null)!==true || ($header['app_version']??null)!==$this->app->version() || !is_string($header['installation_id']??null)) throw new RuntimeException('Drill requires a consistent managed full archive and matching release code.');
        $service->restore($archive,$key,$target,$confirmation,$storage,$recovery,true);
        $settings=(new PostgresStream($this->app->config()))->settings();$settings['database']=$target;
        $manager=new DatabaseManager($this->app->config(),new ConnectionFactory(),$settings);
        $pdo=$manager->connection();
        $id=$pdo->query('SELECT installation_id::text FROM cajeer_installation WHERE singleton_id=1')->fetchColumn();
        if (!is_string($id)||!hash_equals($header['installation_id'],$id)) throw new RuntimeException('Recovered installation identity mismatch.');
        $migration=(new MigrationRunner($manager,$this->app->basePath('migrations/pgsql')))->validate();
        if (!$migration['valid']||in_array('pending',array_column($migration['rows'],'status'),true)) throw new RuntimeException('Recovered migration history invalid.');
        if (!(new CmdbIntegrity($manager))->inspect()['ok']) throw new RuntimeException('Recovered CMDB integrity failed.');
        $appKey=trim((string)file_get_contents($recovery.'/app.key',false,null,0,4096));
        $box=new SecretBox($appKey);$challenge=random_bytes(32);
        if (!hash_equals($challenge,$box->open($box->seal($challenge,'drill'),'drill'))) throw new RuntimeException('Recovered APP_KEY failed.');
        // Existing MFA ciphertext verifies the original application key where enrollment exists.
        $mfa=$pdo->query("SELECT user_id::text,encode(secret_ciphertext,'hex') AS cipher FROM user_mfa ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (is_array($mfa)) { $plain=$box->open(hex2bin($mfa['cipher'])?:'','mfa:'.$mfa['user_id']); sodium_memzero($plain); }
        $vault=false;
        if ((int)$pdo->query('SELECT count(*) FROM vault_key_state')->fetchColumn()>0) {
            $encoded=trim((string)file_get_contents($recovery.'/vault.key',false,null,0,256));
            $keys=new VaultMasterKey($encoded);
            $vault=(new CredentialsVault($manager,new VaultCrypto($keys),$keys))->check()['ok'];
            if (!$vault) throw new RuntimeException('Recovered Vault verifier failed.');
        }
        return ['restored'=>true,'schema_valid'=>true,'cmdb_valid'=>true,'app_key_valid'=>true,'existing_mfa_verified'=>is_array($mfa),'vault_initialized_and_verified'=>$vault,'external_operations_started'=>false,'target_database'=>$target,'warning'=>'Keep this database and recovery directories isolated. Do not start workers; manually destroy drill resources after inspection.'];
    }
}
