<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Closure;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbenchFieldChange;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewRegistration;
use Larena\Storage\View\StorageViewSync;
use stdClass;

/**
 * Walks the records of a field change in record id order, in chunks, and checks
 * that every current record converts to the target fields (its value converted
 * by the read converter, then normalized and validated by the target Property
 * types). No record version is written: the versions keep their schema version
 * and are read through the converter once the structure switches.
 *
 * While it walks, the витрина keeps answering from its current columns. The
 * columns the target needs for fields that already exist (a retyped field, a
 * string whose column changes) were added and reserved before the change; the
 * walk fills them with the converted values, so at the switch they are complete
 * and the old ones are dropped.
 *
 * Writes go on during the walk, under the current fields. The walk records the
 * revision it checked of each record; when it reaches the end it checks again the
 * records whose revision moved since (or that were created since), until few are
 * left, and the switch checks the last of them while it holds the storage for
 * update (writers hold it for share), so no record is switched unchecked. No
 * clock is involved.
 *
 * The position is saved with every chunk: a run that stops (cancel, timeout, a
 * crash) resumes there. A newer run takes the change over; the older stops at its
 * next chunk. Between chunks the caller's checkpoint runs (the queue job's
 * heartbeat, which also notices a queue cancel or a timeout).
 */
final readonly class StorageFieldChangeRunner
{
    public const DEFAULT_CHUNK = 500;
    public const MAX_CHUNK = 10000;
    // Catch-up passes over the records written meanwhile, before the switch takes the rest.
    private const CATCH_UP_PASSES = 5;

    private StorageRecordConverter $converter;

    public function __construct(
        private ConnectionInterface $database,
        private SchemaDefinitionNormalizer $normalizer,
        private StorageFieldChanges $changes,
        private ?StorageViewSync $views,
        StorageRecordConverter $converter,
    ) {
        $this->converter = $converter;
    }

    /**
     * Runs a change to its end. The switch is the caller's: it receives the change,
     * the витрина columns the walk filled, and the check of the records written since
     * the walk passed them, to run inside its transaction.
     *
     * @param Closure(): void|null $checkpoint
     * @param Closure(StorageFieldChangeRow, list<string>, Closure(): int): void $switch
     * @return array{state: string, checked: int, resumed: bool}
     */
    public function run(string $changeRef, int $chunk, ?Closure $checkpoint, Closure $switch): array
    {
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new StorageRejected('storage_field_change_chunk_invalid');
        }
        $change = $this->changes->find($changeRef);
        if ($change === null) {
            throw new StorageRejected('storage_field_change_unknown');
        }
        if (!$change->running()) {
            return ['state' => $change->state, 'checked' => $change->checked, 'resumed' => false];
        }
        $runRef = 'storage-field-run-' . bin2hex(random_bytes(12));
        $resumed = $change->cursor !== null;
        $taken = $this->database->transaction(function () use ($changeRef, $runRef): ?StorageFieldChangeRow {
            $current = $this->changes->find($changeRef, true);
            if ($current === null || !$current->running()) {
                return null;
            }
            // Take the change over; a run that is still going stops at its next chunk.
            $taken = $current->withRun($runRef);
            $this->changes->put($taken);

            return $taken;
        });
        if (!$taken instanceof StorageFieldChangeRow) {
            $ended = $this->changes->find($changeRef);

            return ['state' => $ended->state ?? StorageWorkbenchFieldChange::STATE_CANCELLED, 'checked' => $ended->checked ?? 0, 'resumed' => false];
        }

        try {
            $context = $this->context($taken);
            $first = true;
            while (true) {
                if (!$first && $checkpoint !== null) {
                    $checkpoint();
                }
                $first = false;
                $outcome = $this->database->transaction(function () use ($changeRef, $runRef, $chunk, $context): array {
                    $this->database->table('larena_storage_schemas')->where('schema_id', $context['schema_id'])->sharedLock()->first();
                    $current = $this->current($changeRef, $runRef);
                    $query = $this->heads($context['schema_id'])->limit($chunk);
                    if ($current->cursor !== null) {
                        $query->where('heads.record_id', '>', $current->cursor);
                    }
                    $rows = $query->get()->all();
                    $failed = $this->check($changeRef, $rows, $context);
                    if ($failed !== null) {
                        $this->changes->put($current->ended(StorageWorkbenchFieldChange::STATE_FAILED, $failed, self::now()));

                        return ['rows' => 0, 'failed' => $failed];
                    }
                    $last = $rows === [] ? $current->cursor : (string) $rows[count($rows) - 1]->record_id;
                    $this->changes->put($current->withProgress($last, $current->checked + count($rows), self::now()));

                    return ['rows' => count($rows), 'failed' => null];
                });
                if ($outcome['failed'] !== null) {
                    throw new StorageRejected($outcome['failed']);
                }
                if ($outcome['rows'] < $chunk) {
                    break;
                }
            }

            // The records written since the walk checked them are checked again, until few are left.
            for ($pass = 0; $pass < self::CATCH_UP_PASSES; $pass++) {
                if ($checkpoint !== null) {
                    $checkpoint();
                }
                if ($this->recheck($changeRef, $runRef, $context, $chunk) < $chunk) {
                    break;
                }
            }

            $change = $this->current($changeRef, $runRef);
            $switch($change, $context['filled'], fn (): int => $this->recheckInTransaction($changeRef, $context));
        } catch (StorageConflict $exception) {
            // Another run holds the change now: it goes on with what this one recorded.
            throw $exception;
        } catch (StorageRejected $exception) {
            $this->changes->forgetSeen($changeRef);
            throw $exception;
        }
        $this->changes->forgetSeen($changeRef);

        return ['state' => StorageWorkbenchFieldChange::STATE_DONE, 'checked' => $change->checked, 'resumed' => $resumed];
    }

    /**
     * The fields a change converts or checks, and the витрина columns it fills:
     * the reserved (adding) columns the target layout plans for fields the source has.
     *
     * @param array<string, array<string, mixed>> $source
     * @param list<array<string, mixed>> $targetFields
     * @param list<array<string, mixed>> $descriptorFields
     * @return list<string>
     */
    public function fillColumns(string $schemaId, array $source, array $targetFields, int $targetVersion, array $descriptorFields): array
    {
        if ($this->views === null || !$this->views->enabled()) {
            return [];
        }
        $registration = $this->views->registry()->find($schemaId, true);
        if ($registration === null || $registration->state !== StorageViewRegistration::STATE_READY) {
            return [];
        }
        $planned = $this->views->planner()->plan($targetFields, $targetVersion, $registration->layout, StorageViewFieldOptions::resolve($descriptorFields));
        $filled = [];
        foreach ($planned->inState(StorageViewColumn::STATE_ADDING) as $column) {
            $reserved = $column->column === null ? null : $registration->layout->column($column->column);
            if ($column->column !== null && isset($source[$column->fieldKey])
                && $reserved !== null && $reserved->state === StorageViewColumn::STATE_ADDING) {
                $filled[] = $column->column;
            }
        }

        return $filled;
    }

    /**
     * @return array{schema_id: string, source_version: int, source: array<string, array<string, mixed>>, target: array<string, array<string, mixed>>, schema: StorageSchemaVersion, filled: list<string>}
     */
    private function context(StorageFieldChangeRow $change): array
    {
        $source = $this->converter->fieldsOf($change->schemaId, $change->sourceSchemaVersion);
        $target = $change->targetDefinition;
        $schema = new StorageSchemaVersion(
            new StorageSchemaVersionRef($change->schemaId, $change->sourceSchemaVersion + 1),
            $target['owner_package'],
            $target['fields'],
            $change->targetHash,
            $change->createdBy,
            $change->correlationId,
            $change->startedAt,
        );

        return [
            'schema_id' => $change->schemaId,
            'source_version' => $change->sourceSchemaVersion,
            'source' => $source,
            'target' => StorageRecordConverter::byKey($target['fields']),
            'schema' => $schema,
            'filled' => $this->fillColumns($change->schemaId, $source, $target['fields'], $change->sourceSchemaVersion + 1, $change->descriptor['fields']),
        ];
    }

    /** The change as this run holds it, locked; another run or a cancel stops this one. */
    private function current(string $changeRef, string $runRef): StorageFieldChangeRow
    {
        $current = $this->changes->find($changeRef, $this->database->transactionLevel() > 0);
        if ($current === null || !$current->running()) {
            throw new StorageRejected($current?->state === StorageWorkbenchFieldChange::STATE_FAILED
                ? ($current->reason ?? 'storage_field_change_failed')
                : 'storage_field_change_cancelled');
        }
        if ($current->runRef !== $runRef) {
            throw new StorageConflict('storage_field_change_interrupted');
        }

        return $current;
    }

    /**
     * Checks the records written since the walk checked them, in chunks of their own
     * transactions.
     *
     * @param array{schema_id: string, source_version: int, source: array<string, array<string, mixed>>, target: array<string, array<string, mixed>>, schema: StorageSchemaVersion, filled: list<string>} $context
     */
    private function recheck(string $changeRef, string $runRef, array $context, int $chunk): int
    {
        $after = null;
        $count = 0;
        while (true) {
            $outcome = $this->database->transaction(function () use ($changeRef, $runRef, $context, $chunk, $after): array {
                $this->database->table('larena_storage_schemas')->where('schema_id', $context['schema_id'])->sharedLock()->first();
                $current = $this->current($changeRef, $runRef);
                $rows = $this->written($changeRef, $context['schema_id'], $after, $chunk);
                $failed = $this->check($changeRef, $rows, $context);
                if ($failed !== null) {
                    $this->changes->put($current->ended(StorageWorkbenchFieldChange::STATE_FAILED, $failed, self::now()));
                }

                return ['rows' => $rows, 'failed' => $failed];
            });
            if ($outcome['failed'] !== null) {
                throw new StorageRejected($outcome['failed']);
            }
            $rows = $outcome['rows'];
            $count += count($rows);
            if (count($rows) < $chunk) {
                return $count;
            }
            $last = $rows[count($rows) - 1];
            $after = $last instanceof stdClass ? (string) $last->record_id : $after;
        }
    }

    /**
     * Inside the switch's transaction, which holds the storage for update: the last
     * records written since the walk checked them. Throws when one does not convert.
     *
     * @param array{schema_id: string, source_version: int, source: array<string, array<string, mixed>>, target: array<string, array<string, mixed>>, schema: StorageSchemaVersion, filled: list<string>} $context
     */
    private function recheckInTransaction(string $changeRef, array $context): int
    {
        $rows = $this->written($changeRef, $context['schema_id'], null, null);
        $failed = $this->check($changeRef, $rows, $context);
        if ($failed !== null) {
            throw new StorageRejected($failed);
        }

        return count($rows);
    }

    /**
     * Current records whose revision is not the one the walk checked (written since),
     * or that it never saw (created since), in record id order.
     *
     * @return list<mixed>
     */
    private function written(string $changeRef, string $schemaId, ?string $after, ?int $limit): array
    {
        $seen = $this->changes->seenInMemory($changeRef);
        if ($seen === null) {
            $query = $this->heads($schemaId)
                ->leftJoin(StorageFieldChanges::RECORDS_TABLE . ' as seen', static function ($join) use ($changeRef): void {
                    $join->on('seen.record_id', '=', 'heads.record_id')->where('seen.change_ref', '=', $changeRef);
                })
                ->where(static function ($query): void {
                    $query->whereNull('seen.record_id')->orWhereColumn('seen.revision', '<>', 'heads.current_revision');
                });
            if ($after !== null) {
                $query->where('heads.record_id', '>', $after);
            }
            if ($limit !== null) {
                $query->limit($limit);
            }

            return $query->get()->all();
        }
        // Before the tables are migrated: the revisions are compared here.
        $ids = [];
        foreach ($this->database->table('larena_storage_records')->where('schema_id', $schemaId)->orderBy('record_id')
            ->get(['record_id', 'current_revision']) as $head) {
            $recordId = (string) $head->record_id;
            if (($after === null || $recordId > $after) && ($seen[$recordId] ?? null) !== (int) $head->current_revision) {
                $ids[] = $recordId;
                if ($limit !== null && count($ids) >= $limit) {
                    break;
                }
            }
        }

        return $ids === [] ? [] : $this->heads($schemaId)->whereIn('heads.record_id', $ids)->get()->all();
    }

    /**
     * Converts and validates each record, and fills the reserved витрина columns.
     * Returns the reason a record fails, or null.
     *
     * @param list<mixed> $rows
     * @param array{schema_id: string, source_version: int, source: array<string, array<string, mixed>>, target: array<string, array<string, mixed>>, schema: StorageSchemaVersion, filled: list<string>} $context
     */
    private function check(string $changeRef, array $rows, array $context): ?string
    {
        $views = $this->views;
        $registration = $context['filled'] === [] || $views === null ? null : $views->registry()->find($context['schema_id'], true);
        $fill = [];
        if ($registration !== null && $registration->written() && $registration->layout->schemaVersion === $context['source_version']) {
            foreach ($context['filled'] as $name) {
                $column = $registration->layout->column($name);
                if ($column !== null && $column->state === StorageViewColumn::STATE_ADDING) {
                    $fill[] = $column;
                }
            }
        }
        $projections = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $seen[(string) $row->record_id] = (int) $row->current_revision;
            $version = (int) $row->schema_version;
            if ($version > $context['source_version']) {
                return 'storage_field_change_schema_moved';
            }
            $stored = self::decode((string) $row->values_json);
            $values = $this->converter->convert($context['schema_id'], $version, $context['source_version'], $stored);
            try {
                $converted = $this->converter->step($values, $context['source'], $context['target'], true);
                $normalized = $this->normalizer->normalizeValues($context['schema'], $converted, true);
            } catch (StorageRejected) {
                return 'storage_schema_migration_record_unconvertible';
            }
            if ($fill === [] || $registration === null) {
                continue;
            }
            $projection = $views->projector()->project(
                $registration->layout,
                (string) $row->record_id,
                (int) $row->current_revision,
                $version,
                (string) $row->content_hash,
                $values,
                (string) $row->created_at,
                (string) $row->updated_at,
            );
            foreach ($fill as $column) {
                try {
                    $projection['row'][(string) $column->column] = $views->projector()->cell($column, $normalized[$column->fieldKey] ?? null);
                } catch (StorageRejected $exception) {
                    // A value the new column cannot hold (out of range, too long) refuses the change.
                    return $exception->reasonCode;
                }
            }
            $projections[] = $projection;
        }
        if ($projections !== [] && $registration !== null) {
            // Only the new columns change: the rest of each row, its search text and its side rows
            // are what the writers keep current. A row that is missing is written whole.
            $names = array_map(static fn (StorageViewColumn $column): string => (string) $column->column, $fill);
            foreach (array_chunk(array_map(static fn (array $projection): array => $projection['row'], $projections), 500) as $chunk) {
                $this->database->table($registration->tableName)->upsert($chunk, ['record_id'], $names);
            }
        }
        $this->changes->markSeen($changeRef, $seen);

        return null;
    }

    /** Current heads with their versions, in record id order, locked for share. */
    private function heads(string $schemaId): \Illuminate\Database\Query\Builder
    {
        return $this->database->table('larena_storage_records as heads')
            ->join('larena_storage_record_versions as versions', static function ($join): void {
                $join->on('versions.schema_id', '=', 'heads.schema_id')
                    ->on('versions.record_id', '=', 'heads.record_id')
                    ->on('versions.revision', '=', 'heads.current_revision');
            })
            ->where('heads.schema_id', $schemaId)
            ->orderBy('heads.record_id')
            ->sharedLock()
            ->select([
                'heads.record_id', 'heads.current_revision', 'heads.created_at', 'heads.updated_at',
                'versions.schema_version', 'versions.values_json', 'versions.content_hash',
            ]);
    }

    /**
     * Checks, without writing and without locks, how many current records of a schema would not convert to the
     * target fields: up to $limit records in record id order, and up to five ids of those that would not.
     *
     * @param array{owner_package: string, fields: list<array<string, mixed>>} $target
     * @return array{checked: int, invalid: int, complete: bool, samples: list<string>}
     */
    public function preview(string $schemaId, int $sourceVersion, array $target, int $limit): array
    {
        $source = $this->converter->fieldsOf($schemaId, $sourceVersion);
        $byKey = StorageRecordConverter::byKey($target['fields']);
        $schema = new StorageSchemaVersion(
            new StorageSchemaVersionRef($schemaId, $sourceVersion + 1),
            $target['owner_package'],
            $target['fields'],
            str_repeat('0', 64),
            'storage-field-change-check',
            null,
            self::now(),
        );
        $limit = max(1, $limit);
        $checked = 0;
        $invalid = 0;
        $samples = [];
        $after = null;
        $chunk = min(self::DEFAULT_CHUNK, $limit);
        while ($checked < $limit) {
            $query = $this->database->table('larena_storage_records as heads')
                ->join('larena_storage_record_versions as versions', static function ($join): void {
                    $join->on('versions.schema_id', '=', 'heads.schema_id')
                        ->on('versions.record_id', '=', 'heads.record_id')
                        ->on('versions.revision', '=', 'heads.current_revision');
                })
                ->where('heads.schema_id', $schemaId)
                ->orderBy('heads.record_id')
                ->limit(min($chunk, $limit - $checked))
                ->select(['heads.record_id', 'versions.schema_version', 'versions.values_json']);
            if ($after !== null) {
                $query->where('heads.record_id', '>', $after);
            }
            $rows = $query->get()->all();
            foreach ($rows as $row) {
                $checked++;
                $after = (string) $row->record_id;
                try {
                    $values = $this->converter->convert($schemaId, (int) $row->schema_version, $sourceVersion, self::decode((string) $row->values_json));
                    $this->normalizer->normalizeValues($schema, $this->converter->step($values, $source, $byKey, true), true);
                } catch (StorageRejected) {
                    $invalid++;
                    if (count($samples) < 5) {
                        $samples[] = (string) $row->record_id;
                    }
                }
            }
            if (count($rows) < $chunk) {
                return ['checked' => $checked, 'invalid' => $invalid, 'complete' => true, 'samples' => $samples];
            }
        }
        $more = $this->database->table('larena_storage_records')->where('schema_id', $schemaId)
            ->where('record_id', '>', (string) $after)->exists();

        return ['checked' => $checked, 'invalid' => $invalid, 'complete' => !$more, 'samples' => $samples];
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StorageRejected('storage_record_values_corrupt');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new StorageRejected('storage_record_values_corrupt');
        }
        $values = [];
        foreach ($decoded as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }
}
