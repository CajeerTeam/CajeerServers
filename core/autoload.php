<?php
declare(strict_types=1);

/**
 * Bootstrap autoloader for the Cajeer kernel. Composer remains authoritative
 * for third-party dependencies; the kernel can run its diagnostics before
 * Composer dependencies have been installed.
 */
$vendorLoader = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorLoader)) {
    require_once $vendorLoader;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Cajeer\\Servers\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === '' || str_contains($relative, "\0")) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once __DIR__ . '/helpers.php';
