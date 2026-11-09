<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Logging\JsonLogger;
use Throwable;

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
                'config:check', 'env:check', 'doctor', 'install:check' => $this->doctor(in_array('--json', $argv, true)),
                'self:test' => $this->selfTest(),
                'migrate', 'queue:work', 'scheduler:run', 'connector:sync', 'scripts:run',
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
CajeerServers CLI 0.1.2

Рабочие команды:
  help                 Список доступных команд
  version              Версия приложения
  about                Информация об установленном runtime
  env:check            Проверка конфигурации окружения и runtime
  config:check         То же, что env:check
  config:get <key>     Прочитать несекретное значение конфигурации
  doctor [--json]      Проверить PHP, расширения, storage и конфигурацию
  install:check        Предварительная проверка runtime (не установщик)
  self:test            Самопроверка bootstrap, DI и конфигурации

Команды будущих релизов (migrate, queue:work и др.) возвращают ошибку.
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

    private function unavailable(string $command): int
    {
        fwrite(STDERR, sprintf("Command '%s' is not implemented in 0.1.2. No operation was performed.\n", $command));
        return 3;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, sprintf("Unknown command '%s'. Run 'php bin/cajeer help'.\n", $command));
        return 2;
    }
}
