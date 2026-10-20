<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registry of витрины: one row per storage with its table, its column layout
 * and whether it is in sync with the versions. The витрина tables themselves are
 * created per storage at runtime; rolling this migration back drops every table
 * the registry names.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_views')) {
            return;
        }
        Schema::create('larena_storage_views', static function (Blueprint $table): void {
            $table->string('schema_id', 120)->primary();
            $table->string('table_name', 64)->unique('storage_views_table_name_uq');
            $table->longText('layout_json');
            $table->char('layout_hash', 64);
            $table->string('state', 16);
            $table->unsignedInteger('built_schema_version')->nullable();
            $table->string('build_cursor', 64)->nullable();
            $table->string('build_run_ref', 64)->nullable();
            $table->timestamps();

            $table->index(['state', 'schema_id'], 'storage_views_state_idx');
        });
    }

    public function down(): void
    {
        $names = [];
        if (Schema::hasTable('larena_storage_views')) {
            foreach (Schema::getConnection()->table('larena_storage_views')->pluck('table_name') as $name) {
                $names[] = (string) $name;
            }
        }
        // Tables whose registration is gone (emptied, or left by an interrupted build) go too.
        foreach (Schema::getTables() as $table) {
            $names[] = (string) ($table['name'] ?? '');
        }
        foreach (array_unique($names) as $name) {
            if (preg_match('/\Alarena_storage_view_[0-9a-f]{24}\z/D', $name) === 1) {
                Schema::dropIfExists($name . '_m');
                Schema::dropIfExists($name);
            }
        }
        Schema::dropIfExists('larena_storage_views');
    }
};
