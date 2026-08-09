<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Runtime\ScopedStorageWorkbenchSchemaIdentity;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;

require_once __DIR__ . '/../../vendor/autoload.php';

function workbenchMigrationExpect(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

/** @param array<string, mixed> $config */
function workbenchMigrationConnection(array $config): Connection
{
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection($config);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $connection = $capsule->getConnection();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.connection', $connection);
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return $connection;
}

function workbenchMigrationCanonicalJson(mixed $value): string
{
    return (new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns()))->canonicalJson($value);
}

/** @return list<array<string, mixed>> */
function workbenchMigrationStorageFields(): array
{
    return [
        ['key' => 'larena_scope_ref', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => 191, 'min_length' => 1]],
        ['key' => 'larena_record_state', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => 8, 'min_length' => 6]],
        ['key' => 'name', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['max_length' => 100]],
    ];
}

/** @return list<array<string, mixed>> */
function workbenchMigrationDescriptorFields(): array
{
    return [
        ['key' => 'name', 'label' => 'Name', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['max_length' => 100]],
    ];
}

function workbenchMigrationSeedIdentity(
    Connection $database,
    string $structureId,
    string $scopeRef,
    string $recordId,
    int $schemaVersions,
    bool $corruptLast,
): void {
    $createdAt = '2026-08-09 00:00:00';
    $definitions = [];
    for ($version = 1; $version <= $schemaVersions; $version++) {
        $definition = [
            'schema_id' => $structureId,
            'owner_package' => 'larena/storage',
            'fields' => workbenchMigrationStorageFields(),
        ];
        $definitionJson = workbenchMigrationCanonicalJson($definition);
        $definitionHash = hash('sha256', $definitionJson);
        if ($corruptLast && $version === $schemaVersions) {
            $definitionHash = str_repeat('b', 64);
        }
        $definitions[$version] = ['json' => $definitionJson, 'hash' => $definitionHash];
        $database->table('larena_storage_schema_versions')->insert([
            'schema_id' => $structureId,
            'version' => $version,
            'definition' => $definitionJson,
            'definition_hash' => $definitionHash,
            'owner_package' => 'larena/storage',
            'created_by' => 'actor:migration-test',
            'correlation_id' => 'migration-schema-' . $version,
            'created_at' => $createdAt,
        ]);
    }
    $database->table('larena_storage_schemas')->insert([
        'schema_id' => $structureId,
        'current_version' => $schemaVersions,
        'current_hash' => $definitions[$schemaVersions]['hash'],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $values = ['larena_record_state' => 'active', 'larena_scope_ref' => $scopeRef, 'name' => $structureId];
    $valuesJson = workbenchMigrationCanonicalJson($values);
    $contentHash = hash('sha256', $valuesJson);
    $ownerRef = 'workbench.record:' . hash('sha256', $scopeRef . '|' . $structureId);
    $database->table('larena_storage_records')->insert([
        'record_id' => $recordId,
        'schema_id' => $structureId,
        'owner_ref' => $ownerRef,
        'current_revision' => 1,
        'current_schema_version' => $schemaVersions,
        'current_hash' => $contentHash,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $database->table('larena_storage_record_versions')->insert([
        'schema_id' => $structureId,
        'record_id' => $recordId,
        'revision' => 1,
        'owner_ref' => $ownerRef,
        'schema_version' => $schemaVersions,
        'values_json' => $valuesJson,
        'content_hash' => $contentHash,
        'operation' => 'create',
        'created_by' => 'actor:migration-test',
        'correlation_id' => 'migration-record',
        'created_at' => $createdAt,
    ]);

    $fields = workbenchMigrationDescriptorFields();
    $descriptorHash = hash('sha256', workbenchMigrationCanonicalJson([
        'structure_id' => $structureId,
        'scope_ref' => $scopeRef,
        'version' => 1,
        'schema_version' => $schemaVersions,
        'label' => 'Migration ' . $structureId,
        'fields' => $fields,
    ]));
    $database->table('larena_storage_workbench_structures')->insert([
        'structure_id' => $structureId,
        'scope_ref' => $scopeRef,
        'current_version' => 1,
        'current_schema_version' => $schemaVersions,
        'current_hash' => $descriptorHash,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $database->table('larena_storage_workbench_structure_versions')->insert([
        'structure_id' => $structureId,
        'version' => 1,
        'scope_ref' => $scopeRef,
        'label' => 'Migration ' . $structureId,
        'fields_json' => workbenchMigrationCanonicalJson($fields),
        'schema_version' => $schemaVersions,
        'descriptor_hash' => $descriptorHash,
        'created_by' => 'actor:migration-test',
        'correlation_id' => 'migration-workbench',
        'created_at' => $createdAt,
    ]);
}

/** @return array<string, mixed> */
function workbenchMigrationState(Connection $database): array
{
    $driver = $database->getDriverName();
    if ($driver === 'mysql') {
        $metadata = $database->select(
            "SELECT TABLE_NAME AS table_name, AUTO_INCREMENT AS auto_increment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'larena_storage_%' ORDER BY TABLE_NAME",
        );
    } else {
        $metadata = $database->select(
            "SELECT name AS table_name, NULL AS auto_increment FROM sqlite_master WHERE type = 'table' AND name LIKE 'larena_storage_%' ORDER BY name",
        );
    }
    $state = ['driver' => $driver, 'tables' => []];
    foreach ($metadata as $tableMetadata) {
        $table = (string) $tableMetadata->table_name;
        $rows = array_map(static fn (object $row): array => (array) $row, $database->table($table)->get()->all());
        usort($rows, static fn (array $left, array $right): int => workbenchMigrationCanonicalJson($left) <=> workbenchMigrationCanonicalJson($right));
        if ($driver === 'mysql') {
            $create = (array) $database->selectOne('SHOW CREATE TABLE `' . $table . '`');
            $definition = array_values($create)[1] ?? null;
        } else {
            $definition = $database->scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        }
        $state['tables'][$table] = [
            'definition' => $definition,
            'auto_increment' => $tableMetadata->auto_increment === null ? null : (int) $tableMetadata->auto_increment,
            'rows' => $rows,
        ];
    }

    return $state;
}

function workbenchMigrationInstallLegacy(): void
{
    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_08_09_000001_create_larena_storage_workbench_tables.php')->up();
}

function workbenchMigrationRepairCorruptVersion(Connection $database, string $structureId, int $version): void
{
    $definitionJson = workbenchMigrationCanonicalJson([
        'schema_id' => $structureId,
        'owner_package' => 'larena/storage',
        'fields' => workbenchMigrationStorageFields(),
    ]);
    $hash = hash('sha256', $definitionJson);
    $database->table('larena_storage_schema_versions')
        ->where('schema_id', $structureId)
        ->where('version', $version)
        ->update(['definition' => $definitionJson, 'definition_hash' => $hash]);
    $database->table('larena_storage_schemas')->where('schema_id', $structureId)->update(['current_hash' => $hash]);
}

function workbenchMigrationRunAtomicityScenario(Connection $database, string $driver): void
{
    workbenchMigrationInstallLegacy();
    workbenchMigrationSeedIdentity($database, 'workbench.legacy_alpha', 'scope:tenant-alpha', 'record-' . str_repeat('1', 32), 1, false);
    workbenchMigrationSeedIdentity($database, 'workbench.legacy_beta', 'scope:tenant-beta', 'record-' . str_repeat('2', 32), 2, true);

    $beforeCorrupt = workbenchMigrationState($database);
    try {
        (require __DIR__ . '/../../database/migrations/2026_08_09_000002_scope_larena_storage_workbench_identity.php')->up();
        throw new RuntimeException('workbench_migration_corrupt_definition_accepted');
    } catch (RuntimeException $exception) {
        workbenchMigrationExpect(
            $exception->getMessage() === 'storage_workbench_identity_schema_corrupt',
            'workbench_migration_corrupt_reason_mismatch_' . $driver,
        );
    }
    workbenchMigrationExpect(workbenchMigrationState($database) === $beforeCorrupt, 'workbench_migration_corrupt_state_changed_' . $driver);

    workbenchMigrationRepairCorruptVersion($database, 'workbench.legacy_beta', 2);
    $beforeInjected = workbenchMigrationState($database);
    $targetInsertCount = 0;
    $armed = true;
    $database->beforeExecuting(static function (string $query, array $_bindings, Connection $_connection) use (&$targetInsertCount, &$armed): void {
        $normalizedQuery = strtolower(ltrim($query));

        if (!$armed
            || !str_starts_with($normalizedQuery, 'insert')
            || !str_contains($normalizedQuery, 'larena_storage_schema_versions')) {
            return;
        }
        $targetInsertCount++;
        if ($targetInsertCount === 2) {
            $armed = false;
            throw new RuntimeException('storage_workbench_identity_injected_failure');
        }
    });
    try {
        (require __DIR__ . '/../../database/migrations/2026_08_09_000002_scope_larena_storage_workbench_identity.php')->up();
        throw new RuntimeException('workbench_migration_injected_failure_missing');
    } catch (RuntimeException $exception) {
        workbenchMigrationExpect(
            $exception->getMessage() === 'storage_workbench_identity_injected_failure',
            'workbench_migration_injected_reason_mismatch_' . $driver . ':' . $exception->getMessage(),
        );
    }
    workbenchMigrationExpect($targetInsertCount === 2, 'workbench_migration_injection_not_after_prior_candidate_' . $driver);
    workbenchMigrationExpect(workbenchMigrationState($database) === $beforeInjected, 'workbench_migration_injected_state_changed_' . $driver);

    if ($driver === 'mysql') {
        $beforePostSwapInjected = workbenchMigrationState($database);
        $postSwapArmed = true;
        $database->beforeExecuting(static function (string $query, array $_bindings, Connection $_connection) use (&$postSwapArmed): void {
            $normalizedQuery = strtolower(ltrim($query));
            if (!$postSwapArmed
                || !str_starts_with($normalizedQuery, 'drop table')
                || !str_contains($normalizedQuery, 'larena_storage_workbench_structures_unscoped_v1')) {
                return;
            }

            $postSwapArmed = false;
            throw new RuntimeException('storage_workbench_identity_post_swap_injected_failure');
        });
        try {
            (require __DIR__ . '/../../database/migrations/2026_08_09_000002_scope_larena_storage_workbench_identity.php')->up();
            throw new RuntimeException('workbench_migration_post_swap_injected_failure_missing');
        } catch (RuntimeException $exception) {
            workbenchMigrationExpect(
                $exception->getMessage() === 'storage_workbench_identity_post_swap_injected_failure',
                'workbench_migration_post_swap_injected_reason_mismatch_' . $driver . ':' . $exception->getMessage(),
            );
        }
        workbenchMigrationExpect(!$postSwapArmed, 'workbench_migration_post_swap_injection_missing_' . $driver);
        workbenchMigrationExpect(
            workbenchMigrationState($database) === $beforePostSwapInjected,
            'workbench_migration_post_swap_injected_state_changed_' . $driver,
        );
    }

    $migration = require __DIR__ . '/../../database/migrations/2026_08_09_000002_scope_larena_storage_workbench_identity.php';
    $migration->up();
    $migration->up();
    workbenchMigrationExpect(Schema::hasColumn('larena_storage_workbench_structures', 'storage_schema_id'), 'workbench_migration_scoped_head_missing_' . $driver);
    workbenchMigrationExpect(Schema::hasColumn('larena_storage_workbench_structure_versions', 'storage_schema_id'), 'workbench_migration_scoped_history_missing_' . $driver);
    foreach ([
        ['workbench.legacy_alpha', 'scope:tenant-alpha'],
        ['workbench.legacy_beta', 'scope:tenant-beta'],
    ] as [$structureId, $scopeRef]) {
        $target = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $structureId);
        workbenchMigrationExpect($database->table('larena_storage_schemas')->where('schema_id', $target)->exists(), 'workbench_migration_target_head_missing_' . $driver);
        workbenchMigrationExpect($database->table('larena_storage_records')->where('schema_id', $target)->count() === 1, 'workbench_migration_target_record_missing_' . $driver);
    }
}

/** @return array<string, string> */
function workbenchMigrationParseEnv(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('workbench_migration_env_unreadable');
    }
    $values = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $matches) !== 1) {
            continue;
        }
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && (($value[0] === "'" && $value[-1] === "'") || ($value[0] === '"' && $value[-1] === '"'))) {
            $value = substr($value, 1, -1);
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }
        $values[$matches[1]] = $value;
    }

    return $values;
}

