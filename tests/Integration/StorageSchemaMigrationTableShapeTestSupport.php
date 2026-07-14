<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/** @return array<string, string> */
function storageMigrationShapeTableMap(): array
{
    return [
        'migration_plans' => 'larena_storage_schema_migration_plans',
        'migration_plan_records' => 'larena_storage_schema_migration_plan_records',
        'migration_results' => 'larena_storage_schema_migration_results',
        'migration_result_records' => 'larena_storage_schema_migration_result_records',
    ];
}

/** @return list<string> */
function storageMigrationShapeContractVariants(string $tableKey): array
{
    $variants = [
        'wrong_type',
        'wrong_nullable',
        'extra_column',
        'missing_primary',
        'wrong_primary',
        'missing_secondary',
        'wrong_secondary',
        'extra_secondary',
    ];

    if ($tableKey === 'migration_plans') {
        $variants[] = 'extra_unique';
    } else {
        array_push($variants, 'missing_unique', 'wrong_unique', 'extra_unique');
    }

    return $variants;
}

/** @return list<string> */
function storageMigrationShapeMySqlContractVariants(string $tableKey): array
{
    $variants = [
        'wrong_string_length',
        'wrong_string_kind',
        'wrong_signed',
        'wrong_integer_width',
    ];
    if ($tableKey === 'migration_plans') {
        $variants[] = 'wrong_json_type';
    }
    if (in_array($tableKey, ['migration_plan_records', 'migration_result_records'], true)) {
        $variants[] = 'wrong_auto_increment';
    }

    return $variants;
}

function storageMigrationShapeExpectedContractReason(string $tableKey, string $variant): string
{
    return match ($variant) {
        'wrong_type', 'wrong_nullable' => 'storage_schema_migration_column_contract_incompatible',
        'extra_column' => 'storage_schema_migration_columns_incompatible',
        'missing_primary', 'wrong_primary' => in_array($tableKey, ['migration_plans', 'migration_results'], true)
            ? 'storage_schema_migration_primary_index_incompatible'
            : 'storage_schema_migration_column_contract_incompatible',
        'missing_unique', 'wrong_unique', 'extra_unique' => 'storage_schema_migration_unique_index_incompatible',
        'missing_secondary', 'wrong_secondary', 'extra_secondary' => 'storage_schema_migration_secondary_index_incompatible',
        'wrong_string_length',
        'wrong_string_kind',
        'wrong_signed',
        'wrong_integer_width',
        'wrong_json_type',
        'wrong_auto_increment' => 'storage_schema_migration_column_contract_incompatible',
        default => throw new RuntimeException('storage_migration_shape_expected_reason_unknown'),
    };
}

function storageMigrationShapeCreateContractVariant(
    Connection $connection,
    string $tableKey,
    string $variant,
): void {
    $tableName = storageMigrationShapeTableMap()[$tableKey] ?? null;
    $supportedVariants = array_merge(
        storageMigrationShapeContractVariants($tableKey),
        storageMigrationShapeMySqlContractVariants($tableKey),
    );
    if (!is_string($tableName) || !in_array($variant, $supportedVariants, true)) {
        throw new RuntimeException('storage_migration_shape_variant_invalid');
    }

    $connection->getSchemaBuilder()->create(
        $tableName,
        static function (Blueprint $table) use ($tableKey, $variant): void {
            switch ($tableKey) {
                case 'migration_plans':
                    storageMigrationShapeDefinePlans($table, $variant);
                    break;
                case 'migration_plan_records':
                    storageMigrationShapeDefinePlanRecords($table, $variant);
                    break;
                case 'migration_results':
                    storageMigrationShapeDefineResults($table, $variant);
                    break;
                case 'migration_result_records':
                    storageMigrationShapeDefineResultRecords($table, $variant);
                    break;
                default:
                    throw new RuntimeException('storage_migration_shape_table_invalid');
            }
        },
    );
}

