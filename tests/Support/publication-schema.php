<?php

declare(strict_types=1);

require_once __DIR__ . '/localized-value-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The frozen publication tables on an in-memory connection, mirroring the migration.
 */
function larena_storage_publication_connection(): Connection
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('larena_storage_publication_states', static function (Blueprint $table): void {
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
        $table->unique(['schema_id', 'record_id', 'scope_ref', 'locale'], 'storage_pub_record_scope_locale_uq');
        $table->index(['scope_ref', 'locale', 'state'], 'storage_pub_scope_locale_state_idx');
        $table->index(['state', 'scheduled_at'], 'storage_pub_state_scheduled_idx');
    });

    $schema->create('larena_storage_publication_log', static function (Blueprint $table): void {
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

    return $connection;
}

/**
 * The publication tables plus the localized value table, for the one test that has to
 * prove publishing touches no translation.
 */
function larena_storage_publication_with_locales_connection(): Connection
{
    $connection = larena_storage_publication_connection();

    $connection->getSchemaBuilder()->create('larena_storage_localized_values', static function (Blueprint $table): void {
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
    });

    return $connection;
}
