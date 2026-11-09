<?php
declare(strict_types=1);

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'Web installer is unavailable in 0.1.1. CLI runtime check: php bin/cajeer install:check';
