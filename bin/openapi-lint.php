#!/usr/bin/env php
<?php
declare(strict_types=1);

$argv = [__FILE__, 'openapi:lint', ...array_slice($argv ?? [], 1)];
require __DIR__ . '/cajeer';
