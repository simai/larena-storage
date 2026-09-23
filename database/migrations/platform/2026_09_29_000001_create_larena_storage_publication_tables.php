<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication state and its append-only log.
 *
 * Two tables, not one, and not for symmetry. The state row is the current truth for a
 * record in a scope and a locale, and the published head is a column on it. The log
 * answers questions about the past — `storage.publication.history`, and "which head
 * did this unpublish replace" — which a current-state row cannot answer at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_publication_states')) {
            Schema::create('larena_storage_publication_states', static function (Blueprint $table): void {
                $table->string('publication_id', 190)->primary();
                $table->string('schema_id', 120);
                $table->string('record_id', 39);
                $table->string('scope_ref', 80);
                $table->string('locale', 16);
                $table->string('state', 16)->default('draft');
                $table->unsignedBigInteger('published_revision')->nullable();
                $table->unsignedBigInteger('previous_published_revision')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->string('updated_by', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamps();

                $table->unique(
                    ['schema_id', 'record_id', 'scope_ref', 'locale'],
                    'storage_pub_record_scope_locale_uq',
                );
                $table->index(['scope_ref', 'locale', 'state'], 'storage_pub_scope_locale_state_idx');
                $table->index(['state', 'scheduled_at'], 'storage_pub_state_scheduled_idx');
            });
        }

        if (!Schema::hasTable('larena_storage_publication_log')) {
            Schema::create('larena_storage_publication_log', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('publication_id', 190);
                $table->string('schema_id', 120);
                $table->string('record_id', 39);
                $table->string('scope_ref', 80);
                $table->string('locale', 16);
                $table->string('transition', 24);
                $table->string('from_state', 16);
                $table->string('to_state', 16);
                $table->unsignedBigInteger('revision')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->string('actor_id', 191);
                $table->string('correlation_id', 191)->nullable();
                $table->timestamp('created_at');

                $table->index(['publication_id', 'id'], 'storage_pub_log_publication_idx');
                $table->index(['scope_ref', 'locale', 'created_at'], 'storage_pub_log_scope_time_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_publication_log');
        Schema::dropIfExists('larena_storage_publication_states');
    }
};
