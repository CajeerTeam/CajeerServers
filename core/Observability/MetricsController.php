<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** Opt-in, token-protected scrape endpoint: no sensitive unauthenticated monitoring data. */
final class MetricsController
{
    public function __construct(private readonly ConfigRepository $config, private readonly PrometheusExporter $exporter, private readonly Diagnostics $diagnostics) {}

    public function __invoke(HttpRequest $request): HttpResponse
    {
        $enabled = $this->config->get('observability.prometheus_enabled', false) === true;
        $token = (string) $this->config->get('observability.prometheus_token', '');
        if (!$enabled || strlen($token) < 32) return HttpResponse::json(['error' => 'Not Found'], 404);
        $auth = $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ') || !hash_equals($token, substr($auth, 7))) {
            return HttpResponse::json(['error' => 'Unauthorized'], 401, ['WWW-Authenticate' => 'Bearer realm="cajeer-metrics"']);
        }
        if ($this->config->get('app.env', 'production') === 'production' && !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
            return HttpResponse::json(['error' => 'HTTPS Required'], 403);
        }
        return new HttpResponse(200, $this->exporter->render($this->diagnostics->run()), [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
