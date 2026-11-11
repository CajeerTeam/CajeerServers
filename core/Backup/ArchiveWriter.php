<?php
declare(strict_types=1);

namespace Cajeer\Servers\Backup;

use RuntimeException;

/** Stateful writer; tag FINAL only on authenticated terminal manifest. */
final class ArchiveWriter
{
    /** @param resource $out */
    public function __construct(private $out, private string $state) {}

    public function emit(string $kind, string $data, bool $final = false): void
    {
        if (strlen($data) > EncryptedArchive::CHUNK && !in_array($kind, ['H','F','f','d','T'], true)) {
            throw new RuntimeException('Archive chunk exceeds the streaming limit.');
        }
        $packet = sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state, $kind . $data, '', $final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
        );
        EncryptedArchive::writeAll($this->out, pack('N', strlen($packet)) . $packet);
    }

    /** @param array<string,mixed> $data */
    public function json(string $kind, array $data, bool $final = false): void
    {
        $this->emit($kind, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $final);
    }
}
