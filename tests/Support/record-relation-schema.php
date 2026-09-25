<?php

declare(strict_types=1);

require_once __DIR__ . '/structure-role-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Larena\Storage\Contracts\RelationDescriptor;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;

/**
 * The frozen relation table on an in-memory connection, mirroring the migration.
 */
function larena_storage_relation_connection(): Connection
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();

    $connection->getSchemaBuilder()->create('larena_storage_record_relations', static function (Blueprint $table): void {
        $table->string('relation_id', 190)->primary();
        $table->string('relation_key', 64);
        $table->string('schema_id', 120);
        $table->string('from_record_id', 39);
        $table->string('to_record_id', 39);
        $table->string('kind', 16);
        $table->string('tree_child_key', 39)->nullable();
        $table->string('path', 2048)->nullable();
        $table->unsignedSmallInteger('depth')->default(0);
        $table->integer('order_index')->default(0);
        $table->string('delete_policy', 16);
        $table->string('status', 16)->default('active');
        $table->string('created_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['relation_key', 'from_record_id', 'to_record_id'], 'storage_relations_key_from_to_uq');
        $table->unique(['relation_key', 'tree_child_key'], 'storage_relations_key_child_uq');
        $table->index(['relation_key', 'to_record_id', 'order_index'], 'storage_relations_parent_order_idx');
        $table->index(['schema_id', 'kind'], 'storage_relations_schema_kind_idx');
        $table->index(['path'], 'storage_relations_path_idx');
    });

    return $connection;
}

function larena_storage_tree_descriptor(
    RelationDeletePolicy $policy = RelationDeletePolicy::Cascade,
    string $key = 'site_tree_parent',
): RelationDescriptor {
    return new RelationDescriptor(
        relationKey: $key,
        kind: RelationKind::TreeParent,
        deletePolicy: $policy,
        targetRoleCode: 'site_node',
    );
}

function larena_storage_reference_descriptor(
    RelationDeletePolicy $policy = RelationDeletePolicy::Restrict,
    string $key = 'doc_space_ref',
): RelationDescriptor {
    return new RelationDescriptor(
        relationKey: $key,
        kind: RelationKind::Reference,
        deletePolicy: $policy,
        targetRoleCode: 'doc_space',
    );
}
