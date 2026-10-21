<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;
use Throwable;

/**
 * Reads and writes `larena_storage_field_encryption_runs`. Before the table is migrated there is no run: switching
 * encryption on a field with values stays refused (`storage_field_encryption_rewrite_required`), and every check
 * for an open run answers none.
 */
final class StorageFieldEncryptionRuns
{
    public const TABLE = 'larena_storage_field_encryption_runs';
    private const OPEN_STATES = [
        StorageWorkbenchEncryptionRun::STATE_QUEUED,
        StorageWorkbenchEncryptionRun::STATE_RUNNING,
        StorageWorkbenchEncryptionRun::STATE_FAILED,
    ];

    private ?bool $available = null;

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

    public function insert(StorageFieldEncryptionRunRow $row): void
    {
        if (!$this->available()) {
            throw new StorageRejected(StorageEncryptionPolicy::REWRITE_REQUIRED);
        }
        $this->database->table(self::TABLE)->insert(['run_ref' => $row->runRef] + $this->columns($row) + [
            'schema_id' => $row->schemaId,
            'scope_ref' => $row->scopeRef,
            'structure_id' => $row->structureId,
            'mode' => $row->mode,
            'field_keys_json' => json_encode($row->fields, JSON_THROW_ON_ERROR),
            'source_key_id' => $row->sourceKeyId,
            'target_key_id' => $row->targetKeyId,
            'total' => $row->total,
            'created_by' => $row->createdBy,
            'correlation_id' => $row->correlationId,
            'created_at' => $row->createdAt,
        ]);
    }

    /** Saves the state, the position, the counts and the walk of a run. */
    public function put(StorageFieldEncryptionRunRow $row): void
    {
        $this->database->table(self::TABLE)->where('run_ref', $row->runRef)->update($this->columns($row));
    }

    public function find(string $runRef, bool $forUpdate = false): ?StorageFieldEncryptionRunRow
    {
        if (!$this->available() || preg_match(StorageFieldEncryptionRunRow::RUN_REF_PATTERN, $runRef) !== 1) {
            return null;
        }
        $query = $this->database->table(self::TABLE)->where('run_ref', $runRef);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /** The run of a storage that is queued, running or failed, if any. */
    public function open(string $schemaId, bool $forUpdate = false): ?StorageFieldEncryptionRunRow
    {
        if (!$this->available()) {
            return null;
        }
        $query = $this->database->table(self::TABLE)
            ->where('schema_id', $schemaId)
            ->whereIn('state', self::OPEN_STATES)
            ->orderBy('sequence');
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /**
     * The latest run of each storage, for the structures' state: an open one, or the last one that ended. One query.
     *
     * @param list<string> $schemaIds
     * @return array<string, StorageFieldEncryptionRunRow>
     */
    public function latest(array $schemaIds): array
    {
        if ($schemaIds === [] || !$this->available()) {
            return [];
        }
        $latest = [];
        foreach ($this->database->table(self::TABLE)->whereIn('schema_id', $schemaIds)->orderBy('sequence')->get() as $row) {
            $hydrated = $this->hydrate($row);
            if (!($latest[$hydrated->schemaId] ?? null)?->open()) {
                $latest[$hydrated->schemaId] = $hydrated;
            }
        }

        return $latest;
    }

    /**
     * The fields of a storage that an open `decrypt` run still opens: the витрина keeps them out (no column, no
     * search text, no filter, no sort) until it is done.
     *
     * @return list<string>
     */
    public function sealedForView(string $schemaId): array
    {
        try {
            $open = $this->open($schemaId);
        } catch (Throwable) {
            return [];
        }

        return $open !== null && $open->mode === StorageWorkbenchEncryptionRun::MODE_DECRYPT ? $open->fields : [];
    }

    /** Every run of a storage goes with a structure purge. */
    public function purge(string $schemaId): int
    {
        return $this->available() ? $this->database->table(self::TABLE)->where('schema_id', $schemaId)->delete() : 0;
    }

    /**
     * The fields with `encrypted: true` added for the витрина, for the fields of $schemaId an open decrypt run opens.
     *
     * @param list<array<string, mixed>> $fields
     * @param list<string> $sealed
     * @return list<array<string, mixed>>
     */
    public static function viewFields(array $fields, array $sealed): array
    {
        if ($sealed === []) {
            return $fields;
        }

        return array_map(
            static fn (array $field): array => in_array($field['key'] ?? null, $sealed, true) ? ['encrypted' => true] + $field : $field,
            $fields,
        );
    }

    /** @return array<string, mixed> */
    private function columns(StorageFieldEncryptionRunRow $row): array
    {
        return [
            'state' => $row->state,
            'phase' => $row->phase,
            'cursor' => $row->cursor,
            'worker_ref' => $row->workerRef,
            'rewritten' => $row->rewritten,
            'skipped' => $row->skipped,
            'reason' => $row->reason,
            'updated_at' => $row->updatedAt,
            'finished_at' => $row->finishedAt,
        ];
    }

    private function hydrate(stdClass $row): StorageFieldEncryptionRunRow
    {
        try {
            $fields = json_decode((string) $row->field_keys_json, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_field_encryption_run_corrupt');
        }
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new StorageRejected('storage_field_encryption_run_corrupt');
        }
        $keys = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new StorageRejected('storage_field_encryption_run_corrupt');
            }
            $keys[] = $field;
        }

        return new StorageFieldEncryptionRunRow(
            (string) $row->run_ref,
            (string) $row->schema_id,
            (string) $row->scope_ref,
            (string) $row->structure_id,
            (string) $row->mode,
            $keys,
            $row->source_key_id === null ? null : (string) $row->source_key_id,
            $row->target_key_id === null ? null : (string) $row->target_key_id,
            (string) $row->state,
            (string) $row->phase,
            $row->cursor === null ? null : (string) $row->cursor,
            $row->worker_ref === null ? null : (string) $row->worker_ref,
            (int) $row->rewritten,
            (int) $row->skipped,
            (int) $row->total,
            $row->reason === null ? null : (string) $row->reason,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
            (string) $row->updated_at,
            $row->finished_at === null ? null : (string) $row->finished_at,
        );
    }
}
