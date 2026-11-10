<?php
declare(strict_types=1);

namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Database\DatabaseManager;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only, consistent CMDB integrity scan across inventory, IPAM, topology,
 * taxonomy, history, imports and credential references. No resource names,
 * addresses, secrets or arbitrary attributes ever leave the database.
 *
 * Run on the primary PostgreSQL connection; no automatic repairs or writes.
 */
final class CmdbIntegrity
{
    /** These queries contain no caller-controlled identifiers or parameters. */
    private const CHECKS = [
        'workspace.active_owner' => <<<'SQL'
SELECT count(*) FROM workspaces w WHERE NOT EXISTS (
 SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id
 WHERE wm.workspace_id=w.id AND wm.role='owner' AND u.is_active=true)
SQL,
        'asset.owner_membership' => <<<'SQL'
SELECT count(*) FROM inventory_assets a WHERE a.owner_user_id IS NOT NULL AND NOT EXISTS (
 SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id
 WHERE wm.user_id=a.owner_user_id AND wm.workspace_id=a.workspace_id AND u.is_active=true)
SQL,
        'ipam.overlapping_networks' => <<<'SQL'
SELECT count(*) FROM inventory_networks n JOIN inventory_networks other
 ON other.workspace_id=n.workspace_id AND other.id>n.id AND other.cidr && n.cidr
SQL,
        'ipam.invalid_address_network' => <<<'SQL'
SELECT count(*) FROM inventory_ip_addresses ip LEFT JOIN inventory_networks n
 ON n.id=ip.network_id AND n.workspace_id=ip.workspace_id
 WHERE n.id IS NULL OR NOT (ip.address <<= n.cidr)
SQL,
        'ipam.orphaned_or_deleted_interface' => <<<'SQL'
SELECT count(*) FROM inventory_interfaces i LEFT JOIN inventory_assets a
 ON a.id=i.asset_id AND a.workspace_id=i.workspace_id
 WHERE a.id IS NULL OR a.deleted_at IS NOT NULL
SQL,
        'ipam.invalid_address_assignment' => <<<'SQL'
SELECT count(*) FROM inventory_ip_addresses ip
 LEFT JOIN inventory_interfaces i ON i.id=ip.interface_id AND i.workspace_id=ip.workspace_id
 LEFT JOIN inventory_assets a ON a.id=i.asset_id AND a.workspace_id=i.workspace_id
 WHERE ip.interface_id IS NOT NULL AND (i.id IS NULL OR a.id IS NULL OR a.deleted_at IS NOT NULL)
SQL,
        'topology.invalid_endpoints' => <<<'SQL'
SELECT count(*) FROM inventory_relationships r
 LEFT JOIN inventory_assets a ON a.id=r.source_asset_id AND a.workspace_id=r.workspace_id
 LEFT JOIN inventory_assets b ON b.id=r.target_asset_id AND b.workspace_id=r.workspace_id
 WHERE a.id IS NULL OR b.id IS NULL OR a.deleted_at IS NOT NULL OR b.deleted_at IS NOT NULL
SQL,
        'topology.multiple_structural_parents' => <<<'SQL'
SELECT count(*) FROM (
 SELECT workspace_id,target_asset_id FROM inventory_relationships
 WHERE kind IN ('cluster_contains','hosts') GROUP BY workspace_id,target_asset_id HAVING count(*)>1
) duplicated
SQL,
        'catalog.tags_out_of_sync' => <<<'SQL'
SELECT count(*) FROM inventory_asset_tags at
 JOIN inventory_assets a ON a.id=at.asset_id AND a.workspace_id=at.workspace_id
 JOIN inventory_tags t ON t.id=at.tag_id AND t.workspace_id=at.workspace_id
 WHERE NOT (a.tags ? t.slug)
SQL,
        'catalog.invalid_group_membership' => <<<'SQL'
SELECT count(*) FROM inventory_group_assets ga
 LEFT JOIN inventory_assets a ON a.id=ga.asset_id AND a.workspace_id=ga.workspace_id
 LEFT JOIN inventory_groups g ON g.id=ga.group_id AND g.workspace_id=ga.workspace_id
 WHERE a.id IS NULL OR g.id IS NULL
SQL,
        'history.missing_latest_revision' => <<<'SQL'
SELECT count(*) FROM inventory_assets a
 LEFT JOIN inventory_asset_revisions r ON r.asset_id=a.id AND r.asset_version=a.version
 WHERE r.id IS NULL OR r.workspace_id IS DISTINCT FROM a.workspace_id
SQL,
        'history.missing_field_provenance' => <<<'SQL'
SELECT count(*) FROM inventory_assets a CROSS JOIN
 (VALUES ('name'),('status'),('os_family'),('ip_addresses'),('tags'),('environment')) required(field)
 WHERE NOT EXISTS (SELECT 1 FROM inventory_field_provenance p
 WHERE p.asset_id=a.id AND p.field=required.field)
SQL,
        'imports.progress_mismatch' => <<<'SQL'
SELECT count(*) FROM inventory_import_batches b
 WHERE b.processed_rows <> (SELECT count(*) FROM inventory_import_results r WHERE r.batch_id=b.id)
 OR (b.status IN ('completed','completed_with_errors') AND b.processed_rows<>b.row_count)
SQL,
        'credentials.cross_workspace_references' => <<<'SQL'
SELECT count(*) FROM inventory_asset_credential_bindings b
 LEFT JOIN inventory_assets a ON a.id=b.asset_id AND a.workspace_id=b.workspace_id
 LEFT JOIN inventory_credential_scopes s ON s.credential_id=b.credential_id AND s.workspace_id=b.workspace_id
 WHERE a.id IS NULL OR s.credential_id IS NULL
SQL,
    ];

