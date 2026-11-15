<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Backup\BackupKey;
use Cajeer\Servers\Backup\BackupService;
use Cajeer\Servers\Backup\ManagedBackup;
use Cajeer\Servers\Backup\RecoveryDrill;
use Cajeer\Servers\Update\Maintenance;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Kernel\Application;
use InvalidArgumentException;

/** Strict operator-only CLI: never accept passwords/key material as argv values. */
final class BackupCommands
{
    public function __construct(private readonly Application $app) {}

    /** @param list<string> $argv */
    public function run(string $command, array $argv): int
    {
        $accepted = match ($command) {
            'backup:key:generate' => ['output'],
            'backup:create' => ['output','key-file','db-only'],
            'backup:scheduled' => ['directory','offsite','key-file','keep','drain-seconds','offline'],
            'backup:verify' => ['archive','key-file'],
            'backup:drill' => ['archive','key-file','target-db','confirm-target','storage-target','recovery-target','offline'],
            'backup:restore' => ['archive','key-file','target-db','confirm-target','storage-target','recovery-target','offline'],
            default => throw new InvalidArgumentException('Unknown disaster recovery command.'),
        };
        $args = [];
        foreach (array_slice($argv, 2) as $option) {
            if (!preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/sD', $option, $match)) {
                throw new InvalidArgumentException('Expected --name=value option.');
            }
            $name = $match[1];
            if (!in_array($name, $accepted, true) || isset($args[$name])) {
                throw new InvalidArgumentException('Unknown or repeated backup option: --' . $name);
            }
            if (in_array($name, ['db-only','offline'], true)) {
                if (isset($match[2])) throw new InvalidArgumentException('Flag --' . $name . ' must not have a value.');
                $args[$name] = true;
            } else {
                if (!isset($match[2]) || $match[2] === '') throw new InvalidArgumentException('Option requires a non-empty value: --' . $name);
                $args[$name] = $match[2];
            }
        }
        if ($command === 'backup:key:generate') {
            BackupKey::generate($this->required($args, 'output'));
            return $this->output(['created' => true, 'key_file' => $args['output'], 'hint' => 'Store a second copy offline; loss of the key makes every archive unrecoverable.']);
        }
        $key = $args['key-file'] ?? EnvironmentLoader::getString('BACKUP_KEY_FILE');
        if (!is_string($key) || $key === '') throw new InvalidArgumentException('Configure BACKUP_KEY_FILE or --key-file=/private/backup.key.');
        if ($command==='backup:scheduled') {
            if (!isset($args['offline'])) throw new InvalidArgumentException('Scheduled full backup requires --offline acknowledgement of external writer quiescence.');
            $directory=$args['directory']??EnvironmentLoader::getString('BACKUP_DIRECTORY');
            $offsite=$args['offsite']??EnvironmentLoader::getString('BACKUP_OFFSITE_DIRECTORY');
            $keep=$this->number($args['keep']??EnvironmentLoader::getString('BACKUP_KEEP','7'),2,365);
            $drain=$this->number($args['drain-seconds']??EnvironmentLoader::getString('BACKUP_DRAIN_SECONDS','600'),1,1800);
            return $this->output((new ManagedBackup($this->app))->run($directory,$offsite!==''?$offsite:null,$key,$keep,$drain));
        }
        $backup = new BackupService($this->app);
        $result = match ($command) {
            'backup:create' => $backup->create($this->required($args,'output'), $key, isset($args['db-only'])),
            'backup:verify' => $backup->verify($this->required($args,'archive'), $key),
            'backup:drill' => isset($args['offline']) ? (new Maintenance($this->app->basePath()))->drainExclusive(fn()=>(new RecoveryDrill($this->app))->run($this->required($args,'archive'),$key,$this->required($args,'target-db'),$this->required($args,'confirm-target'),$this->required($args,'storage-target'),$this->required($args,'recovery-target')),10) : throw new InvalidArgumentException('Drill requires --offline.'),
            'backup:restore' => (new Maintenance($this->app->basePath()))->drainExclusive(fn()=>$backup->restore(
                $this->required($args,'archive'), $key, $this->required($args,'target-db'),
                $this->required($args,'confirm-target'), $args['storage-target'] ?? null,
                $args['recovery-target'] ?? null, isset($args['offline'])
            ), 10),
            default => throw new InvalidArgumentException('Unknown backup operation.'),
        };
        return $this->output($result);
    }

    private function number(string $value,int $min,int $max): int
    {
        if (!preg_match('/^[0-9]{1,4}$/D',$value) || (int)$value<$min || (int)$value>$max) throw new InvalidArgumentException('Invalid backup numeric option.');
        return (int)$value;
    }

    private function required(array $args, string $name): string
    {
        $value = $args[$name] ?? null;
        if (!is_string($value) || $value === '') throw new InvalidArgumentException('Required option missing: --' . $name);
        return $value;
    }

    private function output(array $payload): int
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    }
}
