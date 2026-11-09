<?php
declare(strict_types=1);

namespace Cajeer\Servers\Installer;

use RuntimeException;

/** A usable non-interactive and interactive CLI installation entrypoint. */
final class InstallCommand
{
    public function __construct(private readonly InstallService $installer) {}

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $keys = [
            'url' => 'APP_URL', 'env' => 'APP_ENV', 'db-host' => 'DB_HOST', 'db-port' => 'DB_PORT',
            'db-database' => 'DB_DATABASE', 'db-username' => 'DB_USERNAME',
            'db-sslmode' => 'DB_SSLMODE', 'db-sslrootcert' => 'DB_SSLROOTCERT',
            'admin-email' => 'admin_email', 'admin-name' => 'admin_name',
        ];
        $values = [];
        $passwordFile = null;
        $dbPasswordFile = null;
        foreach (array_slice($argv, 2) as $argument) {
            if (!preg_match('/^--([a-z-]+)=(.*)$/sD', $argument, $match)) {
                throw new RuntimeException('Invalid install argument. Run cajeer help.');
            }
            if ($match[1] === 'admin-password-file') $passwordFile = $match[2];
            elseif ($match[1] === 'db-password-file') $dbPasswordFile = $match[2];
            elseif (isset($keys[$match[1]])) $values[$keys[$match[1]]] = $match[2];
            else throw new RuntimeException('Unknown install option: --' . $match[1]);
        }
        if (($values['admin_email'] ?? '') === '') $values['admin_email'] = $this->prompt('Administrator email: ');
        if (($values['admin_name'] ?? '') === '') $values['admin_name'] = $this->prompt('Administrator name: ');
        $values['admin_password'] = $passwordFile !== null ? $this->secretFile($passwordFile) : $this->hiddenPrompt('Administrator password (min 14 bytes): ');
        if ($dbPasswordFile !== null) $values['DB_PASSWORD'] = $this->secretFile($dbPasswordFile);
        $result = $this->installer->install($values);
        fwrite(STDOUT, "Installation completed. Administrator: " . $result['admin_email'] . "\n");
        fwrite(STDOUT, 'Applied migrations: ' . (implode(', ', $result['migrations']) ?: 'none') . "\n");
        return 0;
    }

    private function prompt(string $message): string
    {
        if (!defined('STDIN') || !stream_isatty(STDIN)) throw new RuntimeException('Supply --admin-email, --admin-name and --admin-password-file for non-interactive installation.');
        fwrite(STDERR, $message);
        $line = fgets(STDIN);
        if ($line === false) throw new RuntimeException('Cannot read installer input.');
        return trim($line);
    }

    private function hiddenPrompt(string $message): string
    {
        if (!defined('STDIN') || !stream_isatty(STDIN)) throw new RuntimeException('Supply --admin-password-file=<private file> for non-interactive installation.');
        exec('stty -echo 2>/dev/null', $output, $status);
        if ($status !== 0) throw new RuntimeException('Cannot disable terminal echo. Use --admin-password-file.');
        try {
            fwrite(STDERR, $message);
            $value = fgets(STDIN);
            if ($value === false) throw new RuntimeException('Cannot read administrator password.');
            return rtrim($value, "\r\n");
        } finally {
            exec('stty echo 2>/dev/null');
            fwrite(STDERR, "\n");
        }
    }

    private function secretFile(string $path): string
    {
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Secret file must be a readable, regular non-symlink file.');
        }
        if (filesize($path) === false || filesize($path) > 4096) throw new RuntimeException('Secret file exceeds 4 KiB.');
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Cannot read secret file.');
        return rtrim($contents, "\r\n");
    }
}
