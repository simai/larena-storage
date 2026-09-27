<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A structure can be archived and restored. The state lives on the head row and
// stays out of the versioned descriptor and its hash: archiving changes whether
// the structure accepts writes, not what it describes.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('larena_storage_workbench_structures', 'archived_at')) {
            Schema::table('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable();
                $table->string('archived_by', 191)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('larena_storage_workbench_structures', 'archived_at')) {
            Schema::table('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->dropColumn(['archived_at', 'archived_by']);
            });
        }
    }
};
