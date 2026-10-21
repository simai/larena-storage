<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field changes of workbench structures that check or convert every record in the
 * background (the `storage.schema.migrate` queue job): one row per change, with the
 * target the structure switches to, the walk's position and its state. The structure
 * keeps its fields until the change ends; a change that failed or was cancelled
 * changed nothing.
 *
 * `larena_storage_field_change_records` holds, while a change runs, the revision the
 * walk checked of each record: a record written since (another revision) or created
 * since (no row) is checked again before the switch. Emptied when the change ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_field_changes')) {
            $this->changes();
        }
        if (!Schema::hasTable('larena_storage_field_change_records')) {
            Schema::create('larena_storage_field_change_records', static function (Blueprint $table): void {
                $table->string('change_ref', 64);
                $table->string('record_id', 39);
                $table->unsignedBigInteger('revision');
                $table->primary(['change_ref', 'record_id'], 'storage_field_change_records_pk');
            });
        }
    }

    private function changes(): void
    {
        Schema::create('larena_storage_field_changes', static function (Blueprint $table): void {
            // The order changes were asked for in (started_at has seconds only).
            $table->bigIncrements('sequence');
            $table->string('change_ref', 64)->unique('storage_field_changes_ref_uq');
            $table->string('schema_id', 120);
            $table->string('scope_ref', 191);
            $table->string('structure_id', 120);
            $table->unsignedInteger('source_schema_version');
            $table->unsignedInteger('expected_structure_version');
            $table->longText('target_definition');
            $table->char('target_hash', 64);
            $table->longText('descriptor_json');
            // The keys whose values the change converts or checks (a JSON list).
            $table->text('field_keys');
            $table->string('state', 16);
            $table->string('cursor', 64)->nullable();
            $table->string('run_ref', 64)->nullable();
            $table->unsignedBigInteger('checked')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('reason', 100)->nullable();
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->string('started_at', 32);
            $table->string('updated_at', 32);
            $table->string('finished_at', 32)->nullable();

            $table->index(['schema_id', 'state'], 'storage_field_changes_schema_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_field_change_records');
        Schema::dropIfExists('larena_storage_field_changes');
    }
};
