<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The revision a pending schedule will publish, on the publication's state row.
 *
 * A published record that gets a newer revision scheduled stays published: its head
 * keeps serving the site until the sweep promotes the scheduled revision. The state row
 * then carries both — `published_revision` (live) and `scheduled_revision` with
 * `scheduled_at` (pending). A record that is not published and gets a schedule is
 * `scheduled`, not live, as before.
 *
 * Additive and nullable: existing rows keep their meaning. A schedule written before
 * this column has it null, and the lifecycle reads its revision from the log as it
 * always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('larena_storage_publication_states', 'scheduled_revision')) {
            Schema::table('larena_storage_publication_states', static function (Blueprint $table): void {
                $table->unsignedBigInteger('scheduled_revision')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('larena_storage_publication_states', 'scheduled_revision')) {
            Schema::table('larena_storage_publication_states', static function (Blueprint $table): void {
                $table->dropColumn('scheduled_revision');
            });
        }
    }
};
