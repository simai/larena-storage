<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Larena\Storage\Database\StorageSchemaMigrationTableShapeGuard;
use Larena\Storage\Exceptions\StorageOwnedTableShapeRejected;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/StorageOwnedTableShapeTestSupport.php';
require_once __DIR__ . '/StorageSchemaMigrationTableShapeTestSupport.php';

function storageMigrationShapeMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php';
}

/** @param callable(Connection): void $scenario */
function storageMigrationShapeScenario(callable $scenario): void
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-migration-shape-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_migration_shape_tempfile_failed');
    }
    $opened = storageOwnedShapeOpen([
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    try {
        $scenario($opened['connection']);
    } finally {
        Facade::clearResolvedInstances();
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
            @unlink($file);
        }
    }
}

/** @return list<string> */
function storageMigrationShapeExisting(Connection $connection): array
{
    return array_values(array_filter(
        StorageSchemaMigrationTableShapeGuard::tableNames(),
        static fn (string $table): bool => $connection->getSchemaBuilder()->hasTable($table),
    ));
}

function storageMigrationShapeInsertPlan(Connection $connection): void
{
    $connection->table('larena_storage_schema_migration_plans')->insert([
        'plan_ref' => 'storage-migration-' . str_repeat('a', 32),
        'schema_id' => 'docara.page.shape',
        'source_version' => 1,
        'source_hash' => str_repeat('b', 64),
        'target_version' => 2,
        'target_hash' => str_repeat('c', 64),
        'target_definition' => '{}',
        'compatibility_class' => 'optional_additions',
        'added_optional_count' => 1,
        'record_count' => 0,
        'record_heads_hash' => str_repeat('d', 64),
        'plan_hash' => str_repeat('e', 64),
        'created_by' => 'user:admin:1',
        'correlation_id' => null,
        'created_at' => '2026-07-14 00:00:00',
    ]);
}

function storageMigrationShapeAssertContractRejected(
    Connection $connection,
    string $tableKey,
    string $variant,
): void {
    $table = storageMigrationShapeTableMap()[$tableKey];
    storageMigrationShapeCreateContractVariant($connection, $tableKey, $variant);
    $before = storageMigrationShapeContractSnapshot($connection, $table);

    try {
        storageMigrationShapeMigration()->up();
        throw new RuntimeException('storage migration contract variant unexpectedly accepted');
    } catch (StorageOwnedTableShapeRejected $exception) {
        $expectedReason = storageMigrationShapeExpectedContractReason($tableKey, $variant);
        storageOwnedShapeExpect($exception->reasonCode === $expectedReason, $tableKey . '/' . $variant . ' reason mismatch: ' . $exception->reasonCode);
        storageOwnedShapeExpect($exception->getMessage() === $expectedReason, 'shape exception message leaked details');
        storageOwnedShapeExpect($exception->tableKey === $tableKey, $tableKey . '/' . $variant . ' table key mismatch');
    }

    storageOwnedShapeExpect(
        storageMigrationShapeExisting($connection) === [$table],
        $tableKey . '/' . $variant . ' executed partial DDL',
    );
    storageOwnedShapeExpect(
        storageMigrationShapeContractSnapshot($connection, $table) === $before,
        $tableKey . '/' . $variant . ' mutated the rejected foreign table',
    );
}

$pdoRequested = false;
$unsupported = new Connection(
    static function () use (&$pdoRequested): never {
        $pdoRequested = true;
        throw new RuntimeException('unsupported migration shape guard reached PDO');
    },
    'unused',
    '',
    ['driver' => 'pgsql'],
);
foreach (['preflightUp', 'assertCompleteCompatible', 'preflightDown'] as $method) {
    storageOwnedShapeExpectRejected(
        static fn () => (new StorageSchemaMigrationTableShapeGuard($unsupported))->{$method}(),
        'storage_schema_migration_driver_unsupported',
    );
}
storageOwnedShapeExpect(!$pdoRequested, 'unsupported migration shape guard touched PDO');

foreach (storageMigrationShapeTableMap() as $tableKey => $_table) {
    foreach (storageMigrationShapeContractVariants($tableKey) as $variant) {
        storageMigrationShapeScenario(static function (Connection $connection) use ($tableKey, $variant): void {
            storageMigrationShapeAssertContractRejected($connection, $tableKey, $variant);
        });
    }
}