    private const DEEP_CHECKS = [
        // CYCLE stops at the first repeated vertex. A 5-second SQL timeout prevents
        // expensive/corrupt graph data from freezing a release/health check.
        'topology.cycles' => <<<'SQL'
WITH RECURSIVE walk(workspace_id,source_asset_id,target_asset_id,kind) AS (
 SELECT workspace_id,source_asset_id,target_asset_id,kind FROM inventory_relationships
 WHERE kind IN ('depends_on','cluster_contains','hosts')
 UNION ALL
 SELECT w.workspace_id,w.source_asset_id,r.target_asset_id,w.kind
 FROM walk w JOIN inventory_relationships r
 ON r.workspace_id=w.workspace_id AND r.source_asset_id=w.target_asset_id
 AND (CASE WHEN w.kind='depends_on' THEN r.kind='depends_on'
           ELSE r.kind IN ('cluster_contains','hosts') END)
) CYCLE target_asset_id SET cycle_detected USING path
SELECT count(*) FROM walk WHERE cycle_detected
SQL,
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    /** @return array{ok:bool,checked_at:string,totals:array<string,int>,checks:list<array{name:string,ok:bool,count:int}>,deep:bool} */
    public function inspect(bool $deep = false): array
    {
        if ($this->database->driver() !== 'pgsql') {
            throw new RuntimeException('CMDB integrity inspection requires PostgreSQL.');
        }
        $pdo = $this->database->connection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('CMDB integrity inspection requires an independent read-only transaction.');
        }
        if (!$pdo->beginTransaction()) throw new RuntimeException('Unable to begin CMDB integrity scan.');
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout = '5000ms'");
            $required = ['users','workspaces','workspace_members','inventory_assets','inventory_networks',
                'inventory_interfaces','inventory_ip_addresses','inventory_relationships','inventory_tags',
                'inventory_asset_tags','inventory_groups','inventory_group_assets','inventory_asset_revisions',
                'inventory_field_provenance','inventory_import_batches','inventory_import_results',
                'inventory_credential_scopes','inventory_asset_credential_bindings'];
            $tables = $pdo->query("SELECT relname FROM pg_class JOIN pg_namespace n ON n.oid=relnamespace
                WHERE n.nspname=current_schema() AND relkind IN ('r','p')")?->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($tables) || array_diff($required, $tables) !== []) {
                throw new RuntimeException('CMDB schema is incomplete; apply all migrations before inspection.');
            }
            $totals = [];
            foreach (['assets'=>'inventory_assets','networks'=>'inventory_networks','addresses'=>'inventory_ip_addresses',
                'relationships'=>'inventory_relationships','tags'=>'inventory_tags','groups'=>'inventory_groups',
                'import_batches'=>'inventory_import_batches'] as $name=>$table) {
                // Only compile-time table names; no user-provided SQL.
                $totals[$name] = self::count($pdo, 'SELECT count(*) FROM ' . $table);
            }
            $checks = [];
            foreach (($deep ? self::CHECKS + self::DEEP_CHECKS : self::CHECKS) as $name=>$sql) {
                $count = self::count($pdo, $sql);
                $checks[] = ['name'=>$name,'ok'=>$count===0,'count'=>$count];
            }
            // The transaction is intentionally never committed: no database mutations.
            $pdo->rollBack();
            return ['ok'=>!in_array(false,array_column($checks,'ok'),true),
                'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'totals'=>$totals,'checks'=>$checks,'deep'=>$deep];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function count(PDO $pdo, string $sql): int
    {
        $value = $pdo->query($sql)?->fetchColumn();
        if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
            throw new RuntimeException('CMDB integrity query did not return a count.');
        }
        return (int)$value;
    }
}
