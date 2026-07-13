<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Database\StorageOwnedTableShapeGuard;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/StorageOwnedTableShapeTestSupport.php';

$pdoRequested = false;
$unsupported = new Connection(
    static function () use (&$pdoRequested): never {
        $pdoRequested = true;
        throw new RuntimeException('unsupported driver preflight reached PDO');
    },
    'not-used',
    '',
    ['driver' => 'pgsql'],
);
$unsupportedGuard = new StorageOwnedTableShapeGuard($unsupported);
foreach (['preflightUp', 'assertCompleteCompatible', 'preflightDown'] as $method) {
    storageOwnedShapeExpectRejected(
        static fn () => $unsupportedGuard->{$method}(),
        'storage_owned_table_driver_unsupported',
    );
}
storageOwnedShapeExpect(!$pdoRequested, 'unsupported driver preflight touched PDO or schema metadata');

/** @return array{path: string, connection: Connection} */
function storageOwnedShapeOpenSqlite(bool $useNativeJson = false): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-shape-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_shape_test_tempfile_failed');
    }

    $opened = storageOwnedShapeOpen([
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'use_native_json' => $useNativeJson,
    ]);

    return ['path' => $path, 'connection' => $opened['connection']];
}

function storageOwnedShapeCloseSqlite(string $path): void
{
    Facade::clearResolvedInstances();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
        @unlink($file);
    }
}

/** @param callable(Connection): void $scenario */
function storageOwnedShapeSqliteScenario(callable $scenario, bool $useNativeJson = false): void
{
    $opened = storageOwnedShapeOpenSqlite($useNativeJson);
    try {
        $scenario($opened['connection']);
    } finally {
        storageOwnedShapeCloseSqlite($opened['path']);
    }
}

$columnCases = [
    'foreign' => 'storage_owned_table_columns_incompatible',
    'missing_column' => 'storage_owned_table_columns_incompatible',
    'extra_column' => 'storage_owned_table_columns_incompatible',
];
foreach ($columnCases as $variant => $reasonCode) {
    storageOwnedShapeSqliteScenario(static function (Connection $connection) use ($variant, $reasonCode): void {
        storageOwnedShapeCreateSchemas($connection, $variant);
        storageOwnedShapeExpectRejected(
            static fn () => storageOwnedShapeMigration()->up(),
            $reasonCode,
            'schemas',
        );
        storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schemas');
    });
}

foreach (['wrong_type', 'wrong_nullable'] as $variant) {
    storageOwnedShapeSqliteScenario(static function (Connection $connection) use ($variant): void {
        storageOwnedShapeCreateSchemas($connection, $variant);
        storageOwnedShapeExpectRejected(
            static fn () => storageOwnedShapeMigration()->up(),
            'storage_owned_table_column_contract_incompatible',
            'schemas',
        );
        storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schemas');
    });
}

$primaryCases = [
    'missing_primary' => 'storage_owned_table_primary_index_incompatible',
    'wrong_primary' => 'storage_owned_table_primary_index_incompatible',
    'extra_secondary' => 'storage_owned_table_secondary_index_incompatible',
];
foreach ($primaryCases as $variant => $reasonCode) {
    storageOwnedShapeSqliteScenario(static function (Connection $connection) use ($variant, $reasonCode): void {
        storageOwnedShapeCreateSchemas($connection, $variant);
        storageOwnedShapeExpectRejected(
            static fn () => storageOwnedShapeMigration()->up(),
            $reasonCode,
            'schemas',
        );
        storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schemas');
    });
}