storageMigrationShapeScenario(static function (Connection $connection): void {
    $connection->getSchemaBuilder()->create(
        'larena_storage_schema_migration_plans',
        static function (Blueprint $table): void {
            $table->string('foreign_id')->primary();
        },
    );
    storageOwnedShapeExpectRejected(
        static fn () => storageMigrationShapeMigration()->up(),
        'storage_schema_migration_columns_incompatible',
        'migration_plans',
    );
    storageOwnedShapeExpect(
        storageMigrationShapeExisting($connection) === ['larena_storage_schema_migration_plans'],
        'foreign-shape preflight executed partial DDL',
    );
});

storageMigrationShapeScenario(static function (Connection $connection): void {
    storageMigrationShapeMigration()->up();
    $connection->getSchemaBuilder()->drop('larena_storage_schema_migration_result_records');
    storageMigrationShapeMigration()->up();
    storageOwnedShapeExpect(
        storageMigrationShapeExisting($connection) === StorageSchemaMigrationTableShapeGuard::tableNames(),
        'compatible empty partial migration topology was not completed',
    );
});

storageMigrationShapeScenario(static function (Connection $connection): void {
    storageMigrationShapeMigration()->up();
    $connection->getSchemaBuilder()->drop('larena_storage_schema_migration_result_records');
    $existingBefore = storageMigrationShapeExisting($connection);
    $snapshots = [];
    foreach ($existingBefore as $table) {
        $snapshots[$table] = storageMigrationShapeContractSnapshot($connection, $table);
    }
    storageOwnedShapeExpectRejected(
        static fn () => storageMigrationShapeMigration()->down(),
        'storage_schema_migration_topology_incompatible',
    );
    storageOwnedShapeExpect(storageMigrationShapeExisting($connection) === $existingBefore, 'partial down dropped an owned table');
    foreach ($snapshots as $table => $snapshot) {
        storageOwnedShapeExpect(
            storageMigrationShapeContractSnapshot($connection, $table) === $snapshot,
            'partial down mutated ' . $table,
        );
    }
});

storageMigrationShapeScenario(static function (Connection $connection): void {
    storageMigrationShapeMigration()->up();
    storageMigrationShapeInsertPlan($connection);
    $connection->getSchemaBuilder()->drop('larena_storage_schema_migration_result_records');
    storageOwnedShapeExpectRejected(
        static fn () => storageMigrationShapeMigration()->up(),
        'storage_schema_migration_partial_topology_contains_data',
        'migration_plans',
    );
    storageOwnedShapeExpect(
        !$connection->getSchemaBuilder()->hasTable('larena_storage_schema_migration_result_records'),
        'used partial topology was silently completed',
    );
});

storageMigrationShapeScenario(static function (Connection $connection): void {
    storageMigrationShapeMigration()->up();
    storageMigrationShapeInsertPlan($connection);
    storageOwnedShapeExpectRejected(
        static fn () => storageMigrationShapeMigration()->down(),
        'storage_schema_migration_rollback_would_lose_data',
        'migration_plans',
    );
    storageOwnedShapeExpect(
        storageMigrationShapeExisting($connection) === StorageSchemaMigrationTableShapeGuard::tableNames(),
        'refused migration rollback partially dropped tables',
    );
});

storageMigrationShapeScenario(static function (Connection $connection): void {
    storageMigrationShapeMigration()->up();
    $connection->getSchemaBuilder()->table(
        'larena_storage_schema_migration_plans',
        static fn (Blueprint $table) => $table->dropIndex('storage_migration_plan_schema_index'),
    );
    storageOwnedShapeExpectRejected(
        static fn () => storageMigrationShapeMigration()->up(),
        'storage_schema_migration_secondary_index_incompatible',
        'migration_plans',
    );
    storageOwnedShapeExpect(count(storageMigrationShapeExisting($connection)) === 4, 'index preflight changed topology');
});

storageMigrationShapeScenario(static function (Connection $connection): void {
    storageMigrationShapeMigration()->up();
    storageMigrationShapeMigration()->up();
    storageMigrationShapeMigration()->down();
    storageOwnedShapeExpect(storageMigrationShapeExisting($connection) === [], 'clean rollback left migration tables');
    storageMigrationShapeMigration()->up();
    storageOwnedShapeExpect(
        storageMigrationShapeExisting($connection) === StorageSchemaMigrationTableShapeGuard::tableNames(),
        'migration reapply did not recreate exact topology',
    );
    storageMigrationShapeMigration()->down();
});

echo "StorageSchemaMigrationTableShapeTest passed.\n";
