<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/v1/traces') {
    http_response_code(404);
    exit;
}
$path = getenv('CAJEER_OTLP_CAPTURE_PATH');
if (!is_string($path) || $path === '' || !str_starts_with($path, sys_get_temp_dir() . '/')) {
    http_response_code(500);
    exit;
}
$data = file_get_contents('php://input');
if (!is_string($data) || strlen($data) > 100000 || !is_array(json_decode($data, true))) {
    http_response_code(400);
    exit;
}
file_put_contents($path, $data, LOCK_EX);
http_response_code(202);
header('Content-Type: application/json');
echo '{}';
