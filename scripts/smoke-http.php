#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Real deployed HTTP smoke suite. Never performs mutations or uses credentials. */
$base = getenv('CAJEER_SMOKE_URL') ?: '';
$parts = parse_url($base);
if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http','https'], true)
    || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || !in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query']) || isset($parts['fragment'])) {
    fwrite(STDERR, "Set CAJEER_SMOKE_URL to the deployed https://host origin.\n"); exit(2);
}
if ($parts['scheme'] !== 'https' && !in_array(strtolower($parts['host']), ['127.0.0.1','localhost','::1'], true)) {
    fwrite(STDERR, "Remote deployed endpoints must be tested over HTTPS.\n"); exit(2);
}
$base = rtrim($base, '/');
$checks = [
    ['GET','/api/v1/system',200,true],
    ['GET','/api/v1/live',200,true],
    ['GET','/api/v1/ready',200,true],
    ['GET','/api/v1/admin/session',401,true],
    ['GET','/api/v1/metrics',[401,404],false],
    ['GET','/api/v1/no-such-path',404,true],
];
$failed = 0;
foreach ($checks as [$method,$path,$expected,$json]) {
    $context = stream_context_create([
        'http' => ['method'=>$method, 'header'=>"Accept: application/json\r\n", 'timeout'=>8,
            'ignore_errors'=>true, 'follow_location'=>0, 'max_redirects'=>0],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
    ]);
    $body = @file_get_contents($base . $path, false, $context);
    $headers = $http_response_header ?? [];
    $line = $headers[0] ?? '';
    $code = preg_match('~^HTTP/\S+\s+(\d{3})(?:\s|$)~', $line, $m) === 1 ? (int)$m[1] : 0;
    $normal = [];
    foreach ($headers as $header) {
        if (str_contains($header, ':')) {
            [$name,$value] = explode(':', $header, 2);
            $normal[strtolower(trim($name))] = trim($value);
        }
    }
    $valid = $body !== false && in_array($code, is_array($expected) ? $expected : [$expected], true)
        && strtolower($normal['x-content-type-options'] ?? '') === 'nosniff'
        && strtolower($normal['cache-control'] ?? '') === 'no-store'
        && ($json === false || (is_array(json_decode((string)$body,true)) && str_contains(strtolower($normal['content-type'] ?? ''),'json')))
        && (!isset($normal['set-cookie']))
        && ($parts['scheme'] !== 'https' || isset($normal['strict-transport-security']));
    if ($path === '/api/v1/system') {
        $meta = json_decode((string)$body, true);
        $candidate = getenv('CAJEER_CANDIDATE_ID');
        $valid = $valid && ($meta['version'] ?? null) === trim((string)file_get_contents(dirname(__DIR__).'/VERSION'))
            && ($meta['api_version'] ?? null) === 'v1'
            && (!$candidate || (preg_match('/^[a-f0-9]{64}$/D', $candidate) && ($meta['candidate_id'] ?? null) === $candidate));
    }
    fwrite(STDOUT, sprintf("[%s] %s %s => %d (expected %s)\n", $valid ? 'OK' : 'FAIL', $method, $path, $code, implode('/', array_map('strval', is_array($expected) ? $expected : [$expected]))));
    if (!$valid) $failed++;
}
exit($failed === 0 ? 0 : 1);
