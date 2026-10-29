<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A structure that adopted an existing storage schema in place (DatabaseStorageWorkbench::adoptSchema) names that
// schema here, instead of the one derived from its scope and structure id, and the owner refs of its records: the
// prefix every record's owner ref starts with and, optionally, the field whose value a new record's owner ref ends
// with (site.pages:<slug>). Like the archive state it lives on the head row, outside the versioned descriptor and its
// hash. Additive: existing rows stay null and keep the derived schema id and the workbench.record: owner refs.
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'adopted_schema_id' => 120,
            'record_owner_prefix' => 64,
            'record_owner_field' => 64,
        ] as $column => $length) {
            if (!Schema::hasColumn('larena_storage_workbench_structures', $column)) {
                Schema::table('larena_storage_workbench_structures', static function (Blueprint $table) use ($column, $length): void {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['record_owner_field', 'record_owner_prefix', 'adopted_schema_id'] as $column) {
            if (Schema::hasColumn('larena_storage_workbench_structures', $column)) {
                Schema::table('larena_storage_workbench_structures', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
