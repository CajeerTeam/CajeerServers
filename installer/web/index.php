<?php
declare(strict_types=1);
// The installer is intentionally exposed ONLY through public/index.php at /install.
// Never make the project root or installer/web a public document root.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "Use /install through the public/ front controller.\n";
