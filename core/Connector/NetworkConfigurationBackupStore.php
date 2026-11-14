<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\{VaultCrypto,VaultMasterKey};
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

final class NetworkConfigurationBackupStore
{
    private const FIELDS='id::text,version,target_version,credential_version,identity_hash,sha256,byte_size,observed_at,created_at';
    private const TYPE='network_configuration_backup';
    public function __construct(private readonly VaultCrypto $crypto=new VaultCrypto(new VaultMasterKey())){}
    /** Must execute inside the fenced workspace transaction shared with the collection run. */
    public function save(PDO $pdo,array $target,#[\SensitiveParameter] string $text,string $observedAt):array
    {
        if(!$pdo->inTransaction()||$target['provider']!=='routeros')throw new \LogicException('Invalid configuration backup transaction.');
        NetworkConfigurationBackup::validate($text);
        $hash=hash('sha256',NetworkConfigurationBackup::canonical($text));$sha=hash('sha256',$text);$identity=$target['os_network_profile']['identity_hash'];
        $q=$pdo->prepare('SELECT '.self::FIELDS.',content_hash FROM network_configuration_backups WHERE target_id=:id AND workspace_id=:ws ORDER BY version DESC LIMIT 1 FOR UPDATE');
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);$last=$q->fetch(PDO::FETCH_ASSOC);
        if($last&&new \DateTimeImmutable($last['observed_at'])>new \DateTimeImmutable($observedAt))throw new HttpException(409,'Configuration backup superseded.','snapshot_stale');
        if($last&&hash_equals($last['content_hash'],$hash)&&hash_equals($last['identity_hash'],$identity)&&(int)$last['target_version']===$target['version']&&(int)$last['credential_version']===$target['credential_version']){unset($last['content_hash']);return ['changed'=>false,'backup'=>$last];}
        $id=(string)$pdo->query('SELECT gen_random_uuid()')->fetchColumn();
        $cipher=$this->crypto->seal($text,$id,$target['workspace_id'],self::TYPE);
        $q=$pdo->prepare('INSERT INTO network_configuration_backups(id,target_id,workspace_id,version,target_version,credential_id,credential_version,identity_hash,sha256,content_hash,byte_size,encrypted_payload,observed_at) VALUES(:id,:target,:ws,:v,:tv,:cred,:cv,:identity,:sha,:hash,:size,decode(:cipher,\'hex\'),CAST(:observed AS timestamptz)) RETURNING '.self::FIELDS);
        $q->execute(['id'=>$id,'target'=>$target['id'],'ws'=>$target['workspace_id'],'v'=>$last?(int)$last['version']+1:1,'tv'=>$target['version'],'cred'=>$target['credential_id'],'cv'=>$target['credential_version'],'identity'=>$identity,'sha'=>$sha,'hash'=>$hash,'size'=>strlen($text),'cipher'=>bin2hex($cipher),'observed'=>$observedAt]);
        return ['changed'=>true,'backup'=>$q->fetch(PDO::FETCH_ASSOC)];
    }
    public function list(PDO $pdo,array $target,int $limit,int $offset):array
    {
        $q=$pdo->prepare('SELECT '.self::FIELDS.' FROM network_configuration_backups WHERE target_id=:id AND workspace_id=:ws ORDER BY version DESC LIMIT :lim OFFSET :off');
        $q->bindValue('id',$target['id']);$q->bindValue('ws',$target['workspace_id']);$q->bindValue('lim',$limit,PDO::PARAM_INT);$q->bindValue('off',$offset,PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function content(PDO $pdo,array $target,string $id):array
    {
        $q=$pdo->prepare('SELECT '.self::FIELDS.',encode(encrypted_payload,\'hex\') AS ciphertext FROM network_configuration_backups WHERE id=:id AND target_id=:target AND workspace_id=:ws');
        $q->execute(['id'=>$id,'target'=>$target['id'],'ws'=>$target['workspace_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new HttpException(404,'Configuration backup not found.','not_found');
        $text=$this->crypto->open(hex2bin($row['ciphertext']),$row['id'],$target['workspace_id'],self::TYPE);unset($row['ciphertext']);
        if(!hash_equals($row['sha256'],hash('sha256',$text))||strlen($text)!==(int)$row['byte_size'])throw new \RuntimeException('Configuration backup integrity failure.');
        return ['backup'=>$row,'content'=>$text];
    }
}
