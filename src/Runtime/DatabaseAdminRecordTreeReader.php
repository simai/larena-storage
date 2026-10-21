<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\ConnectionInterface;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Storage\Contracts\AdminRecordTreeReader;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;

/**
 * Reads the current head of every record of a structure and its active tree
 * parent in two queries; a record written under an older schema version reads
 * through the converter. An editor needs storage.record.read.
 */
final readonly class DatabaseAdminRecordTreeReader implements AdminRecordTreeReader
{
    private StorageRecordConverter $converter;

    public function __construct(
        private ConnectionInterface $connection,
        private ActorOperationAuthorizer $authorizer,
        ?StorageRecordConverter $converter = null,
    ) {
        $this->converter = $converter ?? new StorageRecordConverter($connection);
    }

    public function tree(string $schemaId, string $relationKey, string $actor, int $limit = 500): array
    {
        if ($limit < 1 || $limit > 2000) {
            throw new StorageRejected('storage_record_tree_limit_invalid');
        }
        $this->authorizer->assertAllowed($actor, 'storage.record.read');

        $records = $this->connection->table('larena_storage_records as r')
            ->join('larena_storage_record_versions as v', static function ($join): void {
                $join->on('v.schema_id', '=', 'r.schema_id')
                    ->on('v.record_id', '=', 'r.record_id')
                    ->on('v.revision', '=', 'r.current_revision');
            })
            ->where('r.schema_id', $schemaId)
            ->orderBy('r.created_at')
            ->orderBy('r.record_id')
            ->limit($limit)
            ->select(['r.record_id', 'r.owner_ref', 'r.current_revision', 'r.current_schema_version', 'v.values_json'])
            // The schema's current version, in the same query.
            ->selectSub(fn ($query) => $query->from('larena_storage_schema_versions')->selectRaw('max(version)')->where('schema_id', $schemaId), 'latest_schema_version')
            ->get();

        $parents = [];
        foreach ($this->connection->table('larena_storage_record_relations')
            ->where('relation_key', $relationKey)
            ->where('schema_id', $schemaId)
            ->where('kind', 'tree_parent')
            ->where('status', 'active')
            ->get(['from_record_id', 'to_record_id', 'order_index']) as $relation) {
            $parents[(string) $relation->from_record_id] = [(string) $relation->to_record_id, (int) $relation->order_index];
        }

        $rows = [];
        foreach ($records as $record) {
            $decoded = json_decode((string) $record->values_json, true);
            $values = [];
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                $values[(string) $key] = $value;
            }
            $recordId = (string) $record->record_id;
            $version = (int) $record->current_schema_version;
            $currentVersion = (int) $record->latest_schema_version;
            if ($version < $currentVersion) {
                $values = $this->converter->convert($schemaId, $version, $currentVersion, $values);
            }
            $rows[] = [
                'record_id' => $recordId,
                'parent_record_id' => $parents[$recordId][0] ?? null,
                'order_index' => $parents[$recordId][1] ?? 0,
                'revision' => (int) $record->current_revision,
                'schema_version' => (int) $record->current_schema_version,
                'owner_ref' => (string) $record->owner_ref,
                'values' => $values,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['order_index'], $a['record_id']] <=> [$b['order_index'], $b['record_id']]);

        return $rows;
    }
}
