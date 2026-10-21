<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\StorageWorkbenchFieldChange;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;
use Throwable;

/**
 * Reads and writes `larena_storage_field_changes` and the revisions a change's walk
 * checked (`larena_storage_field_change_records`). Before the tables are migrated a
 * change still runs, inline in the request that asked for it, and is kept in memory
 * only (nothing can resume it, and nothing needs to).
 */
final class StorageFieldChanges
{
    public const TABLE = 'larena_storage_field_changes';
    // The revision the walk of a running change checked of each record.
    public const RECORDS_TABLE = 'larena_storage_field_change_records';
    private const CHUNK = 500;

    private ?bool $available = null;
    /** @var array<string, StorageFieldChangeRow> Changes kept in memory while the table is missing. */
    private array $memory = [];
    /** @var array<string, array<string, int>> Checked revisions kept in memory while the table is missing. */
    private array $seen = [];

    public function __construct(private readonly ConnectionInterface $database)
    {
    }

    /** Only a yes is remembered, so a process that migrates sees the table at once. */
    public function available(): bool
    {
        if ($this->available !== true) {
            try {
                $this->available = !$this->database instanceof Connection
                    || $this->database->getSchemaBuilder()->hasTable(self::TABLE);
            } catch (Throwable) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    public function insert(StorageFieldChangeRow $row): void
    {
        if (!$this->available()) {
            $this->memory[$row->changeRef] = $row;

            return;
        }
        $this->database->table(self::TABLE)->insert(['change_ref' => $row->changeRef] + $this->columns($row) + [
            'schema_id' => $row->schemaId,
            'scope_ref' => $row->scopeRef,
            'structure_id' => $row->structureId,
            'source_schema_version' => $row->sourceSchemaVersion,
            'expected_structure_version' => $row->expectedStructureVersion,
            // Canonical, as a schema version's definition: its hash is the target hash.
            'target_definition' => self::canonicalJson($row->targetDefinition),
            'target_hash' => $row->targetHash,
            'descriptor_json' => self::json($row->descriptor),
            'field_keys' => self::json($row->fields),
            'total' => $row->total,
            'created_by' => $row->createdBy,
            'correlation_id' => $row->correlationId,
            'started_at' => $row->startedAt,
        ]);
    }

    /** Saves the state, the position and the run of a change. */
    public function put(StorageFieldChangeRow $row): void
    {
        if (!$this->available()) {
            $this->memory[$row->changeRef] = $row;

            return;
        }
        $this->database->table(self::TABLE)->where('change_ref', $row->changeRef)->update($this->columns($row));
    }

    public function find(string $changeRef, bool $forUpdate = false): ?StorageFieldChangeRow
    {
        if (!$this->available()) {
            return $this->memory[$changeRef] ?? null;
        }
        $query = $this->database->table(self::TABLE)->where('change_ref', $changeRef);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /** The change of a storage that is running, if any. */
    public function running(string $schemaId, bool $forUpdate = false): ?StorageFieldChangeRow
    {
        if (!$this->available()) {
            foreach ($this->memory as $row) {
                if ($row->schemaId === $schemaId && $row->running()) {
                    return $row;
                }
            }

            return null;
        }
        $query = $this->database->table(self::TABLE)
            ->where('schema_id', $schemaId)
            ->where('state', StorageWorkbenchFieldChange::STATE_RUNNING)
            ->orderBy('sequence');
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * The latest change of each storage that did not end in its switch (running,
     * failed or cancelled), for the structures' state; a running one always wins.
     * One query for all of them.
     *
     * @param list<string> $schemaIds
     * @return array<string, StorageFieldChangeRow>
     */
    public function latestOpen(array $schemaIds): array
    {
        if ($schemaIds === []) {
            return [];
        }
        $latest = [];
        if (!$this->available()) {
            // Kept in the order they were asked for.
            foreach ($this->memory as $row) {
                if (in_array($row->schemaId, $schemaIds, true) && $row->state !== StorageWorkbenchFieldChange::STATE_DONE
                    && !($latest[$row->schemaId] ?? null)?->running()) {
                    $latest[$row->schemaId] = $row;
                }
            }

            return $latest;
        }
        foreach ($this->database->table(self::TABLE)
            ->whereIn('schema_id', $schemaIds)
            ->where('state', '<>', StorageWorkbenchFieldChange::STATE_DONE)
            ->orderBy('sequence')
            ->get() as $row) {
            $hydrated = $this->hydrate($row);
            if (!($latest[$hydrated->schemaId] ?? null)?->running()) {
                $latest[$hydrated->schemaId] = $hydrated;
            }
        }

        return $latest;
    }

    /**
     * Records the revisions a walk checked.
     *
     * @param array<string, int> $revisions record id => revision
     */
    public function markSeen(string $changeRef, array $revisions): void
    {
        if ($revisions === []) {
            return;
        }
        if (!$this->available()) {
            $this->seen[$changeRef] = $revisions + ($this->seen[$changeRef] ?? []);

            return;
        }
        $rows = [];
        foreach ($revisions as $recordId => $revision) {
            $rows[] = ['change_ref' => $changeRef, 'record_id' => (string) $recordId, 'revision' => $revision];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->database->table(self::RECORDS_TABLE)->upsert($chunk, ['change_ref', 'record_id'], ['revision']);
        }
    }

    /** @return array<string, int>|null The checked revisions kept in memory; null when they are in the table. */
    public function seenInMemory(string $changeRef): ?array
    {
        return $this->available() ? null : ($this->seen[$changeRef] ?? []);
    }

    /** Empties what a change's walk checked, once the change ended. */
    public function forgetSeen(string $changeRef): void
    {
        if (!$this->available()) {
            unset($this->seen[$changeRef]);

            return;
        }
        $this->database->table(self::RECORDS_TABLE)->where('change_ref', $changeRef)->delete();
    }

    /** @return array<string, mixed> */
    private function columns(StorageFieldChangeRow $row): array
    {
        return [
            'state' => $row->state,
            'cursor' => $row->cursor,
            'run_ref' => $row->runRef,
            'checked' => $row->checked,
            'reason' => $row->reason,
            'updated_at' => $row->updatedAt,
            'finished_at' => $row->finishedAt,
        ];
    }

    private function hydrate(stdClass $row): StorageFieldChangeRow
    {
        $target = self::decode((string) $row->target_definition);
        $descriptor = self::decode((string) $row->descriptor_json);
        $keys = self::decode((string) $row->field_keys);
        $targetFields = self::rows($target['fields'] ?? null);
        $descriptorFields = self::rows($descriptor['fields'] ?? null);
        if (!is_string($target['schema_id'] ?? null) || !is_string($target['owner_package'] ?? null)
            || !is_string($descriptor['structure_id'] ?? null) || !is_string($descriptor['label'] ?? null)
            || !array_is_list($keys) || !hash_equals((string) $row->target_hash, hash('sha256', self::canonicalJson($target)))) {
            throw new StorageRejected('storage_field_change_corrupt');
        }
        $fields = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new StorageRejected('storage_field_change_corrupt');
            }
            $fields[] = $key;
        }

        return new StorageFieldChangeRow(
            (string) $row->change_ref,
            (string) $row->schema_id,
            (string) $row->scope_ref,
            (string) $row->structure_id,
            (int) $row->source_schema_version,
            (int) $row->expected_structure_version,
            ['schema_id' => $target['schema_id'], 'owner_package' => $target['owner_package'], 'fields' => $targetFields],
            (string) $row->target_hash,
            ['structure_id' => $descriptor['structure_id'], 'label' => $descriptor['label'], 'fields' => $descriptorFields],
            $fields,
            (string) $row->state,
            $row->cursor === null ? null : (string) $row->cursor,
            $row->run_ref === null ? null : (string) $row->run_ref,
            (int) $row->checked,
            (int) $row->total,
            $row->reason === null ? null : (string) $row->reason,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->started_at,
            (string) $row->updated_at,
            $row->finished_at === null ? null : (string) $row->finished_at,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new StorageRejected('storage_field_change_corrupt');
        }
        $rows = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new StorageRejected('storage_field_change_corrupt');
            }
            $entry = [];
            foreach ($item as $key => $field) {
                $entry[(string) $key] = $field;
            }
            $rows[] = $entry;
        }

        return $rows;
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_field_change_corrupt');
        }
        if (!is_array($decoded)) {
            throw new StorageRejected('storage_field_change_corrupt');
        }

        return $decoded;
    }

    /** The JSON a schema definition is hashed as (keys sorted, as SchemaDefinitionNormalizer::canonicalJson). */
    public static function canonicalJson(mixed $value): string
    {
        return self::json(self::canonicalize($value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
