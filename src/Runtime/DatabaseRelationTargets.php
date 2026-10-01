<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\RelationTargets;

/**
 * Answers the relation writer from Storage's own tables: the record head, its
 * current version, the schema version it was written under and the role bindings.
 */
final readonly class DatabaseRelationTargets implements RelationTargets
{
    /** The workbench keeps a record's scope in this reserved field. */
    public const SCOPE_FIELD = 'larena_scope_ref';

    public function __construct(private Connection $connection)
    {
    }

    public function schemaOf(string $recordId): ?string
    {
        $schemaId = $this->connection->table('larena_storage_records')->where('record_id', $recordId)->value('schema_id');

        return is_string($schemaId) ? $schemaId : null;
    }

    public function scopeOf(string $recordId): ?string
    {
        $values = $this->currentVersion($recordId)['values'] ?? null;
        $scope = is_array($values) ? ($values[self::SCOPE_FIELD] ?? null) : null;

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    public function playsRole(string $schemaId, string $roleCode): bool
    {
        return $this->connection->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('role_ref', 'like', $roleCode . '@v%')
            ->where('status', 'active')
            ->exists();
    }

    public function declaredRelations(string $recordId): ?array
    {
        $version = $this->currentVersion($recordId);
        if ($version === null) {
            return null;
        }
        $definition = $this->connection->table('larena_storage_schema_versions')
            ->where('schema_id', $version['schema_id'])
            ->where('version', $version['schema_version'])
            ->value('definition');
        $decoded = is_string($definition) ? json_decode($definition, true) : null;
        $relations = is_array($decoded) && is_array($decoded['relations'] ?? null) ? $decoded['relations'] : [];
        if ($relations === []) {
            return null;
        }

        $declared = [];
        foreach ($relations as $relation) {
            if (is_array($relation) && is_string($relation['relation_key'] ?? null)) {
                $declared[$relation['relation_key']] = $relation;
            }
        }

        return $declared;
    }

    /** @return array{schema_id: string, schema_version: int, values: array<string, mixed>}|null */
    private function currentVersion(string $recordId): ?array
    {
        $head = $this->connection->table('larena_storage_records')->where('record_id', $recordId)->first();
        if ($head === null) {
            return null;
        }
        $head = (array) $head;
        $row = $this->connection->table('larena_storage_record_versions')
            ->where('schema_id', $head['schema_id'])
            ->where('record_id', $recordId)
            ->where('revision', $head['current_revision'])
            ->first();
        $values = $row === null ? [] : json_decode((string) ((array) $row)['values_json'], true);

        return [
            'schema_id' => (string) $head['schema_id'],
            'schema_version' => (int) $head['current_schema_version'],
            'values' => is_array($values) ? $values : [],
        ];
    }
}
