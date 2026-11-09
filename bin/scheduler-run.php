#!/usr/bin/env php
<?php
declare(strict_types=1);

$argv = [__DIR__ . '/cajeer', 'scheduler:run', ...array_slice($_SERVER['argv'], 1)];
require __DIR__ . '/cajeer';
