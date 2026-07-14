<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Database\StorageSchemaMigrationTableShapeGuard;

return new class extends Migration
{
    public function up(): void
    {
        $guard = new StorageSchemaMigrationTableShapeGuard(Schema::getConnection());
        $missing = $guard->preflightUp();

        if (in_array('larena_storage_schema_migration_plans', $missing, true)) {
            Schema::create('larena_storage_schema_migration_plans', static function (Blueprint $table): void {
                $table->string('plan_ref', 64)->primary();
                $table->string('schema_id', 120);
                $table->unsignedBigInteger('source_version');
                $table->char('source_hash', 64);
                $table->unsignedBigInteger('target_version');
                $table->char('target_hash', 64);
                $table->json('target_definition');
                $table->string('compatibility_class', 48);
                $table->unsignedBigInteger('added_optional_count');
                $table->unsignedBigInteger('record_count');
                $table->char('record_heads_hash', 64);
                $table->char('plan_hash', 64);
                $table->string('created_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamp('created_at');
                $table->index(['schema_id', 'source_version'], 'storage_migration_plan_schema_index');
            });
        }
        if (in_array('larena_storage_schema_migration_plan_records', $missing, true)) {
            Schema::create('larena_storage_schema_migration_plan_records', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('plan_ref', 64);
                $table->string('record_id', 39);
                $table->string('owner_ref', 191);
                $table->unsignedBigInteger('expected_revision');
                $table->unsignedBigInteger('expected_schema_version');
                $table->char('expected_content_hash', 64);
                $table->unique(['plan_ref', 'record_id'], 'storage_migration_plan_record_unique');
                $table->index(['plan_ref', 'owner_ref'], 'storage_migration_plan_owner_index');
            });
        }
        if (in_array('larena_storage_schema_migration_results', $missing, true)) {
            Schema::create('larena_storage_schema_migration_results', static function (Blueprint $table): void {
                $table->string('result_ref', 64)->primary();
                $table->string('plan_ref', 64);
                $table->string('schema_id', 120);
                $table->unsignedBigInteger('target_version');
                $table->char('target_hash', 64);
                $table->unsignedBigInteger('migrated_record_count');
                $table->char('migrated_records_hash', 64);
                $table->char('result_hash', 64);
                $table->string('applied_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamp('applied_at');
                $table->unique('plan_ref', 'storage_migration_result_plan_unique');
                $table->index(['schema_id', 'target_version'], 'storage_migration_result_schema_index');
            });
        }
        if (in_array('larena_storage_schema_migration_result_records', $missing, true)) {
            Schema::create('larena_storage_schema_migration_result_records', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('result_ref', 64);
                $table->string('record_id', 39);
                $table->string('owner_ref', 191);
                $table->unsignedBigInteger('from_revision');
                $table->unsignedBigInteger('to_revision');
                $table->unsignedBigInteger('target_schema_version');
                $table->char('content_hash', 64);
                $table->unique(['result_ref', 'record_id'], 'storage_migration_result_record_unique');
                $table->index(['result_ref', 'owner_ref'], 'storage_migration_result_owner_index');
            });
        }

        $guard->assertCompleteCompatible();
    }

    public function down(): void
    {
        $guard = new StorageSchemaMigrationTableShapeGuard(Schema::getConnection());
        foreach ($guard->preflightDown() as $table) {
            Schema::drop($table);
        }
    }
};
