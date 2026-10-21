<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage SitePack runs: an export (and, later, a plan and an import) of a storage to or from a SitePack archive.
 *
 * `larena_storage_sitepack_runs` holds one row per run: who started it, the structure, its state and phase, the
 * walk's position (`cursor`), its progress and counts, the archive it sealed (package id, SHA-256, size) and the queue
 * job that runs it. `lease_ref` names the process working on the run right now; a checkpoint that finds another one
 * stops. A run's archive is private to the person who started it and is swept after a few days.
 *
 * `larena_storage_sitepack_ids` is the run's id map, one row per structure, record or file:
 * - an export lists the files its live versions reference (`file`, action `export`, with the content SHA-256) and the
 *   records of other storages they point at (`record`, action `reference`, target_ref = their structure id);
 * - an import maps each source id to the id it got in the target site (action keep, remap, reuse, create).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_sitepack_runs')) {
            Schema::create('larena_storage_sitepack_runs', static function (Blueprint $table): void {
                $table->string('run_ref', 64)->primary();
                $table->string('kind', 16);
                $table->string('scope_ref', 191);
                $table->string('site_ref', 80)->nullable();
                $table->string('structure_id', 120)->nullable();
                $table->string('actor', 191);
                $table->string('state', 16);
                $table->string('phase', 16);
                $table->text('cursor')->nullable();
                $table->unsignedBigInteger('done')->default(0);
                $table->unsignedBigInteger('total')->default(0);
                $table->longText('counts_json')->nullable();
                $table->longText('options_json')->nullable();
                $table->longText('plan_json')->nullable();
                $table->string('plan_digest', 80)->nullable();
                $table->string('package_id', 100)->nullable();
                $table->char('archive_sha256', 64)->nullable();
                $table->unsignedBigInteger('archive_bytes')->nullable();
                $table->string('job_id', 100)->nullable();
                $table->string('lease_ref', 64)->nullable();
                $table->string('reason', 100)->nullable();
                $table->string('correlation_id', 191)->nullable();
                $table->string('created_at', 32);
                $table->string('updated_at', 32);
                $table->string('finished_at', 32)->nullable();

                $table->index(['actor', 'created_at'], 'storage_sitepack_runs_actor_idx');
                $table->index(['created_at'], 'storage_sitepack_runs_created_idx');
            });
        }
        if (!Schema::hasTable('larena_storage_sitepack_ids')) {
            Schema::create('larena_storage_sitepack_ids', static function (Blueprint $table): void {
                $table->string('run_ref', 64);
                $table->string('kind', 16);
                $table->string('source_ref', 191);
                $table->string('target_ref', 191)->nullable();
                $table->string('action', 16);
                $table->char('sha256', 64)->nullable();

                $table->primary(['run_ref', 'kind', 'source_ref'], 'storage_sitepack_ids_pk');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_sitepack_ids');
        Schema::dropIfExists('larena_storage_sitepack_runs');
    }
};
