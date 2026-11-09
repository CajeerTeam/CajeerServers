<?php
declare(strict_types=1);

namespace Cajeer\Servers\Storage;

use InvalidArgumentException;
use RuntimeException;

/** Path-style S3-compatible SigV4 client (PUT/GET/HEAD/DELETE), HTTPS by default. */
final class S3CompatibleDisk implements StorageDiskInterface
{
    private string $endpoint;
    private string $host;
    private string $basePath;

    public function __construct(
        string $endpoint,
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $sessionToken = '',
        bool $allowLocalHttp = false,
        private readonly int $timeoutSeconds = 10,
    ) {
        $url = parse_url($endpoint);
        if (!is_array($url) || !isset($url['scheme'], $url['host']) || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment'])) {
            throw new InvalidArgumentException('Invalid S3 endpoint.');
        }
        $scheme = strtolower($url['scheme']);
        $hostname = strtolower($url['host']);
        $loopback = in_array($hostname, ['127.0.0.1','localhost','::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowLocalHttp && $loopback)) {
            throw new InvalidArgumentException('S3 endpoint requires HTTPS (or explicitly permitted loopback HTTP).');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) || str_contains($bucket, '..')
            || !preg_match('/^[a-z0-9-]{2,32}$/D', $region)
            || $accessKey === '' || $secretKey === '' || $timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new InvalidArgumentException('Invalid S3 configuration.');
        }
        $port = isset($url['port']) ? ':' . $url['port'] : '';
        $this->host = (str_contains($hostname, ':') ? '[' . $hostname . ']' : $hostname) . $port;
        $this->basePath = rtrim($url['path'] ?? '', '/');
        if (preg_match('/[\x00-\x1F\x7F]/', $this->basePath) || str_contains($this->basePath, '..')) {
            throw new InvalidArgumentException('Unsafe S3 endpoint path.');
        }
        $this->endpoint = $scheme . '://' . $this->host;
    }

    public function put(string $key, string $contents): void
    {
        if (strlen($contents) > 16 * 1024 * 1024) throw new InvalidArgumentException('Storage object exceeds 16 MiB.');
        [$status] = $this->request('PUT', $key, $contents);
        if ($status !== 200 && $status !== 201) throw new RuntimeException('S3 write failed (HTTP ' . $status . ').');
    }

    public function get(string $key): string
    {
        [$status, $body] = $this->request('GET', $key);
        if ($status === 404) throw new RuntimeException('Storage object not found.');
        if ($status !== 200) throw new RuntimeException('S3 read failed (HTTP ' . $status . ').');
        return $body;
    }

    public function delete(string $key): bool
    {
        [$status] = $this->request('DELETE', $key);
        if ($status === 404) return false;
        if (!in_array($status, [200, 204], true)) throw new RuntimeException('S3 delete failed (HTTP ' . $status . ').');
        return true; // S3 DELETE is idempotent; existing key cannot be inferred from 204.
    }

    public function exists(string $key): bool
    {
        [$status] = $this->request('HEAD', $key);
        if ($status === 404) return false;
        if ($status !== 200) throw new RuntimeException('S3 HEAD failed (HTTP ' . $status . ').');
        return true;
    }

    /** @return array{int,string} */
    private function request(string $method, string $key, string $body = ''): array
    {
        StorageKey::validate($key);
        $path = $this->basePath . '/' . rawurlencode($this->bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $date = gmdate('Ymd\THis\Z');
        $short = substr($date, 0, 8);
        $hash = hash('sha256', $body);
        $headers = [
            'host' => $this->host,
            'x-amz-content-sha256' => $hash,
            'x-amz-date' => $date,
        ];
        if ($this->sessionToken !== '') $headers['x-amz-security-token'] = $this->sessionToken;
        ksort($headers, SORT_STRING);
        $signed = implode(';', array_keys($headers));
        $canonical = '';
        foreach ($headers as $name => $value) $canonical .= $name . ':' . trim($value) . "\n";
        $scope = $short . '/' . $this->region . '/s3/aws4_request';
        $request = implode("\n", [$method, $path, '', $canonical, $signed, $hash]);
        $stringToSign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $request);
        $kDate = hash_hmac('sha256', $short, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signing = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signing);
        $rawHeaders = [];
        foreach ($headers as $name => $value) $rawHeaders[] = $name . ': ' . $value;
        $rawHeaders[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
        $rawHeaders[] = 'Connection: close';
        if ($method === 'PUT') $rawHeaders[] = 'Content-Type: application/octet-stream';
        $opts = [
            'http' => [
                'method' => $method, 'header' => implode("\r\n", $rawHeaders) . "\r\n",
                'timeout' => $this->timeoutSeconds, 'follow_location' => 0,
                'ignore_errors' => true, 'protocol_version' => 1.1,
                'content' => $method === 'PUT' ? $body : '',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];
        $stream = @fopen($this->endpoint . $path, 'rb', false, stream_context_create($opts));
        if ($stream === false) throw new RuntimeException('S3 network request failed.');
        try {
            $metadata = stream_get_meta_data($stream);
            $response = $metadata['wrapper_data'] ?? [];
            $statusLine = is_array($response) ? ($response[0] ?? '') : '';
            if (!preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})\b~', (string) $statusLine, $matches)) throw new RuntimeException('Invalid S3 response.');
            $status = (int) $matches[1];
            if ($method === 'HEAD') return [$status, ''];
            $content = stream_get_contents($stream, 16 * 1024 * 1024 + 1);
            if ($content === false || strlen($content) > 16 * 1024 * 1024) throw new RuntimeException('S3 response exceeds size limit.');
            return [$status, $content];
        } finally { fclose($stream); }
    }
}
