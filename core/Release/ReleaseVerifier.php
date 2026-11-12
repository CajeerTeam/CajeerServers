<?php
declare(strict_types=1);

namespace Cajeer\Servers\Release;

use JsonException;
use RuntimeException;

/** Verify the actual source tree, metadata and dependency artifacts before a release is deployed. */
final class ReleaseVerifier
{
    public function __construct(private readonly string $root) {}

    /** @return array{ok:bool,version:string,checks:list<array{name:string,ok:bool,detail:string}>} */
    public function verify(bool $deployment = false): array
    {
        $checks = [];
        $check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $version = trim($this->read('VERSION') ?? '');
        $check('semver', preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D', $version) === 1,
            'VERSION must be SemVer.');
        $release = $this->json('release.json');
        $admin = $this->json('admin/package.json');
        $composer = $this->json('composer.json');
        $check('release_version', ($release['version'] ?? null) === $version, 'release.json must match VERSION.');
        $sdk = $this->json('packages/ts-sdk/package.json');
        $check('sdk_version', ($sdk['version'] ?? null) === $version, 'TypeScript SDK version must match VERSION.');
        $check('admin_version', ($admin['version'] ?? null) === $version, 'Nuxt package version must match VERSION.');
        $check('composer_project', ($composer['name'] ?? null) === 'cajeer/cajeerservers', 'Composer project metadata must be consistent.');

        $api = $this->read('api/openapi.yaml');
        $secondary = $this->read('openapi.yaml');
        $check('openapi_copies', $api !== null && hash_equals(hash('sha256', $api), hash('sha256', (string) $secondary)),
            'Both OpenAPI documents must be identical.');
        $apiVersion = null;
        if ($api !== null && preg_match('/^info:\s*\R(?:(?:^[ \t]+.*\R)|(?:^\s*\R))*?^[ \t]+version:\s*[\'\"]?([^\s\'\"]+)/m', $api, $match) === 1) {
            $apiVersion = $match[1];
        }
        $check('openapi_version', $apiVersion === $version && str_contains((string)$api, 'openapi: 3.1.1'),
            'OpenAPI must match VERSION and use 3.1.1.');
        foreach (['public/index.php', 'core/bootstrap.php', 'bin/cajeer', 'routes/api.php',
                  'migrations/pgsql/0007_admin_ui_permissions.sql', 'migrations/pgsql/0008_inventory_readiness.sql',
                  'core/Inventory/InventoryReadiness.php', 'migrations/pgsql/0009_organizations_workspaces.sql',
                  'core/Workspace/WorkspaceService.php', 'core/Workspace/WorkspaceController.php',
                  'core/Inventory/CatalogService.php', 'core/Inventory/CatalogController.php',
                  'migrations/pgsql/0013_inventory_catalog_search.sql', 'admin/pages/catalog.vue', 'admin/nuxt.config.ts',
                  'migrations/pgsql/0015_inventory_transfer_credentials.sql', 'core/Inventory/TransferFormat.php',
                  'core/Inventory/TransferService.php', 'core/Inventory/CredentialBindingService.php',
                  'core/Inventory/TransferController.php', 'admin/pages/transfer.vue',
                  'core/Inventory/CmdbIntegrity.php', 'docs/CMDB-0.3.0.md',
                  'migrations/pgsql/0016_cmdb_stability.sql',
                  'migrations/pgsql/0017_vault_key_verifier.sql', 'core/Credentials/VaultMasterKey.php',
                  'core/Credentials/VaultCrypto.php', 'core/Credentials/CredentialsVault.php',
                  'core/Cli/VaultCommands.php', 'tests/vault-crypto-smoke.php',
                  'migrations/pgsql/0018_encrypted_credentials_crud.sql', 'core/Credentials/CredentialInput.php',
                  'core/Credentials/CredentialStore.php', 'core/Credentials/CredentialController.php',
                  'tests/vault-crud-smoke.php', 'core/Credentials/CredentialProfile.php',
                  'tests/vault-profiles-smoke.php',
                  'migrations/pgsql/0020_vault_versioning_rotation.sql',
                  'core/Credentials/VaultKeyRotation.php', 'core/Credentials/CredentialVersionService.php',
                  'core/Credentials/CredentialVersionController.php',
                  'tests/vault-rotation-smoke.php',
                  'migrations/pgsql/0022_vault_validated_rotation.sql', 'tests/vault-stable-smoke.php',
                  'core/Backup/BackupService.php', 'core/Backup/PostgresStream.php', 'core/Backup/EncryptedArchive.php', 'core/Backup/ArchiveWriter.php',
                  'core/Backup/BackupKey.php', 'core/Cli/BackupCommands.php', 'tests/backup-smoke.php',
                  'tests/backup-postgres-integration.php', 'admin/pages/vault.vue',
                  'admin/composables/vaultProfiles.ts', 'admin/tests/e2e/vault-ui.spec.ts',
                  'admin/tests/vault-ui-contract.mjs', 'core/Integration/ConnectorRegistry.php',
                  'core/Connector/ConnectorRuntimeService.php','core/Connector/SnmpTransport.php','core/Connector/SnmpSession.php','core/Connector/RouterConnector.php','core/Connector/ConnectorHealthMonitor.php','core/Connector/ConnectorConcurrency.php','tests/snmp-health-smoke.php','tests/snmp-protocol-integration.sh','tests/snmp-protocol-integration.php','tests/connector-health-postgres-integration.php','docs/SNMP-Connector-Health-0.4.6.md','migrations/pgsql/0026_connector_health.sql', 'core/Connector/HttpsTransport.php',
                  'core/Connector/EndpointPolicy.php', 'tests/connector-runtime-smoke.php',
                  'core/Connector/ConnectorExecutionService.php', 'core/Connector/ConnectorActionService.php', 'core/Connector/ConnectorActionDriver.php', 'core/Integration/ConnectorExtensionLoader.php', 'migrations/pgsql/0028_connector_actions.sql', 'tests/connector-actions-smoke.php', 'tests/connector-actions-postgres-integration.php', 'tests/connector-actions-https-integration.py', 'tests/connector-sdk-integration.sh', 'docs/Connector-Actions-SDK-0.4.8.md', 'core/Connector/ConnectorEventService.php', 'core/Connector/ConnectorEventController.php', 'core/Connector/ConnectorEventPayload.php', 'migrations/pgsql/0027_connector_events.sql', 'tests/connector-events-smoke.php', 'tests/connector-events-postgres-integration.php', 'docs/Connector-Events-0.4.7.md', 'core/Cli/ConnectorCommands.php',
                  'migrations/pgsql/0023_connector_execution.sql', 'tests/connector-execution-smoke.php',
                  'migrations/pgsql/0024_connector_reconciliation.sql', 'tests/connector-reconciliation-smoke.php', 'core/Connector/VmmanagerConnector.php',
                  'core/Connector/ConnectorTransportException.php', 'tests/vmmanager-runtime-smoke.php',
                  'tests/vmmanager-https-integration.py', 'tests/vmmanager-postgres-integration.php', 'admin/components/ConnectorLifecycle.vue', 'admin/composables/connectorLifecycle.ts', 'admin/tests/connector-lifecycle-contract.mjs', 'admin/tests/e2e/connector-lifecycle.spec.ts', 'tests/connector-e2e-fixture.py', 'docs/Connector-Admin-UI-E2E-0.4.9.md', 'docs/Connector-Framework-Stable-0.5.0.md', 'core/Connector/ConnectorReadiness.php', 'tests/connector-stability-smoke.php', 'tests/connector-stability-https-integration.py','core/Collector/CollectionRuntimeService.php','core/Collector/CollectorRegistry.php','migrations/pgsql/0029_collection_runtime.sql','tests/collector-runtime-smoke.php','docs/Collector-Runtime-0.5.1.md','core/Script/ScriptRegistry.php','core/Script/ScriptVersion.php','core/Script/ScriptTemplate.php','migrations/pgsql/0030_script_registry.sql','tests/script-registry-smoke.php','tests/script-registry-postgres-integration.php','docs/Script-Registry-0.5.2.md','bin/script-sign.php','admin/pages/scripts.vue','core/Script/ScriptRunner.php','core/Script/ScriptExecution.php','core/Script/ScriptExecutionPolicy.php','migrations/pgsql/0031_script_execution.sql','tests/script-execution-smoke.php','tests/script-execution-postgres-integration.php','tests/script-sandbox-integration.php','docs/Secure-Script-Execution-0.5.3.md','deploy/systemd/cajeer-script-worker.service','deploy/systemd/cajeer-script-worker.timer','core/Script/ScriptOutputParser.php','core/Script/DataNormalizer.php','tests/script-output-smoke.php','docs/Output-Parsers-0.5.4.md','core/Collector/CollectionSchedule.php','migrations/pgsql/0032_collection_plans.sql','tests/collection-plans-smoke.php','tests/collection-plans-postgres-integration.php','docs/Collection-Jobs-0.5.5.md','core/Metrics/MetricStore.php','core/Metrics/MetricBatch.php','core/Connector/PrometheusConnector.php','migrations/pgsql/0033_metric_storage.sql','tests/metrics-smoke.php','tests/metrics-https-integration.py','tests/metrics-postgres-integration.php','admin/components/ConnectorMetrics.vue','docs/Metrics-Scraping-0.5.6.md','core/Ingestion/IngestionService.php','core/Ingestion/IngestionController.php','core/Collector/WebhookCollector.php','migrations/pgsql/0034_data_ingestion.sql','tests/ingestion-smoke.php','tests/ingestion-postgres-integration.php','tests/ingestion-https-integration.py','examples/ingestion/send.py','admin/components/ConnectorIngestion.vue','deploy/systemd/cajeer-ingestion-worker.service','deploy/systemd/cajeer-ingestion-worker.timer','deploy/systemd/cajeer-ingestion-retention.service','deploy/systemd/cajeer-ingestion-retention.timer','docs/Data-Ingestion-0.5.7.md','docs/Data-Exchange-0.5.8.md','core/ImportExport/ExportManager.php','core/ImportExport/ImportManager.php','core/ImportExport/ExporterRegistry.php','core/ImportExport/DataExchangeController.php','core/ImportExport/WebhookDelivery.php','migrations/pgsql/0035_data_exchange.sql','tests/data-exchange-smoke.php','tests/data-export-https-integration.py','admin/components/ConnectorDataExport.vue','packages/php-sdk/src/Exporter.php','deploy/systemd/cajeer-data-export-worker.service','deploy/systemd/cajeer-data-export-worker.timer','admin/pages/collections.vue','admin/composables/collectionNavigation.ts','admin/tests/collection-navigation-contract.mjs','admin/tests/e2e/data-collection.spec.ts','docs/Data-Collection-Admin-0.5.9.md'] as $file) {
            $check('source:' . $file, is_file($this->root . '/' . $file) && is_readable($this->root . '/' . $file), 'Required source file is available.');
        }
        // Strict deployment checks are intentionally not bypassed by the kernel's native autoload fallback.
        if ($deployment) {
            $composerLock = $this->json('composer.lock');
            $packageLock = $this->json('admin/package-lock.json');
            $sdkLock = $this->json('packages/ts-sdk/package-lock.json');
            $sdkPackages = $sdkLock['packages'] ?? null;
            $composerPackages = $composerLock['packages'] ?? null;
            $nodePackages = $packageLock['packages'] ?? null;
            $check('composer_lock', is_array($composerPackages) && count($composerPackages) > 0
                && preg_match('/^[a-f0-9]{32}$/D', (string)($composerLock['content-hash'] ?? '')) === 1,
                'A resolved Composer lockfile is required for repeatable installation.');
            $check('npm_lock', is_array($nodePackages) && count($nodePackages) > 1
                && in_array($packageLock['lockfileVersion'] ?? null, [2, 3], true)
                && ($nodePackages['']['version'] ?? null) === $version,
                'A resolved npm lockfile matching the admin package is required.');
            $check('sdk_lock', is_array($sdkPackages) && count($sdkPackages) > 1
                && in_array($sdkLock['lockfileVersion'] ?? null, [2, 3], true)
                && ($sdkPackages['']['version'] ?? null) === $version
                && ($sdkPackages['node_modules/typescript']['version'] ?? null) === ($sdk['devDependencies']['typescript'] ?? null),
                'A resolved SDK lockfile matching the pinned compiler and package is required.');
            $check('sdk_installed', is_file($this->root . '/packages/ts-sdk/node_modules/typescript/bin/tsc'),
                'Install TypeScript SDK dependencies reproducibly before verification.');
            $check('composer_installed', is_file($this->root . '/vendor/autoload.php'),
                'Composer dependencies must be installed before production rollout.');
            $check('admin_built', is_file($this->root . '/admin/.output/server/index.mjs'),
                'Nuxt production SSR bundle must be built before rollout.');
        }
        return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'version' => $version, 'checks' => $checks];
    }

    private function read(string $relative): ?string
    {
        $path = $this->root . '/' . $relative;
        if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
        $text = @file_get_contents($path);
        return is_string($text) ? $text : null;
    }

    /** @return array<string,mixed> */
    private function json(string $relative): array
    {
        $text = $this->read($relative);
        if ($text === null) return [];
        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }
}