$schemaVersionIndexCases = [
    'missing_unique' => 'storage_owned_table_unique_index_incompatible',
    'wrong_unique' => 'storage_owned_table_unique_index_incompatible',
    'renamed_unique' => 'storage_owned_table_unique_index_incompatible',
    'missing_secondary' => 'storage_owned_table_secondary_index_incompatible',
    'wrong_secondary' => 'storage_owned_table_secondary_index_incompatible',
    'renamed_secondary' => 'storage_owned_table_secondary_index_incompatible',
];
foreach ($schemaVersionIndexCases as $variant => $reasonCode) {
    storageOwnedShapeSqliteScenario(static function (Connection $connection) use ($variant, $reasonCode): void {
        storageOwnedShapeCreateSchemaVersions($connection, $variant);
        storageOwnedShapeExpectRejected(
            static fn () => storageOwnedShapeMigration()->up(),
            $reasonCode,
            'schema_versions',
        );
        storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schema_versions');
    });
}

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeCreateSchemaVersions($connection, 'missing_auto_increment');
    storageOwnedShapeExpectRejected(
        static fn () => storageOwnedShapeMigration()->up(),
        'storage_owned_table_column_contract_incompatible',
        'schema_versions',
    );
    storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schema_versions');
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeCreateSchemas($connection);
    storageOwnedShapeMigration()->up();
    storageOwnedShapeExpect(
        storageOwnedShapeExistingTables($connection) === StorageOwnedTableShapeGuard::tableNames(),
        'compatible empty partial topology was not completed',
    );
    storageOwnedShapeValidationMigration()->up();
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeCreateSchemas($connection);
    $connection->table('larena_storage_schemas')->insert([
        'schema_id' => 'existing.partial',
        'current_version' => 1,
        'current_hash' => str_repeat('a', 64),
        'created_at' => '2026-07-14 00:00:00',
        'updated_at' => '2026-07-14 00:00:00',
    ]);
    storageOwnedShapeExpectRejected(
        static fn () => storageOwnedShapeMigration()->up(),
        'storage_owned_table_partial_topology_contains_data',
        'schemas',
    );
    storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schemas');
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->up();
    $connection->table('larena_storage_schemas')->insert([
        'schema_id' => 'existing.complete',
        'current_version' => 1,
        'current_hash' => str_repeat('b', 64),
        'created_at' => '2026-07-14 00:00:00',
        'updated_at' => '2026-07-14 00:00:00',
    ]);
    storageOwnedShapeMigration()->up();
    storageOwnedShapeValidationMigration()->up();
    storageOwnedShapeExpect(
        $connection->table('larena_storage_schemas')->where('schema_id', 'existing.complete')->exists(),
        'compatible complete topology was not idempotent',
    );
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->up();
    $connection->getSchemaBuilder()->table(
        'larena_storage_schema_versions',
        static fn ($table) => $table->dropIndex('storage_schema_created_index'),
    );
    storageOwnedShapeExpectRejected(
        static fn () => storageOwnedShapeValidationMigration()->up(),
        'storage_owned_table_secondary_index_incompatible',
        'schema_versions',
    );
    storageOwnedShapeExpect(
        count(storageOwnedShapeExistingTables($connection)) === 4,
        'read-only upgrade validation mutated the topology',
    );
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->down();
    storageOwnedShapeExpect(storageOwnedShapeExistingTables($connection) === [], 'empty down was not a no-op');
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeCreateSchemas($connection);
    storageOwnedShapeExpectRejected(
        static fn () => storageOwnedShapeMigration()->down(),
        'storage_owned_table_topology_incompatible',
    );
    storageOwnedShapeAssertNoTablesCreatedAfterFailure($connection, 'larena_storage_schemas');
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->up();
    $connection->getSchemaBuilder()->table(
        'larena_storage_schema_versions',
        static fn ($table) => $table->dropUnique('storage_schema_version_unique'),
    );
    storageOwnedShapeExpectRejected(
        static fn () => storageOwnedShapeMigration()->down(),
        'storage_owned_table_unique_index_incompatible',
        'schema_versions',
    );
    storageOwnedShapeExpect(
        count(storageOwnedShapeExistingTables($connection)) === 4,
        'incompatible down dropped an owned table',
    );
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->up();
    $connection->table('larena_storage_schemas')->insert([
        'schema_id' => 'used.complete',
        'current_version' => 1,
        'current_hash' => str_repeat('c', 64),
        'created_at' => '2026-07-14 00:00:00',
        'updated_at' => '2026-07-14 00:00:00',
    ]);
    storageOwnedShapeExpectRejected(
        static fn () => storageOwnedShapeMigration()->down(),
        'storage_typed_content_rollback_would_lose_data',
        'schemas',
    );
    storageOwnedShapeExpect(
        count(storageOwnedShapeExistingTables($connection)) === 4,
        'used down dropped an owned table',
    );
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->up();
    storageOwnedShapeMigration()->down();
    storageOwnedShapeExpect(storageOwnedShapeExistingTables($connection) === [], 'clean down kept an owned table');
    storageOwnedShapeMigration()->up();
    storageOwnedShapeValidationMigration()->up();
    storageOwnedShapeExpect(
        storageOwnedShapeExistingTables($connection) === StorageOwnedTableShapeGuard::tableNames(),
        'clean reapply did not restore the owned topology',
    );
});

storageOwnedShapeSqliteScenario(static function (Connection $connection): void {
    storageOwnedShapeMigration()->up();
    storageOwnedShapeValidationMigration()->up();
    storageOwnedShapeExpect(
        storageOwnedShapeExistingTables($connection) === StorageOwnedTableShapeGuard::tableNames(),
        'native-json SQLite fresh topology mismatch',
    );
    storageOwnedShapeMigration()->down();
    storageOwnedShapeMigration()->up();
    storageOwnedShapeValidationMigration()->up();
    storageOwnedShapeExpect(
        storageOwnedShapeExistingTables($connection) === StorageOwnedTableShapeGuard::tableNames(),
        'native-json SQLite reapply topology mismatch',
    );
}, true);

echo "StorageOwnedTableShapeTest passed.\n";
