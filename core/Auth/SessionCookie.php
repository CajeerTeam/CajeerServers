<?php
declare(strict_types=1);

namespace Cajeer\Servers\Auth;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Runtime\HttpRequest;
use RuntimeException;

final class SessionCookie
{
    private readonly string $name;
    private readonly bool $secure;

    public function __construct(ConfigRepository $config)
    {
        $url = parse_url((string) $config->get('app.url', ''));
        $this->secure = is_array($url) && ($url['scheme'] ?? '') === 'https';
        if ((string) $config->get('app.env', 'production') === 'production' && !$this->secure) {
            throw new RuntimeException('Production sessions require HTTPS APP_URL.');
        }
        $this->name = $this->secure ? '__Host-cajeer_session' : 'cajeer_session';
    }

    public function read(HttpRequest $request): ?string
    {
        $header = $request->header('Cookie', '');
        if ($header === '' || strlen($header) > 8192) return null;
        $found = null;
        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || $pair[0] !== $this->name) continue;
            if ($found !== null || strlen($pair[1]) > 256) return null;
            $found = $pair[1];
        }
        return $found;
    }

    public function set(string $credential): string
    {
        if (TokenCodec::parse($credential, 'cs1') === null) throw new \InvalidArgumentException('Invalid session credential.');
        return $this->name . '=' . $credential . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=43200' . ($this->secure ? '; Secure' : '');
    }

    public function clear(): string
    {
        return $this->name . '=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT' . ($this->secure ? '; Secure' : '');
    }
}
