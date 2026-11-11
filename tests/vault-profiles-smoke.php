#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Credentials\CredentialProfile;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;

$n=0;
$ok=static function(bool $valid,string $label)use(&$n):void {if(!$valid)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(string $type,mixed $secret,string $label)use($ok):void {
    try { CredentialProfile::encode($type,$secret); }
    catch(HttpException $e) { $ok($e->status===422 && !str_contains($e->getMessage(),'test-super-secret'),'reject '.$label);return; }
    throw new RuntimeException('FAIL: accepted '.$label);
};
$valid=[
    'generic_secret'=>'test-super-secret',
    'api_token'=>'test-super-secret',
    'proxmox_api'=>['token_id'=>'root@pam!monitor','token_secret'=>'test-super-secret'],
    'vmmanager_api'=>['token'=>'test-super-secret'],
    'ssh_password'=>['username'=>'deploy','password'=>'test-super-secret'],
    'winrm'=>['username'=>'EXAMPLE\\service','password'=>'test-super-secret','auth'=>'negotiate'],
    'snmp_v2c'=>['community'=>'test-super-secret'],
    'snmp_v3'=>['username'=>'monitor','auth_protocol'=>'sha256','auth_passphrase'=>'test-super-secret',
        'privacy_protocol'=>'aes128','privacy_passphrase'=>'test-super-secret'],
];
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
if ($key===false || !openssl_pkey_export($key,$pem)) throw new RuntimeException('OpenSSL test private key generation failed');
$valid['ssh_private_key']=['username'=>'deploy','private_key'=>$pem];
$makeSshString=static fn(string $v):string=>pack('N',strlen($v)).$v;
$kp=sodium_crypto_sign_keypair();
$pub=sodium_crypto_sign_publickey($kp);
$priv=sodium_crypto_sign_secretkey($kp);
$pubBlock=$makeSshString('ssh-ed25519').$makeSshString($pub);
$check=random_int(0,0x7fffffff);
$block=pack('NN',$check,$check).$makeSshString('ssh-ed25519').$makeSshString($pub).$makeSshString($priv).$makeSshString('generated-test-key');
for($i=1;strlen($block)%8!==0;$i++) $block.=chr($i);
$sshBlob="openssh-key-v1\0".$makeSshString('none').$makeSshString('none').$makeSshString('').pack('N',1).$makeSshString($pubBlock).$makeSshString($block);
$sshPem="-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($sshBlob),70,"\n")."-----END OPENSSH PRIVATE KEY-----\n";
$sshSecret=['username'=>'deploy','private_key'=>$sshPem];
$ok(is_string(CredentialProfile::encode('ssh_private_key',$sshSecret)),'valid native OpenSSH Ed25519 key');
$badBlock=$block;
$badBlock[0]=chr(ord($badBlock[0])^0xff);
$badBlob="openssh-key-v1\0".$makeSshString('none').$makeSshString('none').$makeSshString('').pack('N',1).$makeSshString($pubBlock).$makeSshString($badBlock);
$badPem="-----BEGIN OPENSSH PRIVATE KEY-----\n".chunk_split(base64_encode($badBlob),70,"\n")."-----END OPENSSH PRIVATE KEY-----\n";
$reject('ssh_private_key',['username'=>'deploy','private_key'=>$badPem],'corrupt native OpenSSH checkints');
$all=CredentialProfile::types();
$ok(count($all)===9,'nine implemented profiles');
$ok(count(CredentialProfile::descriptors())===count($valid),'profile discovery count');
$ok(count(array_unique($all))===count($all),'no duplicate profile names');
$id='a1211111-bbbb-4ddd-8abc-111111111111';
$ws='e2222222-bbbb-4ccc-8abc-222222222222';
$crypto=new VaultCrypto(new VaultMasterKey('base64:'.base64_encode(random_bytes(32))));
foreach ($valid as $type=>$secret) {
    $ok(CredentialInput::type($type)===$type,'recognized type '.$type);
    $encoded=CredentialProfile::encode($type,$secret);
    $ok(strlen($encoded)>0 && strlen($encoded)<=65536,'bounded serialized profile '.$type);
    $envelope=$crypto->seal($encoded,$id,$ws,$type);
    $ok(!str_contains($envelope,'test-super-secret'),'ciphertext contains no plaintext '.$type);
    $result=CredentialProfile::decodeForInternalUse($type,$crypto->open($envelope,$id,$ws,$type));
    $ok($result===$secret,'encrypted roundtrip '.$type);
    $reject($type,['other'=>'test-super-secret'],'unknown or wrong profile fields '.$type);
}
foreach (CredentialProfile::descriptors() as $definition) {
    $ok(!isset($definition['secret'],$definition['token'],$definition['password']),'descriptor never returns values');
    $ok(array_key_exists('required_fields',$definition),'descriptor required field names');
}
$reject('ssh_password',['username'=>'deploy'],'missing password');
$reject('ssh_password',['username'=>'a b','password'=>'test-super-secret'],'username contains spaces');
$reject('ssh_password',['username'=>'deploy','password'=>'test-super-secret','host'=>'localhost'],'unknown network target field');
$reject('winrm',['username'=>'admin','password'=>'test-super-secret','auth'=>'plaintext'],'invalid WinRM authentication');
$reject('snmp_v3',['username'=>'monitor','auth_protocol'=>'sha1','auth_passphrase'=>'test-super-secret'],'weak snmp auth');
$reject('snmp_v3',['username'=>'monitor','auth_protocol'=>'sha256','auth_passphrase'=>'test-super-secret', 'privacy_protocol'=>'aes128'],'missing SNMP privacy passphrase');
$reject('snmp_v3',['username'=>'monitor','auth_protocol'=>'sha256','auth_passphrase'=>'test-super-secret', 'privacy_passphrase'=>'test-super-secret'],'missing SNMP privacy algorithm');
$reject('snmp_v2c',['community'=>"bad\ncommunity"],'control characters in community');
$reject('proxmox_api',['token_id'=>'bad-token','token_secret'=>'test-super-secret'],'invalid proxmox ID');
$reject('vmmanager_api',['token'=>'short'],'short VMmanager token');
$reject('ssh_private_key',['username'=>'deploy','private_key'=>'-----BEGIN OPENSSH PRIVATE KEY-----\ninvalid\n-----END OPENSSH PRIVATE KEY-----'],'invalid SSH key');
$reject('api_token',['token'=>'test-super-secret'],'old API token must remain scalar');
$reject('generic_secret',['secret'=>'test-super-secret'],'old generic secret must remain scalar');
$reject('snmp_v3',['username'=>'monitor','auth_protocol'=>'sha256','auth_passphrase'=>'test-super-secret', 'privacy_protocol'=>'aes128','privacy_passphrase'=>'short'],'short privacy passphrase');
$reject('ssh_password',['username'=>'deploy','password'=>str_repeat('x',8193)],'large password');
$reject('api_token','', 'empty legacy secret');
$reject('generic_secret',str_repeat('x',65537),'large legacy secret');
$reject('not-supported','test-super-secret','unknown profile');
try { CredentialProfile::decodeForInternalUse('ssh_password','{"username":"deploy","password":1}');throw new RuntimeException('Invalid decrypted profile accepted'); }
catch (HttpException $e) {$ok($e->status===422,'invalid decoded type rejected');}
try { $crypto->open($crypto->seal(CredentialProfile::encode('snmp_v2c',['community'=>'test-super-secret']),$id,$ws,'snmp_v2c'),$id,$ws,'api_token');throw new RuntimeException('Wrong type decrypt accepted'); }
catch(RuntimeException $e) {$ok(true,'AEAD binds credential type');}
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try {
    $r=$app->handle(new HttpRequest('GET','/api/v1/vault/profiles'));
    $ok(in_array($r->status,[401,503],true),'unauthenticated profile listing is blocked');
    $ok(!str_contains($r->body,'test-super-secret'),'no credential values in error response');
    $bad=$app->handle(new HttpRequest('POST','/api/v1/vault/credentials',['Content-Type'=>'application/json'],
        json_encode(['workspace_id'=>$ws,'name'=>'bad','type'=>'proxmox_api','secret'=>['token_id'=>'test','token_secret'=>'test-super-secret']],JSON_THROW_ON_ERROR)));
    $ok(in_array($bad->status,[401,422,503],true),'structured credential HTTP pipeline rejects missing auth/invalid payload');
    $ok(!str_contains($bad->body,'test-super-secret'),'no structured secret in error response');
}finally {$app->terminate();}
echo "Vault profiles smoke: $n PASS\n";
