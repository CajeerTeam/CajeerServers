<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Http\Exception\HttpException;
use JsonException;

/**
 * Executable, allowlisted credential formats. All structured fields are encrypted
 * together in the CJV1 envelope; no field from this class is stored as plaintext
 * PostgreSQL metadata or returned by credential CRUD responses.
 *
 * This is format validation, NOT authentication against remote endpoints.
 */
final class CredentialProfile
{
    /** @var array<string,array{fields:list<string>,optional:list<string>,note:string}> */
    private const TYPES = [
        'generic_secret' => ['fields'=>['secret'], 'optional'=>[], 'note'=>'Opaque UTF-8 secret'],
        'api_token' => ['fields'=>['secret'], 'optional'=>[], 'note'=>'Opaque API token (legacy scalar format)'],
        'proxmox_api' => ['fields'=>['token_id','token_secret'], 'optional'=>[], 'note'=>'Proxmox VE API token'],
        'vmmanager_api' => ['fields'=>['token'], 'optional'=>[], 'note'=>'VMmanager API token'],
        'ssh_password' => ['fields'=>['username','password'], 'optional'=>[], 'note'=>'SSH user and password'],
        'ssh_private_key' => ['fields'=>['username','private_key'], 'optional'=>['passphrase'], 'note'=>'SSH private key (PEM / OpenSSH)'],
        'winrm' => ['fields'=>['username','password'], 'optional'=>['auth'], 'note'=>'WinRM / PowerShell credentials'],
        'snmp_v2c' => ['fields'=>['community'], 'optional'=>[], 'note'=>'SNMP v2c community'],
        'snmp_v3' => ['fields'=>['username','auth_protocol','auth_passphrase'], 'optional'=>['privacy_protocol','privacy_passphrase'], 'note'=>'SNMPv3 authNoPriv or authPriv'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /** Safe API descriptor: field names only, no values, ciphertext or key references. */
    public static function descriptors(): array
    {
        $result=[];
        foreach (self::TYPES as $type=>$definition) {
            $result[]=[
                'type'=>$type,
                'format'=>in_array($type,['generic_secret','api_token'],true)?'string':'object',
                'required_fields'=>$definition['fields'],
                'optional_fields'=>$definition['optional'],
                'description'=>$definition['note'],
            ];
        }
        return $result;
    }

    public static function type(mixed $value): string
    {
        if (!is_string($value) || !isset(self::TYPES[$value])) self::reject();
        return $value;
    }

    /**
     * Convert input into the exact byte string that must be encrypted.
     * Existing 0.3.2 generic_secret/api_token ciphertext remains unchanged.
     */
    public static function encode(string $type, mixed $value): string
    {
        self::type($type);
        if ($type==='generic_secret' || $type==='api_token') {
            return self::bounded($value, 1, 65536, true);
        }
        if (!is_array($value) || array_is_list($value)) self::reject();
        $definition=self::TYPES[$type];
        $allowed=array_merge($definition['fields'],$definition['optional']);
        if (array_diff(array_keys($value),$allowed) !== []) self::reject();
        foreach ($definition['fields'] as $required) {
            if (!array_key_exists($required,$value)) self::reject();
        }
        // Normalize the canonical binary format so profile content is deterministic.
        $payload=[];
        foreach ($allowed as $field) {
            if (array_key_exists($field,$value)) $payload[$field]=self::field($type,$field,$value[$field]);
        }
        self::crossValidate($type,$payload);
        try {
            $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        } catch (JsonException) { self::reject(); }
        if (!is_string($json) || strlen($json)>65536) self::reject();
        return $json;
    }

    /** Internal-use-only decoder. Never expose decoded values from REST controllers. */
    public static function decodeForInternalUse(string $type, string $plaintext): string|array
    {
        self::type($type);
        if (in_array($type,['generic_secret','api_token'],true)) {
            return self::encode($type,$plaintext);
        }
        try { $value=json_decode($plaintext,true,32,JSON_THROW_ON_ERROR); }
        catch (JsonException) { self::reject(); }
        if (!is_array($value)) self::reject();
        self::encode($type,$value);
        return $value;
    }

    private static function field(string $type,string $field,mixed $value):string
    {
        return match ($field) {
            'username' => self::identity($value, $type==='winrm'?256:128),
            'token_id' => self::tokenId($value),
            'token','token_secret' => self::bounded($value, 8, 8192, false),
            'password' => self::bounded($value, 1, 8192, false),
            'passphrase' => self::bounded($value, 1, 8192, false),
            'community' => self::bounded($value, 1, 255, false),
            'private_key' => self::privateKey($value),
            'auth' => self::enum($value,['negotiate','ntlm','kerberos','basic']),
            'auth_protocol' => self::enum($value,['sha256','sha512']),
            'privacy_protocol' => self::enum($value,['aes128','aes256']),
            'auth_passphrase','privacy_passphrase' => self::bounded($value, 8, 255, false),
            default => self::invalidField(),
        };
    }

    private static function invalidField(): never { self::reject(); }

    private static function crossValidate(string $type,array $payload):void
    {
        if ($type==='snmp_v3') {
            if (array_key_exists('privacy_protocol',$payload) !== array_key_exists('privacy_passphrase',$payload)) self::reject();
        }
        if ($type==='ssh_private_key') {
            $key=$payload['private_key'];
            if (str_starts_with($key,'-----BEGIN OPENSSH PRIVATE KEY-----')) {
                self::checkOpenSsh($key,isset($payload['passphrase']));
            } else {
                $pkey=@openssl_pkey_get_private($key,$payload['passphrase']??'');
                if ($pkey===false) self::reject();
                // OpenSSL object is released by PHP's refcounting.
                unset($pkey);
            }
        }
    }

    private static function checkOpenSsh(string $key,bool $hasPassphrase):void
    {
        if (!preg_match('/\A-----BEGIN OPENSSH PRIVATE KEY-----\r?\n([A-Za-z0-9+\/=\r\n]+)\r?\n-----END OPENSSH PRIVATE KEY-----\r?\n?\z/D',$key,$match)) self::reject();
        $data=base64_decode(preg_replace('/\s+/','',$match[1]),true);
        if (!is_string($data) || !str_starts_with($data,"openssh-key-v1\0")) self::reject();
        $cursor=15;
        $cipher=self::sshString($data,$cursor);
        $kdf=self::sshString($data,$cursor);
        $opts=self::sshString($data,$cursor);
        $keys=self::sshU32($data,$cursor);
        if ($keys!==1) self::reject();
        $public=self::sshString($data,$cursor);
        $private=self::sshString($data,$cursor);
        if ($cursor!==strlen($data) || strlen($public)<12 || strlen($private)<16) self::reject();
        if ($cipher==='none') {
            if ($kdf!=='none' || $opts!=='') self::reject();
            // For unencrypted OpenSSH keys, verify checkints and the Ed25519
            // private/public binding rather than accepting any base64 blob.
            $pos=0;
            $a=self::sshU32($private,$pos);
            $b=self::sshU32($private,$pos);
            if ($a!==$b) self::reject();
            $keyType=self::sshString($private,$pos);
            if (!in_array($keyType,['ssh-ed25519','ssh-rsa','ecdsa-sha2-nistp256','ecdsa-sha2-nistp384','ecdsa-sha2-nistp521'],true)) self::reject();
            if ($keyType==='ssh-ed25519') {
                $pub=self::sshString($private,$pos);
                $priv=self::sshString($private,$pos);
                if (strlen($pub)!==32 || strlen($priv)!==64 || !hash_equals($pub,substr($priv,32,32))) self::reject();
                $comment=self::sshString($private,$pos);
                $padding=substr($private,$pos);
                if ($padding==='' || strlen($padding)>16) self::reject();
                for ($i=0;$i<strlen($padding);$i++) if (ord($padding[$i])!==$i+1) self::reject();
                $publicOffset=0;
                if (self::sshString($public,$publicOffset)!=='ssh-ed25519' || !hash_equals(self::sshString($public,$publicOffset),$pub) || $publicOffset!==strlen($public)) self::reject();
            }
        } elseif ($kdf!=='bcrypt' || !$hasPassphrase || !in_array($cipher,['aes256-ctr','aes256-cbc','aes256-gcm@openssh.com'],true)) {
            self::reject();
        }
        // OpenSSH encrypted-key passphrase correctness is verified by SSH transport,
        // not by this offline envelope validation.
    }

    private static function sshU32(string $bytes,int &$offset):int
    {
        if (strlen($bytes)-$offset<4) self::reject();
        $n=unpack('Nlength',substr($bytes,$offset,4));$offset+=4;
        return $n['length'];
    }

    private static function sshString(string $bytes,int &$offset):string
    {
        $length=self::sshU32($bytes,$offset);
        if ($length>65536 || $length>strlen($bytes)-$offset) self::reject();
        $result=substr($bytes,$offset,$length);$offset+=$length;
        return $result;
    }

    private static function privateKey(mixed $value):string
    {
        $string=self::bounded($value,64,32768,true);
        if (!str_starts_with($string,'-----BEGIN OPENSSH PRIVATE KEY-----') &&
            !preg_match('/\A-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----\r?\n/',$string)) self::reject();
        return $string;
    }

    private static function identity(mixed $value,int $max):string
    {
        $s=self::bounded($value,1,$max,false);
        if (preg_match('/[\x00-\x20\x7f]/',$s)) self::reject();
        return $s;
    }

    private static function tokenId(mixed $value):string
    {
        $s=self::bounded($value,5,255,false);
        if (!preg_match('/\A[a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+![a-zA-Z0-9._-]+\z/D',$s)) self::reject();
        return $s;
    }

    private static function enum(mixed $value,array $allowed):string
    {
        if (!is_string($value) || !in_array($value,$allowed,true)) self::reject();
        return $value;
    }

    private static function bounded(mixed $value,int $min,int $max,bool $multiline):string
    {
        if (!is_string($value) || strlen($value)<$min || strlen($value)>$max || !preg_match('//u',$value)) self::reject();
        if (str_contains($value,"\0") || (!$multiline && preg_match('/[\x00-\x1f\x7f]/',$value))) self::reject();
        return $value;
    }

    private static function reject():never
    {
        // Deliberately do not include secret fields or input lengths in errors.
        throw new HttpException(422,'Invalid credential profile or secret value.','validation_failed');
    }
}
