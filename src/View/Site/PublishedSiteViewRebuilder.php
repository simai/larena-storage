<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Closure;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\MySqlStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewShapeVerifier;
use Larena\Storage\View\StorageViewTableShape;

/**
 * Builds a storage's site витрина from its publications, slot by slot, in chunks,
 * the way StorageViewRebuilder builds the admin one.
 *
 * - The table: when the storage has none, or its public fields changed, or its table
 *   does not hold the layout, the registration turns pending (every slot with it),
 *   the writers that still remember it finish, and the tables are made again from
 *   the current public fields. A storage without a public field loses its site
 *   витрина.
 * - Each slot that is not ready along the current locale chain (or every slot, on a
 *   restart) is emptied and marked building under a run reference: from then on
 *   every publication and translation keeps its rows current, while the walk reads
 *   the published heads in record id order, with a shared lock, and writes their
 *   entries. The position is saved with each chunk, so a build that stops resumes
 *   where it was, and a newer run takes a slot over (the older one stops at its next
 *   chunk). A slot is ready only once the walk reached its end, with the locale chain
 *   it was built along.
 * - Between chunks the caller's checkpoint runs (the queue job's cancel and timeout
 *   check). On MySQL a filled table gets its index statistics at the end.
 */
final readonly class PublishedSiteViewRebuilder
{
    public const DEFAULT_CHUNK = 500;
    public const MAX_CHUNK = 10000;

    public function __construct(
        private PublishedSiteViewIndex $site,
        private StorageMetrics $metrics = new StorageMetrics(),
    ) {
    }

    /**
     * @param bool $restart Build every slot (and the table) from scratch.
     * @param Closure(string): void|null $progress
     * @param Closure(): void|null $checkpoint Runs between chunks, after one is committed and before the next.
     * @return array{schema_id: string, state: string, slots: int, records: int, resumed: bool}
     */
    public function rebuild(
        string $schemaId,
        int $chunk = self::DEFAULT_CHUNK,
        bool $restart = false,
        ?Closure $progress = null,
        ?Closure $checkpoint = null,
    ): array {
        $database = $this->site->connection();
        $ddl = $this->site->ddl();
        $registry = $this->site->registry();
        if (!$this->site->enabled() || $ddl === null) {
            throw new StorageRejected('storage_view_unavailable');
        }
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new StorageRejected('storage_view_rebuild_chunk_invalid');
        }
        if ($database->transactionLevel() > 0) {
            throw new StorageRejected('storage_view_rebuild_in_transaction');
        }
        $schema = $this->site->currentSchema($schemaId);
        if ($schema === null) {
            throw new StorageRejected('storage_view_schema_unknown');
        }
        $table = StorageViewNaming::siteTable($schemaId);
        if (PublishedSiteViewProjector::publicFields($schema['fields']) === []) {
            // Nothing of it is public: it has no site витрина.
            if ($registry->find($schemaId, true) !== null) {
                $database->transaction(static fn () => $registry->delete($schemaId));
                $this->waitForWriters();
            }
            $ddl->dropTables($table);

            return ['schema_id' => $schemaId, 'state' => 'none', 'slots' => 0, 'records' => 0, 'resumed' => false];
        }
        $hash = PublishedSiteViewProjector::publicFieldsHash($schema['fields'], $schema['options']);
        $registration = $registry->find($schemaId, true);
        $fresh = $restart || $registration === null || !$registration->ready() || $registration->publicFieldsHash !== $hash
            || !(new StorageViewShapeVerifier($ddl))->matches($registration->tableName, $registration->layout, true, StorageViewTableShape::Site);
        if ($fresh) {
            $registration = $this->rebuildTable($schemaId, $schema, $hash);
        }

        // Every scope and locale with a published record has a slot; slots of scopes that have none any more are emptied too.
        $this->ensureSlots($schemaId);
        $slots = 0;
        $records = 0;
        $resumed = false;
        foreach ($registry->slots($schemaId) as $slot) {
            $chain = $this->site->readContracts()->localeChainKey($slot->locale);
            if (!$fresh && $slot->answers($chain)) {
                continue;
            }
            $result = $this->rebuildSlot($registration, $slot, $chunk, $restart || $fresh, $progress, $checkpoint);
            $slots++;
            $records += $result['records'];
            $resumed = $resumed || $result['resumed'];
        }
        // A freshly filled table gets its index statistics now, so the first lists are planned on them.
        if ($ddl instanceof MySqlStorageViewDdl && $records > 0) {
            try {
                $tables = [$table, StorageViewNaming::multiTable($table)];
                if ($ddl->tableExists(StorageViewNaming::searchTable($table))) {
                    $tables[] = StorageViewNaming::searchTable($table);
                }
                $database->select('ANALYZE TABLE `' . implode('`, `', $tables) . '`');
            } catch (\Throwable) {
            }
        }

        return ['schema_id' => $schemaId, 'state' => PublishedSiteViewSlot::STATE_READY, 'slots' => $slots, 'records' => $records, 'resumed' => $resumed];
    }

    /**
     * The storages a scheduled `--missing` run builds: every registration that is not
     * ready, has a slot that does not answer along the current chain, or whose public
     * fields changed; and every published storage with a public field and no site
     * витрина yet.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $registry = $this->site->registry();
        if (!$this->site->enabled()) {
            return [];
        }
        $targets = [];
        $registered = [];
        foreach ($registry->all() as $registration) {
            $registered[$registration->schemaId] = true;
            if (!$registration->ready() || $this->site->currentHash($registration->schemaId) !== $registration->publicFieldsHash) {
                $targets[$registration->schemaId] = true;
                continue;
            }
            foreach ($registry->slots($registration->schemaId) as $slot) {
                if (!$slot->answers($this->site->readContracts()->localeChainKey($slot->locale))) {
                    $targets[$registration->schemaId] = true;
                    break;
                }
            }
        }
        foreach ($this->site->connection()->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('state', PublicationStateValue::Published->value)
            ->distinct()->orderBy('schema_id')->pluck('schema_id') as $schemaId) {
            $schemaId = (string) $schemaId;
            if (!isset($registered[$schemaId]) && $this->site->currentHash($schemaId) !== null) {
                $targets[$schemaId] = true;
            }
        }
        $list = array_map('strval', array_keys($targets));
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * Stops the writers, drops and makes the tables again for the current public
     * fields, and returns the ready registration; every slot is pending.
     *
     * @param array{version: int, fields: list<array<string, mixed>>, options: ?\Larena\Storage\View\StorageViewFieldOptions} $schema
     */
    private function rebuildTable(string $schemaId, array $schema, string $hash): PublishedSiteViewRegistration
    {
        $registry = $this->site->registry();
        $ddl = $this->site->ddl() ?? throw new StorageRejected('storage_view_unavailable');
        $layout = $this->site->projector()->layout($schema['fields'], $schema['version'], $schema['options']);
        $pending = PublishedSiteViewRegistration::create($schemaId, $layout, $hash, PublishedSiteViewRegistration::STATE_PENDING);
        $this->site->connection()->transaction(static function () use ($registry, $pending, $schemaId): void {
            $registry->findForUpdate($schemaId);
            $registry->put($pending);
            $registry->silenceSchema($schemaId);
        });
        // Writers that still remember the earlier state stop before the tables go.
        $this->waitForWriters();
        $ddl->dropTables($pending->tableName);
        $ddl->ensureTable($pending->tableName, $layout, StorageViewTableShape::Site);
        if (!(new StorageViewShapeVerifier($ddl))->matches($pending->tableName, $layout, true, StorageViewTableShape::Site)) {
            $this->site->connection()->transaction(static fn () => $registry->put($pending->withState(PublishedSiteViewRegistration::STATE_FAILED)));

            throw new StorageRejected('storage_site_view_table_unusable');
        }
        $ready = $pending->withState(PublishedSiteViewRegistration::STATE_READY, $schema['version']);
        $this->site->connection()->transaction(static fn () => $registry->put($ready));

        return $ready;
    }

    /** Every scope and locale where the storage has a published record gets its slot row. */
    private function ensureSlots(string $schemaId): void
    {
        $registry = $this->site->registry();
        $known = [];
        foreach ($registry->slots($schemaId) as $slot) {
            $known[$slot->key()] = true;
        }
        foreach ($this->site->connection()->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)
            ->where('state', PublicationStateValue::Published->value)
            ->distinct()->orderBy('scope_ref')->orderBy('locale')
            ->get(['scope_ref', 'locale']) as $row) {
            $key = PublishedSiteViewSlot::keyOf($schemaId, (string) $row->scope_ref, (string) $row->locale);
            if (!isset($known[$key])) {
                $this->site->connection()->transaction(static fn () => $registry->slotFor($schemaId, (string) $row->scope_ref, (string) $row->locale));
                $known[$key] = true;
            }
        }
    }

    /**
     * Builds one slot, resuming a build under way unless `$restart`.
     *
     * @param Closure(string): void|null $progress
     * @param Closure(): void|null $checkpoint
     * @return array{records: int, resumed: bool}
     */
    private function rebuildSlot(
        PublishedSiteViewRegistration $registration,
        PublishedSiteViewSlot $slot,
        int $chunk,
        bool $restart,
        ?Closure $progress,
        ?Closure $checkpoint,
    ): array {
        $database = $this->site->connection();
        $registry = $this->site->registry();
        $writer = $this->site->writer();
        $runRef = 'storage-site-view-build-' . bin2hex(random_bytes(12));
        $search = $registration->layout->searchTable();
        $resumed = !$restart && $slot->state === PublishedSiteViewSlot::STATE_BUILDING;
        $database->transaction(static function () use ($registry, $writer, $registration, $slot, $runRef, $resumed, $search): void {
            $locked = $registry->slotForUpdate($slot->id);
            if ($locked === null) {
                throw new StorageConflict('storage_view_rebuild_interrupted');
            }
            if ($resumed && $locked->state === PublishedSiteViewSlot::STATE_BUILDING) {
                // Take the build over; a run that is still going stops at its next chunk.
                $registry->putSlot(new PublishedSiteViewSlot($locked->id, $locked->schemaId, $locked->scopeRef, $locked->locale,
                    PublishedSiteViewSlot::STATE_BUILDING, null, $locked->buildCursor, $runRef, null));

                return;
            }
            $writer->deleteSlotRecords($registration->tableName, $locked->id, null, $search);
            $registry->putSlot(new PublishedSiteViewSlot($locked->id, $locked->schemaId, $locked->scopeRef, $locked->locale,
                PublishedSiteViewSlot::STATE_BUILDING, null, null, $runRef, null));
        });

        $records = 0;
        $first = true;
        while (true) {
            if (!$first && $checkpoint !== null) {
                $checkpoint();
            }
            $first = false;
            $started = hrtime(true);
            $written = (int) $database->transaction(function () use ($registration, $slot, $chunk, $runRef): int {
                $registry = $this->site->registry();
                $current = $registry->peekSlot($slot->id);
                $view = $registry->peek($slot->schemaId);
                if ($current === null || $current->state !== PublishedSiteViewSlot::STATE_BUILDING || $current->buildRunRef !== $runRef
                    || $view === null || !$view->ready() || $view->layout->hash !== $registration->layout->hash
                    || $view->publicFieldsHash !== $registration->publicFieldsHash) {
                    throw new StorageConflict('storage_view_rebuild_interrupted');
                }
                // Lock order as everywhere: publication rows, then the slot, then its rows.
                $heads = $this->site->readContracts()->publishedHeadPage($slot->schemaId, $slot->scopeRef, $slot->locale, $chunk, $current->buildCursor, true);
                $locked = $registry->slotForUpdate($slot->id);
                if ($locked === null || $locked->state !== PublishedSiteViewSlot::STATE_BUILDING
                    || $locked->buildRunRef !== $runRef || $locked->buildCursor !== $current->buildCursor) {
                    throw new StorageConflict('storage_view_rebuild_interrupted');
                }
                if ($heads !== []) {
                    $projections = $this->site->projections($registration, $locked, $heads);
                    if ($projections === null) {
                        $registry->putSlot(new PublishedSiteViewSlot($locked->id, $locked->schemaId, $locked->scopeRef, $locked->locale,
                            PublishedSiteViewSlot::STATE_FAILED, null, null, null, null));

                        return -2;
                    }
                    $writer = $this->site->writer();
                    // Writers may have written some of these rows since the build started: they are replaced.
                    $writer->deleteSlotRecords($registration->tableName, $locked->id, array_column($heads, 'record_id'), $registration->layout->searchTable());
                    $writer->upsert($registration->tableName, $projections, PublishedSiteViewIndex::hasMulti($registration->layout), true, StorageViewTableShape::Site);
                }
                if (count($heads) < $chunk) {
                    // The last chunk: the slot is ready in the same transaction, along the chain it was built with.
                    $registry->putSlot(new PublishedSiteViewSlot($locked->id, $locked->schemaId, $locked->scopeRef, $locked->locale,
                        PublishedSiteViewSlot::STATE_READY, $this->site->readContracts()->localeChainKey($locked->locale), null, null, gmdate('Y-m-d H:i:s')));

                    return $heads === [] ? -1 : count($heads);
                }
                $registry->putSlot(new PublishedSiteViewSlot($locked->id, $locked->schemaId, $locked->scopeRef, $locked->locale,
                    PublishedSiteViewSlot::STATE_BUILDING, null, $heads[array_key_last($heads)]['record_id'], $runRef, null));

                return count($heads);
            });
            if ($written === -2) {
                throw new StorageRejected('storage_site_view_record_unprojectable');
            }
            if ($written < 0) {
                break;
            }
            $this->metrics->rebuildChunk(StorageMetrics::elapsedMs($started), $written);
            $records += $written;
            if ($progress !== null) {
                $progress(sprintf('%s %s/%s: %d records', $slot->schemaId, $slot->scopeRef, $slot->locale, $records));
            }
            if ($written < $chunk) {
                break;
            }
        }

        return ['records' => $records, 'resumed' => $resumed];
    }

    private function waitForWriters(): void
    {
        $seconds = $this->site->registry()->memoSeconds();
        if ($seconds > 0) {
            usleep((int) ceil($seconds * 1_000_000) + 50_000);
        }
    }
}
