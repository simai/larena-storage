<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The published-key table and its sets.
 *
 * A key (a slug) is unique among the published records of one structure, scope and
 * locale. The key field is declared per structure, so there is no column of the
 * versions to index; this table holds the key each published head shows, under a
 * unique index, and publication keeps it in its own transaction. A set row says
 * whether the table holds every published record of its structure, scope, locale and
 * key field, and along which locale chain it was built: only a ready set answers.
 *
 * The key is indexed by its sha256, so that a long key fits the index; the value is
 * kept to compare. Both tables are derived and rebuilt by
 * `larena:storage:published-keys:rebuild`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_published_keys')) {
            Schema::create('larena_storage_published_keys', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('schema_id', 120);
                $table->string('scope_ref', 80);
                $table->string('locale', 16);
                $table->string('key_field', 64);
                $table->char('key_hash', 64);
                $table->text('key_value');
                $table->string('record_id', 39);
                $table->unsignedBigInteger('revision');
                $table->timestamp('updated_at');

                $table->unique(['schema_id', 'scope_ref', 'locale', 'key_field', 'key_hash'], 'storage_published_keys_key_uq');
                $table->unique(['schema_id', 'record_id', 'scope_ref', 'locale', 'key_field'], 'storage_published_keys_record_uq');
            });
        }

        if (!Schema::hasTable('larena_storage_published_key_sets')) {
            Schema::create('larena_storage_published_key_sets', static function (Blueprint $table): void {
                $table->string('schema_id', 120);
                $table->string('scope_ref', 80);
                $table->string('locale', 16);
                $table->string('key_field', 64);
                $table->string('locale_chain', 255);
                $table->string('state', 16);
                $table->string('build_cursor', 39)->nullable();
                $table->unsignedInteger('conflicts')->default(0);
                $table->timestamp('built_at')->nullable();
                $table->timestamp('updated_at');

                $table->primary(['schema_id', 'scope_ref', 'locale', 'key_field'], 'storage_published_key_sets_pk');
                $table->index(['state'], 'storage_published_key_sets_state_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_published_key_sets');
        Schema::dropIfExists('larena_storage_published_keys');
    }
};
