<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use InvalidArgumentException;
use RuntimeException;

/** Versioned binary envelope: key ID + wrapped per-record DEK + authenticated ciphertext. */
final class VaultCrypto
{
    private const HEADER = 'CJV1';
    private const NONCE = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    private const TAG = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
    private const DEK = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const MAX_PLAINTEXT = 1_048_576;

    public function __construct(private readonly VaultMasterKey $masterKey) {}

    public function seal(string $plaintext, string $credentialId, string $workspaceId, string $type): string
    {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) throw new InvalidArgumentException('Credential exceeds 1 MiB limit.');
        $context = self::context($credentialId, $workspaceId, $type);
        $root = $this->masterKey->load();
        $dek = random_bytes(self::DEK);
        $kek = '';
        try {
            $keyId = hex2bin(VaultMasterKey::identifier($root));
            $kek = sodium_crypto_generichash('cajeerservers:vault:kek:v1', $root, self::DEK);
            $wrapNonce = random_bytes(self::NONCE);
            $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, 'vault:dek:v1:' . $context, $wrapNonce, $kek);
            $dataNonce = random_bytes(self::NONCE);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, 'vault:data:v1:' . $context, $dataNonce, $dek);
            return self::HEADER . $keyId . $wrapNonce . $wrapped . $dataNonce . $cipher;
        } finally {
            sodium_memzero($dek);
            if ($kek !== '') sodium_memzero($kek);
            sodium_memzero($root);
        }
    }

    public function open(string $envelope, string $credentialId, string $workspaceId, string $type): string
    {
        $context = self::context($credentialId, $workspaceId, $type);
        $min = 4 + 16 + self::NONCE + self::DEK + self::TAG + self::NONCE + self::TAG;
        if (strlen($envelope) < $min || strlen($envelope) > self::MAX_PLAINTEXT + $min || substr($envelope, 0, 4) !== self::HEADER) {
            throw new RuntimeException('Invalid Vault envelope.');
        }
        $root = $this->masterKey->load();
        $kek = '';
        $dek = '';
        try {
            $keyId = substr($envelope, 4, 16);
            $expected = hex2bin(VaultMasterKey::identifier($root));
            if (!hash_equals($expected, $keyId)) throw new RuntimeException('Vault key does not match this envelope.');
            $offset = 20;
            $wrapNonce = substr($envelope, $offset, self::NONCE); $offset += self::NONCE;
            $wrapped = substr($envelope, $offset, self::DEK + self::TAG); $offset += self::DEK + self::TAG;
            $dataNonce = substr($envelope, $offset, self::NONCE); $offset += self::NONCE;
            $cipher = substr($envelope, $offset);
            $kek = sodium_crypto_generichash('cajeerservers:vault:kek:v1', $root, self::DEK);
            $unwrapped = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($wrapped, 'vault:dek:v1:' . $context, $wrapNonce, $kek);
            if (!is_string($unwrapped) || strlen($unwrapped) !== self::DEK) throw new RuntimeException('Vault authentication failed.');
            $dek = $unwrapped;
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, 'vault:data:v1:' . $context, $dataNonce, $dek);
            if (!is_string($plain)) throw new RuntimeException('Vault authentication failed.');
            return $plain;
        } finally {
            if ($dek !== '') sodium_memzero($dek);
            if ($kek !== '') sodium_memzero($kek);
            sodium_memzero($root);
        }
    }

    /** AAD fields are explicit and strongly typed; no free-form untrusted AAD serialization. */
    private static function context(string $credentialId, string $workspaceId, string $type): string
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
        if (!preg_match($uuid, strtolower($credentialId)) || !preg_match($uuid, strtolower($workspaceId)) ||
            !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $type)) {
            throw new InvalidArgumentException('Invalid Vault credential context.');
        }
        return json_encode([strtolower($credentialId), strtolower($workspaceId), $type], JSON_THROW_ON_ERROR);
    }
}
