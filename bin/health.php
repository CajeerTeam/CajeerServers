#!/usr/bin/env php
<?php
declare(strict_types=1);

$argv = [__FILE__, 'health:ready', ...array_slice($argv ?? [], 1)];
require __DIR__ . '/cajeer';
