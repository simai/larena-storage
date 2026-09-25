<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure roles and their bindings.
 *
 * Additive: no existing Storage table is touched. Index names are given
 * explicitly and kept short, because MySQL rejects an identifier over 64
 * characters while SQLite accepts it silently — a difference that only shows up
 * on the production driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_structure_roles')) {
            Schema::create('larena_storage_structure_roles', static function (Blueprint $table): void {
                $table->string('role_ref', 140)->primary();
                $table->string('role_code', 120);
                $table->unsignedInteger('role_version')->default(1);
                $table->string('title', 191);
                $table->json('required_fields');
                $table->json('optional_fields');
                $table->json('required_relations');
                $table->string('lifecycle', 24);
                $table->string('owner_package', 120);
                $table->string('status', 16)->default('active');
                $table->string('created_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamps();
                $table->unique(['role_code', 'role_version'], 'storage_roles_code_version_uq');
                $table->index(['owner_package'], 'storage_roles_owner_idx');
                $table->index(['status'], 'storage_roles_status_idx');
            });
        }

        if (!Schema::hasTable('larena_storage_structure_role_bindings')) {
            Schema::create('larena_storage_structure_role_bindings', static function (Blueprint $table): void {
                $table->string('binding_id', 190)->primary();
                $table->string('role_ref', 140);
                $table->string('schema_id', 120);
                $table->string('scope_ref', 80);
                $table->string('status', 16)->default('active');
                $table->timestamp('conformance_checked_at')->nullable();
                $table->string('bound_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamps();
                $table->unique(['role_ref', 'schema_id', 'scope_ref'], 'storage_role_bindings_role_schema_scope_uq');
                $table->index(['scope_ref', 'role_ref'], 'storage_role_bindings_scope_role_idx');
                $table->index(['schema_id'], 'storage_role_bindings_schema_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_structure_role_bindings');
        Schema::dropIfExists('larena_storage_structure_roles');
    }
};
