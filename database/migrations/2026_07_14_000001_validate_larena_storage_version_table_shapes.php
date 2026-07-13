<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Database\StorageOwnedTableShapeGuard;

return new class extends Migration
{
    public function up(): void
    {
        (new StorageOwnedTableShapeGuard(Schema::getConnection()))->assertCompleteCompatible();
    }

    public function down(): void
    {
        // Read-only validation migration: the owned-table migration performs
        // the fail-closed rollback preflight before any table is dropped.
    }
};
