<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Database\StorageOwnedTableShapeGuard;
use Larena\Storage\Exceptions\StorageOwnedTableShapeRejected;

/**
 * @param array<string, mixed> $config
 * @return array{capsule: Capsule, connection: Connection}
 */
function storageOwnedShapeOpen(array $config): array
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

    return ['capsule' => $capsule, 'connection' => $connection];
}

function storageOwnedShapeMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php';
}

function storageOwnedShapeValidationMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_07_14_000001_validate_larena_storage_version_table_shapes.php';
}

function storageOwnedShapeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function storageOwnedShapeExpectRejected(
    callable $operation,
    string $reasonCode,
    string $tableKey = 'topology',
): void {
    try {
        $operation();
    } catch (StorageOwnedTableShapeRejected $exception) {
        storageOwnedShapeExpect($exception->reasonCode === $reasonCode, 'shape rejection reason mismatch');
        storageOwnedShapeExpect($exception->getMessage() === $reasonCode, 'shape rejection message is not sanitized');
        storageOwnedShapeExpect($exception->tableKey === $tableKey, 'shape rejection table key mismatch');

        return;
    }

    throw new RuntimeException('storage table shape operation unexpectedly succeeded');
}

/** @return list<string> */
function storageOwnedShapeExistingTables(Connection $connection): array
{
    $schema = $connection->getSchemaBuilder();

    return array_values(array_filter(
        StorageOwnedTableShapeGuard::tableNames(),
        static fn (string $table): bool => $schema->hasTable($table),
    ));
}

function storageOwnedShapeDropAll(Connection $connection): void
{
    $schema = $connection->getSchemaBuilder();
    foreach (array_reverse(StorageOwnedTableShapeGuard::tableNames()) as $table) {
        $schema->dropIfExists($table);
    }
}

function storageOwnedShapeCreateSchemas(Connection $connection, string $variant = 'valid'): void
{
    $connection->getSchemaBuilder()->create(
        'larena_storage_schemas',
        static function (Blueprint $table) use ($variant): void {
            if ($variant === 'foreign') {
                $table->string('foreign_id')->primary();

                return;
            }

            $table->string('schema_id', 120);
            $table->unsignedBigInteger('current_version');
            if ($variant !== 'missing_column') {
                if ($variant === 'wrong_type') {
                    $table->unsignedBigInteger('current_hash');
                } else {
                    $table->char('current_hash', 64)->nullable($variant === 'wrong_nullable');
                }
            }
            $table->timestamps();
            if ($variant === 'extra_column') {
                $table->string('foreign_value')->nullable();
            }
            if ($variant === 'wrong_primary') {
                $table->primary(['schema_id', 'current_version']);
            } elseif ($variant !== 'missing_primary') {
                $table->primary('schema_id');
            }
            if ($variant === 'extra_secondary') {
                $table->index('current_version');
            }
        },
    );
}

function storageOwnedShapeCreateSchemaVersions(Connection $connection, string $variant = 'valid'): void
{
    $connection->getSchemaBuilder()->create(
        'larena_storage_schema_versions',
        static function (Blueprint $table) use ($variant): void {
            if ($variant === 'missing_auto_increment') {
                $table->unsignedBigInteger('id');
            } else {
                $table->bigIncrements('id');
            }
            $table->string('schema_id', 120);
            $table->unsignedBigInteger('version');
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->string('owner_package', 120);
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamp('created_at');

            if ($variant === 'missing_auto_increment') {
                $table->primary(['schema_id', 'version']);
            }

            if ($variant === 'wrong_unique') {
                $table->unique(['schema_id', 'definition_hash'], 'shape_wrong_schema_unique');
            } elseif ($variant === 'renamed_unique') {
                $table->unique(['schema_id', 'version'], 'shape_foreign_schema_unique');
            } elseif ($variant !== 'missing_unique') {
                $table->unique(['schema_id', 'version'], 'storage_schema_version_unique');
            }

            if ($variant === 'wrong_secondary') {
                $table->index(['schema_id', 'version'], 'shape_wrong_schema_index');
            } elseif ($variant === 'renamed_secondary') {
                $table->index(['schema_id', 'created_at'], 'shape_foreign_schema_index');
            } elseif ($variant !== 'missing_secondary') {
                $table->index(['schema_id', 'created_at'], 'storage_schema_created_index');
            }
        },
    );
}

function storageOwnedShapeAssertNoTablesCreatedAfterFailure(Connection $connection, string $onlyTable): void
{
    storageOwnedShapeExpect(
        storageOwnedShapeExistingTables($connection) === [$onlyTable],
        'failed preflight executed partial DDL',
    );
}
