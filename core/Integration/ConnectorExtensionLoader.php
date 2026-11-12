<?php
declare(strict_types=1);
namespace Cajeer\Servers\Integration;
use Cajeer\Servers\Connector\{ConnectorInterface,HttpsTransport};
use RuntimeException;
/** Operator-installed trusted code, not a sandbox. Never accepts a path from the API. */
final class ConnectorExtensionLoader
{
    public const SDK_VERSION=1;
    public static function load(ConnectorRegistry $registry,string $file,HttpsTransport $transport):ConnectorRegistry
    {
        if ($file==='') return $registry;
        if (!str_starts_with($file,'/') || !is_file($file)||!is_readable($file)) throw new RuntimeException('Trusted connector extension file is invalid.');
        $factory=require $file;
        if (!$factory instanceof \Closure) throw new RuntimeException('Connector extension must return a factory.');
        $driver=$factory($transport,self::SDK_VERSION);
        if (!$driver instanceof ConnectorInterface || $driver->manifest()->provider!=='custom') throw new RuntimeException('Extension must implement the custom connector driver.');
        // SDK 1 permits a single custom provider; its write actions are not exposed.
        if ($driver instanceof \Cajeer\Servers\Connector\ConnectorActionDriver) throw new RuntimeException('Custom write actions are not supported.');
        $registry->register($driver);
        return $registry;
    }
}
