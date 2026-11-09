<?php
declare(strict_types=1);

namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Database\DatabaseManager;
use PDO;
use RuntimeException;

/** Read-only pre-upgrade inventory audit and explicit PostgreSQL constraint validation. */
final class InventoryReadiness
{
    private const EXPECTED_COLUMNS = [
        'id' => 'uuid', 'type' => 'varchar', 'source' => 'varchar',
        'external_id' => 'varchar', 'name' => 'varchar', 'status' => 'varchar',
        'os_family' => 'varchar', 'ip_addresses' => 'jsonb', 'tags' => 'jsonb',
        'attributes' => 'jsonb', 'created_at' => 'timestamptz', 'updated_at' => 'timestamptz',
    ];

    private const CONSTRAINTS = [
        'inventory_assets_name_nonblank', 'inventory_assets_type_nonblank',
        'inventory_assets_source_nonblank', 'inventory_assets_status_nonblank',
        'inventory_assets_external_id_nonblank', 'inventory_assets_ip_array',
        'inventory_assets_tags_array', 'inventory_assets_attributes_object',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * Never exports hostnames, asset names, IP addresses, secrets or arbitrary metadata.
     * Pre-upgrade mode checks legacy data; strict mode also requires validated 0.2.1 constraints.
     * @return array{ok:bool,rows:int,checks:list<array{name:string,ok:bool,count:int}>,constraints:array<string,string>}
     */
    public function inspect(bool $strict = false): array
    {
        $pdo = $this->postgres();
        $exists = $pdo->query("SELECT to_regclass('inventory_assets')")?->fetchColumn();
        if ($exists === false || $exists === null) {
            throw new RuntimeException('Inventory table is missing. Run database migrations first.');
        }
        $columns = $pdo->query(<<<'SQL'
SELECT column_name, udt_name
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = 'inventory_assets'
SQL)?->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!is_array($columns)) throw new RuntimeException('Cannot inspect inventory schema.');
        $checks = [];
        foreach (self::EXPECTED_COLUMNS as $name => $type) {
            $checks[] = ['name' => 'schema.' . $name, 'ok' => ($columns[$name] ?? null) === $type, 'count' => 0];
        }
        if (in_array(false, array_column($checks, 'ok'), true)) {
            return ['ok' => false, 'rows' => 0, 'checks' => $checks, 'constraints' => []];
        }
        // Each metric is calculated by PostgreSQL and returned only as a count: no infrastructure data leaves the DB.
        $row = $pdo->query(<<<'SQL'
SELECT count(*)::bigint AS total,
 count(*) FILTER (WHERE name !~ '[^[:space:]]')::bigint AS name_blank,
 count(*) FILTER (WHERE type !~ '[^[:space:]]')::bigint AS type_blank,
 count(*) FILTER (WHERE source !~ '[^[:space:]]')::bigint AS source_blank,
 count(*) FILTER (WHERE status !~ '[^[:space:]]')::bigint AS status_blank,
 count(*) FILTER (WHERE external_id IS NOT NULL AND external_id !~ '[^[:space:]]')::bigint AS external_id_blank,
 count(*) FILTER (WHERE jsonb_typeof(ip_addresses) IS DISTINCT FROM 'array')::bigint AS ip_not_array,
 count(*) FILTER (WHERE jsonb_typeof(tags) IS DISTINCT FROM 'array')::bigint AS tags_not_array,
 count(*) FILTER (WHERE jsonb_typeof(attributes) IS DISTINCT FROM 'object')::bigint AS attributes_not_object
FROM inventory_assets
SQL)?->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Inventory data inspection failed.');
        foreach (['name_blank', 'type_blank', 'source_blank', 'status_blank', 'external_id_blank',
            'ip_not_array', 'tags_not_array', 'attributes_not_object'] as $key) {
            $n = (int) ($row[$key] ?? -1);
            $checks[] = ['name' => 'data.' . $key, 'ok' => $n === 0, 'count' => $n];
        }
        $found = $pdo->query(<<<'SQL'
SELECT c.conname, c.convalidated
FROM pg_constraint c
WHERE c.conrelid = 'inventory_assets'::regclass AND c.contype = 'c'
SQL)?->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($found)) throw new RuntimeException('Cannot inspect inventory constraints.');
        $constraints = [];
        foreach ($found as $entry) {
            $constraints[(string)$entry['conname']] = in_array($entry['convalidated'], [true, 't', '1', 1], true) ? 'validated' : 'pending';
        }
        if ($strict) {
            foreach (self::CONSTRAINTS as $name) {
                $valid = ($constraints[$name] ?? 'missing') === 'validated';
                $checks[] = ['name' => 'constraint.' . $name, 'ok' => $valid, 'count' => 0];
            }
        }
        return [
            'ok' => !in_array(false, array_column($checks, 'ok'), true),
            'rows' => (int)($row['total'] ?? 0),
            'checks' => $checks,
            'constraints' => array_intersect_key($constraints, array_fill_keys(self::CONSTRAINTS, true)),
        ];
    }

    /**
     * A deliberate DBA action. Never rewrites old data or silently forces invalid legacy rows.
     * VALIDATE CONSTRAINT scans existing rows while preserving their values.
     * @return array{validated:list<string>,remaining:list<string>}
     */
    public function validateConstraints(): array
    {
        $before = $this->inspect(false);
        if (!$before['ok']) {
            throw new RuntimeException('Existing inventory data is invalid; inspect and repair rows before constraint validation.');
        }
        $validated = [];
        $existing = $before['constraints'];
        foreach (self::CONSTRAINTS as $name) {
            if (!isset($existing[$name])) {
                throw new RuntimeException('Inventory constraints are missing; run migrations first.');
            }
            if ($existing[$name] === 'validated') continue;
            // Identifiers come ONLY from the compile-time whitelist, never from user input.
            $this->database->connection()->exec('ALTER TABLE inventory_assets VALIDATE CONSTRAINT ' . $name);
            $validated[] = $name;
        }
        $after = $this->inspect(true);
        if (!$after['ok']) throw new RuntimeException('Inventory constraints were not fully validated.');
        return ['validated' => $validated, 'remaining' => []];
    }

    private function postgres(): PDO
    {
        if ($this->database->driver() !== 'pgsql') {
            throw new RuntimeException('Inventory readiness requires PostgreSQL.');
        }
        return $this->database->connection();
    }
}
