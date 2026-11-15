<?php
declare(strict_types=1);

namespace Cajeer\Servers\Api\Controller;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** Public version metadata only: no host paths, PHP configuration or credentials. */
final class SystemController
{
    public function __construct(private readonly Application $app) {}

    public function index(HttpRequest $request): HttpResponse
    {
        $data = [
            'name' => $this->app->name(),
            'version' => $this->app->version(),
            'api_version' => 'v1',
            'type' => 'api-first web control shell',
        ];
        // Only the opaque identity of the sealed release is public. Never expose its file list.
        $manifest = $this->app->basePath('.release-candidate.json');
        if (is_file($manifest) && !is_link($manifest)) {
            $raw = @file_get_contents($manifest, false, null, 0, 16 * 1024 * 1024 + 1);
            if (is_string($raw) && strlen($raw) <= 16 * 1024 * 1024) {
                $meta = json_decode($raw, true);
                if (is_array($meta) && ($meta['format'] ?? null) === 1
                    && ($meta['version'] ?? null) === $this->app->version()
                    && is_array($meta['files'] ?? null) && !empty($meta['files'])
                    && is_string($meta['sbom_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $meta['sbom_sha256'])
                    && is_string($meta['commit'] ?? null) && preg_match('/^[a-f0-9]{40,64}$/D', $meta['commit'])) {
                    $data['candidate_id'] = hash('sha256', $raw);
                }
            }
        }
        return HttpResponse::json($data);
    }
}
