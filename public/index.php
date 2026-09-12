<?php
$app = require dirname(__DIR__) . '/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'name' => $app->name(),
    'version' => $app->version(),
    'type' => 'api-first web control shell',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
