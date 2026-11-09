<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Config\EnvironmentLoader;
use RuntimeException;

/** Versioned, authenticated encryption for server-held MFA and webhook secrets. */
final class SecretBox
{
    private ?string $key = null;
    public function __construct(private readonly ?string $initialKey = null) {}

    private function key(): string
    {
        if ($this->key !== null) return $this->key;
        if (!extension_loaded('sodium')) throw new RuntimeException('ext-sodium is required.');
        $raw = $this->initialKey ?? EnvironmentLoader::getString('APP_KEY');
        if (str_starts_with($raw, 'base64:')) $raw = base64_decode(substr($raw, 7), true) ?: '';
        if (strlen($raw) < 32 || strlen(count_chars($raw, 3)) < 8) throw new RuntimeException('APP_KEY is not configured securely.');
        return $this->key = sodium_crypto_generichash('cajeerservers:security:v1', $raw, 32);
    }
    public function seal(string $plaintext, string $context): string
    {
        if ($context === '' || strlen($context) > 200) throw new \InvalidArgumentException('Invalid encryption context.');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return chr(1) . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key());
    }
    public function open(string $ciphertext, string $context): string
    {
        $min = 1 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        if (strlen($ciphertext) < $min || ord($ciphertext[0]) !== 1) throw new RuntimeException('Invalid encrypted secret.');
        $nonce = substr($ciphertext, 1, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $value = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($ciphertext, 1 + strlen($nonce)), $context, $nonce, $this->key());
        if (!is_string($value)) throw new RuntimeException('Encrypted secret authentication failed.');
        return $value;
    }
    public function digest(string $value, string $purpose): string
    {
        return hash_hmac('sha256', $purpose . "\0" . $value, $this->key());
    }
}
