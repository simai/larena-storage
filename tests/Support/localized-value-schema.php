<?php

declare(strict_types=1);

require_once __DIR__ . '/structure-role-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The frozen localized value table on an in-memory connection.
 */
function larena_storage_localized_connection(): Connection
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();

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
        $table->index(['schema_id', 'record_id', 'locale'], 'storage_localized_record_locale_idx');
        $table->index(['schema_id', 'locale', 'field_key'], 'storage_localized_locale_field_idx');
    });

    return $connection;
}

/** The localized fields of the site_node fixture. */
function larena_storage_localized_fields(): array
{
    return ['title', 'description'];
}
