<?php
declare(strict_types=1);
namespace Cajeer\Servers\Audit;

use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Append-only structured audit events; no request bodies or credentials are accepted. */
final class AuditLogger
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function record(PDO $pdo, ?string $actor, string $action, string $result,
                           ?string $targetType = null, ?string $targetId = null,
                           array $details = [], ?string $requestId = null): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,180}$/D', $action) !== 1
            || !in_array($result, ['success', 'denied', 'failure'], true)
            || ($targetType !== null && preg_match('/^[a-z_]{1,120}$/D', $targetType) !== 1)
            || ($requestId !== null && strlen($requestId) > 64)) {
            throw new \InvalidArgumentException('Invalid audit event.');
        }
        $metadata = [];
        // Explicit allowlist prevents accidental secret/token/password leakage.
        foreach (['role', 'permission', 'reason', 'method', 'source', 'count'] as $key) {
            if (isset($details[$key]) && (is_int($details[$key]) || is_bool($details[$key]) || is_string($details[$key]))) {
                $metadata[$key] = is_string($details[$key]) ? substr($details[$key], 0, 120) : $details[$key];
            }
        }
        $sql = 'INSERT INTO audit_log (actor_type,actor_id,action,target_type,target_id,result,payload,request_id) VALUES (:actor_type,:actor_id,:action,:target,:target_id,:result,CAST(:payload AS jsonb),:request_id)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'actor_type' => $actor === null ? 'anonymous' : 'user', 'actor_id' => $actor,
            'action' => $action, 'target' => $targetType, 'target_id' => $targetId,
            'result' => $result, 'payload' => json_encode($metadata, JSON_THROW_ON_ERROR), 'request_id' => $requestId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50, ?string $before = null): array
    {
        if ($limit < 1 || $limit > 100) throw new HttpException(422, 'Invalid audit limit.', 'validation_failed');
        if ($before !== null && (strlen($before) > 50 || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $before))) {
            throw new HttpException(422, 'Invalid audit cursor.', 'validation_failed');
        }
        $stmt = $this->db->connection()->prepare(<<<'SQL'
SELECT id::text, actor_type, actor_id::text, action, target_type, target_id::text,
       result, payload::text, request_id,
       to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS created_at
FROM audit_log WHERE (CAST(:cursor_check AS timestamptz) IS NULL OR created_at < CAST(:cursor_bound AS timestamptz))
ORDER BY created_at DESC, id DESC LIMIT :limit
SQL);
        $stmt->bindValue(':cursor_check', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':cursor_bound', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $row): array {
            $row['payload'] = json_decode((string) $row['payload'], true, 16, JSON_THROW_ON_ERROR);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
