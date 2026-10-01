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

    larena_storage_localized_seed_versions($connection);

    return $connection;
}

/**
 * The schema and record versions a translation belongs to. A localized value is
 * validated against the typed field of its revision's schema version, so the
 * fixture records exist with a typed site_node schema.
 *
 * @param list<array{string, int}> $records record id and revision
 */
function larena_storage_localized_seed_versions(Connection $connection, string $schemaId = 'site.pages', array $records = [
    ['node-1', 1], ['node-1', 2], ['node-2', 1], ['node-3', 1], ['node-4', 1],
]): void {
    $schema = $connection->getSchemaBuilder();
    if (!$schema->hasTable('larena_storage_schema_versions')) {
        $schema->create('larena_storage_schema_versions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('schema_id', 120);
            $table->unsignedBigInteger('version');
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->string('owner_package', 120);
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamp('created_at');
        });
    }
    if (!$schema->hasTable('larena_storage_record_versions')) {
        $schema->create('larena_storage_record_versions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('schema_id', 120);
            $table->string('record_id', 39);
            $table->unsignedBigInteger('revision');
            $table->string('owner_ref', 191);
            $table->unsignedBigInteger('schema_version');
            $table->json('values_json');
            $table->char('content_hash', 64);
            $table->string('operation', 24);
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamp('created_at');
        });
    }
    if (!$connection->table('larena_storage_schema_versions')->where('schema_id', $schemaId)->exists()) {
        $definition = json_encode(['fields' => [
            ['key' => 'slug', 'type' => 'string', 'type_version' => 1, 'visibility' => 'public', 'constraints' => []],
            ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'visibility' => 'public', 'constraints' => []],
            ['key' => 'description', 'type' => 'text', 'type_version' => 1, 'visibility' => 'public', 'constraints' => []],
            ['key' => 'footer', 'type' => 'text', 'type_version' => 1, 'visibility' => 'public', 'constraints' => []],
        ]], JSON_THROW_ON_ERROR);
        $connection->table('larena_storage_schema_versions')->insert([
            'schema_id' => $schemaId, 'version' => 1, 'definition' => $definition,
            'definition_hash' => hash('sha256', $definition), 'owner_package' => 'larena/storage',
            'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
    foreach ($records as [$recordId, $revision]) {
        $connection->table('larena_storage_record_versions')->insert([
            'schema_id' => $schemaId, 'record_id' => $recordId, 'revision' => $revision, 'owner_ref' => $recordId,
            'schema_version' => 1, 'values_json' => '{}', 'content_hash' => hash('sha256', '{}'),
            'operation' => 'create', 'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

/** The localized fields of the site_node fixture. */
function larena_storage_localized_fields(): array
{
    return ['title', 'description'];
}
