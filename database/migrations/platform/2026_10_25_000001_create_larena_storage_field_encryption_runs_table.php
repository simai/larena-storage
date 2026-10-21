<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encryption runs of storages (StorageFieldEncryptionRunner, the `storage.field.encryption.rewrite` queue job): one
 * row per run that rewrites the stored form of fields in place, in every record version and translation, after the
 * schema switched (`encrypt`, `decrypt`) or the storage's data key was replaced (`rotate`).
 *
 * - `field_keys_json`: the fields the run rewrites (a JSON list; for `rotate` every field whose envelopes the retiring
 *   key sealed is rewritten, the list names the encrypted fields of the schema).
 * - `source_key_id` / `target_key_id`: the data key the values leave and the one they get (null where none applies).
 * - `state`: queued, running, done or failed. At most one queued, running or failed run a storage; the storage takes
 *   no structure change, field change or other run until it is done.
 * - `phase` + `cursor`: the walk's position, resumable: `versions` with `<record id>#<revision>`, then `localized`
 *   with the last translation row id, then `final`.
 * - `worker_ref`: the walk that holds the run; a newer walk takes it over and the older stops at its next chunk.
 * - `rewritten` / `skipped`: rows whose stored form changed, and rows the walk read and left as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_field_encryption_runs')) {
            return;
        }
        Schema::create('larena_storage_field_encryption_runs', static function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->string('run_ref', 64)->unique('storage_field_encryption_runs_ref_uq');
            $table->string('schema_id', 120);
            $table->string('scope_ref', 191);
            $table->string('structure_id', 120);
            $table->string('mode', 16);
            $table->text('field_keys_json');
            $table->string('source_key_id', 64)->nullable();
            $table->string('target_key_id', 64)->nullable();
            $table->string('state', 16);
            $table->string('phase', 16);
            $table->string('cursor', 96)->nullable();
            $table->string('worker_ref', 64)->nullable();
            $table->unsignedBigInteger('rewritten')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('reason', 100)->nullable();
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->string('created_at', 32);
            $table->string('updated_at', 32);
            $table->string('finished_at', 32)->nullable();

            $table->index(['schema_id', 'state'], 'storage_field_encryption_runs_schema_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_field_encryption_runs');
    }
};
