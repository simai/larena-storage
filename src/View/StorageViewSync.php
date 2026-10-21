<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use RuntimeException;
use Throwable;

/**
 * Keeps every storage's витрина in step with its versions.
 *
 * - A record write upserts the витрина row in the same transaction, when the
 *   storage has a витрина that is ready or being built.
 * - A structure gets its table before its creating transaction; a field change
 *   gets its new columns before the migrating transaction, and loses its old
 *   ones after the commit. DDL never runs inside a MySQL transaction: when there
 *   is no way around it, the витрина is marked pending and the DDL waits for the
 *   commit (the rebuild command then fills it).
 */
final class StorageViewSync
{
    private const SCHEMAS_TABLE = 'larena_storage_schemas';

    private readonly StorageViewLayoutPlanner $planner;
    private readonly StorageViewProjector $projector;
    private readonly StorageViewWriter $writer;
    /** @var array<string, true> Schemas whose DDL waits for the transaction to commit. */
    private array $deferred = [];
    /** @var array<string, StorageViewFieldOptions> The options a structure change in progress brings, by schema. */
    private array $intended = [];
    private ?StorageRecordConverter $converter = null;

    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly StorageViewRegistry $registry,
        private readonly ?StorageViewDdl $ddl,
    ) {
        $this->planner = new StorageViewLayoutPlanner();
        $this->projector = new StorageViewProjector();
        $this->writer = new StorageViewWriter($database);
    }

    /** @param float $memoSeconds How long a registry row is reused (0 reads it on every write). */
    public static function for(ConnectionInterface $database, float $memoSeconds = 1.0): self
    {
        return new self($database, new StorageViewRegistry($database, $memoSeconds), StorageViewDdl::for($database));
    }

    public function registry(): StorageViewRegistry
    {
        return $this->registry;
    }

    public function ddl(): ?StorageViewDdl
    {
        return $this->ddl;
    }

    public function planner(): StorageViewLayoutPlanner
    {
        return $this->planner;
    }

    public function projector(): StorageViewProjector
    {
        return $this->projector;
    }

    public function writer(): StorageViewWriter
    {
        return $this->writer;
    }

    public function connection(): ConnectionInterface
    {
        return $this->database;
    }

    /**
     * The read converter the rebuild projects older record versions through. The
     * application gives it its Property types; without them the built-in ones.
     */
    public function converter(): StorageRecordConverter
    {
        return $this->converter ??= new StorageRecordConverter($this->database);
    }

    public function useConverter(StorageRecordConverter $converter): void
    {
        $this->converter = $converter;
    }

    /** False on a driver without витрина DDL, or before the registry table is migrated. */
    public function enabled(): bool
    {
        return $this->ddl !== null && $this->registry->available();
    }

    /** DDL may run now: the driver rolls it back with the transaction, or no transaction is open. */
    public function canRunDdl(): bool
    {
        return $this->ddl !== null && ($this->ddl->transactional() || $this->database->transactionLevel() === 0);
    }

    /**
     * After a record version was inserted, in the same transaction.
     *
     * @param array<string, mixed> $values Normalized values of the version.
     */
    public function recordWritten(
        StorageSchemaVersion $schema,
        string $recordId,
        int $revision,
        array $values,
        string $contentHash,
        string $createdAt,
        string $updatedAt,
    ): void {
        if (!$this->enabled()) {
            return;
        }
        $schemaId = $schema->ref->schemaId;
        $registration = $this->registry->find($schemaId);
        if ($registration !== null && $registration->layout->schemaVersion !== $schema->ref->version) {
            $registration = $this->registry->find($schemaId, true);
        }
        if ($registration === null || !$registration->written()) {
            return;
        }
        if ($registration->layout->schemaVersion !== $schema->ref->version) {
            // The layout was planned for another schema version: the витрина is out of
            // date until it is rebuilt, and nothing writes it meanwhile.
            $this->registry->put($registration->withState(StorageViewRegistration::STATE_PENDING));

            return;
        }
        $this->writer->upsert($registration->tableName, [$this->projector->project(
            $registration->layout,
            $recordId,
            $revision,
            $schema->ref->version,
            $contentHash,
            $values,
            $createdAt,
            $updatedAt,
        // Revision 1 is the record's create: it has no earlier side rows.
        )], self::hasMulti($registration->layout), $revision === 1);
    }

    /** @param list<string> $recordIds Records purged in the caller's transaction. */
    public function recordsRemoved(string $schemaId, array $recordIds): void
    {
        if ($recordIds === [] || !$this->enabled()) {
            return;
        }
        $registration = $this->registry->find($schemaId, true);
        if ($registration !== null && $registration->written()) {
            $this->writer->delete($registration->tableName, $recordIds, $registration->layout->searchTable());
        }
    }

    /**
     * Before the transaction that creates a structure: its table, empty. Best effort;
     * whatever is not done here is done in the transaction or after it.
     *
     * @param list<array<string, mixed>> $fields
     */
    public function prepareCreate(string $schemaId, array $fields, ?StorageViewFieldOptions $options = null): void
    {
        try {
            if (!$this->enabled() || !$this->canRunDdl() || $this->ddl === null
                || $this->registry->find($schemaId, true) !== null
                || $this->database->table(self::SCHEMAS_TABLE)->where('schema_id', $schemaId)->exists()) {
                return;
            }
            $table = StorageViewNaming::table($schemaId);
            // No registration names the table, so nothing writes it: a table left by an
            // earlier attempt that did not commit is replaced.
            $this->ddl->dropTables($table);
            $this->ddl->ensureTable($table, $this->newLayout($fields, 1, $options));
        } catch (Throwable) {
        }
    }

    /**
     * The options a structure change brings, for the schema apply inside it (which
     * knows the fields, not their options). The workbench names them before the
     * change and forgets them after it.
     */
    public function intend(string $schemaId, ?StorageViewFieldOptions $options): void
    {
        if ($options === null) {
            unset($this->intended[$schemaId]);

            return;
        }
        $this->intended[$schemaId] = $options;
    }

    /** In the transaction that created the structure's schema. */
    public function structureCreated(StorageSchemaVersion $schema, ?StorageViewFieldOptions $options = null): void
    {
        if (!$this->enabled() || $this->ddl === null) {
            return;
        }
        $schemaId = $schema->ref->schemaId;
        $table = StorageViewNaming::table($schemaId);
        $layout = $this->newLayout($schema->fields, $schema->ref->version, $options);
        $verifier = new StorageViewShapeVerifier($this->ddl);
        if (!$verifier->matches($table, $layout) && $this->canRunDdl()) {
            $this->ddl->dropTables($table);
            $this->ddl->ensureTable($table, $layout);
        }
        if ($verifier->matches($table, $layout)) {
            // A new storage has no records, so its empty витрина is in sync.
            $this->registry->put(StorageViewRegistration::create($schemaId, $layout, StorageViewRegistration::STATE_READY));

            return;
        }
        $this->registry->put(StorageViewRegistration::create($schemaId, $layout, StorageViewRegistration::STATE_PENDING));
        $this->defer($schemaId);
    }

    /**
     * Before the transaction that changes a structure's fields: the columns the new
     * fields need are added (and reserved in the layout, unwritten) so the change
     * itself needs no DDL. Best effort.
     *
     * The same runs for a change of the fields' options alone: a string that becomes
     * indexed gets its new column and index here, a VARCHAR that becomes indexed again
     * gets its index.
     *
     * @param list<array<string, mixed>> $targetFields
     */
    public function prepareChange(string $schemaId, array $targetFields, ?StorageViewFieldOptions $options = null): void
    {
        try {
            if (!$this->enabled() || !$this->canRunDdl() || $this->ddl === null) {
                return;
            }
            $registration = $this->registry->find($schemaId, true);
            if ($registration === null || !$registration->written()) {
                return;
            }
            $target = $this->planner->plan($targetFields, $registration->layout->schemaVersion + 1, $registration->layout, $options);
            $this->reserve($registration, $target);
        } catch (Throwable) {
        }
    }

    /**
     * Adds the columns and indexes a target layout needs to the table, reserving
     * the new columns in the registered layout first (so a failed step is retried
     * and an unused column is dropped). Outside a MySQL transaction.
     */
    private function reserve(StorageViewRegistration $registration, StorageViewLayout $target): bool
    {
        if ($this->ddl === null) {
            return false;
        }
        $reserved = $registration->layout->reserving($target);
        if ($reserved->hash !== $registration->layout->hash
            && !$this->replaceLayout($registration, $reserved)) {
            return false;
        }
        if ($target->searchTable()) {
            // A table built before the search table gets it, empty; the backfill fills it.
            $this->ddl->ensureSearchTable($registration->tableName);
        }
        foreach ($reserved->inState(StorageViewColumn::STATE_ADDING) as $column) {
            if ($column->column !== null) {
                $this->ddl->addColumn($registration->tableName, $column);
                if ($column->indexed) {
                    $this->ddl->createIndex($registration->tableName, $column);
                }
            }
        }
        foreach ($target->physical() as $column) {
            // A kept column that becomes indexed (a string indexed again) gets its index now.
            $before = $registration->layout->column((string) $column->column);
            if ($column->indexed && $column->state !== StorageViewColumn::STATE_DROPPING
                && $before !== null && !$before->indexed && $before->state !== StorageViewColumn::STATE_DROPPING) {
                $this->ddl->createIndex($registration->tableName, $column);
            }
        }
        if ($reserved->inState(StorageViewColumn::STATE_DROPPING) !== $registration->layout->inState(StorageViewColumn::STATE_DROPPING)) {
            // A reservation nothing uses any more gave way: its column goes now.
            $this->settleSchema($registration->schemaId);
        }

        return true;
    }

    /**
     * In the schema-apply transaction, after the target schema version is inserted
     * and before the records are rewritten: the витрина takes the target layout, and
     * the returned writer upserts each rewritten record. Null when the storage has
     * no витрина that is written, or when its columns could not be added now (the
     * витрина is then pending and the columns are added after the commit).
     */
    public function beginApply(StorageSchemaVersion $target): ?StorageViewApply
    {
        if (!$this->enabled() || $this->ddl === null) {
            return null;
        }
        $schemaId = $target->ref->schemaId;
        $registration = $this->registry->findForUpdate($schemaId);
        if ($registration === null || !$registration->written()) {
            return null;
        }
        $layout = $this->planner->plan($target->fields, $target->ref->version, $registration->layout, $this->intended[$schemaId] ?? null);
        $verifier = new StorageViewShapeVerifier($this->ddl);
        if (!$verifier->matches($registration->tableName, $layout) && $this->canRunDdl()) {
            $this->ddl->materialize($registration->tableName, $layout->withoutDropped());
        }
        if (!$verifier->matches($registration->tableName, $layout)) {
            $this->registry->put($registration->withLayout($layout)->withState(StorageViewRegistration::STATE_PENDING));
            $this->defer($schemaId);

            return null;
        }
        // Every record is rewritten here, so every column and the search text are filled
        // with it: a backfill under way has nothing left to do (a build goes on).
        $promoted = $layout->promoted();
        $next = $registration->withLayout($promoted);
        if ($registration->state === StorageViewRegistration::STATE_READY) {
            // Every record is rewritten in this transaction, so the витрина stays in sync.
            $next = $next->withState(StorageViewRegistration::STATE_READY, $target->ref->version);
        }
        $this->registry->put($next);
        if ($promoted->inState(StorageViewColumn::STATE_DROPPING) !== []) {
            $this->defer($schemaId);
        }

        return new StorageViewApply($this->projector, $this->writer, $registration->tableName, $promoted, self::hasMulti($promoted));
    }

    /**
     * In the transaction of a field change that rewrites no record version, after the
     * target schema version is in place: the витрина takes the target layout.
     *
     * - A field added by the change gets its column (prepared before the transaction)
     *   ready at once: every row written before has no value for it, and its empty
     *   column says exactly that. Nothing is backfilled.
     * - A field the change removed loses its column after the commit; its side rows go.
     * - A field whose new column a field change job filled ($filled, by column name)
     *   gets that column ready, and its old column is dropped after the commit.
     * - A column that changes only because of the fields' options (a string that becomes
     *   indexed) is backfilling, while the old one keeps answering, as with applyOptions.
     * - The search text and the titles stay when they read the same values as before
     *   (only empty added fields joined them); otherwise the walk refills them.
     *
     * Returns true when the витрина needs the rebuild job (a backfill or a build).
     *
     * @param list<string> $filled
     */
    public function switchSchema(StorageSchemaVersion $source, StorageSchemaVersion $target, ?StorageViewFieldOptions $options, array $filled = []): bool
    {
        if (!$this->enabled() || $this->ddl === null) {
            return false;
        }
        $schemaId = $target->ref->schemaId;
        $registration = $this->registry->findForUpdate($schemaId);
        if ($registration === null || !$registration->written()) {
            return false;
        }
        $planned = $this->planner->plan($target->fields, $target->ref->version, $registration->layout, $options ?? $this->intended[$schemaId] ?? null);
        $verifier = new StorageViewShapeVerifier($this->ddl);
        if (!$verifier->matches($registration->tableName, $planned) && $this->canRunDdl()) {
            $this->ddl->materialize($registration->tableName, $planned->withoutDropped());
        }
        if (!$verifier->matches($registration->tableName, $planned)) {
            // The columns could not be added now: nothing writes the витрина until it is rebuilt.
            $this->registry->put($registration->withLayout($planned)->withState(StorageViewRegistration::STATE_PENDING));
            $this->defer($schemaId);

            return true;
        }
        $sourceKeys = [];
        foreach ($source->fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $sourceKeys[$field['key']] = true;
            }
        }
        $complete = [];
        foreach ($planned->columns as $column) {
            if ($column->state === StorageViewColumn::STATE_ADDING && (!isset($sourceKeys[$column->fieldKey])
                || ($column->column !== null && in_array($column->column, $filled, true)))) {
                $complete[$column->fieldKey] = true;
            }
        }
        $columns = [];
        $backfill = false;
        foreach ($planned->columns as $column) {
            if ($column->state === StorageViewColumn::STATE_ADDING) {
                $ready = isset($complete[$column->fieldKey]);
                $backfill = $backfill || !$ready;
                $columns[] = $column->withState($ready ? StorageViewColumn::STATE_READY : StorageViewColumn::STATE_BACKFILLING);
            } elseif ($column->state === StorageViewColumn::STATE_READY && isset($complete[$column->fieldKey])) {
                // The field's new column holds every row now; the old one goes.
                $columns[] = $column->withState(StorageViewColumn::STATE_DROPPING);
            } else {
                $backfill = $backfill || $column->state === StorageViewColumn::STATE_BACKFILLING;
                $columns[] = $column;
            }
        }
        $layout = new StorageViewLayout($planned->schemaVersion, $planned->nextOrdinal, $columns, $planned->searchFields, $planned->searchState);
        $before = $registration->layout;
        $after = $layout->searchFields ?? [];
        $changed = [];
        foreach ($layout->columns as $column) {
            if ($column->column !== null && in_array($column->column, $filled, true)) {
                $changed[] = $column->fieldKey;
            }
        }
        // The search reads the same values as before when only empty added fields joined it.
        $sameText = $before->searchFields !== null
            && array_values(array_filter($after, static fn (string $key): bool => isset($sourceKeys[$key]))) === $before->searchFields
            && array_intersect($changed, $after) === []
            && $this->projector->titleKey($layout) === $this->projector->titleKey($before);
        // Otherwise the walk refills the search text and the titles of every row.
        $layout = $layout->withSearch($after, $sameText ? $before->searchState : StorageViewLayout::SEARCH_BACKFILLING);
        $backfill = $backfill || $layout->needsBackfill();
        if ($registration->state === StorageViewRegistration::STATE_BUILDING && $backfill) {
            // A build under way wrote its rows with the earlier layout: it starts again.
            $this->registry->put($registration->withLayout($layout)->withState(StorageViewRegistration::STATE_PENDING));
            $this->defer($schemaId);

            return true;
        }
        $next = $registration->state === StorageViewRegistration::STATE_READY
            // A walk under way did not fill what is new for the rows behind it: it starts again.
            ? $registration->withLayout($layout)->withState(
                StorageViewRegistration::STATE_READY,
                $target->ref->version,
                $backfill ? null : $registration->buildCursor,
                $backfill ? null : $registration->buildRunRef,
            )
            : $registration->withLayout($layout);
        $this->registry->put($next);
        if ($layout->inState(StorageViewColumn::STATE_DROPPING) !== []) {
            $this->defer($schemaId);
        }

        return $backfill;
    }

    /**
     * In the transaction of a structure change, after its schema version (if any)
     * is in place: the витрина takes the fields' options. Nothing is rewritten.
     * New columns (prepared before the transaction) become backfilling and the
     * search text of a changed set of search fields is marked for refilling; the
     * rows written before are filled by the rebuild, and the old columns are dropped
     * once the new ones are filled. Returns true when the витрина needs that
     * rebuild (a backfill, or a build when the columns could not be added).
     */
    public function applyOptions(StorageSchemaVersion $schema, StorageViewFieldOptions $options): bool
    {
        if (!$this->enabled() || $this->ddl === null) {
            return false;
        }
        $schemaId = $schema->ref->schemaId;
        $registration = $this->registry->findForUpdate($schemaId);
        if ($registration === null || !$registration->written() || $registration->layout->schemaVersion !== $schema->ref->version) {
            return false;
        }
        $target = $this->planner->plan($schema->fields, $schema->ref->version, $registration->layout, $options);
        // Columns reserved before the transaction are part of the layout already: the change is
        // whether they are written now.
        if ($target->backfilling()->hash === $registration->layout->hash) {
            return false;
        }
        $verifier = new StorageViewShapeVerifier($this->ddl);
        if (!$verifier->matches($registration->tableName, $target) && $this->canRunDdl()) {
            $this->ddl->materialize($registration->tableName, $target->withoutDropped());
        }
        if (!$verifier->matches($registration->tableName, $target)) {
            // The columns could not be added now: nothing writes the витрина until it is rebuilt.
            $this->registry->put($registration->withLayout($target)->withState(StorageViewRegistration::STATE_PENDING));
            $this->defer($schemaId);

            return true;
        }
        $this->registry->put($this->backfillFrom($registration, $target));
        $this->defer($schemaId);

        return $target->backfilling()->needsBackfill();
    }

    /**
     * Outside a transaction (the rebuild): brings a ready витрина to the options
     * its structure declares now, with the DDL first, as a structure change does.
     * Returns the registration to backfill, or null when it could not be reshaped.
     *
     * @param list<array<string, mixed>> $fields The current schema version's fields.
     */
    public function reshape(StorageViewRegistration $registration, array $fields, ?StorageViewFieldOptions $options): ?StorageViewRegistration
    {
        if (!$this->enabled() || $this->ddl === null || $this->database->transactionLevel() > 0) {
            return null;
        }
        $target = $this->planner->plan($fields, $registration->layout->schemaVersion, $registration->layout, $options);
        if ($target->backfilling()->hash === $registration->layout->hash) {
            return $registration;
        }
        if (!$this->reserve($registration, $target)) {
            return null;
        }
        $verifier = new StorageViewShapeVerifier($this->ddl);
        $reshaped = $this->database->transaction(function () use ($registration, $fields, $options, $verifier): ?StorageViewRegistration {
            $current = $this->registry->findForUpdate($registration->schemaId);
            if ($current === null || $current->state !== StorageViewRegistration::STATE_READY
                || $current->layout->schemaVersion !== $registration->layout->schemaVersion) {
                return null;
            }
            $target = $this->planner->plan($fields, $current->layout->schemaVersion, $current->layout, $options);
            if (!$verifier->matches($current->tableName, $target)) {
                return null;
            }
            $next = $this->backfillFrom($current, $target);
            $this->registry->put($next);

            return $next;
        });
        $this->settleSchema($registration->schemaId);

        return $reshaped instanceof StorageViewRegistration ? $this->registry->find($registration->schemaId, true) : null;
    }

    /**
     * Once a build or a backfill reached the last row: the витрина is ready, every
     * column and the search text are complete, and the columns they replace go
     * (dropped by settleSchema).
     */
    public static function completed(StorageViewRegistration $registration): StorageViewRegistration
    {
        return $registration->withLayout($registration->layout->promoted())
            ->withState(StorageViewRegistration::STATE_READY, $registration->layout->schemaVersion);
    }

    /**
     * Marks a ready витрина's search text for refilling: the next rebuild (or job)
     * empties the search table and fills it again, in place. On MySQL that gives the
     * FULLTEXT index a fresh start after heavy editing. False when there is nothing
     * to refill (no ready витрина with a search table).
     */
    public function refreshSearch(string $schemaId): bool
    {
        if (!$this->enabled() || $this->database->transactionLevel() > 0) {
            return false;
        }
        $registration = $this->registry->find($schemaId, true);
        if ($registration === null || $registration->state !== StorageViewRegistration::STATE_READY
            || !$registration->layout->searchTable() || $registration->layout->searchState === StorageViewLayout::SEARCH_BACKFILLING) {
            return false;
        }
        $layout = $registration->layout->withSearch($registration->layout->searchFields, StorageViewLayout::SEARCH_BACKFILLING);

        return (bool) $this->database->transaction(function () use ($registration, $layout): bool {
            $current = $this->registry->findForUpdate($registration->schemaId);
            if ($current === null || $current->state !== StorageViewRegistration::STATE_READY || $current->layout->hash !== $registration->layout->hash) {
                return false;
            }
            // The walk starts from the first record.
            $this->registry->put($current->withLayout($layout)->withState(StorageViewRegistration::STATE_READY, $current->builtSchemaVersion));

            return true;
        });
    }

    /** The registration with the target layout, its new columns written from now on and its walk restarted. */
    private function backfillFrom(StorageViewRegistration $registration, StorageViewLayout $target): StorageViewRegistration
    {
        // A walk under way did not fill the new columns of the rows behind it: it starts again.
        return $registration->withLayout($target->backfilling())->withState($registration->state, $registration->builtSchemaVersion);
    }

    /**
     * The layout of a new, empty table: everything is filled (there is no row).
     *
     * @param list<array<string, mixed>> $fields
     */
    private function newLayout(array $fields, int $schemaVersion, ?StorageViewFieldOptions $options): StorageViewLayout
    {
        return $this->planner->plan($fields, $schemaVersion, null, $options)->promoted();
    }

    /** In the transaction that purges a structure: its витрина goes with it. */
    public function structurePurged(string $schemaId): void
    {
        if (!$this->enabled() || $this->ddl === null) {
            return;
        }
        $this->registry->delete($schemaId);
        if ($this->ddl->transactional()) {
            $this->ddl->dropTables(StorageViewNaming::table($schemaId));

            return;
        }
        $this->defer($schemaId);
    }

    /** Runs the DDL that waited for the commit. Callers run it after their transaction. */
    public function settle(): void
    {
        if ($this->ddl === null || $this->database->transactionLevel() > 0 || $this->deferred === []) {
            return;
        }
        $schemaIds = array_keys($this->deferred);
        $this->deferred = [];
        foreach ($schemaIds as $schemaId) {
            $this->settleSchema((string) $schemaId);
        }
    }

    /**
     * Brings one storage's tables to its committed registration: drops the tables of
     * a storage without one, builds a pending one's shape, and drops columns that are
     * no longer written. Idempotent and best effort: the rebuild repairs the rest.
     */
    public function settleSchema(string $schemaId): void
    {
        try {
            if (!$this->enabled() || !$this->canRunDdl() || $this->ddl === null) {
                return;
            }
            $table = StorageViewNaming::table($schemaId);
            $registration = $this->registry->find($schemaId, true);
            if ($registration === null) {
                $this->ddl->dropTables($table);

                return;
            }
            if (!$registration->written()) {
                $this->ddl->materialize($registration->tableName, $registration->layout);
                $this->replaceLayout($registration, $registration->layout->withoutDropped());

                return;
            }
            $this->ddl->dropStaleIndexes($registration->tableName, $registration->layout);
            $dropping = $registration->layout->inState(StorageViewColumn::STATE_DROPPING);
            if ($dropping === []) {
                return;
            }
            $kept = [];
            foreach ($registration->layout->written() as $column) {
                $kept[$column->fieldKey] = $column->column === null;
            }
            foreach ($dropping as $column) {
                if ($column->column !== null) {
                    $this->ddl->dropIndex($registration->tableName, $column->column);
                    $this->ddl->dropColumn($registration->tableName, $column->column);
                } elseif (($kept[$column->fieldKey] ?? false) !== true) {
                    $this->writer->deleteField($registration->tableName, $column->fieldKey);
                }
            }
            $this->replaceLayout($registration, $registration->layout->withoutDropped());
        } catch (Throwable) {
        }
    }

    /** Swaps the layout when nobody changed it since it was read. */
    private function replaceLayout(StorageViewRegistration $registration, StorageViewLayout $layout): bool
    {
        if ($layout->hash === $registration->layout->hash) {
            return true;
        }

        return (bool) $this->database->transaction(function () use ($registration, $layout): bool {
            $current = $this->registry->findForUpdate($registration->schemaId);
            if ($current === null || $current->layout->hash !== $registration->layout->hash || $current->state !== $registration->state) {
                return false;
            }
            $this->registry->put($current->withLayout($layout));

            return true;
        });
    }

    private function defer(string $schemaId): void
    {
        $this->deferred[$schemaId] = true;
        if ($this->database instanceof Connection) {
            try {
                // With Laravel's transaction manager this runs once the outermost
                // transaction commits; without it the callers settle themselves.
                $this->database->afterCommit(fn () => $this->settle());
            } catch (RuntimeException) {
            }
        }
    }

    private static function hasMulti(StorageViewLayout $layout): bool
    {
        foreach ($layout->columns as $column) {
            if ($column->column === null) {
                return true;
            }
        }

        return false;
    }
}
