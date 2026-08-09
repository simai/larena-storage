<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_workbench_structures')) {
            Schema::create('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->string('structure_id', 120)->primary();
                $table->string('scope_ref', 191);
                $table->unsignedBigInteger('current_version');
                $table->unsignedBigInteger('current_schema_version');
                $table->char('current_hash', 64);
                $table->timestamps();
                $table->index(['scope_ref', 'structure_id'], 'storage_workbench_structure_scope_index');
            });
        }

        if (!Schema::hasTable('larena_storage_workbench_structure_versions')) {
            Schema::create('larena_storage_workbench_structure_versions', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('structure_id', 120);
                $table->unsignedBigInteger('version');
                $table->string('scope_ref', 191);
                $table->string('label', 120);
                $table->json('fields_json');
                $table->unsignedBigInteger('schema_version');
                $table->char('descriptor_hash', 64);
                $table->string('created_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamp('created_at');
                $table->unique(['structure_id', 'version'], 'storage_workbench_structure_version_unique');
                $table->index(['scope_ref', 'structure_id', 'created_at'], 'storage_workbench_structure_history_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_workbench_structure_versions');
        Schema::dropIfExists('larena_storage_workbench_structures');
    }
};
