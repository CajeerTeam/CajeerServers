<?php
declare(strict_types=1);

namespace Cajeer\Servers\Runtime;

use Cajeer\Servers\Logging\JsonLogger;
use ErrorException;
use Throwable;

/** Global error boundary, activated only by an application entrypoint. */
final class ErrorHandler
{
    private bool $active = false;
    private string $mode = 'cli';
    private ?string $requestId = null;

    public function __construct(private readonly JsonLogger $logger, private readonly bool $debug)
    {
    }

    public function mode(string $mode, ?string $requestId = null): void
    {
        if (!in_array($mode, ['cli', 'http'], true)) {
            throw new \InvalidArgumentException('Unknown runtime mode.');
        }
        $this->mode = $mode;
        $this->requestId = $requestId;
    }

    public function register(): void
    {
        if ($this->active) {
            return;
        }
        $this->active = true;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler(function (Throwable $error): void {
            if ($this->mode === 'http') {
                $this->respondHttp($error);
                return;
            }
            fwrite(STDERR, $this->cliMessage($error));
            exit(1);
        });
        register_shutdown_function(function (): void {
            if (!$this->active) {
                return;
            }
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            $this->record(new ErrorException((string) $error['message'], 0, (int) $error['type'], (string) $error['file'], (int) $error['line']));
            if ($this->mode === 'http' && !headers_sent()) {
                HttpResponse::json(['error' => 'Internal Server Error', 'request_id' => $this->requestId], 500)->send();
            }
        });
    }

    public function unregister(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        restore_error_handler();
        restore_exception_handler();
    }

    public function respondHttp(Throwable $error): void
    {
        $this->record($error);
        $payload = [
            'error' => 'Internal Server Error',
            'request_id' => $this->requestId,
        ];
        if ($this->debug) {
            $payload['detail'] = JsonLogger::redactString($error->getMessage());
            $payload['type'] = $error::class;
        }
        HttpResponse::json($payload, 500)->send();
    }

    public function cliMessage(Throwable $error): string
    {
        $this->record($error);
        if ($this->debug) {
            return '[ERROR] ' . $error::class . ': ' . JsonLogger::redactString($error->getMessage()) . "\n";
        }
        return '[ERROR] Operation failed. See application logs.' . "\n";
    }

    private function record(Throwable $error): void
    {
        try {
            $this->logger->error('Uncaught runtime error', [
                'type' => $error::class,
                'detail' => $this->debug ? JsonLogger::redactString($error->getMessage()) : '[REDACTED]',
                'fingerprint' => hash('sha256', $error::class . ':' . $error->getMessage()),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'request_id' => $this->requestId,
            ]);
        } catch (Throwable) {
            error_log('CajeerServers: runtime error could not be written to application log.');
        }
    }
}
