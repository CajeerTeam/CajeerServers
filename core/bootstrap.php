<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use Cajeer\Servers\Kernel\Application;

return (new Application(dirname(__DIR__)))->boot();
