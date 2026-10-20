<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Closure;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\StorageMetrics;
use stdClass;

/**
 * Builds a storage's витрина from its current versions, in chunks.
 *
 * A fresh build stops the writers (pending), recreates the tables, then marks the
 * витрина building: from then on every write keeps its row current while the
 * build walks the records in record id order. The position is saved with each
 * chunk, so a build that stops resumes where it was. When it reaches the end the
 * витрина is ready. Between chunks the caller's checkpoint runs (the queue job's
 * cancel and timeout check); whatever it throws stops the build after a committed
 * chunk, and the next build resumes from there.
 *
 * A ready витрина whose rows lack something a change of the fields' options
 * brought (a new column of a string that became indexed, the search text of a
 * changed set of search fields, the search table of a table built before it)
 * is not rebuilt but backfilled: it stays ready and keeps answering lists (from
 * the old column, and the PHP list for a search meanwhile), the walk rewrites its
 * rows in chunks the same way, resumable, and at the end the new columns become
 * ready and the ones they replace are dropped.
 */
final readonly class StorageViewRebuilder
{
    public const DEFAULT_CHUNK = 1000;
    public const MAX_CHUNK = 10000;

    public function __construct(
        private StorageViewSync $views,
        private StorageMetrics $metrics = new StorageMetrics(),
    ) {
    }

    /**
     * @param bool $restart Build from scratch even when a build can be resumed.
     * @param Closure(string): void|null $progress
     * @param Closure(): void|null $checkpoint Runs between chunks, after one is committed and before the next.
     * @return array{schema_id: string, state: string, records: int, resumed: bool}
     */
    public function rebuild(
        string $schemaId,
        int $chunk = self::DEFAULT_CHUNK,
        bool $restart = false,
        ?Closure $progress = null,
        ?Closure $checkpoint = null,
    ): array {
        $database = $this->views->connection();
        $ddl = $this->views->ddl();
        $registry = $this->views->registry();
        if (!$this->views->enabled() || $ddl === null) {
            throw new StorageRejected('storage_view_unavailable');
        }
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new StorageRejected('storage_view_rebuild_chunk_invalid');
        }
        if ($database->transactionLevel() > 0) {
            throw new StorageRejected('storage_view_rebuild_in_transaction');
        }
        [$version, $fields] = $this->currentSchema($database, $schemaId);
        // The options the structure declares now; without a structure, the layout's own.
        $options = StorageViewFieldOptions::load($database, $schemaId);
        $runRef = 'storage-view-build-' . bin2hex(random_bytes(12));
        $backfill = $restart ? null : $this->backfillable($schemaId, $version, $fields, $options);
        $resumed = false;
        if ($backfill !== null) {
            // The витрина stays ready and answers lists; the walk fills what its rows lack.
            $resumed = $backfill->buildCursor !== null;
            $cleared = false;
            if (!$resumed && $backfill->layout->searchState === StorageViewLayout::SEARCH_BACKFILLING) {
                // Nothing searches the old text: the walk refills an empty search table.
                $ddl->clearSearchTable($backfill->tableName);
                $cleared = true;
            }
            $database->transaction(static function () use ($registry, $backfill, $runRef, $cleared): void {
                $current = $registry->findForUpdate($backfill->schemaId);
                if ($cleared && $current !== null && $current->state === StorageViewRegistration::STATE_READY && $current->layout->searchTable()) {
                    // The search table is empty now, whatever happened meanwhile (a schema apply may have
                    // filled it and called it complete): its text is refilled from the first record.
                    $layout = $current->layout->withSearch($current->layout->searchFields, StorageViewLayout::SEARCH_BACKFILLING);
                    $registry->put($current->withLayout($layout)->withState(StorageViewRegistration::STATE_READY, $current->builtSchemaVersion, null, $runRef));

                    return;
                }
                if ($current === null || $current->state !== StorageViewRegistration::STATE_READY || $current->layout->hash !== $backfill->layout->hash
                    || $current->buildCursor !== $backfill->buildCursor) {
                    // Another change came first; the build it asked for goes on from there.
                    throw new StorageConflict('storage_view_rebuild_interrupted');
                }
                // Take the walk over; a run that is still going stops at its next chunk.
                $registry->put($current->withState(StorageViewRegistration::STATE_READY, $current->builtSchemaVersion, $current->buildCursor, $runRef));
            });
            $walkState = StorageViewRegistration::STATE_READY;
        } else {
            $walkState = StorageViewRegistration::STATE_BUILDING;
            $registration = $registry->find($schemaId, true);
            $resumed = !$restart
                && $registration !== null
                && $registration->state === StorageViewRegistration::STATE_BUILDING
                && $registration->layout->schemaVersion === $version
                && $registration->layout->inState(StorageViewColumn::STATE_ADDING) === []
                && $registration->layout->inState(StorageViewColumn::STATE_DROPPING) === []
                && (new StorageViewShapeVerifier($ddl))->matches($registration->tableName, $registration->layout);
            if ($resumed) {
                // Take the build over; a run that is still going stops at its next chunk.
                $registry->put($registration->withState(StorageViewRegistration::STATE_BUILDING, null, $registration->buildCursor, $runRef));
            } else {
                $layout = $this->views->planner()->plan($fields, $version, $registration?->layout, $options)
                    ->promoted()->withoutDropped();
                $pending = $registration === null
                    ? StorageViewRegistration::create($schemaId, $layout, StorageViewRegistration::STATE_PENDING)
                    : $registration->withLayout($layout)->withState(StorageViewRegistration::STATE_PENDING);
                $registry->put($pending);
                // Writers that still remember the earlier state stop before the tables go.
                $this->waitForWriters();
                $ddl->dropTables($pending->tableName);
                $ddl->ensureTable($pending->tableName, $layout);
                $registry->put($pending->withState(StorageViewRegistration::STATE_BUILDING, null, null, $runRef));
                // Writers that still remember "pending" finish before the walk reads their records.
                $this->waitForWriters();
            }
        }

        $records = 0;
        $first = true;
        while (true) {
            if (!$first && $checkpoint !== null) {
                $checkpoint();
            }
            $first = false;
            $started = hrtime(true);
            $written = (int) $database->transaction(function () use ($database, $registry, $schemaId, $chunk, $runRef, $walkState): int {
                // Lock order as everywhere: schema, record heads, then the registry row.
                $database->table('larena_storage_schemas')->where('schema_id', $schemaId)->sharedLock()->first();
                $current = $registry->peek($schemaId);
                if ($current === null || $current->state !== $walkState || $current->buildRunRef !== $runRef) {
                    throw new StorageConflict('storage_view_rebuild_interrupted');
                }
                $query = $database->table('larena_storage_records as heads')
                    ->join('larena_storage_record_versions as versions', static function ($join): void {
                        $join->on('versions.schema_id', '=', 'heads.schema_id')
                            ->on('versions.record_id', '=', 'heads.record_id')
                            ->on('versions.revision', '=', 'heads.current_revision');
                    })
                    ->where('heads.schema_id', $schemaId)
                    ->orderBy('heads.record_id')
                    ->limit($chunk)
                    ->sharedLock()
                    ->select([
                        'heads.record_id', 'heads.current_revision', 'heads.created_at', 'heads.updated_at',
                        'versions.schema_version', 'versions.values_json', 'versions.content_hash',
                    ]);
                if ($current->buildCursor !== null) {
                    $query->where('heads.record_id', '>', $current->buildCursor);
                }
                $rows = $query->get()->all();
                $locked = $registry->findForUpdate($schemaId);
                if ($locked === null || $locked->state !== $walkState
                    || $locked->buildRunRef !== $runRef || $locked->buildCursor !== $current->buildCursor) {
                    throw new StorageConflict('storage_view_rebuild_interrupted');
                }
                if ($rows === []) {
                    $registry->put(StorageViewSync::completed($locked));

                    return -1;
                }
                $projections = [];
                $last = null;
                foreach ($rows as $row) {
                    if ((int) $row->schema_version !== $locked->layout->schemaVersion) {
                        // A record is at a schema version the layout does not describe. A build fails;
                        // a backfill stops and leaves the ready витрина to the schema apply that moved it.
                        if ($walkState === StorageViewRegistration::STATE_BUILDING) {
                            $registry->put($locked->withState(StorageViewRegistration::STATE_FAILED));
                        }

                        return -2;
                    }
                    $projections[] = $this->views->projector()->project(
                        $locked->layout,
                        (string) $row->record_id,
                        (int) $row->current_revision,
                        (int) $row->schema_version,
                        (string) $row->content_hash,
                        $this->decode((string) $row->values_json),
                        (string) $row->created_at,
                        (string) $row->updated_at,
                    );
                    $last = (string) $row->record_id;
                }
                $this->views->writer()->upsert($locked->tableName, $projections);
                // A short chunk is the last one: the витрина is ready in the same transaction.
                $registry->put(count($rows) < $chunk
                    ? StorageViewSync::completed($locked)
                    : $locked->withCursor($last));

                return count($projections);
            });
            if ($written === -2) {
                throw new StorageRejected('storage_view_rebuild_schema_changed');
            }
            if ($written < 0) {
                break;
            }
            $this->metrics->rebuildChunk(StorageMetrics::elapsedMs($started), $written);
            $records += $written;
            if ($progress !== null) {
                $progress(sprintf('%s: %d records', $schemaId, $records));
            }
            if ($written < $chunk) {
                break;
            }
        }
        // Columns a backfill replaced, and indexes no longer wanted, go now.
        $this->views->settleSchema($schemaId);
        // A freshly filled table gets its index statistics now, so the first lists are planned on them.
        if ($ddl instanceof MySqlStorageViewDdl && $records > 0) {
            $table = StorageViewNaming::table($schemaId);
            try {
                $tables = [$table, StorageViewNaming::multiTable($table)];
                if ($ddl->tableExists(StorageViewNaming::searchTable($table))) {
                    $tables[] = StorageViewNaming::searchTable($table);
                }
                $database->select('ANALYZE TABLE `' . implode('`, `', $tables) . '`');
            } catch (\Throwable) {
            }
        }

        return ['schema_id' => $schemaId, 'state' => StorageViewRegistration::STATE_READY, 'records' => $records, 'resumed' => $resumed];
    }

    /**
     * The ready витрина of the current schema version, reshaped to the declared
     * options and given its FULLTEXT index, when its rows only lack columns or
     * search text the walk can fill in place; null when it needs a build instead.
     *
     * @param list<array<string, mixed>> $fields
     */
    private function backfillable(string $schemaId, int $version, array $fields, ?StorageViewFieldOptions $options): ?StorageViewRegistration
    {
        $ddl = $this->views->ddl();
        $registration = $this->views->registry()->find($schemaId, true);
        if ($ddl === null || $registration === null || $registration->state !== StorageViewRegistration::STATE_READY
            || $registration->builtSchemaVersion !== $version || $registration->layout->schemaVersion !== $version) {
            return null;
        }
        $this->views->settleSchema($schemaId);
        $registration = $this->views->registry()->find($schemaId, true);
        if ($registration === null || $registration->state !== StorageViewRegistration::STATE_READY
            || !(new StorageViewShapeVerifier($ddl))->matches($registration->tableName, $registration->layout, false)) {
            return null;
        }
        $registration = $this->views->reshape($registration, $fields, $options);

        return $registration !== null && $registration->layout->needsBackfill() ? $registration : null;
    }

    /** @return array{0: int, 1: list<array<string, mixed>>} */
    private function currentSchema(ConnectionInterface $database, string $schemaId): array
    {
        $head = $database->table('larena_storage_schemas')->where('schema_id', $schemaId)->first();
        if (!$head instanceof stdClass) {
            throw new StorageRejected('storage_view_schema_unknown');
        }
        $version = (int) $head->current_version;
        $definition = $database->table('larena_storage_schema_versions')
            ->where('schema_id', $schemaId)->where('version', $version)->value('definition');
        $decoded = is_string($definition) ? $this->decode($definition) : [];
        $fields = $decoded['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new StorageRejected('storage_view_schema_unknown');
        }
        $list = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new StorageRejected('storage_view_schema_unknown');
            }
            $list[] = $field;
        }

        return [$version, $list];
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StorageRejected('storage_view_record_values_corrupt');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new StorageRejected('storage_view_record_values_corrupt');
        }
        $values = [];
        foreach ($decoded as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    private function waitForWriters(): void
    {
        $seconds = $this->views->registry()->memoSeconds();
        if ($seconds > 0) {
            usleep((int) ceil($seconds * 1_000_000) + 50_000);
        }
    }
}
