<?php

declare(strict_types=1);

require_once __DIR__ . '/publication-schema.php';

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * A connection carrying everything a read contract needs: schema versions, record
 * versions, publication state and localized values.
 */
function larena_storage_read_contract_connection(): Connection
{
    $connection = larena_storage_publication_with_locales_connection();
    $schema = $connection->getSchemaBuilder();

    // Mirrors the migration one to one, including definition_hash and
    // correlation_id. A fixture that omits a NOT NULL column is worse than no
    // fixture: the SQLite smoke of this wave silently dropped every insert because
    // the fixture here was narrower than the real table.
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
        $table->unique(['schema_id', 'version'], 'storage_schema_version_unique');
        $table->index(['schema_id', 'created_at'], 'storage_schema_created_index');
    });

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
        $table->unique(['schema_id', 'record_id', 'revision'], 'storage_record_revision_unique');
    });

    $schema->create('larena_storage_structure_role_bindings', static function (Blueprint $table): void {
        $table->string('binding_id', 190)->primary();
        $table->string('role_ref', 140);
        $table->string('schema_id', 120);
        $table->string('scope_ref', 80);
        $table->string('status', 16)->default('active');
        $table->timestamp('conformance_checked_at')->nullable();
        $table->string('bound_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['role_ref', 'schema_id', 'scope_ref'], 'storage_role_bindings_role_schema_scope_uq');
    });

    return $connection;
}

/**
 * A site_node schema whose `internal_note` field is admin-only, so the projection has
 * something it must leave out.
 */
function larena_storage_define_site_node_schema(Connection $connection, string $schemaId = 'site.pages'): void
{
    $definition = json_encode(['fields' => [
            ['key' => 'slug', 'type' => 'string', 'visibility' => 'public'],
            ['key' => 'title', 'type' => 'string', 'visibility' => 'public'],
            ['key' => 'order_index', 'type' => 'integer', 'visibility' => 'public'],
            ['key' => 'target', 'type' => 'string', 'visibility' => 'public'],
            ['key' => 'internal_note', 'type' => 'string', 'visibility' => 'admin'],
            ['key' => 'draft_comment', 'type' => 'string', 'visibility' => 'protected'],
            ['key' => 'secret_token', 'type' => 'string', 'visibility' => 'encrypted'],
            ['key' => 'unmarked', 'type' => 'string'],
    ]], JSON_THROW_ON_ERROR);

    $connection->table('larena_storage_schema_versions')->insert([
        'schema_id' => $schemaId,
        'version' => 1,
        'definition' => $definition,
        'definition_hash' => hash('sha256', $definition),
        'owner_package' => 'larena/storage',
        'created_by' => 'actor:test',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

/**
 * @param array<string, mixed> $values
 */
function larena_storage_write_record_version(
    Connection $connection,
    string $recordId,
    int $revision,
    array $values,
    string $schemaId = 'site.pages',
): void {
    $encoded = json_encode($values, JSON_THROW_ON_ERROR);

    $connection->table('larena_storage_record_versions')->insert([
        'schema_id' => $schemaId,
        'record_id' => $recordId,
        'revision' => $revision,
        'owner_ref' => 'site:main/' . $recordId,
        'schema_version' => 1,
        'values_json' => $encoded,
        'content_hash' => hash('sha256', $encoded),
        'operation' => 'create',
        'created_by' => 'actor:test',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}
