<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How many saved versions of each record a structure keeps («Хранить изменений»). Like the
// archive state it lives on the head row, outside the versioned descriptor and its hash:
// it says how long the history is kept, not what the structure describes. Null keeps all.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('larena_storage_workbench_structures', 'history_keep')) {
            Schema::table('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->unsignedInteger('history_keep')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('larena_storage_workbench_structures', 'history_keep')) {
            Schema::table('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->dropColumn('history_keep');
            });
        }
    }
};
