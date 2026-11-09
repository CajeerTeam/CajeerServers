<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use PDO;
use PDOException;

/** Durable signed webhook inbox. Stores verified events; no untrusted code is executed. */
final class WebhookManager
{
    public function __construct(private readonly DatabaseManager $db, private readonly SecretBox $secrets,
                                private readonly AuditLogger $audit, private readonly RateLimiter $limiter) {}

    /** @param array<string,mixed> $principal */
    public function create(array $principal, string $name): array
    {
        $name = trim($name);
        if (strlen($name) < 1 || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new HttpException(422, 'Invalid webhook source name.', 'validation_failed');
        }
        return $this->db->transaction(function (PDO $pdo) use ($principal, $name): array {
            $id = $pdo->query('SELECT gen_random_uuid()::text')->fetchColumn();
            if (!is_string($id)) throw new \RuntimeException('Cannot generate webhook ID.');
            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $cipher = $this->secrets->seal($secret, 'webhook:' . $id);
            $stmt = $pdo->prepare('INSERT INTO webhook_sources (id,name,secret_ciphertext,created_by) VALUES (:id,:name,decode(:cipher,\'hex\'),:user)');
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':cipher', bin2hex($cipher));
            $stmt->bindValue(':user', $principal['user']['id']);
            $stmt->execute();
            $this->audit->record($pdo, $principal['user']['id'], 'security.webhook.created', 'success', 'webhook_source', $id);
            return ['id' => $id, 'name' => $name, 'secret' => $secret];
        });
    }

    /** @return list<array<string,mixed>> */
    public function sources(): array
    {
        return $this->db->connection()->query('SELECT id::text,name,enabled,created_at::text,revoked_at::text FROM webhook_sources ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $principal */
    public function revoke(array $principal, string $id): void
    {
        $this->db->transaction(function (PDO $pdo) use ($principal, $id): void {
            $stmt = $pdo->prepare('UPDATE webhook_sources SET revoked_at=now(),enabled=false WHERE id=:id AND revoked_at IS NULL');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() !== 1) throw new HttpException(404, 'Webhook source not found.', 'not_found');
            $this->audit->record($pdo, $principal['user']['id'], 'security.webhook.revoked', 'success', 'webhook_source', $id);
        });
    }

    /** @return array{id:string,status:string} */
    public function ingest(string $sourceId, HttpRequest $request): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/iD', $sourceId)) throw new HttpException(404, 'Not found.', 'not_found');
        $type = strtolower(trim(explode(';', $request->header('Content-Type', ''), 2)[0]));
        if ($type !== 'application/json' || strlen($request->body) < 2 || strlen($request->body) > 262144) {
            throw new HttpException(415, 'A JSON body is required (max 256 KiB).', 'invalid_content_type');
        }
        try { $payload = json_decode($request->body, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(400, 'Invalid JSON.', 'invalid_json'); }
        if (!is_object($payload)) throw new HttpException(422, 'Expected JSON object.', 'validation_failed');
        $source = $this->db->connection()->prepare('SELECT secret_ciphertext FROM webhook_sources WHERE id=:id AND enabled=true AND revoked_at IS NULL');
        $source->execute(['id' => $sourceId]);
        $stored = $source->fetchColumn();
        if ($stored === false) throw new HttpException(401, 'Invalid webhook credentials.', 'invalid_signature');
        // Write the limiter counter independently; invalid signatures cannot roll it back.
        $allowed = $this->db->transaction(fn (PDO $pdo): bool => $this->limiter->hit($pdo, 'webhook', $sourceId, 120, 60));
        if (!$allowed) throw new HttpException(429, 'Webhook rate limit exceeded.', 'rate_limited', ['Retry-After' => '60']);
        $stored = $this->pgBytea($stored);
        $secret = $this->secrets->open($stored, 'webhook:' . $sourceId);
        try {
            $digest = SignedPayload::verify($request->body, $secret, $request->header('X-Cajeer-Timestamp', ''), $request->header('X-Cajeer-Signature', ''));
        } catch (\InvalidArgumentException) {
            throw new HttpException(401, 'Invalid webhook credentials.', 'invalid_signature');
        }
        try {
            return $this->db->transaction(function (PDO $pdo) use ($sourceId, $payload, $digest): array {
                // Recheck revocation after signature verification (TOCTOU defense).
                $check = $pdo->prepare('SELECT 1 FROM webhook_sources WHERE id=:id AND enabled=true AND revoked_at IS NULL FOR SHARE');
                $check->execute(['id' => $sourceId]);
                if (!$check->fetchColumn()) throw new HttpException(401, 'Invalid webhook credentials.', 'invalid_signature');
                $stmt = $pdo->prepare('INSERT INTO webhook_events (source_id,replay_digest,payload) VALUES (:source,:digest,CAST(:payload AS jsonb)) RETURNING id::text');
                $stmt->execute(['source' => $sourceId, 'digest' => $digest, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                $id = $stmt->fetchColumn();
                if (!is_string($id)) throw new \RuntimeException('Webhook event could not be stored.');
                $this->audit->record($pdo, null, 'security.webhook.received', 'success', 'webhook_source', $sourceId);
                return ['id' => $id, 'status' => 'received'];
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? $error->getCode()) === '23505') {
                throw new HttpException(409, 'Webhook replay rejected.', 'replayed_event');
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function events(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) throw new HttpException(422, 'Invalid limit.', 'validation_failed');
        $query = $this->db->connection()->prepare('SELECT id::text,source_id::text,received_at::text FROM webhook_events ORDER BY received_at DESC LIMIT :limit');
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pgBytea(mixed $value): string
    {
        if (is_resource($value)) return stream_get_contents($value) ?: '';
        if (!is_string($value)) throw new \RuntimeException('Corrupted secret storage.');
        if (str_starts_with($value, '\\x')) {
            $result = hex2bin(substr($value, 2));
            if ($result === false) throw new \RuntimeException('Corrupted secret encoding.');
            return $result;
        }
        return $value;
    }
}