function storageMigrationShapeDefinePlans(Blueprint $table, string $variant): void
{
    $table->string('plan_ref', $variant === 'wrong_string_length' ? 63 : 64);
    if ($variant === 'wrong_type') {
        $table->unsignedBigInteger('schema_id');
    } else {
        $table->string('schema_id', 120)->nullable($variant === 'wrong_nullable');
    }
    if ($variant === 'wrong_signed') {
        $table->bigInteger('source_version');
    } elseif ($variant === 'wrong_integer_width') {
        $table->unsignedInteger('source_version');
    } else {
        $table->unsignedBigInteger('source_version');
    }
    if ($variant === 'wrong_string_kind') {
        $table->string('source_hash', 64);
    } else {
        $table->char('source_hash', 64);
    }
    $table->unsignedBigInteger('target_version');
    $table->char('target_hash', 64);
    if ($variant === 'wrong_json_type') {
        $table->text('target_definition');
    } else {
        $table->json('target_definition');
    }
    $table->string('compatibility_class', 48);
    $table->unsignedBigInteger('added_optional_count');
    $table->unsignedBigInteger('record_count');
    $table->char('record_heads_hash', 64);
    $table->char('plan_hash', 64);
    $table->string('created_by', 191);
    $table->string('correlation_id', 191)->nullable();
    $table->timestamp('created_at');
    if ($variant === 'extra_column') {
        $table->string('foreign_column')->nullable();
    }
    if ($variant === 'wrong_primary') {
        $table->primary('schema_id');
    } elseif ($variant !== 'missing_primary') {
        $table->primary('plan_ref');
    }
    if ($variant === 'extra_unique') {
        $table->unique('schema_id', 'storage_migration_plan_extra_unique');
    }
    if ($variant === 'wrong_secondary') {
        $table->index(['schema_id', 'target_version'], 'storage_migration_plan_schema_index');
    } elseif ($variant !== 'missing_secondary') {
        $table->index(['schema_id', 'source_version'], 'storage_migration_plan_schema_index');
    }
    if ($variant === 'extra_secondary') {
        $table->index('created_by', 'storage_migration_plan_extra_index');
    }
}

function storageMigrationShapeDefinePlanRecords(Blueprint $table, string $variant): void
{
    if (in_array($variant, ['missing_primary', 'wrong_primary'], true)) {
        $table->unsignedBigInteger('id');
    } elseif ($variant === 'wrong_auto_increment') {
        $table->unsignedBigInteger('id')->primary();
    } else {
        $table->bigIncrements('id');
    }
    $table->string('plan_ref', $variant === 'wrong_string_length' ? 63 : 64);
    $table->string('record_id', 39);
    if ($variant === 'wrong_type') {
        $table->unsignedBigInteger('owner_ref');
    } else {
        $table->string('owner_ref', 191)->nullable($variant === 'wrong_nullable');
    }
    if ($variant === 'wrong_signed') {
        $table->bigInteger('expected_revision');
    } elseif ($variant === 'wrong_integer_width') {
        $table->unsignedInteger('expected_revision');
    } else {
        $table->unsignedBigInteger('expected_revision');
    }
    $table->unsignedBigInteger('expected_schema_version');
    if ($variant === 'wrong_string_kind') {
        $table->string('expected_content_hash', 64);
    } else {
        $table->char('expected_content_hash', 64);
    }
    if ($variant === 'extra_column') {
        $table->string('foreign_column')->nullable();
    }
    if ($variant === 'wrong_primary') {
        $table->primary('plan_ref');
    }
    if ($variant === 'wrong_unique') {
        $table->unique(['plan_ref', 'owner_ref'], 'storage_migration_plan_record_unique');
    } elseif ($variant !== 'missing_unique') {
        $table->unique(['plan_ref', 'record_id'], 'storage_migration_plan_record_unique');
    }
    if ($variant === 'extra_unique') {
        $table->unique('owner_ref', 'storage_migration_plan_record_extra_unique');
    }
    if ($variant === 'wrong_secondary') {
        $table->index(['plan_ref', 'expected_revision'], 'storage_migration_plan_owner_index');
    } elseif ($variant !== 'missing_secondary') {
        $table->index(['plan_ref', 'owner_ref'], 'storage_migration_plan_owner_index');
    }
    if ($variant === 'extra_secondary') {
        $table->index('expected_schema_version', 'storage_migration_plan_record_extra_index');
    }
}

