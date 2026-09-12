<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Cajeer\Servers\Kernel\Application;

return new Application(dirname(__DIR__));
