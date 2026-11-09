<?php
declare(strict_types=1);

namespace Cajeer\Servers\Release;

use JsonException;
use RuntimeException;

/** Verify the actual source tree, metadata and dependency artifacts before a release is deployed. */
final class ReleaseVerifier
{
    public function __construct(private readonly string $root) {}

    /** @return array{ok:bool,version:string,checks:list<array{name:string,ok:bool,detail:string}>} */
    public function verify(bool $deployment = false): array
    {
        $checks = [];
        $check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $version = trim($this->read('VERSION') ?? '');
        $check('semver', preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D', $version) === 1,
            'VERSION must be SemVer.');
        $release = $this->json('release.json');
        $admin = $this->json('admin/package.json');
        $composer = $this->json('composer.json');
        $check('release_version', ($release['version'] ?? null) === $version, 'release.json must match VERSION.');
        $check('admin_version', ($admin['version'] ?? null) === $version, 'Nuxt package version must match VERSION.');
        $check('composer_project', ($composer['name'] ?? null) === 'cajeer/cajeerservers', 'Composer project metadata must be consistent.');

        $api = $this->read('api/openapi.yaml');
        $secondary = $this->read('openapi.yaml');
        $check('openapi_copies', $api !== null && hash_equals(hash('sha256', $api), hash('sha256', (string) $secondary)),
            'Both OpenAPI documents must be identical.');
        $apiVersion = null;
        if ($api !== null && preg_match('/^info:\s*\R(?:(?:^[ \t]+.*\R)|(?:^\s*\R))*?^[ \t]+version:\s*[\'\"]?([^\s\'\"]+)/m', $api, $match) === 1) {
            $apiVersion = $match[1];
        }
        $check('openapi_version', $apiVersion === $version && str_contains((string)$api, 'openapi: 3.1.1'),
            'OpenAPI must match VERSION and use 3.1.1.');
        foreach (['public/index.php', 'core/bootstrap.php', 'bin/cajeer', 'routes/api.php',
                  'migrations/pgsql/0007_admin_ui_permissions.sql', 'migrations/pgsql/0008_inventory_readiness.sql',
                  'core/Inventory/InventoryReadiness.php', 'migrations/pgsql/0009_organizations_workspaces.sql',
                  'core/Workspace/WorkspaceService.php', 'core/Workspace/WorkspaceController.php', 'admin/nuxt.config.ts'] as $file) {
            $check('source:' . $file, is_file($this->root . '/' . $file) && is_readable($this->root . '/' . $file), 'Required source file is available.');
        }
        // Strict deployment checks are intentionally not bypassed by the kernel's native autoload fallback.
        if ($deployment) {
            $composerLock = $this->json('composer.lock');
            $packageLock = $this->json('admin/package-lock.json');
            $composerPackages = $composerLock['packages'] ?? null;
            $nodePackages = $packageLock['packages'] ?? null;
            $check('composer_lock', is_array($composerPackages) && count($composerPackages) > 0
                && preg_match('/^[a-f0-9]{32}$/D', (string)($composerLock['content-hash'] ?? '')) === 1,
                'A resolved Composer lockfile is required for repeatable installation.');
            $check('npm_lock', is_array($nodePackages) && count($nodePackages) > 1
                && in_array($packageLock['lockfileVersion'] ?? null, [2, 3], true)
                && ($nodePackages['']['version'] ?? null) === $version,
                'A resolved npm lockfile matching the admin package is required.');
            $check('composer_installed', is_file($this->root . '/vendor/autoload.php'),
                'Composer dependencies must be installed before production rollout.');
            $check('admin_built', is_file($this->root . '/admin/.output/server/index.mjs'),
                'Nuxt production SSR bundle must be built before rollout.');
        }
        return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'version' => $version, 'checks' => $checks];
    }

    private function read(string $relative): ?string
    {
        $path = $this->root . '/' . $relative;
        if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
        $text = @file_get_contents($path);
        return is_string($text) ? $text : null;
    }

    /** @return array<string,mixed> */
    private function json(string $relative): array
    {
        $text = $this->read($relative);
        if ($text === null) return [];
        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }
}