function storageMigrationShapeDefineResults(Blueprint $table, string $variant): void
{
    $table->string('result_ref', $variant === 'wrong_string_length' ? 63 : 64);
    $table->string('plan_ref', 64);
    if ($variant === 'wrong_type') {
        $table->unsignedBigInteger('schema_id');
    } else {
        $table->string('schema_id', 120)->nullable($variant === 'wrong_nullable');
    }
    if ($variant === 'wrong_signed') {
        $table->bigInteger('target_version');
    } elseif ($variant === 'wrong_integer_width') {
        $table->unsignedInteger('target_version');
    } else {
        $table->unsignedBigInteger('target_version');
    }
    if ($variant === 'wrong_string_kind') {
        $table->string('target_hash', 64);
    } else {
        $table->char('target_hash', 64);
    }
    $table->unsignedBigInteger('migrated_record_count');
    $table->char('migrated_records_hash', 64);
    $table->char('result_hash', 64);
    $table->string('applied_by', 191);
    $table->string('correlation_id', 191)->nullable();
    $table->timestamp('applied_at');
    if ($variant === 'extra_column') {
        $table->string('foreign_column')->nullable();
    }
    if ($variant === 'wrong_primary') {
        $table->primary('schema_id');
    } elseif ($variant !== 'missing_primary') {
        $table->primary('result_ref');
    }
    if ($variant === 'wrong_unique') {
        $table->unique('schema_id', 'storage_migration_result_plan_unique');
    } elseif ($variant !== 'missing_unique') {
        $table->unique('plan_ref', 'storage_migration_result_plan_unique');
    }
    if ($variant === 'extra_unique') {
        $table->unique('result_hash', 'storage_migration_result_extra_unique');
    }
    if ($variant === 'wrong_secondary') {
        $table->index(['schema_id', 'plan_ref'], 'storage_migration_result_schema_index');
    } elseif ($variant !== 'missing_secondary') {
        $table->index(['schema_id', 'target_version'], 'storage_migration_result_schema_index');
    }
    if ($variant === 'extra_secondary') {
        $table->index('applied_by', 'storage_migration_result_extra_index');
    }
}

function storageMigrationShapeDefineResultRecords(Blueprint $table, string $variant): void
{
    if (in_array($variant, ['missing_primary', 'wrong_primary'], true)) {
        $table->unsignedBigInteger('id');
    } elseif ($variant === 'wrong_auto_increment') {
        $table->unsignedBigInteger('id')->primary();
    } else {
        $table->bigIncrements('id');
    }
    $table->string('result_ref', $variant === 'wrong_string_length' ? 63 : 64);
    $table->string('record_id', 39);
    if ($variant === 'wrong_type') {
        $table->unsignedBigInteger('owner_ref');
    } else {
        $table->string('owner_ref', 191)->nullable($variant === 'wrong_nullable');
    }
    if ($variant === 'wrong_signed') {
        $table->bigInteger('from_revision');
    } elseif ($variant === 'wrong_integer_width') {
        $table->unsignedInteger('from_revision');
    } else {
        $table->unsignedBigInteger('from_revision');
    }
    $table->unsignedBigInteger('to_revision');
    $table->unsignedBigInteger('target_schema_version');
    if ($variant === 'wrong_string_kind') {
        $table->string('content_hash', 64);
    } else {
        $table->char('content_hash', 64);
    }
    if ($variant === 'extra_column') {
        $table->string('foreign_column')->nullable();
    }
    if ($variant === 'wrong_primary') {
        $table->primary('result_ref');
    }
    if ($variant === 'wrong_unique') {
        $table->unique(['result_ref', 'owner_ref'], 'storage_migration_result_record_unique');
    } elseif ($variant !== 'missing_unique') {
        $table->unique(['result_ref', 'record_id'], 'storage_migration_result_record_unique');
    }
    if ($variant === 'extra_unique') {
        $table->unique('owner_ref', 'storage_migration_result_record_extra_unique');
    }
    if ($variant === 'wrong_secondary') {
        $table->index(['result_ref', 'target_schema_version'], 'storage_migration_result_owner_index');
    } elseif ($variant !== 'missing_secondary') {
        $table->index(['result_ref', 'owner_ref'], 'storage_migration_result_owner_index');
    }
    if ($variant === 'extra_secondary') {
        $table->index('to_revision', 'storage_migration_result_record_extra_index');
    }
}

function storageMigrationShapeContractSnapshot(Connection $connection, string $table): string
{
    return json_encode([
        'columns' => $connection->getSchemaBuilder()->getColumns($table),
        'indexes' => $connection->getSchemaBuilder()->getIndexes($table),
    ], JSON_THROW_ON_ERROR);
}
