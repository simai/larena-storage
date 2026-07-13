<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_schemas')) {
            Schema::create('larena_storage_schemas', static function (Blueprint $table): void {
                $table->string('schema_id', 120)->primary();
                $table->unsignedBigInteger('current_version');
                $table->char('current_hash', 64);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('larena_storage_schema_versions')) {
            Schema::create('larena_storage_schema_versions', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('schema_id', 120);
                $table->unsignedBigInteger('version');
                $table->json('definition');
                $table->char('definition_hash', 64);
                $table->string('owner_package', 120);
                $table->string('created_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamp('created_at');
                $table->unique(['schema_id', 'version'], 'storage_schema_version_unique');
                $table->index(['schema_id', 'created_at'], 'storage_schema_created_index');
            });
        }

        if (!Schema::hasTable('larena_storage_records')) {
            Schema::create('larena_storage_records', static function (Blueprint $table): void {
                $table->string('record_id', 39)->primary();
                $table->string('schema_id', 120);
                $table->string('owner_ref', 191);
                $table->unsignedBigInteger('current_revision');
                $table->unsignedBigInteger('current_schema_version');
                $table->char('current_hash', 64);
                $table->timestamps();
                $table->unique(['schema_id', 'owner_ref'], 'storage_schema_owner_unique');
                $table->index(['schema_id', 'current_revision'], 'storage_record_head_index');
            });
        }

        if (!Schema::hasTable('larena_storage_record_versions')) {
            Schema::create('larena_storage_record_versions', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('schema_id', 120);
                $table->string('record_id', 39);
                $table->unsignedBigInteger('revision');
                $table->string('owner_ref', 191);
                $table->unsignedBigInteger('schema_version');
                $table->json('values_json');
                $table->char('content_hash', 64);
                $table->string('operation', 24);
                $table->string('created_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamp('created_at');
                $table->unique(['schema_id', 'record_id', 'revision'], 'storage_record_revision_unique');
                $table->index(['schema_id', 'owner_ref', 'revision'], 'storage_record_owner_index');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'larena_storage_record_versions',
            'larena_storage_records',
            'larena_storage_schema_versions',
            'larena_storage_schemas',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::getConnection()->table($table)->exists()) {
                throw new RuntimeException('storage_typed_content_rollback_would_lose_data');
            }
        }

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
