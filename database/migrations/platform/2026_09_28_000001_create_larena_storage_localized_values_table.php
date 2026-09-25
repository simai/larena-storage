<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localized values: one row per revision, locale and field.
 *
 * The layout is the whole decision. A locale column on
 * `larena_storage_record_versions` would need one row per locale for one revision,
 * breaking the unique key on `(schema_id, record_id, revision)` and the idea that a
 * revision is one unit of change. A locale dimension inside `values_json` could be
 * neither indexed nor counted without decoding every document. Rows keyed by
 * revision, locale and field make a per-locale read indexed, coverage a count, and
 * immutability free — a row belongs to its revision and is never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_localized_values')) {
            return;
        }

        Schema::create('larena_storage_localized_values', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('schema_id', 120);
            $table->string('record_id', 39);
            $table->unsignedBigInteger('revision');
            $table->string('locale', 16);
            $table->string('field_key', 64);
            $table->json('value_json');
            $table->char('content_hash', 64);
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamp('created_at');

            $table->unique(
                ['schema_id', 'record_id', 'revision', 'locale', 'field_key'],
                'storage_localized_rev_locale_field_uq',
            );
            $table->index(['schema_id', 'record_id', 'locale'], 'storage_localized_record_locale_idx');
            $table->index(['schema_id', 'locale', 'field_key'], 'storage_localized_locale_field_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_localized_values');
    }
};