$sqlitePath = tempnam(sys_get_temp_dir(), 'larena-workbench-migration-atomicity-');
workbenchMigrationExpect(is_string($sqlitePath), 'workbench_migration_sqlite_tempfile_failed');
try {
    $sqlite = workbenchMigrationConnection(['driver' => 'sqlite', 'database' => $sqlitePath, 'prefix' => '', 'foreign_key_constraints' => true]);
    workbenchMigrationRunAtomicityScenario($sqlite, 'sqlite');
    $sqlite->disconnect();
} finally {
    Facade::clearResolvedInstances();
    foreach ([$sqlitePath, $sqlitePath . '-wal', $sqlitePath . '-shm', $sqlitePath . '-journal'] as $file) {
        @unlink($file);
    }
}

$optIn = getenv('LARENA_STORAGE_WORKBENCH_MIGRATION_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "StorageWorkbenchIdentityMigrationAtomicityTest passed (SQLite); MariaDB skipped (explicit opt-in required).\n";
    exit(0);
}

workbenchMigrationExpect(extension_loaded('pdo_mysql'), 'workbench_migration_pdo_mysql_missing');
$root = dirname(__DIR__, 5) . '/larena';
$envPath = realpath($root . '/.env.auth-mfa-mysql-test');
workbenchMigrationExpect(is_string($envPath) && basename($envPath) === '.env.auth-mfa-mysql-test', 'workbench_migration_env_path_invalid');
workbenchMigrationExpect((fileperms($envPath) & 0o077) === 0, 'workbench_migration_env_permissions_unsafe');
$env = workbenchMigrationParseEnv($envPath);
foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
    workbenchMigrationExpect(array_key_exists($required, $env), 'workbench_migration_env_incomplete');
}
workbenchMigrationExpect(in_array(strtolower($env['DB_HOST']), ['127.0.0.1', 'localhost', '::1'], true), 'workbench_migration_host_not_local');
$databaseName = 'larena_storage_workbench_migration_' . strtolower(bin2hex(random_bytes(6)));
workbenchMigrationExpect(preg_match('/^larena_storage_workbench_migration_[a-f0-9]{12}$/', $databaseName) === 1, 'workbench_migration_database_name_invalid');
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $env['DB_HOST'], (int) $env['DB_PORT']),
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$created = false;
try {
    $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $mysql = workbenchMigrationConnection([
        'driver' => 'mysql',
        'host' => $env['DB_HOST'],
        'port' => (int) $env['DB_PORT'],
        'database' => $databaseName,
        'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
    ]);
    workbenchMigrationRunAtomicityScenario($mysql, 'mysql');
    $mysql->disconnect();
} finally {
    Facade::clearResolvedInstances();
    if ($created) {
        $server->exec('DROP DATABASE `' . $databaseName . '`');
        $remaining = $server->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $server->quote($databaseName))->fetchColumn();
        workbenchMigrationExpect((int) $remaining === 0, 'workbench_migration_cleanup_failed');
    }
}

echo "StorageWorkbenchIdentityMigrationAtomicityTest passed (SQLite + MariaDB).\n";
