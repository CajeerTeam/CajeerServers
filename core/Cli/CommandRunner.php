<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Logging\JsonLogger;
use Throwable;
use Cajeer\Servers\Installer\InstallService;
use Cajeer\Servers\Installer\InstallCommand;

/** CLI functionality shipped in 0.1.1; future commands fail, not fake success. */
final class CommandRunner
{
    public function __construct(private readonly Application $app)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        if ($command === '--help' || $command === '-h') {
            $command = 'help';
        }
        $this->app->errors()->mode('cli');
        try {
            return match ($command) {
                'help' => $this->help(),
                'version', '--version', '-V' => $this->version(),
                'about' => $this->about(),
                'config:get' => $this->getConfig($argv[2] ?? ''),
                'config:check', 'env:check', 'doctor' => $this->doctor(in_array('--json', $argv, true)),
                'self:test' => $this->selfTest(),
                'install' => (new InstallCommand(new InstallService($this->app->basePath())))->run($argv),
                'install:web-token' => $this->webInstallToken(),
                'install:status' => $this->installStatus(in_array('--json', $argv, true)),
                'install:recover' => $this->installRecover(),
                'install:check' => $this->installCheck(in_array('--json', $argv, true)),
                'database:check', 'db:check' => $this->databaseCheck(in_array('--json', $argv, true)),
                'migrate' => $this->migrate($argv),
                'migrate:status' => $this->migrationStatus(in_array('--json', $argv, true)),
                'migrate:pending' => $this->migrationPending(in_array('--json', $argv, true)),
                'migrate:validate', 'schema:check' => $this->migrationValidate(in_array('--json', $argv, true)),
                'cache:check', 'cache:get', 'cache:put', 'cache:delete',
                'queue:push', 'queue:work', 'queue:status', 'queue:failed', 'queue:retry', 'queue:prune',
                'scheduler:add', 'scheduler:list', 'scheduler:run', 'scheduler:enable', 'scheduler:disable',
                'storage:check', 'storage:put', 'storage:get', 'storage:exists', 'storage:delete' => (new InfrastructureCommands($this->app))->run($command, $argv),
                'connector:sync', 'scripts:run',
                'system:report', 'openapi:lint', 'update:check' => $this->unavailable($command),
                default => $this->unknown($command),
            };
        } catch (Throwable $error) {
            fwrite(STDERR, $this->app->errors()->cliMessage($error));
            return 1;
        }
    }

    private function help(): int
    {
        fwrite(STDOUT, <<<'TEXT'
CajeerServers CLI 0.1.7

Рабочие команды:
  help                 Список доступных команд
  version              Версия приложения
  about                Информация об установленном runtime
  env:check            Проверка конфигурации окружения и runtime
  config:check         То же, что env:check
  config:get <key>     Прочитать несекретное значение конфигурации
  doctor [--json]      Проверить PHP, расширения, storage и конфигурацию
  install:check        Реальная проверка предпосылок установщика (--json)
  install:status       Текущий статус установки (--json)
  install:recover      Восстановить локальный marker из подтверждённой PostgreSQL-записи
  install:web-token    Сгенерировать одноразовый код для Web Installer
  install             Установка PostgreSQL, миграции и первого администратора
                      --admin-email=... --admin-name=... --admin-password-file=/private/file
                      --url=https://... --db-host=... --db-database=... --db-username=...
                      --db-password-file=/private/file (не передавать пароль в аргументах)
  self:test            Самопроверка bootstrap, DI и конфигурации
  database:check       Проверить реальное подключение к БД
  migrate              Выполнить неприменённые PostgreSQL миграции
  migrate --dry-run    Показать план без изменения БД
  migrate:status       Статус каждой миграции (--json)
  migrate:pending      Список ожидающих миграций (--json)
  migrate:validate     Проверить SQL и checksum журнал (--json)
  cache:check          Проверить Redis PING
  cache:put            --key=... --value=... [--ttl=300]
  cache:get            --key=...
  cache:delete         --key=...
  queue:push           --type=runtime.diagnostics|storage.probe [--queue=default] [--payload-file=...]
  queue:work           [--once] или --max-jobs=N / --max-seconds=N [--queue=default]
  queue:status         --id=UUID
  queue:failed         [--queue=default] [--limit=50]
  queue:retry          --id=UUID
  queue:prune          [--queue=default] [--days=7] [--limit=500]
  scheduler:add        --name=... --cron='*/5 * * * *' --type=runtime.diagnostics [--run-now]
  scheduler:list       Просмотреть задания PostgreSQL
  scheduler:run        Планировать due jobs и отправлять outbox в выбранную очередь
  scheduler:enable     --name=...
  scheduler:disable    --name=...
  storage:check        Реальная проверка write/read/delete
  storage:put          --key=... --file=/path/to/local/file
  storage:get          --key=... --output=/path/to/new/file
  storage:exists       --key=...
  storage:delete       --key=...

Нереализованные инфраструктурные команды возвращают ошибку.
TEXT
        );
        fwrite(STDOUT, "\n");
        return 0;
    }

    private function version(): int
    {
        fwrite(STDOUT, $this->app->version() . "\n");
        return 0;
    }

    private function about(): int
    {
        fwrite(STDOUT, sprintf("%s %s\nPHP %s (%s)\nEnvironment: %s\n", $this->app->name(), $this->app->version(), PHP_VERSION, PHP_SAPI, (string) $this->app->config()->get('app.env', 'production')));
        return 0;
    }

    private function getConfig(string $key): int
    {
        if ($key === '') {
            fwrite(STDERR, "Usage: cajeer config:get <section.key>\n");
            return 2;
        }
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            if (JsonLogger::sensitive($part)) {
                fwrite(STDERR, "Access to sensitive configuration is denied.\n");
                return 2;
            }
        }
        if (!$this->app->config()->has($key)) {
            fwrite(STDERR, "Configuration key not found.\n");
            return 2;
        }
        $value = JsonLogger::redact($this->app->config()->get($key));
        fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    }

    private function doctor(bool $json): int
    {
        $result = $this->app->container()->get(RuntimeDiagnostics::class)->run();
        if ($json) {
            fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            foreach ($result['checks'] as $check) {
                fwrite(STDOUT, sprintf("[%s] %s — %s\n", $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['detail']));
            }
            fwrite(STDOUT, $result['ok'] ? "Runtime ready (DB, Redis and API connections not tested).\n" : "Runtime is NOT ready.\n");
        }
        return $result['ok'] ? 0 : 1;
    }

    private function selfTest(): int
    {
        $checks = [
            'version' => $this->app->version() !== '',
            'application_name' => $this->app->name() !== '',
            'config' => is_array($this->app->config()->get('app')),
            'container_instances' => $this->app->container()->get(Application::class) === $this->app,
            'container_singletons' => $this->app->container()->get(JsonLogger::class) === $this->app->container()->get(JsonLogger::class),
            'services' => $this->app->container()->get(ServiceContainer::class) === $this->app->container(),
        ];
        foreach ($checks as $name => $ok) {
            fwrite(STDOUT, sprintf("[%s] %s\n", $ok ? 'OK' : 'FAIL', $name));
        }
        return in_array(false, $checks, true) ? 1 : 0;
    }

    private function installCheck(bool $json): int
    {
        $result = (new InstallService($this->app->basePath()))->preflight();
        $text = implode("\n", array_map(static fn (array $c): string => sprintf('[%s] %s', $c['ok'] ? 'OK' : 'FAIL', $c['name']), $result['checks'])) . "\n";
        $this->output($result, $json, $text);
        return $result['ok'] ? 0 : 1;
    }

    private function installStatus(bool $json): int
    {
        $result = (new InstallService($this->app->basePath()))->state()->status();
        $this->output($result, $json, $result['installed'] ? "Installed (local marker).\n" : "Not installed (local marker missing; database state not verified).\n");
        return 0;
    }

    private function installRecover(): int
    {
        $result = (new InstallService($this->app->basePath()))->recover();
        fwrite(STDOUT, "Installation marker verified/recovered. Installed at: " . $result['installed_at'] . "\n");
        return 0;
    }

    private function webInstallToken(): int
    {
        $token = (new InstallService($this->app->basePath()))->state()->issueWebToken();
        fwrite(STDOUT, "Web installation token (shown once; store securely):\n" . $token . "\n");
        return 0;
    }

    private function databaseCheck(bool $json): int
    {
        $result = $this->app->container()->get(DatabaseManager::class)->ping();
        $this->output($result, $json, sprintf("Database connection OK (%s %s).\n", $result['driver'], $result['version']));
        return 0;
    }

    private function migrate(array $argv): int
    {
        foreach (array_slice($argv, 2) as $argument) {
            if (!in_array($argument, ['--dry-run', '--json'], true)) {
                fwrite(STDERR, "Usage: cajeer migrate [--dry-run] [--json]\n");
                return 2;
            }
        }
        $json = in_array('--json', $argv, true);
        $dryRun = in_array('--dry-run', $argv, true);
        $result = $this->app->container()->get(MigrationRunner::class)->run($dryRun);
        $message = $dryRun
            ? 'Pending migrations (dry-run): ' . (implode(', ', $result['pending']) ?: 'none') . "\n"
            : 'Applied migrations: ' . (implode(', ', $result['applied']) ?: 'none') . "\n";
        $this->output($result, $json, $message);
        return 0;
    }

    private function migrationStatus(bool $json): int
    {
        $rows = $this->app->container()->get(MigrationRunner::class)->status();
        $text = '';
        foreach ($rows as $row) {
            $text .= sprintf("%-10s %s\n", strtoupper($row['status']), $row['version']);
        }
        $this->output(['migrations' => $rows], $json, $text !== '' ? $text : "No migrations found.\n");
        return array_intersect(['modified', 'missing', 'out_of_order'], array_column($rows, 'status')) !== [] ? 1 : 0;
    }

    private function migrationPending(bool $json): int
    {
        $result = $this->app->container()->get(MigrationRunner::class)->validate();
        if (!$result['valid']) {
            fwrite(STDERR, "Migration history is inconsistent; run migrate:validate.\n");
            return 1;
        }
        $names = array_values(array_map(static fn (array $row): string => $row['version'], array_filter(
            $result['rows'], static fn (array $row): bool => $row['status'] === 'pending',
        )));
        $this->output(['pending' => $names], $json, $names === [] ? "No pending migrations.\n" : implode("\n", $names) . "\n");
        return 0;
    }

    private function migrationValidate(bool $json): int
    {
        $result = $this->app->container()->get(MigrationRunner::class)->validate();
        $this->output($result, $json, $result['valid'] ? "Migration history valid.\n" : "Migration checksum/history mismatch.\n");
        return $result['valid'] ? 0 : 1;
    }

    private function output(array $result, bool $json, string $human): void
    {
        fwrite(STDOUT, $json ? json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" : $human);
    }

    private function unavailable(string $command): int
    {
        fwrite(STDERR, sprintf("Command '%s' is not implemented in 0.1.3. No operation was performed.\n", $command));
        return 3;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, sprintf("Unknown command '%s'. Run 'php bin/cajeer help'.\n", $command));
        return 2;
    }
}
