#!/usr/bin/env php
<?php
declare(strict_types=1);

// Compatibility entrypoint delegates to real CLI dispatcher.
$argv = [__DIR__ . '/cajeer', 'queue:work', ...array_slice($_SERVER['argv'], 1)];
require __DIR__ . '/cajeer';
