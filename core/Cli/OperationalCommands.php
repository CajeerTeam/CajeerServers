<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cli;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Observability\Diagnostics;
use Cajeer\Servers\Observability\HealthCheck;
use Cajeer\Servers\Observability\PrometheusExporter;
use Cajeer\Servers\Observability\SystemReport;
use Cajeer\Servers\Observability\OperationalStats;
use RuntimeException;

final class OperationalCommands
{
    public function __construct(private readonly Application $app) {}

    /** @param list<string> $argv */
    public function run(string $command, array $argv): int
    {
        $options = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (!preg_match('/^--(json|deep|queue|days|limit|apply)(?:=(.*))?$/D', $arg, $matches)) {
                throw new \InvalidArgumentException('Unknown operational CLI option.');
            }
            if (isset($options[$matches[1]])) throw new \InvalidArgumentException('Duplicate operational CLI option.');
            if (in_array($matches[1], ['json','deep','apply'], true) && isset($matches[2])) throw new \InvalidArgumentException('Flag does not accept a value.');
            if (in_array($matches[1], ['queue','days','limit'], true) && !isset($matches[2])) throw new \InvalidArgumentException('Option requires a value.');
            $options[$matches[1]] = $matches[2] ?? true;
        }
        $container = $this->app->container();
        $json = isset($options['json']);
        $deep = isset($options['deep']);
        return match ($command) {
            'health:live' => $this->print($container->get(HealthCheck::class)->live(), $json),
            'health:ready', 'health' => $this->status($container->get(HealthCheck::class)->ready(), $json),
            'doctor:deep' => $this->status($container->get(Diagnostics::class)->run(true), $json),
            'system:report' => $this->statusReport($container->get(SystemReport::class)->generate($deep)),
            'metrics:render' => $this->metrics($container->get(PrometheusExporter::class), $container->get(Diagnostics::class)),
            'openapi:lint' => $this->lint(),
            'queue:stats' => $this->print($container->get(OperationalStats::class)->queue((string)($options['queue'] ?? 'default'))),
            'scheduler:stats' => $this->print($container->get(OperationalStats::class)->scheduler()),
            'security:prune' => $this->print($container->get(OperationalStats::class)->pruneSecurity(
                $this->integer($options, 'days', 30, 7, 3650), $this->integer($options, 'limit', 500, 1, 5000), isset($options['apply']))),
            default => throw new RuntimeException('Unsupported operational command.'),
        };
    }

    private function integer(array $options, string $name, int $default, int $min, int $max): int
    {
        $value = (string)($options[$name] ?? $default);
        if (!ctype_digit($value) || strlen($value) > 9 || (int)$value < $min || (int)$value > $max) {
            throw new \InvalidArgumentException('Invalid --' . $name . ' value.');
        }
        return (int)$value;
    }

    private function print(array $data, bool $pretty = true): int
    {
        fwrite(STDOUT, json_encode(JsonLogger::redact($data), JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    }

    private function status(array $report, bool $json): int
    {
        $this->print($report, $json);
        return ($report['status'] ?? '') === 'ready' || ($report['status'] ?? '') === 'alive' ? 0 : 1;
    }

    private function statusReport(array $report): int
    {
        $this->print($report);
        return ($report['runtime']['ready'] ?? false) === true && ($report['dependencies']['ok'] ?? false) === true ? 0 : 1;
    }

    private function metrics(PrometheusExporter $exporter, Diagnostics $diagnostics): int
    {
        fwrite(STDOUT, $exporter->render($diagnostics->run()));
        return 0;
    }

    private function lint(): int
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class) && !function_exists('yaml_parse_file')) {
            fwrite(STDERR, "OpenAPI lint requires symfony/yaml (composer install) or ext-yaml.\n");
            return 3;
        }
        $specs = [$this->app->basePath('api/openapi.yaml'), $this->app->basePath('openapi.yaml')];
        if (!hash_equals(hash_file('sha256', $specs[0]), hash_file('sha256', $specs[1]))) {
            throw new RuntimeException('OpenAPI copies differ.');
        }
        $spec = class_exists(\Symfony\Component\Yaml\Yaml::class)
            ? \Symfony\Component\Yaml\Yaml::parseFile($specs[0]) : yaml_parse_file($specs[0]);
        if (!is_array($spec) || ($spec['openapi'] ?? '') !== '3.1.1'
            || ($spec['info']['version'] ?? null) !== $this->app->version()
            || !is_array($spec['paths'] ?? null)) {
            throw new RuntimeException('Invalid OpenAPI 3.1.1 document.');
        }
        $paths = 0;
        $operations = 0;
        foreach ($spec['paths'] as $path => $item) {
            if (!is_string($path) || !str_starts_with($path, '/') || !is_array($item)) throw new RuntimeException('Invalid OpenAPI path.');
            $paths++;
            foreach ($item as $verb => $operation) {
                if (!in_array(strtolower((string)$verb), ['get','post','put','patch','delete','head','options','trace','parameters','summary','description','servers'], true)) {
                    throw new RuntimeException('Unknown OpenAPI path operation.');
                }
                if (in_array($verb, ['get','post','put','patch','delete','head','options','trace'], true)) {
                    if (!is_array($operation) || !is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                        throw new RuntimeException('Operation missing responses.');
                    }
                    $operations++;
                }
            }
        }
        $references = 0;
        $this->validateReferences($spec, $spec, $references);
        $this->print(['valid' => true, 'version' => $spec['openapi'], 'paths' => $paths, 'operations' => $operations, 'local_references' => $references]);
        return 0;
    }

    /** Verify all local JSON Pointer references; external network references are deliberately rejected. */
    private function validateReferences(array $node, array $document, int &$count, int $depth = 0): void
    {
        if ($depth > 80 || ++$count > 50000) throw new RuntimeException('OpenAPI document exceeds validation limit.');
        if (isset($node['$ref'])) {
            $ref = $node['$ref'];
            if (!is_string($ref) || !str_starts_with($ref, '#/')) throw new RuntimeException('Unsupported external OpenAPI reference.');
            $cursor = $document;
            foreach (explode('/', substr($ref, 2)) as $encoded) {
                $part = str_replace(['~1','~0'], ['/','~'], $encoded);
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) throw new RuntimeException('Broken OpenAPI local reference.');
                $cursor = $cursor[$part];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) $this->validateReferences($value, $document, $count, $depth + 1);
        }
    }

}
