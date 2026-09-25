<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record relations: references and tree edges on one table.
 *
 * Two design points live in the index list rather than in code.
 *
 * `tree_child_key` is NULL for a reference edge and equals the child record id for
 * a tree edge, so the unique index on (relation_key, tree_child_key) *is* the
 * single-parent guarantee — enforced by the database, not by a check that a
 * concurrent write could slip past. It is portable because both SQLite and MySQL
 * allow repeated NULLs in a unique index.
 *
 * `path` is a materialized path, so a subtree is a prefix match and ancestors are
 * a string split. Neither needs a recursive CTE, which MySQL 5.7 does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_record_relations')) {
            return;
        }

        Schema::create('larena_storage_record_relations', static function (Blueprint $table): void {
            $table->string('relation_id', 190)->primary();
            $table->string('relation_key', 64);
            $table->string('schema_id', 120);
            $table->string('from_record_id', 39);
            $table->string('to_record_id', 39);
            $table->string('kind', 16);
            $table->string('tree_child_key', 39)->nullable();
            $table->string('path', 2048)->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->integer('order_index')->default(0);
            $table->string('delete_policy', 16);
            $table->string('status', 16)->default('active');
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['relation_key', 'from_record_id', 'to_record_id'], 'storage_relations_key_from_to_uq');
            $table->unique(['relation_key', 'tree_child_key'], 'storage_relations_key_child_uq');
            $table->index(['relation_key', 'to_record_id', 'order_index'], 'storage_relations_parent_order_idx');
            $table->index(['schema_id', 'kind'], 'storage_relations_schema_kind_idx');
        });

        // A 2048-character column cannot be indexed whole on MySQL with utf8mb4,
        // so the path index takes a prefix. SQLite ignores the length and indexes
        // the column, which is why the statement is driver-specific rather than a
        // Blueprint index: the alternative is a migration that passes on SQLite
        // and fails on the production driver.
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement(
                'CREATE INDEX storage_relations_path_idx ON larena_storage_record_relations (path(191))',
            );
        } else {
            Schema::table('larena_storage_record_relations', static function (Blueprint $table): void {
                $table->index(['path'], 'storage_relations_path_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_record_relations');
    }
};
