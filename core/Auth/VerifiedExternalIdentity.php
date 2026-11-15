<?php
declare(strict_types=1);

namespace Cajeer\Servers\Auth;

/** Internal handoff from a trusted provider adapter AFTER signature, issuer, audience,
 * nonce and protocol transaction verification. Never hydrate from request JSON/claims.
 * No provider is registered or exposed by this release.
 */
final readonly class VerifiedExternalIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $assertionDigest,
        public int $issuedAt,
        public int $expiresAt,
    ) {
        $url = parse_url($issuer);
        if (strlen($issuer) > 2048 || !is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || preg_match('/[\x00-\x20\x7f]/', $issuer)
            || $subject === '' || strlen($subject) > 255 || preg_match('/[\x00-\x1f\x7f]/', $subject)
            || preg_match('/^[a-f0-9]{64}$/D', $assertionDigest) !== 1
            || $issuedAt <= 0 || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('Invalid verified external identity.');
        }
    }

    /** Limit the exchange window independently of the provider token lifetime. */
    public function exchangeExpiresAt(): int
    {
        return min($this->expiresAt, $this->issuedAt + 300);
    }

    public function isFresh(int $now): bool
    {
        return $this->issuedAt <= $now + 30 && $this->exchangeExpiresAt() > $now;
    }
}
