<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A structure a SitePack import is still writing names its run here (larena_storage_sitepack_runs.run_ref). Until the
// import ends the structure is hidden from lists and refuses every read and write of the workbench; the import clears
// the column when it ends, and a cancelled or failed import removes the structure. Like the archive state it lives on
// the head row, outside the versioned descriptor and its hash. Additive: existing rows stay null.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('larena_storage_workbench_structures', 'import_run_ref')) {
            Schema::table('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->string('import_run_ref', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('larena_storage_workbench_structures', 'import_run_ref')) {
            Schema::table('larena_storage_workbench_structures', static function (Blueprint $table): void {
                $table->dropColumn('import_run_ref');
            });
        }
    }
};
