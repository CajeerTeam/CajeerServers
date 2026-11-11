<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Kernel\Application;

/** Safe operational commands. Private key material never printed. */
final class VaultCommands
{
    public function __construct(private readonly Application $app) {}

    public function run(string $command, array $argv): int
    {
        $args = array_slice($argv, 2);
        if ($command === 'vault:key:generate') {
            if (count($args) !== 1 || !str_starts_with($args[0], '--output=') || strlen(substr($args[0], 9)) === 0) {
                fwrite(STDERR, "Usage: cajeer vault:key:generate --output=/private/path/vault.key\n");
                return 2;
            }
            VaultMasterKey::generatePrivateFile(substr($args[0], 9));
            fwrite(STDOUT, "Vault key file created with 0600 permissions. Configure VAULT_MASTER_KEY_FILE.\n");
            return 0;
        }
        if ($command === 'vault:key:rotate') {
            $path = null;
            $confirmed = false;
            $json = false;
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--next-key-file=')) $path = substr($arg,16);
                elseif ($arg === '--confirm-offline') $confirmed = true;
                elseif ($arg === '--json') $json = true;
                else { fwrite(STDERR, "Unsupported vault rotation argument.\n"); return 2; }
            }
            if (!$confirmed || !$path || !str_starts_with($path,'/')) {
                fwrite(STDERR, "Usage: vault:key:rotate --next-key-file=/private/staged.key --confirm-offline [--json]\n");
                return 2;
            }
            // The explicit maintenance acknowledgement cannot be silently bypassed.
            // Run only with HTTP/queue workers stopped and a tested DB/key backup.
            $next=VaultMasterKey::fromPrivateFile($path);
            $result=$this->app->container()->get(\Cajeer\Servers\Credentials\VaultKeyRotation::class)->rotate($next);
            if ($json) fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");
            else fwrite(STDOUT, "Vault key envelopes rewrapped and committed. SWITCH VAULT_MASTER_KEY_FILE to the staged key before restarting workers.\n");
            return 0;
        }
        if ($args !== [] && $args !== ['--json']) {
            fwrite(STDERR, "Usage: cajeer $command [--json]\n");
            return 2;
        }
        $json = $args === ['--json'];
        $result = match ($command) {
            'vault:key:status' => ['configured' => true, 'key_id' => $this->app->container()->get(VaultMasterKey::class)->keyId()],
            'vault:init' => $this->app->container()->get(CredentialsVault::class)->initialize(),
            'vault:check' => $this->app->container()->get(CredentialsVault::class)->check(),
            'vault:probe' => $this->app->container()->get(CredentialsVault::class)->probe(),
            default => throw new \RuntimeException('Unknown Vault operation.'),
        };
        if ($json) {
            fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            fwrite(STDOUT, strtoupper(str_replace(':', ' ', $command)) . ": OK\n");
        }
        return 0;
    }
}
