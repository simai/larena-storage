<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\StorageSchemaVersion;
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
            $this->writer->delete($registration->tableName, $recordIds);
        }
    }

    /**
     * Before the transaction that creates a structure: its table, empty. Best effort;
     * whatever is not done here is done in the transaction or after it.
     *
     * @param list<array<string, mixed>> $fields
     */
    public function prepareCreate(string $schemaId, array $fields): void
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
            $this->ddl->ensureTable($table, $this->planner->plan($fields, 1)->promoted());
        } catch (Throwable) {
        }
    }

    /** In the transaction that created the structure's schema. */
    public function structureCreated(StorageSchemaVersion $schema): void
    {
        if (!$this->enabled() || $this->ddl === null) {
            return;
        }
        $schemaId = $schema->ref->schemaId;
        $table = StorageViewNaming::table($schemaId);
        $layout = $this->planner->plan($schema->fields, $schema->ref->version)->promoted();
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
     * @param list<array<string, mixed>> $targetFields
     */
    public function prepareChange(string $schemaId, array $targetFields): void
    {
        try {
            if (!$this->enabled() || !$this->canRunDdl() || $this->ddl === null) {
                return;
            }
            $registration = $this->registry->find($schemaId, true);
            if ($registration === null || !$registration->written()) {
                return;
            }
            $target = $this->planner->plan($targetFields, $registration->layout->schemaVersion + 1, $registration->layout);
            $reserved = $registration->layout->reserving($target);
            if ($reserved->hash !== $registration->layout->hash
                && !$this->replaceLayout($registration, $reserved)) {
                return;
            }
            foreach ($reserved->inState(StorageViewColumn::STATE_ADDING) as $column) {
                if ($column->column !== null) {
                    $this->ddl->addColumn($registration->tableName, $column);
                    if ($column->indexed) {
                        $this->ddl->createIndex($registration->tableName, $column);
                    }
                }
            }
        } catch (Throwable) {
        }
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
        $layout = $this->planner->plan($target->fields, $target->ref->version, $registration->layout);
        $verifier = new StorageViewShapeVerifier($this->ddl);
        if (!$verifier->matches($registration->tableName, $layout) && $this->canRunDdl()) {
            foreach ($layout->physical() as $column) {
                if ($column->state !== StorageViewColumn::STATE_DROPPING) {
                    $this->ddl->addColumn($registration->tableName, $column);
                    if ($column->indexed) {
                        $this->ddl->createIndex($registration->tableName, $column);
                    }
                }
            }
        }
        if (!$verifier->matches($registration->tableName, $layout)) {
            $this->registry->put($registration->withLayout($layout)->withState(StorageViewRegistration::STATE_PENDING));
            $this->defer($schemaId);

            return null;
        }
        $promoted = $layout->promoted();
        $next = $registration->withLayout($promoted);
        if ($registration->state === StorageViewRegistration::STATE_READY) {
            // Every record is rewritten in this transaction, so the витрина stays in sync.
            $next = $next->withBuiltVersion($target->ref->version);
        }
        $this->registry->put($next);
        if ($promoted->inState(StorageViewColumn::STATE_DROPPING) !== []) {
            $this->defer($schemaId);
        }

        return new StorageViewApply($this->projector, $this->writer, $registration->tableName, $promoted, self::hasMulti($promoted));
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
