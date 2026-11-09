<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use PDO;

/** Durable PostgreSQL fixed-window limiter. Invoke inside a committed transaction. */
final class RateLimiter
{
    public function __construct(private readonly SecretBox $secrets) {}

    public function hit(PDO $pdo, string $category, string $subject, int $limit, int $seconds): bool
    {
        if (preg_match('/^[a-z0-9_.-]{1,60}$/D', $category) !== 1
            || $subject === '' || $limit < 1 || $limit > 10000 || $seconds < 1 || $seconds > 86400) {
            throw new \InvalidArgumentException('Invalid rate limit.');
        }
        $hash = $this->secrets->digest($subject, 'limiter:' . $category);
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO security_rate_limits (subject_hash, hits, expires_at)
VALUES (:hash, 1, now() + CAST(:initial_window AS integer) * interval '1 second')
ON CONFLICT (subject_hash) DO UPDATE SET
 hits = CASE WHEN security_rate_limits.expires_at <= now() THEN 1 ELSE security_rate_limits.hits + 1 END,
 expires_at = CASE WHEN security_rate_limits.expires_at <= now() THEN now() + CAST(:reset_window AS integer) * interval '1 second' ELSE security_rate_limits.expires_at END
RETURNING hits
SQL);
        $stmt->execute(['hash' => $hash, 'initial_window' => $seconds, 'reset_window' => $seconds]);
        return (int) $stmt->fetchColumn() <= $limit;
    }
}
