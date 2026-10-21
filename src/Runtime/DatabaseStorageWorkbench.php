<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSchemaEvolutionTransactionScope;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Contracts\StorageWorkbench as StorageWorkbenchContract;
use Larena\Storage\Contracts\StorageWorkbenchFieldChange;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldEncryptionRunRow;
use Larena\Storage\Encryption\StorageFieldEncryptionRunner;
use Larena\Storage\Encryption\StorageFieldEncryptionRuns;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Encryption\StorageFieldSealer;
use Larena\Storage\Contracts\StorageWorkbenchEncryption;
use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageFieldChangeRow;
use Larena\Storage\SchemaEvolution\StorageFieldChangeRunner;
use Larena\Storage\SchemaEvolution\StorageFieldChanges;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\View\StorageRecordQueryNormalizer;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewCounter;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewKeyset;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewQuery;
use Larena\Storage\View\StorageViewQueryCompiler;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistration;
use Larena\Storage\View\StorageViewSync;
use stdClass;
use Throwable;

final readonly class DatabaseStorageWorkbench implements StorageWorkbenchContract, StorageWorkbenchEncryption
{
    private const STRUCTURE_RESOURCE = 'storage.workbench.structure';
    private const RECORD_RESOURCE = 'storage.workbench.record';
    private const SCOPE_FIELD = 'larena_scope_ref';
    private const STATE_FIELD = 'larena_record_state';
    private const STATE_ACTIVE = 'active';
    private const STATE_ARCHIVED = 'archived';
    private const MAX_STRUCTURES = 100;
    private const MAX_FIELDS = 100;
    // A structure purge removes its records in one transaction, at most this many (VersionedStorage's purge limit).
    private const MAX_STRUCTURE_PURGE = 5000;
    // Lists read from the витрина keep page numbers while the offset stays at or under this.
    private const MAX_OFFSET = 10000;
    // One bulk archive runs in a single transaction; 1000 records keeps "archive all matching" bounded.
    private const MAX_BULK = 1000;
    private const LOCK_UPDATE = 'update';
    private const LOCK_SHARED = 'shared';
    // A field change that checks every record runs in the request up to this many records; above it, in the background.
    public const INLINE_FIELD_CHANGE_LIMIT = 5000;
    private const INLINE_FIELD_CHANGE_CHUNK = 1000;
    // An encryption run rewrites up to this many rows (versions and translations) in the request; above it, in the
    // background (storage.field.encryption.rewrite).
    public const INLINE_ENCRYPTION_RUN_LIMIT = 5000;
    private const INLINE_ENCRYPTION_RUN_CHUNK = 1000;
    /** The descriptor flag that confirms switching encryption off a field with values (its values become plain). */
    public const DISABLE_CONFIRMATION = 'encryption_disable_confirmed';
    public const EVENT_STARTED = 'storage.field.encryption.started';
    public const EVENT_ENABLED = 'storage.field.encryption.enabled';
    public const EVENT_DISABLED = 'storage.field.encryption.disabled';
    public const EVENT_ROTATED = 'storage.field.encryption.rotated';
    public const EVENT_FAILED = 'storage.field.encryption.failed';
    public const EVENT_RETRIED = 'storage.field.encryption.retried';
    /**
     * A list of a storage whose витрина is not ready builds it in the request up to this
     * many records (one walk of the current versions in chunks of 1000), and then answers
     * from it; a larger storage is refused with VIEW_NOT_READY while the queued rebuild
     * builds it. Measured with `larena:storage:bench` (view_build_ms): see the CHANGELOG.
     */
    public const INLINE_VIEW_BUILD_LIMIT = 5000;
    /** A list (or an incoming-reference check) of a storage whose витрина is being built. */
    public const VIEW_NOT_READY = 'storage_view_not_ready';
    /** No витрина can be built here: no StorageViewSync, a driver without витрина DDL, or the registry not migrated. */
    public const VIEW_UNAVAILABLE = 'storage_view_unavailable';
    /** A query no витрина answers (a sort by a multi-valued field). */
    public const QUERY_UNSUPPORTED = 'storage_workbench_record_query_unsupported';

    private SchemaDefinitionNormalizer $normalizer;
    private StorageSecurityEventSink $events;
    private StorageViewQueryCompiler $compiler;
    private StorageViewCounter $counter;
    private StorageMetrics $metrics;
    private StorageRecordConverter $converter;
    private StorageFieldChanges $fieldChanges;
    private StorageFieldEncryptionRuns $encryptionRuns;
    private StorageRecordQueryNormalizer $queryNormalizer;

    public function __construct(
        private ConnectionInterface $database,
        private PropertyTypeRegistry $propertyTypes,
        private ActorOperationAuthorizer $authorizer,
        private QueryScopeProvider $scopeProvider,
        private VersionedStorage $storage,
        private StorageSchemaEvolutionOwnerPolicyRegistry $ownerPolicies,
        private string $cursorKey,
        ?StorageSecurityEventSink $events = null,
        // Builds each structure's витрина and keeps its columns in step with the fields.
        private ?StorageViewSync $views = null,
        // Counts lists read from the витрина: exact under a threshold, an estimate above it.
        ?StorageViewCounter $counter = null,
        // Times record lists for larena/monitoring when it is installed; a no-op otherwise.
        ?StorageMetrics $metrics = null,
        // Queues the витрина rebuild a change of field options needs (storage.view.rebuild).
        private ?Closure $viewBuilds = null,
        // Queues a field change that runs in the background (storage.schema.migrate); returns
        // true when it is queued. Without it every field change runs in the request.
        private ?Closure $fieldChangeJobs = null,
        // Up to this many records a field change runs in the request; above it, in the background.
        private int $inlineFieldChangeLimit = self::INLINE_FIELD_CHANGE_LIMIT,
        // Up to this many records a list builds a витрина that is not ready in the request; above it, refuses.
        private int $inlineViewBuildLimit = self::INLINE_VIEW_BUILD_LIMIT,
        // Encrypted fields: their data keys and availability (the storage seals the values). Without it a structure
        // with an encrypted field is refused (storage_field_encryption_unavailable).
        private ?StorageFieldSealer $encryption = null,
        // Queues an encryption run that runs in the background (storage.field.encryption.rewrite), given the run and
        // its last update (a retried run is a new job); returns true when it is queued. Without it every run runs in
        // the request.
        private ?Closure $encryptionJobs = null,
        // Up to this many rows an encryption run runs in the request; above it, in the background.
        private int $inlineEncryptionRunLimit = self::INLINE_ENCRYPTION_RUN_LIMIT,
    ) {
        $this->normalizer = new SchemaDefinitionNormalizer($propertyTypes);
        $this->events = $events ?? new NullStorageSecurityEventSink();
        $this->compiler = new StorageViewQueryCompiler();
        $this->counter = $counter ?? new StorageViewCounter();
        $this->metrics = $metrics ?? new StorageMetrics();
        // Reads every record version written under an older schema version under the current one.
        $this->converter = new StorageRecordConverter($database, $propertyTypes);
        $this->fieldChanges = new StorageFieldChanges($database);
        $this->encryptionRuns = new StorageFieldEncryptionRuns($database);
        $this->queryNormalizer = new StorageRecordQueryNormalizer($propertyTypes);
    }

    public function filterOperators(): array
    {
        return StorageRecordQueryNormalizer::FILTER_OPERATORS_BY_TYPE;
    }

    public function createStructure(
        string $scopeRef,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        $this->assertScope($actor, 'storage.workbench.structure.create', $scopeRef, self::STRUCTURE_RESOURCE);

        return $this->insertStructure($scopeRef, $descriptor, $actor, $correlationId, null, null);
    }

    /**
     * For a SitePack import (larena-specs docs/architecture/2026-10-22-storage-sitepack.md): the normalized descriptor
     * a new structure of $descriptor would get and its storage schema definition, without writing anything. Refused
     * like createStructure() would refuse the descriptor.
     *
     * @param array<string, mixed> $descriptor
     * @return array{descriptor: array{structure_id: string, label: string, fields: list<array<string, mixed>>}, schema_id: string, definition: array<string, mixed>}
     */
    public function importDefinition(string $scopeRef, array $descriptor): array
    {
        $normalized = $this->normalizeDescriptor($descriptor);
        $this->assertStructureId($normalized['structure_id']);
        $schemaId = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $normalized['structure_id']);

        return [
            'descriptor' => $normalized,
            'schema_id' => $schemaId,
            'definition' => $this->schemaDefinition($schemaId, $normalized['fields']),
        ];
    }

    /**
     * For a SitePack import: creates the structure in the «importing» state (`import_run_ref`). Until the import
     * clears that state the structure is hidden from lists and every workbench read or write of it is refused with
     * `storage_workbench_structure_importing`. Its витрина is left pending: the import writes no витрина rows and
     * rebuilds it when it ends. Needs the right to create structures in the scope.
     *
     * @param array<string, mixed> $descriptor
     */
    public function createImportingStructure(
        string $scopeRef,
        array $descriptor,
        ?int $historyKeep,
        string $importRunRef,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        $this->assertScope($actor, 'storage.workbench.structure.create', $scopeRef, self::STRUCTURE_RESOURCE);
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $importRunRef) !== 1
            || ($historyKeep !== null && ($historyKeep < 1 || $historyKeep > VersionedStorage::HISTORY_KEEP_LIMIT))) {
            throw new StorageRejected('storage_workbench_structure_import_invalid');
        }

        return $this->insertStructure($scopeRef, $descriptor, $actor, $correlationId, $importRunRef, $historyKeep);
    }

    /**
     * For a SitePack import: ends the «importing» state of the structure the run created; false when no structure of
     * the scope is importing for that run. In the caller's transaction.
     */
    public function finishImportingStructure(string $scopeRef, string $structureId, string $importRunRef): bool
    {
        return $this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $scopeRef)
            ->where('structure_id', $structureId)
            ->where('import_run_ref', $importRunRef)
            ->update(['import_run_ref' => null, 'updated_at' => $this->timestamp()]) === 1;
    }

    /**
     * For a SitePack import that is cancelled or failed: removes the structure the run created, while it is still
     * importing, with its schema, role bindings and витрины. Its records are removed by the import before (they may
     * be many). Nothing of a structure whose import ended is touched; false when there is nothing to remove.
     */
    public function discardImportingStructure(string $scopeRef, string $structureId, string $importRunRef): bool
    {
        try {
            $removed = $this->database->transaction(function () use ($scopeRef, $structureId, $importRunRef): bool {
                $head = $this->database->table('larena_storage_workbench_structures')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->where('import_run_ref', $importRunRef)
                    ->lockForUpdate()
                    ->first();
                if (!$head instanceof stdClass) {
                    return false;
                }
                $schemaId = (string) $head->storage_schema_id;
                if ($this->database->table('larena_storage_records')->where('schema_id', $schemaId)->exists()) {
                    throw new StorageRejected('storage_workbench_structure_import_not_empty');
                }
                if ($this->tableExists('larena_storage_structure_role_bindings')) {
                    $this->database->table('larena_storage_structure_role_bindings')->where('schema_id', $schemaId)->delete();
                }
                $this->database->table('larena_storage_workbench_structure_versions')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->delete();
                $this->database->table('larena_storage_workbench_structures')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->where('import_run_ref', $importRunRef)
                    ->delete();
                $this->database->table('larena_storage_schema_versions')->where('schema_id', $schemaId)->delete();
                $this->database->table('larena_storage_schemas')->where('schema_id', $schemaId)->delete();
                if ($this->tableExists(StorageDataKeys::TABLE)) {
                    StorageDataKeys::purge($this->database, $schemaId);
                }
                $this->views?->structurePurged($schemaId);

                return true;
            });
            $this->views?->settle();

            return $removed;
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /** @param array<string, mixed> $descriptor */
    private function insertStructure(
        string $scopeRef,
        array $descriptor,
        string $actor,
        ?string $correlationId,
        ?string $importRunRef,
        ?int $historyKeep,
    ): StorageWorkbenchStructure {
        $normalized = $this->normalizeDescriptor($descriptor);
        $this->assertStructureId($normalized['structure_id']);
        $correlationId = $this->correlationId($correlationId);
        $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $normalized['structure_id']);
        $definition = $this->schemaDefinition($storageSchemaId, $normalized['fields']);
        // Encrypted fields need the manage right and a working key, before anything is written.
        $encrypted = StorageEncryptionPolicy::encryptedKeys($normalized['fields']) !== [];
        if ($encrypted) {
            $this->assertEncryptionManaged($actor);
        }
        // The витрина table is created before the transaction: MySQL cannot run DDL inside one.
        $options = StorageViewFieldOptions::resolve($normalized['fields']);
        $this->views?->prepareCreate($storageSchemaId, $definition['fields'], $options);

        try {
            $created = $this->database->transaction(function () use ($scopeRef, $normalized, $storageSchemaId, $definition, $actor, $correlationId, $options, $importRunRef, $historyKeep, $encrypted): StorageWorkbenchStructure {
                $schema = $this->storage->registerSchemaVersion(
                    $definition,
                    null,
                    $actor,
                    $correlationId,
                );
                if ($encrypted) {
                    // The storage's data key is made with it, in its transaction.
                    $this->encryption?->keys()->ensureActive($storageSchemaId);
                }
                $this->views?->structureCreated($schema, $options);
                if ($importRunRef !== null && $this->views !== null && $this->views->enabled()) {
                    // The import writes no витрина rows: the витрина waits for the rebuild the import queues.
                    $registration = $this->views->registry()->find($storageSchemaId, true);
                    if ($registration !== null) {
                        $this->views->registry()->put($registration->withState(StorageViewRegistration::STATE_PENDING));
                    }
                }
                $now = $this->timestamp();
                $hash = $this->descriptorHash(
                    $normalized['structure_id'],
                    $scopeRef,
                    1,
                    $schema->ref->version,
                    $normalized['label'],
                    $normalized['fields'],
                );
                $this->database->table('larena_storage_workbench_structures')->insert([
                    'structure_id' => $normalized['structure_id'],
                    'scope_ref' => $scopeRef,
                    'storage_schema_id' => $storageSchemaId,
                    'current_version' => 1,
                    'current_schema_version' => $schema->ref->version,
                    'current_hash' => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ] + ($importRunRef === null ? [] : ['import_run_ref' => $importRunRef, 'history_keep' => $historyKeep]));
                $this->insertStructureVersion(
                    $normalized['structure_id'],
                    1,
                    $scopeRef,
                    $storageSchemaId,
                    $normalized['label'],
                    $normalized['fields'],
                    $schema->ref->version,
                    $hash,
                    $actor,
                    $correlationId,
                    $now,
                );

                return new StorageWorkbenchStructure(
                    $normalized['structure_id'],
                    $scopeRef,
                    1,
                    $schema->ref,
                    $normalized['label'],
                    $normalized['fields'],
                    $hash,
                    $now,
                    'create',
                    $actor,
                    $correlationId,
                    null,
                    null,
                    $historyKeep,
                );
            });
            $this->views?->settle();

            return $created;
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintConflict($exception)) {
                throw new StorageConflict('storage_workbench_structure_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function updateStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        $this->assertStructureId($structureId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('storage_workbench_structure_version_invalid');
        }
        $this->assertScope($actor, 'storage.workbench.structure.update', $scopeRef, self::STRUCTURE_RESOURCE);
        $confirmed = self::takeDisableConfirmation($descriptor);
        $normalized = $this->normalizeDescriptor($descriptor);
        if ($normalized['structure_id'] !== $structureId) {
            throw new StorageRejected('storage_workbench_structure_identity_changed');
        }

        return $this->changeStructure($scopeRef, $structureId, $expectedVersion, $normalized, $actor, $this->correlationId($correlationId), false, $confirmed);
    }

    /**
     * Changes a structure in ways an update refuses: fields may be removed, retyped,
     * made required or given other constraints. No record version is rewritten: each
     * keeps the schema version it was written under and is read through the converter
     * (StorageRecordConverter), so a removed field's value is not read any more and a
     * retyped one reads converted. History keeps the earlier revisions as written.
     *
     * - Removing fields and adding optional ones switch the structure at once.
     * - A change that every record must pass (a retype, a field made required, changed
     *   constraints) is a field change: every current record is checked, and the
     *   витрина's new columns filled, before the structure switches. Up to
     *   INLINE_FIELD_CHANGE_LIMIT records (or without a queue) it runs in this call,
     *   refused as a whole when a record does not convert; above it, it runs in the
     *   background as the `storage.schema.migrate` job, and the structure returned keeps
     *   its fields and carries the running change (StorageWorkbenchStructure::$fieldChange).
     */
    public function migrateStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        $this->assertStructureId($structureId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('storage_workbench_structure_version_invalid');
        }
        $this->assertScope($actor, 'storage.workbench.structure.migrate', $scopeRef, self::STRUCTURE_RESOURCE);
        $confirmed = self::takeDisableConfirmation($descriptor);
        $normalized = $this->normalizeDescriptor($descriptor);
        if ($normalized['structure_id'] !== $structureId) {
            throw new StorageRejected('storage_workbench_structure_identity_changed');
        }

        return $this->changeStructure($scopeRef, $structureId, $expectedVersion, $normalized, $actor, $this->correlationId($correlationId), true, $confirmed);
    }

    /**
     * Runs a field change to its end: the `storage.schema.migrate` job, and a small change
     * inline. Resumable, idempotent (a change that ended returns its state) and cancel-safe:
     * the structure switches in one transaction only while the change is still running.
     *
     * @param Closure(): void|null $checkpoint Runs between chunks (the job's heartbeat).
     * @return array{state: string, checked: int, resumed: bool}
     */
    public function runFieldChange(string $changeRef, int $chunk = StorageFieldChangeRunner::DEFAULT_CHUNK, ?Closure $checkpoint = null): array
    {
        $runner = new StorageFieldChangeRunner($this->database, $this->normalizer, $this->fieldChanges, $this->views, $this->converter);
        try {
            return $runner->run($changeRef, $chunk, $checkpoint, function (StorageFieldChangeRow $change, array $filled, Closure $recheck): void {
                $this->switchFieldChange($change, $filled, $recheck);
            });
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isLockConflict($exception)) {
                throw new StorageConflict('storage_field_change_interrupted');
            }
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * Stops a field change that runs in the background. Nothing it did is kept: the
     * structure keeps its fields and the columns it reserved are dropped by the next change.
     */
    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.structure.migrate', $scopeRef, self::STRUCTURE_RESOURCE);
        $correlationId = $this->correlationId($correlationId);
        $structure = $this->database->transaction(function () use ($scopeRef, $structureId, $actor, $correlationId): StorageWorkbenchStructure {
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_UPDATE);
            $running = $this->fieldChanges->running($structure->schema->schemaId, true);
            if ($running === null) {
                throw new StorageRejected('storage_workbench_field_change_not_running');
            }
            $this->fieldChanges->put($running->ended(StorageWorkbenchFieldChange::STATE_CANCELLED, null, $this->timestamp()));
            $this->emitFieldChange('storage.schema_migration.rejected', $running, $actor, $correlationId, 'storage_field_change_cancelled');

            return $structure;
        });

        return $this->stated([$structure])[0];
    }

    /**
     * Runs an encryption run to its end: the `storage.field.encryption.rewrite` job, and a small run inline.
     * Resumable and idempotent (a run that ended returns its state). At the end the run is audited
     * (`storage.field.encryption.enabled`, `.disabled` or `.rotated`, with counts and field keys, never a value);
     * after a decrypt run the витрина is planned again and refilled, so the field gets its column back.
     *
     * @param Closure(): void|null $checkpoint Runs between chunks (the job's heartbeat).
     * @return array{state: string, rewritten: int, skipped: int, resumed: bool}
     */
    public function runFieldEncryption(string $runRef, int $chunk = StorageFieldEncryptionRunner::DEFAULT_CHUNK, ?Closure $checkpoint = null): array
    {
        if ($this->encryption === null) {
            throw new StorageRejected(StorageEncryptionPolicy::UNAVAILABLE);
        }
        $runner = new StorageFieldEncryptionRunner($this->database, $this->encryptionRuns, $this->encryption->keys(), $this->normalizer, $this->views);
        $decrypted = null;
        try {
            $result = $runner->run($runRef, $chunk, $checkpoint, function (StorageFieldEncryptionRunRow $run) use (&$decrypted): void {
                $this->emitEncryption(match ($run->mode) {
                    StorageWorkbenchEncryptionRun::MODE_ENCRYPT => self::EVENT_ENABLED,
                    StorageWorkbenchEncryptionRun::MODE_DECRYPT => self::EVENT_DISABLED,
                    default => self::EVENT_ROTATED,
                }, $run, $run->createdBy, $run->correlationId ?? $this->correlationId(null), null);
                if ($run->mode === StorageWorkbenchEncryptionRun::MODE_DECRYPT) {
                    $decrypted = $run->schemaId;
                }
            });
        } catch (StorageRejected $exception) {
            $failed = $this->encryptionRuns->find($runRef);
            if ($failed !== null && $failed->state === StorageWorkbenchEncryptionRun::STATE_FAILED) {
                try {
                    $this->emitEncryption(self::EVENT_FAILED, $failed, $failed->createdBy, $failed->correlationId ?? $this->correlationId(null), $exception->reasonCode);
                } catch (Throwable) {
                    // The run is failed already; its state says why.
                }
            }
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isLockConflict($exception)) {
                throw new StorageConflict(StorageFieldEncryptionRunner::REASON_INTERRUPTED);
            }
            throw StoragePersistenceFailed::from($exception);
        }
        $this->views?->settle();
        if (is_string($decrypted)) {
            $this->replanView($decrypted);
        }

        return $result;
    }

    public function rotateEncryptionKey(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.structure.update', $scopeRef, self::STRUCTURE_RESOURCE);
        $this->assertEncryptionManaged($actor);
        $correlationId = $this->correlationId($correlationId);
        try {
            $runRef = $this->database->transaction(function () use ($scopeRef, $structureId, $actor, $correlationId): string {
                $head = $this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE);
                if ($this->headArchivedAt($head) !== null) {
                    throw new StorageRejected('storage_workbench_structure_archived');
                }
                $structure = $this->hydrateStructureVersion($head, self::LOCK_UPDATE);
                $schemaId = $structure->schema->schemaId;
                $this->assertNoFieldChange($schemaId);
                $keys = $this->encryption?->keys();
                if ($keys === null || !$this->encryptionRuns->available()) {
                    throw new StorageRejected(StorageEncryptionPolicy::UNAVAILABLE);
                }
                if ($keys->activeKeyId($schemaId) === null) {
                    throw new StorageRejected(StorageEncryptionPolicy::NOT_ENABLED);
                }
                [$retiring, $active] = $keys->rotate($schemaId);

                return $this->startEncryptionRun(
                    $structure,
                    StorageWorkbenchEncryptionRun::MODE_ROTATE,
                    StorageEncryptionPolicy::encryptedKeys($structure->fields),
                    $retiring,
                    $active,
                    $actor,
                    $correlationId,
                );
            });
        } catch (StorageRejected|StorageConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException && $this->isLockConflict($exception)) {
                throw new StorageConflict('storage_workbench_structure_version_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        }
        $this->dispatchEncryptionRun($runRef);

        return $this->stated([$this->readStructureInternal($scopeRef, $structureId)])[0];
    }

    public function retryEncryptionRun(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.structure.update', $scopeRef, self::STRUCTURE_RESOURCE);
        $this->assertEncryptionManaged($actor);
        $correlationId = $this->correlationId($correlationId);
        $runRef = $this->database->transaction(function () use ($scopeRef, $structureId): string {
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_UPDATE);
            $open = $this->encryptionRuns->open($structure->schema->schemaId, true);
            if ($open === null || $open->state !== StorageWorkbenchEncryptionRun::STATE_FAILED) {
                throw new StorageRejected(StorageEncryptionPolicy::NOT_FAILED);
            }
            $this->encryptionRuns->put($open->retried(StorageFieldEncryptionRunner::now()));

            return $open->runRef;
        });
        $retried = $this->encryptionRuns->find($runRef);
        if ($retried !== null) {
            $this->emitEncryption(self::EVENT_RETRIED, $retried, $actor, $correlationId, null);
        }
        $this->dispatchEncryptionRun($runRef);

        return $this->stated([$this->readStructureInternal($scopeRef, $structureId)])[0];
    }

    /**
     * In the transaction that switched the structure (or rotated its key): records the run, queued.
     *
     * @param list<string> $fields
     */
    private function startEncryptionRun(
        StorageWorkbenchStructure $structure,
        string $mode,
        array $fields,
        ?string $sourceKeyId,
        ?string $targetKeyId,
        string $actor,
        string $correlationId,
    ): string {
        $schemaId = $structure->schema->schemaId;
        $versions = $this->database->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count();
        $localized = 0;
        if ($this->tableExists('larena_storage_localized_values')) {
            $query = $this->database->table('larena_storage_localized_values')->where('schema_id', $schemaId);
            if ($mode !== StorageWorkbenchEncryptionRun::MODE_ROTATE) {
                $query->whereIn('field_key', $fields === [] ? [''] : $fields);
            }
            $localized = $query->count();
        }
        $now = $this->timestamp();
        $run = new StorageFieldEncryptionRunRow(
            StorageFieldEncryptionRunRow::newRef(),
            $schemaId,
            $structure->scopeRef,
            $structure->structureId,
            $mode,
            $fields,
            $sourceKeyId,
            $targetKeyId,
            StorageWorkbenchEncryptionRun::STATE_QUEUED,
            StorageFieldEncryptionRunRow::PHASE_VERSIONS,
            null,
            null,
            0,
            0,
            $versions + $localized,
            null,
            $actor,
            $correlationId,
            $now,
            $now,
            null,
        );
        $this->encryptionRuns->insert($run);
        $this->emitEncryption(self::EVENT_STARTED, $run, $actor, $correlationId, null);

        return $run->runRef;
    }

    /** After the commit: a large run goes to the queue; a small one (or one no queue takes) runs here. */
    private function dispatchEncryptionRun(string $runRef): void
    {
        $run = $this->encryptionRuns->find($runRef);
        if ($run === null || !$run->running()) {
            return;
        }
        if ($run->total > $this->inlineEncryptionRunLimit && $this->encryptionJobs !== null) {
            try {
                if (($this->encryptionJobs)($runRef, $run->updatedAt) === true) {
                    return;
                }
            } catch (Throwable) {
            }
        }
        try {
            $this->runFieldEncryption($runRef, self::INLINE_ENCRYPTION_RUN_CHUNK);
        } catch (StorageRejected|StorageConflict) {
            // The switch is committed: the run is failed (and says why) or another walk holds it; the structure
            // returned carries its state.
        }
    }

    /**
     * After a decrypt run: the витрина is planned again for the fields as they are now (the opened field gets its
     * column and search text back) and refilled: here for a small storage, else by the queued rebuild.
     */
    private function replanView(string $schemaId): void
    {
        $views = $this->views;
        if ($views === null || !$views->enabled()) {
            return;
        }
        try {
            $large = $this->database->table('larena_storage_records')
                ->where('schema_id', $schemaId)
                ->orderBy('record_id')
                ->offset(max(0, $this->inlineViewBuildLimit))
                ->limit(1)
                ->exists();
            $ddl = $views->ddl();
            if ($large || $ddl === null || $this->database->transactionLevel() > 0) {
                $this->requestViewBuild($schemaId);

                return;
            }
            (new StorageViewRebuilder($views, $this->metrics))->rebuild($schemaId, StorageViewRebuilder::DEFAULT_CHUNK);
        } catch (Throwable) {
            // The rebuild job (or `larena:storage:view:check --repair`) finishes it.
            $this->requestViewBuild($schemaId);
        } finally {
            $views->registry()->forget($schemaId);
        }
    }

    private function emitEncryption(string $type, StorageFieldEncryptionRunRow $run, string $actor, string $correlationId, ?string $reason): void
    {
        $this->events->emit(new StorageSecurityEvent(
            'encryption',
            $type,
            $actor,
            'storage-schema:' . $run->schemaId,
            $correlationId,
            [
                'schema_id' => $run->schemaId,
                'structure_id' => $run->structureId,
                'run_ref' => $run->runRef,
                'mode' => $run->mode,
                'fields' => $run->fields,
                'source_key_id' => $run->sourceKeyId,
                'target_key_id' => $run->targetKeyId,
                'rewritten' => $run->rewritten,
                'skipped' => $run->skipped,
                'total' => $run->total,
            ] + ($reason === null ? [] : ['reason_code' => $reason]),
        ));
    }

    /**
     * The confirmation flag of a structure change that switches encryption off a field with values, taken out of the
     * descriptor (it is no part of the structure).
     *
     * @param array<string, mixed> $descriptor
     */
    private static function takeDisableConfirmation(array &$descriptor): bool
    {
        if (!array_key_exists(self::DISABLE_CONFIRMATION, $descriptor)) {
            return false;
        }
        $confirmed = $descriptor[self::DISABLE_CONFIRMATION];
        unset($descriptor[self::DISABLE_CONFIRMATION]);
        if (!is_bool($confirmed)) {
            throw new StorageRejected('storage_workbench_structure_descriptor_invalid');
        }

        return $confirmed;
    }

    /**
     * The schema the витрина is planned for: the fields a decrypt run still opens stay out as if encrypted.
     *
     * @param list<string> $sealed
     */
    private static function viewSchema(StorageSchemaVersion $schema, array $sealed): StorageSchemaVersion
    {
        if ($sealed === []) {
            return $schema;
        }

        return new StorageSchemaVersion(
            $schema->ref,
            $schema->ownerPackage,
            StorageFieldEncryptionRuns::viewFields($schema->fields, $sealed),
            $schema->definitionHash,
            $schema->createdBy,
            $schema->correlationId,
            $schema->createdAt,
        );
    }

    /**
     * @param array{structure_id: string, label: string, fields: list<array<string, mixed>>} $normalized
     */
    private function changeStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        array $normalized,
        string $actor,
        string $correlationId,
        bool $migrate,
        bool $disableConfirmed = false,
    ): StorageWorkbenchStructure {
        // A change of which fields are encrypted needs the manage right and a working key, asked before anything.
        $before = StorageEncryptionPolicy::encryptedKeys($this->readStructureInternal($scopeRef, $structureId)->fields);
        $after = StorageEncryptionPolicy::encryptedKeys($normalized['fields']);
        sort($before, SORT_STRING);
        sort($after, SORT_STRING);
        if ($before !== $after) {
            $this->assertEncryptionManaged($actor);
        }
        $this->prepareViewChange($scopeRef, $structureId, $expectedVersion, $normalized['fields'], $migrate);

        try {
            $viewBuild = false;
            $changeRef = null;
            $runRef = null;
            $changed = $this->database->transaction(function () use (
                $scopeRef,
                $structureId,
                $expectedVersion,
                $normalized,
                $actor,
                $correlationId,
                $migrate,
                $disableConfirmed,
                &$viewBuild,
                &$changeRef,
                &$runRef,
            ): StorageWorkbenchStructure {
                $head = $this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE);
                if ((int) $head->current_version !== $expectedVersion) {
                    throw new StorageConflict('storage_workbench_structure_version_conflict');
                }
                if ($this->headArchivedAt($head) !== null) {
                    throw new StorageRejected('storage_workbench_structure_archived');
                }
                $current = $this->hydrateStructureVersion($head, self::LOCK_UPDATE);
                $this->assertNoFieldChange($current->schema->schemaId);
                $currentSchema = $this->storage->schemaVersion($current->schema, true);
                $candidateDefinition = $migrate
                    ? $this->schemaDefinition($current->schema->schemaId, $normalized['fields'])
                    : $this->schemaDefinitionForUpdate($currentSchema, $normalized['fields']);
                $rewrite = $this->assertEncryptionChange($currentSchema, $candidateDefinition);
                $checked = $migrate ? $this->checkedFields($currentSchema, $candidateDefinition) : [];
                if ($rewrite !== null) {
                    // Switch first, then rewrite: the structure takes the new setting now (new writes follow it at
                    // once), and the run that brings what was stored to it is queued in the same transaction.
                    if ($checked !== []) {
                        throw new StorageRejected(StorageEncryptionPolicy::CHANGE_COMBINED);
                    }
                    $decrypt = $rewrite['mode'] === StorageWorkbenchEncryptionRun::MODE_DECRYPT;
                    if ($decrypt && !$disableConfirmed) {
                        throw new StorageRejected(StorageEncryptionPolicy::DISABLE_UNCONFIRMED);
                    }
                    [$structure, $viewBuild] = $this->switchStructure(
                        $head,
                        $current,
                        $currentSchema,
                        $normalized,
                        $candidateDefinition,
                        $actor,
                        $correlationId,
                        $migrate ? 'migrate' : 'update',
                        [],
                        // The витрина gets the column of a field switched off once its values are plain.
                        $decrypt ? $rewrite['fields'] : [],
                    );
                    $runRef = $this->startEncryptionRun(
                        $structure,
                        $rewrite['mode'],
                        $rewrite['fields'],
                        null,
                        $decrypt ? null : $this->encryption?->keys()->activeKeyId($structure->schema->schemaId),
                        $actor,
                        $correlationId,
                    );

                    return $structure;
                }
                if ($checked !== []) {
                    // Every record must pass: the change checks them first, and switches after.
                    $changeRef = $this->startFieldChange($head, $current, $currentSchema, $normalized, $candidateDefinition, $checked, $actor, $correlationId);

                    return $current;
                }
                [$structure, $viewBuild] = $this->switchStructure(
                    $head,
                    $current,
                    $currentSchema,
                    $normalized,
                    $candidateDefinition,
                    $actor,
                    $correlationId,
                    $migrate ? 'migrate' : 'update',
                    [],
                );

                return $structure;
            });
            $this->views?->settle();
            if ($viewBuild) {
                $this->requestViewBuild($changed->schema->schemaId);
            }
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintConflict($exception) || $this->isLockConflict($exception)) {
                throw new StorageConflict('storage_workbench_structure_version_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
        if ($runRef !== null) {
            $this->dispatchEncryptionRun($runRef);

            return $this->stated([$this->readStructureInternal($scopeRef, $structureId)])[0];
        }
        if ($changeRef === null) {
            return $this->stated([$changed])[0];
        }
        $change = $this->fieldChanges->find($changeRef);
        if ($change !== null && $change->total > $this->inlineFieldChangeLimit && $this->enqueueFieldChange($changeRef)) {
            return $this->stated([$this->readStructureInternal($scopeRef, $structureId)])[0];
        }
        $this->runFieldChange($changeRef, self::INLINE_FIELD_CHANGE_CHUNK);

        return $this->stated([$this->readStructureInternal($scopeRef, $structureId)])[0];
    }

    /**
     * In the structure change's transaction: the schema version of the new fields
     * (when they changed), the витрина in step with it, and the structure's next
     * version. No record version is written.
     *
     * @param array{structure_id: string, label: string, fields: list<array<string, mixed>>} $normalized
     * @param array<string, mixed> $candidateDefinition
     * @param list<string> $filled The витрина columns a field change filled.
     * @param list<string> $viewSealed Fields the витрина keeps out as if encrypted (a decrypt run opens them).
     * @return array{0: StorageWorkbenchStructure, 1: bool} The structure, and whether its витрина needs the rebuild job.
     */
    private function switchStructure(
        stdClass $head,
        StorageWorkbenchStructure $current,
        StorageSchemaVersion $currentSchema,
        array $normalized,
        array $candidateDefinition,
        string $actor,
        string $correlationId,
        string $operation,
        array $filled,
        array $viewSealed = [],
    ): array {
        $structureId = $current->structureId;
        $scopeRef = $current->scopeRef;
        $expectedVersion = $current->version;
        $schemaVersion = $current->schema->version;
        $targetSchema = $currentSchema;
        $options = StorageViewFieldOptions::resolve($normalized['fields']);
        $viewBuild = false;
        if ($this->canonicalJson($candidateDefinition) !== $this->canonicalJson(
            $this->schemaDefinitionFromVersion($currentSchema),
        )) {
            $targetSchema = $this->switchSchema($currentSchema, $candidateDefinition, $actor, $correlationId);
            $schemaVersion = $targetSchema->ref->version;
            $viewBuild = $this->views?->switchSchema($currentSchema, self::viewSchema($targetSchema, $viewSealed), $options, $filled) ?? false;
        }
        // Options alone change the витрина, never a record version.
        $viewBuild = ($this->views?->applyOptions(self::viewSchema($targetSchema, $viewSealed), $options) ?? false) || $viewBuild;

        $nextVersion = $expectedVersion + 1;
        $now = $this->timestamp();
        $hash = $this->descriptorHash(
            $structureId,
            $scopeRef,
            $nextVersion,
            $schemaVersion,
            $normalized['label'],
            $normalized['fields'],
        );
        $updated = $this->database->table('larena_storage_workbench_structures')
            ->where('structure_id', $structureId)
            ->where('scope_ref', $scopeRef)
            ->where('current_version', $expectedVersion)
            ->update([
                'current_version' => $nextVersion,
                'current_schema_version' => $schemaVersion,
                'current_hash' => $hash,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new StorageConflict('storage_workbench_structure_version_conflict');
        }
        $this->insertStructureVersion(
            $structureId,
            $nextVersion,
            $scopeRef,
            (string) $head->storage_schema_id,
            $normalized['label'],
            $normalized['fields'],
            $schemaVersion,
            $hash,
            $actor,
            $correlationId,
            $now,
        );

        return [new StorageWorkbenchStructure(
            $structureId,
            $scopeRef,
            $nextVersion,
            new StorageSchemaVersionRef((string) $head->storage_schema_id, $schemaVersion),
            $normalized['label'],
            $normalized['fields'],
            $hash,
            $now,
            $operation,
            $actor,
            $correlationId,
            $current->archivedAt,
            $current->archivedBy,
            $current->historyKeep,
        ), $viewBuild];
    }

    /**
     * Registers the next schema version of a workbench storage without rewriting a
     * record: the records keep their schema versions and read through the converter.
     *
     * @param array<string, mixed> $candidateDefinition
     */
    private function switchSchema(StorageSchemaVersion $current, array $candidateDefinition, string $actor, string $correlationId): StorageSchemaVersion
    {
        // The same Access operations the planned migration asked for.
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.plan');
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.dispatch');
        $definition = $this->normalizer->normalize($candidateDefinition);
        if ($definition['schema_id'] !== $current->ref->schemaId || $definition['owner_package'] !== $current->ownerPackage) {
            throw new StorageRejected('storage_schema_migration_identity_changed');
        }
        $definitionJson = $this->normalizer->canonicalJson($definition);
        $hash = hash('sha256', $definitionJson);
        // The owner's policy still decides who changes a workbench schema: the workbench itself.
        $this->ownerPolicies->withinTransaction($this->database, function (StorageSchemaEvolutionTransactionScope $scope) use ($current, $actor, $hash): void {
            $this->ownerPolicies->authorize($current->ownerPackage, new StorageSchemaEvolutionOwnerContext(
                operation: 'apply',
                actor: $actor,
                source: $current->ref,
                sourceHash: $current->definitionHash,
                targetHash: $hash,
                planRef: null,
                planHash: null,
                connection: $this->database,
                transactionScope: $scope,
            ), $this);
        });
        $schemaHead = $this->database->table('larena_storage_schemas')
            ->where('schema_id', $current->ref->schemaId)
            ->lockForUpdate()
            ->first();
        if (!$schemaHead instanceof stdClass
            || (int) $schemaHead->current_version !== $current->ref->version
            || !hash_equals((string) $schemaHead->current_hash, $current->definitionHash)) {
            throw new StorageRejected('storage_schema_migration_schema_head_stale');
        }
        $target = new StorageSchemaVersionRef($current->ref->schemaId, $current->ref->version + 1);
        $now = $this->timestamp();
        $updated = $this->database->table('larena_storage_schemas')
            ->where('schema_id', $current->ref->schemaId)
            ->where('current_version', $current->ref->version)
            ->update(['current_version' => $target->version, 'current_hash' => $hash, 'updated_at' => $now]);
        if ($updated !== 1) {
            throw new StorageConflict('storage_schema_migration_conflict');
        }
        $this->database->table('larena_storage_schema_versions')->insert([
            'schema_id' => $target->schemaId,
            'version' => $target->version,
            'definition' => $definitionJson,
            'definition_hash' => $hash,
            'owner_package' => $definition['owner_package'],
            'created_by' => $actor,
            'correlation_id' => $correlationId,
            'created_at' => $now,
        ]);
        $this->events->emit(new StorageSecurityEvent(
            'schema_migration',
            'storage.schema_migration.applied',
            $actor,
            'storage-schema:' . $target->schemaId,
            $correlationId,
            [
                'schema_id' => $target->schemaId,
                'source_version' => $current->ref->version,
                'target_version' => $target->version,
                // Read through the converter: no record version is rewritten.
                'record_count' => 0,
            ],
        ));

        return new StorageSchemaVersion($target, $definition['owner_package'], $definition['fields'], $hash, $actor, $correlationId, $now);
    }

    /**
     * The fields of a declared change that every record must pass: a retyped field, a
     * field made required, changed constraints, an added field that is required. Empty
     * when the change only removes fields, adds optional ones, or changes what values
     * do not depend on (visibility, order, the localized flag).
     *
     * @param array<string, mixed> $candidateDefinition
     * @return list<string>
     */
    private function checkedFields(StorageSchemaVersion $current, array $candidateDefinition): array
    {
        $from = StorageRecordConverter::byKey($current->fields);
        $fields = is_array($candidateDefinition['fields'] ?? null) ? $candidateDefinition['fields'] : [];
        $checked = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null)) {
                continue;
            }
            $key = $field['key'];
            $source = $from[$key] ?? null;
            if ($source === null) {
                // An optional added field is empty in every record, whatever its constraints.
                if (($field['required'] ?? false) !== false) {
                    $checked[] = $key;
                }
                continue;
            }
            if (($source['type'] ?? null) !== ($field['type'] ?? null)
                || ($source['type_version'] ?? 1) !== ($field['type_version'] ?? 1)
                || $this->canonicalJson($source['constraints'] ?? []) !== $this->canonicalJson($field['constraints'] ?? [])
                || (($source['required'] ?? false) === false && ($field['required'] ?? false) === true)) {
                $checked[] = $key;
            }
        }

        return $checked;
    }

    /**
     * In the structure change's transaction: records the field change, to be run.
     *
     * @param array{structure_id: string, label: string, fields: list<array<string, mixed>>} $normalized
     * @param array<string, mixed> $candidateDefinition
     * @param list<string> $checked
     */
    private function startFieldChange(
        stdClass $head,
        StorageWorkbenchStructure $current,
        StorageSchemaVersion $currentSchema,
        array $normalized,
        array $candidateDefinition,
        array $checked,
        string $actor,
        string $correlationId,
    ): string {
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.plan');
        $definition = $this->normalizer->normalize($candidateDefinition);
        $now = $this->timestamp();
        $change = new StorageFieldChangeRow(
            'storage-field-change-' . bin2hex(random_bytes(16)),
            $current->schema->schemaId,
            $current->scopeRef,
            $current->structureId,
            $currentSchema->ref->version,
            (int) $head->current_version,
            ['schema_id' => $definition['schema_id'], 'owner_package' => $definition['owner_package'], 'fields' => $definition['fields']],
            hash('sha256', $this->normalizer->canonicalJson($definition)),
            $normalized,
            $checked,
            StorageWorkbenchFieldChange::STATE_RUNNING,
            null,
            null,
            0,
            $this->database->table('larena_storage_records')->where('schema_id', $current->schema->schemaId)->count(),
            null,
            $actor,
            $correlationId,
            $now,
            $now,
            null,
        );
        $this->fieldChanges->insert($change);
        $this->emitFieldChange('storage.schema_migration.planned', $change, $actor, $correlationId, null);

        return $change->changeRef;
    }

    /**
     * The switch at the end of a field change, in one transaction that holds the
     * storage for update (writers wait): the records written since the walk checked
     * them are checked, then the structure takes the target fields and the витрина
     * its filled columns. Refused when the change was cancelled or the structure
     * moved meanwhile; a record that does not convert fails the change.
     *
     * @param list<string> $filled
     * @param Closure(): int $recheck
     */
    private function switchFieldChange(StorageFieldChangeRow $change, array $filled, Closure $recheck): void
    {
        $viewBuild = false;
        try {
            $this->database->transaction(function () use ($change, $filled, $recheck, &$viewBuild): void {
                // Writers hold the storage for share: once this is taken, every write has committed.
                $this->database->table('larena_storage_schemas')->where('schema_id', $change->schemaId)->lockForUpdate()->first();
                $head = $this->structureHead($change->scopeRef, $change->structureId, self::LOCK_UPDATE);
                $locked = $this->fieldChanges->find($change->changeRef, true);
                if ($locked === null || !$locked->running() || $locked->runRef !== $change->runRef) {
                    throw new StorageConflict('storage_field_change_interrupted');
                }
                $current = $this->hydrateStructureVersion($head, self::LOCK_UPDATE);
                if ((int) $head->current_version !== $change->expectedStructureVersion || $this->headArchivedAt($head) !== null
                    || $current->schema->version !== $change->sourceSchemaVersion) {
                    throw new StorageRejected('storage_field_change_structure_moved');
                }
                $currentSchema = $this->storage->schemaVersion($current->schema, true);
                $recheck();
                [, $viewBuild] = $this->switchStructure(
                    $head,
                    $current,
                    $currentSchema,
                    $change->descriptor,
                    $change->targetDefinition,
                    $change->createdBy,
                    $change->correlationId ?? $this->correlationId(null),
                    'migrate',
                    $filled,
                );
                $this->fieldChanges->put($locked->ended(StorageWorkbenchFieldChange::STATE_DONE, null, $this->timestamp()));
            });
        } catch (StorageConflict $exception) {
            throw $exception;
        } catch (StorageRejected $exception) {
            // Nothing switched: the change failed, and says why.
            $this->database->transaction(function () use ($change, $exception): void {
                $locked = $this->fieldChanges->find($change->changeRef, true);
                if ($locked !== null && $locked->running()) {
                    $this->fieldChanges->put($locked->ended(StorageWorkbenchFieldChange::STATE_FAILED, $exception->reasonCode, $this->timestamp()));
                }
            });
            $this->emitFieldChange('storage.schema_migration.rejected', $change, $change->createdBy, $change->correlationId ?? $this->correlationId(null), $exception->reasonCode);
            throw $exception;
        }
        $this->views?->settle();
        if ($viewBuild) {
            $this->requestViewBuild($change->schemaId);
        }
    }

    /**
     * A structure whose field change runs takes no other change until it ends; nor does one whose encryption run is
     * queued, running or failed (both ways: a field change never starts beside a run, a run never beside a field
     * change).
     */
    private function assertNoFieldChange(string $schemaId): void
    {
        if ($this->fieldChanges->running($schemaId, true) !== null) {
            throw new StorageRejected('storage_workbench_field_change_running');
        }
        $open = $this->encryptionRuns->open($schemaId, true);
        if ($open !== null) {
            throw new StorageRejected($open->running() ? StorageEncryptionPolicy::RUNNING : StorageEncryptionPolicy::FAILED);
        }
    }

    /** Queues the change's job; false when there is no queue (the change then runs inline). */
    private function enqueueFieldChange(string $changeRef): bool
    {
        if ($this->fieldChangeJobs === null || !$this->fieldChanges->available()) {
            return false;
        }
        try {
            return ($this->fieldChangeJobs)($changeRef) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function emitFieldChange(string $type, StorageFieldChangeRow $change, string $actor, string $correlationId, ?string $reason): void
    {
        $this->events->emit(new StorageSecurityEvent(
            'schema_migration',
            $type,
            $actor,
            'storage-schema:' . $change->schemaId,
            $correlationId,
            [
                'schema_id' => $change->schemaId,
                'source_version' => $change->sourceSchemaVersion,
                'target_version' => $change->sourceSchemaVersion + 1,
                'change_ref' => $change->changeRef,
                'record_count' => $change->total,
            ] + ($reason === null ? [] : ['reason_code' => $reason]),
        ));
    }

    /**
     * The structures with the state of their витрина and of their field change, read
     * cheaply: the registry row (memoized for the request) and one query for the changes.
     *
     * @param list<StorageWorkbenchStructure> $structures
     * @return list<StorageWorkbenchStructure>
     */
    private function stated(array $structures): array
    {
        if ($structures === []) {
            return [];
        }
        try {
            $open = $this->fieldChanges->latestOpen(array_values(array_unique(array_map(
                static fn (StorageWorkbenchStructure $structure): string => $structure->schema->schemaId,
                $structures,
            ))));
        } catch (Throwable) {
            $open = [];
        }
        try {
            $runs = $this->encryptionRuns->latest(array_values(array_unique(array_map(
                static fn (StorageWorkbenchStructure $structure): string => $structure->schema->schemaId,
                $structures,
            ))));
        } catch (Throwable) {
            $runs = [];
        }
        // One registry read for a list, the memoized row for one structure.
        $registrations = null;
        if (count($structures) > 1) {
            try {
                $registrations = [];
                foreach ($this->views !== null && $this->views->enabled() ? $this->views->registry()->all() : [] as $registration) {
                    $registrations[$registration->schemaId] = $registration;
                }
            } catch (Throwable) {
                $registrations = [];
            }
        }
        $stated = [];
        foreach ($structures as $structure) {
            $registration = null;
            try {
                $registration = $registrations !== null
                    ? ($registrations[$structure->schema->schemaId] ?? null)
                    : ($this->views !== null && $this->views->enabled() ? $this->views->registry()->find($structure->schema->schemaId) : null);
            } catch (Throwable) {
            }
            $pending = [];
            foreach ($registration?->layout->inState(StorageViewColumn::STATE_BACKFILLING) ?? [] as $column) {
                $pending[] = $column->fieldKey;
            }
            $change = $open[$structure->schema->schemaId] ?? null;
            if ($change !== null && !$change->running() && $change->expectedStructureVersion !== $structure->version) {
                // It ended before a later change of the structure.
                $change = null;
            }
            if ($change !== null && $change->running()) {
                $pending = array_merge($pending, $change->fields);
            }
            // The encryption run that is open (queued, running or failed); one that is done is not shown.
            $run = $runs[$structure->schema->schemaId] ?? null;
            $run = $run !== null && $run->open() ? $run : null;
            if ($run !== null && $run->mode === StorageWorkbenchEncryptionRun::MODE_DECRYPT) {
                // The column of a field switched off comes when its values are plain.
                $pending = array_merge($pending, $run->fields);
            }
            $pending = array_values(array_unique($pending));
            sort($pending, SORT_STRING);
            $stated[] = $structure->withState(
                $registration?->state,
                $registration?->backfillPending() ?? false,
                $pending,
                $change?->contract(),
                $run?->contract(),
            );
        }

        return $stated;
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.structure.read', $scopeRef, self::STRUCTURE_RESOURCE);

        return $this->stated([$this->readStructureInternal($scopeRef, $structureId)])[0];
    }

    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array
    {
        $this->assertScope($actor, 'storage.workbench.structure.list', $scopeRef, self::STRUCTURE_RESOURCE);
        try {
            $query = $this->database->table('larena_storage_workbench_structures')
                ->where('scope_ref', $scopeRef);
            if (!$includeArchived) {
                $query->whereNull('archived_at');
            }
            /** @var list<stdClass> $rows */
            $rows = $query
                ->orderBy('structure_id')
                ->limit(self::MAX_STRUCTURES + 1)
                ->get()
                ->all();
            if (count($rows) > self::MAX_STRUCTURES) {
                throw new StorageRejected('storage_workbench_structure_list_limit_exceeded');
            }

            // A structure a SitePack import is still writing is not listed.
            $rows = array_values(array_filter($rows, static fn (stdClass $row): bool => ($row->import_run_ref ?? null) === null));

            return $this->stated(array_map(fn (stdClass $row): StorageWorkbenchStructure => $this->hydrateStructureVersion($row), $rows));
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function createRecord(
        string $scopeRef,
        string $structureId,
        #[\SensitiveParameter] array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.record.create', $scopeRef, self::RECORD_RESOURCE);
        $this->assertUserValues($values);
        $structure = $this->readStructureInternal($scopeRef, $structureId, null, true);
        $this->assertRecordReferences($structure, $scopeRef, $values, null, $actor);
        $values[self::SCOPE_FIELD] = $scopeRef;
        $values[self::STATE_FIELD] = self::STATE_ACTIVE;
        $ownerRef = 'workbench.record:' . bin2hex(random_bytes(16));
        $result = $this->storage->create($ownerRef, $structure->schema, $values, $actor, $correlationId);

        return $this->workbenchRecord($structure, $result->version);
    }

    public function updateRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        #[\SensitiveParameter] array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord {
        $this->assertRecordId($recordId);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('storage_workbench_record_revision_invalid');
        }
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.record.update', $scopeRef, self::RECORD_RESOURCE);
        $this->assertUserValues($values);

        return $this->database->transaction(function () use (
            $scopeRef,
            $structureId,
            $recordId,
            $expectedRevision,
            $values,
            $actor,
            $correlationId,
        ): StorageWorkbenchRecord {
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_SHARED, true);
            $current = $this->readRecordInternal($structure, $scopeRef, $recordId, $actor, true);
            if ($current->revision !== $expectedRevision) {
                throw new StorageConflict('storage_workbench_record_revision_conflict');
            }
            if ($current->state !== self::STATE_ACTIVE) {
                throw new StorageRejected('storage_workbench_record_archived');
            }
            $this->assertRecordReferences($structure, $scopeRef, $values, $current, $actor);
            $values[self::SCOPE_FIELD] = $scopeRef;
            $values[self::STATE_FIELD] = self::STATE_ACTIVE;
            $version = $this->storage->compareAndSwap(
                $this->ownerRef($structure->schema->schemaId, $current->recordId),
                new StorageRecordVersionRef($structure->schema->schemaId, $recordId, $expectedRevision),
                $structure->schema,
                $values,
                $actor,
                $correlationId,
            )->version;
            $this->keepHistory($structure, [$recordId], $actor, $correlationId);

            return $this->workbenchRecord($structure, $version);
        });
    }

    public function archiveRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord {
        $this->assertRecordId($recordId);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('storage_workbench_record_revision_invalid');
        }
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.record.archive', $scopeRef, self::RECORD_RESOURCE);

        return $this->database->transaction(function () use (
            $scopeRef,
            $structureId,
            $recordId,
            $expectedRevision,
            $actor,
            $correlationId,
        ): StorageWorkbenchRecord {
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_SHARED, true);
            $current = $this->readRecordInternal($structure, $scopeRef, $recordId, $actor, true);
            if ($current->revision !== $expectedRevision) {
                throw new StorageConflict('storage_workbench_record_revision_conflict');
            }
            if ($current->state !== self::STATE_ACTIVE) {
                throw new StorageRejected('storage_workbench_record_archived');
            }
            $values = $current->values;
            $values[self::SCOPE_FIELD] = $scopeRef;
            $values[self::STATE_FIELD] = self::STATE_ARCHIVED;
            $version = $this->storage->transition(
                $this->ownerRef($structure->schema->schemaId, $recordId),
                new StorageRecordVersionRef($structure->schema->schemaId, $recordId, $expectedRevision),
                $structure->schema,
                $values,
                'delete',
                $actor,
                $correlationId,
            )->version;
            $this->keepHistory($structure, [$recordId], $actor, $correlationId);

            return $this->workbenchRecord($structure, $version);
        });
    }

    public function restoreRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord {
        $this->assertRecordId($recordId);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('storage_workbench_record_revision_invalid');
        }
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.record.restore', $scopeRef, self::RECORD_RESOURCE);

        return $this->database->transaction(function () use (
            $scopeRef,
            $structureId,
            $recordId,
            $expectedRevision,
            $actor,
            $correlationId,
        ): StorageWorkbenchRecord {
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_SHARED, true);
            $current = $this->readRecordInternal($structure, $scopeRef, $recordId, $actor, true);
            if ($current->revision !== $expectedRevision) {
                throw new StorageConflict('storage_workbench_record_revision_conflict');
            }
            if ($current->state !== self::STATE_ARCHIVED) {
                throw new StorageRejected('storage_workbench_record_not_archived');
            }
            $values = $current->values;
            $values[self::SCOPE_FIELD] = $scopeRef;
            $values[self::STATE_FIELD] = self::STATE_ACTIVE;
            $version = $this->storage->transition(
                $this->ownerRef($structure->schema->schemaId, $recordId),
                new StorageRecordVersionRef($structure->schema->schemaId, $recordId, $expectedRevision),
                $structure->schema,
                $values,
                'restore',
                $actor,
                $correlationId,
            )->version;
            $this->keepHistory($structure, [$recordId], $actor, $correlationId);

            return $this->workbenchRecord($structure, $version);
        });
    }

    public function bulkArchive(
        string $scopeRef,
        string $structureId,
        array $expectedRevisions,
        string $actor,
        ?string $correlationId = null,
    ): array {
        $this->assertStructureId($structureId);
        if ($expectedRevisions === [] || array_is_list($expectedRevisions) || count($expectedRevisions) > self::MAX_BULK) {
            throw new StorageRejected('storage_workbench_bulk_invalid');
        }
        foreach ($expectedRevisions as $recordId => $revision) {
            if (!is_string($recordId) || !is_int($revision) || $revision < 1) {
                throw new StorageRejected('storage_workbench_bulk_invalid');
            }
            $this->assertRecordId($recordId);
        }
        ksort($expectedRevisions, SORT_STRING);
        $this->assertScope($actor, 'storage.workbench.record.bulk_archive', $scopeRef, self::RECORD_RESOURCE);

        return $this->database->transaction(function () use (
            $scopeRef,
            $structureId,
            $expectedRevisions,
            $actor,
            $correlationId,
        ): array {
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_SHARED, true);
            $preflight = [];
            foreach ($expectedRevisions as $recordId => $revision) {
                $record = $this->readRecordInternal($structure, $scopeRef, $recordId, $actor, true);
                if ($record->revision !== $revision) {
                    throw new StorageConflict('storage_workbench_record_revision_conflict');
                }
                if ($record->state !== self::STATE_ACTIVE) {
                    throw new StorageRejected('storage_workbench_record_archived');
                }
                $preflight[$recordId] = $record;
            }

            $archived = [];
            foreach ($preflight as $recordId => $record) {
                $values = $record->values;
                $values[self::SCOPE_FIELD] = $scopeRef;
                $values[self::STATE_FIELD] = self::STATE_ARCHIVED;
                $version = $this->storage->transition(
                    $this->ownerRef($structure->schema->schemaId, $recordId),
                    new StorageRecordVersionRef($structure->schema->schemaId, $recordId, $record->revision),
                    $structure->schema,
                    $values,
                    'delete',
                    $actor,
                    $correlationId,
                )->version;
                $archived[] = $this->workbenchRecord($structure, $version);
            }
            $this->keepHistory($structure, array_map('strval', array_keys($preflight)), $actor, $correlationId);

            return $archived;
        });
    }

    public function setHistoryKeep(
        string $scopeRef,
        string $structureId,
        ?int $keep,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        $this->assertStructureId($structureId);
        if ($keep !== null && ($keep < 1 || $keep > VersionedStorage::HISTORY_KEEP_LIMIT)) {
            throw new InvalidArgumentException('storage_workbench_history_keep_invalid');
        }
        $this->assertScope($actor, 'storage.workbench.structure.update', $scopeRef, self::STRUCTURE_RESOURCE);
        $correlationId = $this->correlationId($correlationId);

        try {
            return $this->database->transaction(function () use ($scopeRef, $structureId, $keep, $actor, $correlationId): StorageWorkbenchStructure {
                $head = $this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE);
                // The same setting again changes nothing and says nothing.
                if ((isset($head->history_keep) ? (int) $head->history_keep : null) === $keep) {
                    return $this->hydrateStructureVersion($head, self::LOCK_UPDATE);
                }
                $this->database->table('larena_storage_workbench_structures')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->update(['history_keep' => $keep, 'updated_at' => $this->timestamp()]);
                $schemaId = (string) $head->storage_schema_id;
                $this->emitStructure('storage.workbench.structure.history_keep_changed', $scopeRef, $structureId, $actor, $correlationId,
                    ['schema_id' => $schemaId, 'keep' => $keep]);
                // A number trims every record's history now; each later write keeps it trimmed.
                if ($keep !== null) {
                    $ids = array_map('strval', $this->database->table('larena_storage_records')
                        ->where('schema_id', $schemaId)->orderBy('record_id')->pluck('record_id')->all());
                    foreach (array_chunk($ids, 5000) as $chunk) {
                        $this->storage->pruneHistory($schemaId, $chunk, $keep, $actor, $correlationId);
                    }
                }

                return $this->hydrateStructureVersion($this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE), self::LOCK_UPDATE);
            });
        } catch (StorageRejected|StorageConflict $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isLockConflict($exception)) {
                throw new StorageConflict('storage_workbench_structure_version_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function archiveStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        return $this->changeStructureState($scopeRef, $structureId, $expectedVersion, $actor, $correlationId, true);
    }

    public function restoreStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        return $this->changeStructureState($scopeRef, $structureId, $expectedVersion, $actor, $correlationId, false);
    }

    public function purgeStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchPurgeReceipt {
        $this->assertStructureId($structureId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('storage_workbench_structure_version_invalid');
        }
        $this->assertScope($actor, 'storage.workbench.structure.purge', $scopeRef, self::STRUCTURE_RESOURCE);
        $correlationId = $this->correlationId($correlationId);
        $this->prepareReferenceViews($scopeRef, $structureId);

        try {
            $receipt = $this->database->transaction(function () use ($scopeRef, $structureId, $expectedVersion, $actor, $correlationId): StorageWorkbenchPurgeReceipt {
                $head = $this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE);
                if ((int) $head->current_version !== $expectedVersion) {
                    throw new StorageConflict('storage_workbench_structure_version_conflict');
                }
                if ($this->headArchivedAt($head) === null) {
                    throw new StorageRejected('storage_workbench_structure_not_archived');
                }
                $schemaId = (string) $head->storage_schema_id;
                if ($this->referencedRecordIds($scopeRef, $structureId, null) !== []) {
                    throw new StorageRejected('storage_record_purge_referenced');
                }
                if ($this->tableExists('larena_storage_structure_role_bindings')
                    && $this->database->table('larena_storage_structure_role_bindings')->where('schema_id', $schemaId)->exists()) {
                    throw new StorageRejected('storage_workbench_structure_role_bound');
                }
                $rows = $this->database->table('larena_storage_records')
                    ->where('schema_id', $schemaId)
                    ->orderBy('record_id')
                    ->limit(self::MAX_STRUCTURE_PURGE + 1)
                    ->get(['record_id', 'current_revision'])
                    ->all();
                if (count($rows) > self::MAX_STRUCTURE_PURGE) {
                    throw new StorageRejected('storage_workbench_structure_purge_too_large');
                }
                $refs = array_map(
                    static fn (stdClass $row): StorageRecordVersionRef => new StorageRecordVersionRef(
                        $schemaId,
                        (string) $row->record_id,
                        (int) $row->current_revision,
                    ),
                    $rows,
                );
                $versions = $refs === [] ? 0 : $this->storage->purge($refs, $actor, $correlationId);
                $this->database->table('larena_storage_workbench_structure_versions')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->delete();
                $deleted = $this->database->table('larena_storage_workbench_structures')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->where('current_version', $expectedVersion)
                    ->delete();
                if ($deleted !== 1) {
                    throw new StorageConflict('storage_workbench_structure_version_conflict');
                }
                // The витрина goes with the structure; its tables are dropped after the commit.
                $this->views?->structurePurged($schemaId);
                // So do its data keys: its encrypted values can never be read again.
                $dataKeys = $this->tableExists(StorageDataKeys::TABLE) ? StorageDataKeys::purge($this->database, $schemaId) : 0;
                // And its encryption runs: a walk still going finds its run gone and stops.
                $this->encryptionRuns->purge($schemaId);
                $recordIds = array_map(static fn (StorageRecordVersionRef $ref): string => $ref->recordId, $refs);
                $this->emitStructure('storage.workbench.structure.purged', $scopeRef, $structureId, $actor, $correlationId, [
                    'schema_id' => $schemaId,
                    'structure_version' => $expectedVersion,
                    'record_count' => count($recordIds),
                    'version_count' => $versions,
                ] + ($dataKeys > 0 ? ['data_key_count' => $dataKeys] : []));

                return new StorageWorkbenchPurgeReceipt(
                    $structureId,
                    $scopeRef,
                    $recordIds,
                    $versions,
                    true,
                    $actor,
                    $correlationId,
                    $this->timestamp(),
                );
            });
            $this->views?->settle();

            return $receipt;
        } catch (StorageRejected|StorageConflict $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isLockConflict($exception)) {
                throw new StorageConflict('storage_workbench_structure_version_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function purgeRecords(
        string $scopeRef,
        string $structureId,
        array $expectedRevisions,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchPurgeReceipt {
        $this->assertStructureId($structureId);
        if ($expectedRevisions === [] || array_is_list($expectedRevisions) || count($expectedRevisions) > self::MAX_BULK) {
            throw new StorageRejected('storage_workbench_bulk_invalid');
        }
        foreach ($expectedRevisions as $recordId => $revision) {
            if (!is_string($recordId) || !is_int($revision) || $revision < 1) {
                throw new StorageRejected('storage_workbench_bulk_invalid');
            }
            $this->assertRecordId($recordId);
        }
        ksort($expectedRevisions, SORT_STRING);
        $this->assertScope($actor, 'storage.workbench.record.purge', $scopeRef, self::RECORD_RESOURCE);
        $correlationId = $this->correlationId($correlationId);
        $this->prepareReferenceViews($scopeRef, $structureId);

        return $this->database->transaction(function () use (
            $scopeRef,
            $structureId,
            $expectedRevisions,
            $actor,
            $correlationId,
        ): StorageWorkbenchPurgeReceipt {
            // An archived structure keeps accepting permanent deletion of its archived records.
            $structure = $this->readStructureInternal($scopeRef, $structureId, self::LOCK_UPDATE);
            $refs = [];
            foreach ($expectedRevisions as $recordId => $revision) {
                $record = $this->readRecordInternal($structure, $scopeRef, $recordId, $actor, true);
                if ($record->revision !== $revision) {
                    throw new StorageConflict('storage_workbench_record_revision_conflict');
                }
                if ($record->state !== self::STATE_ARCHIVED) {
                    throw new StorageRejected('storage_workbench_record_not_archived');
                }
                $refs[] = new StorageRecordVersionRef($structure->schema->schemaId, $recordId, $revision);
            }
            // A record another record still names through record@1 stays (owner decision: restrict).
            if ($this->referencedRecordIds($scopeRef, $structureId, array_keys($expectedRevisions)) !== []) {
                throw new StorageRejected('storage_record_purge_referenced');
            }
            $versions = $this->storage->purge($refs, $actor, $correlationId);

            return new StorageWorkbenchPurgeReceipt(
                $structureId,
                $scopeRef,
                array_keys($expectedRevisions),
                $versions,
                false,
                $actor,
                $correlationId,
                $this->timestamp(),
            );
        });
    }

    /**
     * A record@1 value that a write sets or changes must name an active record of the field's
     * target structure in the same scope. A value the update leaves as it was is kept even when
     * its target has been archived since.
     *
     * @param array<string, mixed> $values
     */
    private function assertRecordReferences(
        StorageWorkbenchStructure $structure,
        string $scopeRef,
        #[\SensitiveParameter] array $values,
        ?StorageWorkbenchRecord $current,
        string $actor,
    ): void {
        foreach ($structure->fields as $field) {
            if (($field['type'] ?? null) !== 'record' || !array_key_exists((string) $field['key'], $values)) {
                continue;
            }
            $value = $values[(string) $field['key']];
            if (!is_string($value) || $value === '') {
                continue;
            }
            $value = strtolower($value);
            $previous = $current?->values[(string) $field['key']] ?? null;
            if (is_string($previous) && $previous === $value) {
                continue;
            }
            $target = $field['constraints']['target_structure_id'] ?? null;
            try {
                if (!is_string($target) || preg_match('/\Arecord-[0-9a-f]{32}\z/D', $value) !== 1) {
                    throw new StorageRejected('storage_record_reference_target_unavailable');
                }
                $targetStructure = $this->readStructureInternal($scopeRef, $target, null, true);
                $record = $this->readRecordInternal($targetStructure, $scopeRef, $value, $actor);
            } catch (StorageRejected) {
                throw new StorageRejected('storage_record_reference_target_unavailable');
            }
            if ($record->state !== self::STATE_ACTIVE) {
                throw new StorageRejected('storage_record_reference_target_unavailable');
            }
        }
    }

    /**
     * Records of the structure (all of them, or the given ones) that another record of the scope
     * names through a record@1 field. References from the given records themselves, or from the
     * structure's own records when the whole structure goes, do not hold them.
     *
     * @param list<string>|null $recordIds
     * @return list<string>
     */
    private function referencedRecordIds(string $scopeRef, string $structureId, ?array $recordIds): array
    {
        $wanted = $recordIds === null ? null : array_fill_keys($recordIds, true);
        $referenced = [];
        foreach ($this->referencingStructures($scopeRef, $structureId) as ['source' => $source, 'keys' => $keys]) {
            if ($wanted === null && $source->structureId === $structureId) {
                continue;
            }
            // Indexed: the record columns of the source's витрина (prepared before the transaction).
            $fromView = $this->viewReferencedIds($source, $keys, $wanted,
                $source->structureId === $structureId && $wanted !== null ? $wanted : []);
            if ($fromView === null) {
                // Never a scan of the versions: the purge waits until that витрина is built
                // (prepareReferenceViews queued its rebuild before the transaction).
                $this->assertViewsAvailable();
                throw new StorageRejected(self::VIEW_NOT_READY);
            }
            foreach ($fromView as $value) {
                $referenced[$value] = true;
            }
        }

        return array_keys($referenced);
    }

    /**
     * The structures of the scope with a record@1 field that targets the structure (itself
     * included), with those fields' keys.
     *
     * @return list<array{source: StorageWorkbenchStructure, keys: list<string>}>
     */
    private function referencingStructures(string $scopeRef, string $structureId): array
    {
        $heads = $this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $scopeRef)
            ->orderBy('structure_id')
            ->limit(self::MAX_STRUCTURES + 1)
            ->get()
            ->all();
        $referencing = [];
        foreach ($heads as $head) {
            $source = $this->hydrateStructureVersion($head);
            $keys = [];
            foreach ($source->fields as $field) {
                if (($field['type'] ?? null) === 'record'
                    && ($field['constraints']['target_structure_id'] ?? null) === $structureId) {
                    $keys[] = (string) $field['key'];
                }
            }
            if ($keys !== []) {
                $referencing[] = ['source' => $source, 'keys' => $keys];
            }
        }

        return $referencing;
    }

    /**
     * Before a purge's transaction: the витрины that answer its incoming references are
     * made ready (built inline when small, queued when large), as a list would. Best effort:
     * the check in the transaction refuses with VIEW_NOT_READY what is still not ready.
     */
    private function prepareReferenceViews(string $scopeRef, string $structureId): void
    {
        try {
            $referencing = $this->referencingStructures($scopeRef, $structureId);
        } catch (Throwable) {
            return;
        }
        foreach ($referencing as ['source' => $source, 'keys' => $keys]) {
            $view = $this->listView($source);
            if ($view !== null && $this->referenceColumns($view, $keys) !== null) {
                continue;
            }
            try {
                $this->prepareView($source);
            } catch (StorageRejected|StoragePersistenceFailed) {
            }
        }
    }

    private function changeStructureState(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId,
        bool $archive,
    ): StorageWorkbenchStructure {
        $this->assertStructureId($structureId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('storage_workbench_structure_version_invalid');
        }
        $operation = $archive ? 'storage.workbench.structure.archive' : 'storage.workbench.structure.restore';
        $this->assertScope($actor, $operation, $scopeRef, self::STRUCTURE_RESOURCE);
        $correlationId = $this->correlationId($correlationId);

        try {
            return $this->database->transaction(function () use ($scopeRef, $structureId, $expectedVersion, $actor, $correlationId, $archive): StorageWorkbenchStructure {
                $head = $this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE);
                if ((int) $head->current_version !== $expectedVersion) {
                    throw new StorageConflict('storage_workbench_structure_version_conflict');
                }
                $archived = $this->headArchivedAt($head) !== null;
                if ($archive && $archived) {
                    throw new StorageRejected('storage_workbench_structure_archived');
                }
                if (!$archive && !$archived) {
                    throw new StorageRejected('storage_workbench_structure_not_archived');
                }
                if ($archive) {
                    $this->assertNoFieldChange((string) $head->storage_schema_id);
                }
                $now = $this->timestamp();
                $updated = $this->database->table('larena_storage_workbench_structures')
                    ->where('scope_ref', $scopeRef)
                    ->where('structure_id', $structureId)
                    ->where('current_version', $expectedVersion)
                    ->update($archive
                        ? ['archived_at' => $now, 'archived_by' => $actor, 'updated_at' => $now]
                        : ['archived_at' => null, 'archived_by' => null, 'updated_at' => $now]);
                if ($updated !== 1) {
                    throw new StorageConflict('storage_workbench_structure_version_conflict');
                }
                $this->emitStructure(
                    $archive ? 'storage.workbench.structure.archived' : 'storage.workbench.structure.restored',
                    $scopeRef,
                    $structureId,
                    $actor,
                    $correlationId,
                    ['schema_id' => (string) $head->storage_schema_id, 'structure_version' => $expectedVersion],
                );

                return $this->hydrateStructureVersion($this->structureHead($scopeRef, $structureId, self::LOCK_UPDATE), self::LOCK_UPDATE);
            });
        } catch (StorageRejected|StorageConflict $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isLockConflict($exception)) {
                throw new StorageConflict('storage_workbench_structure_version_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * Before a structure change opens its transaction: the витрина gains the columns
     * the changed fields need, so the change itself runs no DDL. Best effort; a
     * change this cannot foresee is handled inside the transaction.
     *
     * @param list<array<string, mixed>> $fields
     */
    private function prepareViewChange(string $scopeRef, string $structureId, int $expectedVersion, array $fields, bool $migrate): void
    {
        if ($this->views === null) {
            return;
        }
        try {
            $head = $this->structureHead($scopeRef, $structureId, null);
            if ((int) $head->current_version !== $expectedVersion || $this->headArchivedAt($head) !== null) {
                return;
            }
            $current = $this->hydrateStructureVersion($head);
            // A running field change fills its reserved columns: nothing reshapes them meanwhile. Nor while an
            // encryption run is open: the structure takes no change then.
            if ($this->fieldChanges->running($current->schema->schemaId) !== null
                || $this->encryptionRuns->open($current->schema->schemaId) !== null) {
                return;
            }
            $currentSchema = $this->storage->schemaVersion($current->schema);
            $candidate = $migrate
                ? $this->schemaDefinition($current->schema->schemaId, $fields)
                : $this->schemaDefinitionForUpdate($currentSchema, $fields);
            // A field switched off that holds values gets its column only once its decrypt run is done.
            $sealed = [];
            $wasEncrypted = StorageEncryptionPolicy::encryptedKeys($currentSchema->fields);
            $isEncrypted = StorageEncryptionPolicy::encryptedKeys(is_array($candidate['fields'] ?? null) ? $candidate['fields'] : []);
            foreach (array_diff($wasEncrypted, $isEncrypted) as $key) {
                if (StorageEncryptionPolicy::fieldHasValues($this->database, $current->schema->schemaId, $key)) {
                    $sealed[] = $key;
                }
            }
            // Also when nothing changes: a витрина built before the search table gets it here, outside
            // the transaction, and takes the options in it.
            $this->views->prepareChange(
                $current->schema->schemaId,
                StorageFieldEncryptionRuns::viewFields(array_values(array_filter($candidate['fields'], 'is_array')), $sealed),
                StorageViewFieldOptions::resolve($fields),
            );
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $payload */
    private function emitStructure(
        string $eventType,
        string $scopeRef,
        string $structureId,
        string $actor,
        string $correlationId,
        array $payload,
    ): void {
        $this->events->emit(new StorageSecurityEvent(
            'version',
            $eventType,
            $actor,
            'storage-structure:' . $structureId,
            $correlationId,
            ['scope_ref' => $scopeRef, 'structure_id' => $structureId] + $payload,
        ));
    }

    public function readRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        string $actor,
    ): StorageWorkbenchRecord {
        $this->assertStructureId($structureId);
        $this->assertRecordId($recordId);
        $this->assertScope($actor, 'storage.workbench.record.read', $scopeRef, self::RECORD_RESOURCE);
        $structure = $this->readStructureInternal($scopeRef, $structureId);

        return $this->readRecordInternal($structure, $scopeRef, $recordId, $actor);
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        $started = hrtime(true);
        $mode = 'sql';
        $sort = null;
        try {
            $page = $this->listRecordsFrom($query, $actor, $mode, $sort);
        } catch (Throwable $exception) {
            $this->metrics->list($mode, null, StorageMetrics::outcomeOf($exception), StorageMetrics::elapsedMs($started), $sort);
            throw $exception;
        }
        $count = $query->countMode === StorageWorkbenchCountMode::Skip ? 'skip' : ($page->countExact ? 'exact' : 'approx');
        $this->metrics->list($mode, $count, 'ok', StorageMetrics::elapsedMs($started), $sort);

        return $page;
    }

    /**
     * $mode is set to the path the list took: sql (a ready витрина), inline (the витрина was
     * built in this call, then answered) or not_ready (refused while it is built);
     * $sort to how it was sorted once the query is known: none (no sort asked), indexed (every
     * sorted field has an index on the витрина) or unindexed (one has none).
     *
     * @param-out 'indexed'|'unindexed'|'none'|null $sort null while the query is not yet known
     */
    private function listRecordsFrom(StorageWorkbenchRecordQuery $query, string $actor, string &$mode, ?string &$sort): StorageWorkbenchRecordPage
    {
        $this->assertStructureId($query->structureId);
        $decision = $this->assertScope($actor, 'storage.workbench.record.list', $query->scopeRef, self::RECORD_RESOURCE);
        $structure = $this->readStructureInternal($query->scopeRef, $query->structureId);
        $normalizedQuery = $this->normalizeRecordQuery($query, $structure);
        $queryHash = hash('sha256', $this->canonicalJson($normalizedQuery));
        $scopeHash = hash('sha256', $this->canonicalJson([
            'actor' => $decision->actor,
            'operation' => $decision->operation,
            'scope_ref' => $query->scopeRef,
            'target' => $decision->target,
            'reason_code' => $decision->reasonCode,
        ]));
        $fields = [];
        foreach ($this->listFields($structure) as $field) {
            $fields[(string) $field['key']] = $field;
        }
        $sort = $normalizedQuery['sort'] === [] ? 'none' : 'unindexed';
        // Every list answers from the витрина, ready for the structure's current schema version;
        // the current versions are never scanned for a list.
        $view = $this->listView($structure);
        $compiled = $view === null ? null : $this->compileList($view, $fields, $normalizedQuery);
        if ($compiled === null) {
            // Not ready, or still filling what this query reads (search text, a backfilled column).
            if (!$this->answerable($structure, $fields, $normalizedQuery)) {
                throw new StorageRejected(self::QUERY_UNSUPPORTED);
            }
            $mode = 'not_ready';
            // A small storage gets its витрина now; a large one is refused while its rebuild is queued.
            $this->prepareView($structure);
            $view = $this->listView($structure, true);
            $compiled = $view === null ? null : $this->compileList($view, $fields, $normalizedQuery);
            if ($compiled === null) {
                $this->queueViewBuild($structure->schema->schemaId);
                throw new StorageRejected(self::VIEW_NOT_READY);
            }
            $mode = 'inline';
        }
        if ($normalizedQuery['sort'] !== [] && $compiled->unindexedSort === []) {
            $sort = 'indexed';
        }

        return $this->listFromView($compiled, $query, $structure, $queryHash, $scopeHash);
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $query
     */
    private function compileList(StorageViewRegistration $view, array $fields, array $query): ?StorageViewQuery
    {
        try {
            return $this->compiler->compile($this->viewConnection(), $view, $fields, $query);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * Whether a витрина built in full for the structure's current fields answers the query:
     * false for a query no витрина answers (a sort by a choices field), whatever its state.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $query
     */
    private function answerable(StorageWorkbenchStructure $structure, array $fields, array $query): bool
    {
        $this->assertViewsAvailable();
        if ($this->views === null) {
            return false;
        }
        try {
            $layout = $this->views->planner()->plan(
                $this->listFields($structure),
                $structure->schema->version,
                null,
                StorageViewFieldOptions::resolve($structure->fields),
            )->promoted();
        } catch (Throwable) {
            // A layout that cannot be planned is the build's to report.
            return true;
        }
        try {
            return $this->compiler->compileLayout($this->viewConnection(), StorageViewNaming::table($structure->schema->schemaId), $layout, $fields, $query) !== null;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /** Throws VIEW_UNAVAILABLE where no витрина can exist (see the constant). */
    private function assertViewsAvailable(): void
    {
        try {
            $available = $this->views !== null && $this->database instanceof Connection && $this->views->enabled();
        } catch (Throwable) {
            $available = false;
        }
        if (!$available) {
            throw new StorageRejected(self::VIEW_UNAVAILABLE);
        }
    }

    /**
     * Makes the structure's витрина ready for its current schema version, in this call,
     * when the storage holds at most the inline limit of records (counted cheaply: one
     * probe of the record heads past the limit): the rebuild walks its current versions
     * in chunks, or only fills what a backfill left. A larger storage, or a call inside a
     * transaction the build cannot run in (MySQL's DDL commits it), queues the rebuild and
     * is refused with VIEW_NOT_READY; so is a build another process took over meanwhile.
     */
    private function prepareView(StorageWorkbenchStructure $structure): void
    {
        $this->assertViewsAvailable();
        $views = $this->views;
        if ($views === null) {
            throw new StorageRejected(self::VIEW_UNAVAILABLE);
        }
        $schemaId = $structure->schema->schemaId;
        try {
            $large = $this->database->table('larena_storage_records')
                ->where('schema_id', $schemaId)
                ->orderBy('record_id')
                ->offset(max(0, $this->inlineViewBuildLimit))
                ->limit(1)
                ->exists();
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
        $ddl = $views->ddl();
        if ($large || $ddl === null || ($this->database->transactionLevel() > 0 && !$ddl->transactional())) {
            $this->queueViewBuild($schemaId);
            throw new StorageRejected(self::VIEW_NOT_READY);
        }
        try {
            (new StorageViewRebuilder($views, $this->metrics))->rebuild($schemaId, StorageViewRebuilder::DEFAULT_CHUNK);
        } catch (StorageRejected|StorageConflict) {
            // Another build took the walk over, or the schema moved under it: that build finishes it.
            $this->queueViewBuild($schemaId);
            throw new StorageRejected(self::VIEW_NOT_READY);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        } finally {
            $views->registry()->forget($schemaId);
        }
    }

    /**
     * Queues the rebuild of a витрина that is not ready, once: a build or backfill already
     * walking (its cursor moved) is left to finish, since a new job would take its walk over.
     * A walk that stopped is picked up by its job's retries or the scheduled
     * `larena:storage:view:rebuild --missing`.
     */
    private function queueViewBuild(string $schemaId): void
    {
        if ($this->views === null || $this->database->transactionLevel() > 0) {
            return;
        }
        try {
            $registration = $this->views->registry()->find($schemaId, true);
        } catch (Throwable) {
            $registration = null;
        }
        if ($registration !== null && $registration->buildCursor !== null
            && in_array($registration->state, [StorageViewRegistration::STATE_BUILDING, StorageViewRegistration::STATE_READY], true)) {
            return;
        }
        $this->requestViewBuild($schemaId);
    }

    /**
     * One page of a list from the витрина: ids in list order (one more than the
     * page to know whether there is more), then the records from their current
     * versions in one query, without recomputing their hashes.
     */
    private function listFromView(
        StorageViewQuery $compiled,
        StorageWorkbenchRecordQuery $query,
        StorageWorkbenchStructure $structure,
        string $queryHash,
        string $scopeHash,
    ): StorageWorkbenchRecordPage {
        if ($query->page !== null && ($query->continuation !== null || $query->page < 1
            || ($query->page - 1) * $query->limit > self::MAX_OFFSET)) {
            throw new StorageRejected('storage_workbench_record_page_invalid');
        }
        $keyset = new StorageViewKeyset($this->cursorKey, $this->canonicalJson(...));
        $offset = 0;
        $after = null;
        if ($query->page !== null) {
            $offset = ($query->page - 1) * $query->limit;
        } elseif ($query->continuation !== null) {
            if (StorageViewKeyset::version($query->continuation) === StorageViewKeyset::VERSION) {
                $after = $keyset->decode($query->continuation, $queryHash, $scopeHash, count($compiled->sort));
                $offset = $after['offset'];
            } else {
                // An offset continuation: issued before the витрина, or for a sort key too long to carry.
                $offset = $this->decodeContinuation($query->continuation, $queryHash, $scopeHash, StorageViewKeyset::MAX_OFFSET);
            }
        }

        try {
            $rows = $compiled->page($query->limit, $after === null ? $offset : 0, $after);
            $more = count($rows) > $query->limit;
            $rows = array_slice($rows, 0, $query->limit);
            $items = $this->listedRecords($structure, $query, array_column($rows, 'record_id'));
            $passed = $offset + count($rows);
            $continuation = null;
            if ($more && $rows !== []) {
                $last = $rows[count($rows) - 1];
                // A sort key too long to carry (long text) continues by offset: slower, still complete.
                $continuation = $keyset->encode($queryHash, $scopeHash, $last['keys'], $last['record_id'], $passed)
                    ?? $this->encodeContinuation($passed, $queryHash, $scopeHash);
            }
            $unindexed = $compiled->unindexedSort;
            if ($query->countMode === StorageWorkbenchCountMode::Skip) {
                // A list that pages on uncounted is sized only on its first page and when its sort has no
                // index, by the capped Auto count: a reader paging on (an export) pays nothing more.
                $large = $unindexed !== [] && ($more
                    ? $offset === 0 && $after === null
                        && $this->counter->large($this->counter->count($compiled, StorageWorkbenchCountMode::Auto)['count'])
                    : $this->counter->large($passed));

                return new StorageWorkbenchRecordPage($items, $continuation, $passed + ($more ? 1 : 0), !$more, $unindexed, $large);
            }
            // The last page read by offset knows the total already.
            if ($query->countMode === StorageWorkbenchCountMode::Auto && !$more && $after === null && ($rows !== [] || $offset === 0)) {
                return new StorageWorkbenchRecordPage($items, $continuation, $passed, true, $unindexed,
                    $unindexed !== [] && $this->counter->large($passed));
            }
            $count = $this->counter->count($compiled, $query->countMode);
            // An estimate is never below the rows the list has already shown.
            $matched = $count['exact'] ? $count['count'] : max($count['count'], $passed + ($more ? 1 : 0));

            return new StorageWorkbenchRecordPage($items, $continuation, $matched, $count['exact'], $unindexed,
                $unindexed !== [] && $this->counter->large($matched));
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * The records of the listed ids, in that order, from their current versions.
     *
     * @param list<string> $recordIds
     * @return list<StorageWorkbenchRecord>
     */
    private function listedRecords(StorageWorkbenchStructure $structure, StorageWorkbenchRecordQuery $query, array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }
        $rows = $this->database->table('larena_storage_records as heads')
            ->join('larena_storage_record_versions as versions', static function ($join): void {
                $join->on('versions.schema_id', '=', 'heads.schema_id')
                    ->on('versions.record_id', '=', 'heads.record_id')
                    ->on('versions.revision', '=', 'heads.current_revision');
            })
            ->where('heads.schema_id', $structure->schema->schemaId)
            ->whereIn('heads.record_id', $recordIds)
            ->select([
                'versions.schema_id', 'versions.record_id', 'versions.revision', 'versions.owner_ref',
                'versions.schema_version', 'versions.values_json', 'versions.content_hash',
                'versions.operation', 'versions.created_by', 'versions.correlation_id', 'versions.created_at',
                'heads.current_hash as head_hash',
            ])
            ->get();
        $records = [];
        foreach ($rows as $row) {
            if (!hash_equals((string) $row->content_hash, (string) $row->head_hash)) {
                throw new StorageRejected('storage_workbench_record_head_corrupt');
            }
            $version = $this->hydrateRawRecordVersion($row, false);
            if (($version->values[self::SCOPE_FIELD] ?? null) !== $query->scopeRef) {
                continue;
            }
            $record = $this->workbenchRecord($structure, $version);
            if (!$query->includeArchived && $record->state === self::STATE_ARCHIVED) {
                continue;
            }
            $records[$record->recordId] = $record;
        }
        $ordered = [];
        foreach ($recordIds as $recordId) {
            if (isset($records[$recordId])) {
                $ordered[] = $records[$recordId];
            }
        }

        return $ordered;
    }

    /**
     * The structure's витрина when it is ready and built for the structure's current schema
     * version; $fresh reads the registry row past its memo.
     */
    private function listView(StorageWorkbenchStructure $structure, bool $fresh = false): ?StorageViewRegistration
    {
        if ($this->views === null || !$this->database instanceof Connection) {
            return null;
        }
        try {
            if (!$this->views->enabled()) {
                return null;
            }
            $view = $this->views->registry()->find($structure->schema->schemaId, $fresh);
        } catch (Throwable) {
            return null;
        }
        if ($view === null || $view->state !== StorageViewRegistration::STATE_READY
            || $view->builtSchemaVersion !== $structure->schema->version
            || $view->layout->schemaVersion !== $structure->schema->version) {
            return null;
        }

        return $view;
    }

    private function viewConnection(): Connection
    {
        if (!$this->database instanceof Connection) {
            throw new StorageRejected('storage_view_connection_unavailable');
        }

        return $this->database;
    }

    /**
     * The records a record@1 field of one structure names, from that structure's
     * витрина; null when the витрина cannot answer (not ready, or a field without
     * a written column), and the caller refuses until it can.
     *
     * @param list<string> $keys
     * @param array<string, true>|null $wanted
     * @param array<string, true> $excluded Records whose own references do not count.
     * @return list<string>|null
     */
    private function viewReferencedIds(StorageWorkbenchStructure $source, array $keys, ?array $wanted, array $excluded): ?array
    {
        $view = $this->listView($source);
        $columns = $view === null ? null : $this->referenceColumns($view, $keys);
        if ($view === null || $columns === null) {
            return null;
        }
        $referenced = [];
        $wantedChunks = $wanted === null ? [null] : array_chunk(array_map('strval', array_keys($wanted)), 500);
        $excludedIds = array_map('strval', array_keys($excluded));
        foreach ($columns as $column) {
            foreach ($wantedChunks as $chunk) {
                // The витрина holds this scope's records only (its table is named from the scoped schema id).
                $select = $this->viewConnection()->table($view->tableName)
                    ->whereNotNull($column)
                    ->distinct();
                if ($chunk !== null) {
                    $select->whereIn($column, $chunk);
                }
                foreach (array_chunk($excludedIds, 500) as $excludedChunk) {
                    $select->whereNotIn('record_id', $excludedChunk);
                }
                foreach ($select->pluck($column) as $value) {
                    if (is_string($value)) {
                        $referenced[$value] = true;
                    }
                }
            }
        }

        return array_map('strval', array_keys($referenced));
    }

    /**
     * The written, ready record columns of the fields in the витрина; null when one of
     * the fields has none.
     *
     * @param list<string> $keys
     * @return list<string>|null
     */
    private function referenceColumns(StorageViewRegistration $view, array $keys): ?array
    {
        $columns = [];
        foreach ($keys as $key) {
            $found = null;
            foreach ($view->layout->columns as $column) {
                if ($column->fieldKey === $key && $column->kind === 'record' && $column->column !== null
                    && $column->state === StorageViewColumn::STATE_READY) {
                    $found = $column->column;
                }
            }
            if ($found === null) {
                return null;
            }
            $columns[] = $found;
        }

        return $columns;
    }

    public function recordHistory(
        string $scopeRef,
        string $structureId,
        string $recordId,
        string $actor,
        int $limit = 50,
    ): array {
        $this->assertStructureId($structureId);
        $this->assertRecordId($recordId);
        if ($limit < 1 || $limit > 100) {
            throw new StorageRejected('storage_workbench_history_limit_invalid');
        }
        $this->assertScope($actor, 'storage.workbench.record.history', $scopeRef, self::RECORD_RESOURCE);
        $structure = $this->readStructureInternal($scopeRef, $structureId);
        $this->readRecordInternal($structure, $scopeRef, $recordId, $actor);

        try {
            /** @var list<stdClass> $rows */
            $rows = $this->database->table('larena_storage_record_versions')
                ->where('schema_id', $structure->schema->schemaId)
                ->where('record_id', $recordId)
                ->orderByDesc('revision')
                ->limit($limit)
                ->get()
                ->all();
            $history = [];
            foreach ($rows as $row) {
                $version = $this->hydrateRawRecordVersion($row);
                if (($version->values[self::SCOPE_FIELD] ?? null) !== $scopeRef) {
                    throw new StorageRejected('storage_workbench_record_scope_mismatch');
                }
                $history[] = $this->workbenchRecord($structure, $version);
            }

            return $history;
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    private function assertScope(
        string $actor,
        string $operation,
        string $scopeRef,
        string $resourceType,
    ): AccessDecision {
        $this->assertActor($actor);
        $this->assertScopeRef($scopeRef);
        if (!$this->scopeProvider->supports($resourceType, $operation)) {
            throw new StorageRejected('storage_workbench_scope_missing');
        }
        $context = ['requested_scope_ref' => $scopeRef, 'resource_type' => $resourceType];
        $decision = $this->scopeProvider->explain($resourceType, $actor, $operation, $context);
        if (!$decision->isAllowed()) {
            throw new StorageRejected('storage_workbench_scope_denied');
        }
        if ($decision->actor !== $actor || $decision->operation !== $operation) {
            throw new StorageRejected('storage_workbench_scope_invalid');
        }
        $scoped = $this->scopeProvider->scope(['scope_ref' => $scopeRef], $actor, $operation, $context);
        $keys = array_keys($scoped);
        sort($keys, SORT_STRING);
        if ($keys !== ['scope_ref'] || ($scoped['scope_ref'] ?? null) !== $scopeRef) {
            throw new StorageRejected('storage_workbench_scope_invalid');
        }
        $this->authorizer->assertAllowed($actor, $operation);

        return $decision;
    }

    /**
     * After a structure change that left rows of its витрина to fill: queues the
     * rebuild that fills them. Best effort; without a queue the витрина stays as it
     * is (lists keep reading it; a search waits for it, built in the next list of a
     * small storage, refused on a large one) until `larena:storage:view:rebuild` runs.
     */
    private function requestViewBuild(string $schemaId): void
    {
        if ($this->viewBuilds === null) {
            return;
        }
        try {
            ($this->viewBuilds)($schemaId);
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $descriptor
     * @return array{structure_id: string, label: string, fields: list<array<string, mixed>>}
     */
    private function normalizeDescriptor(array $descriptor): array
    {
        $keys = array_keys($descriptor);
        sort($keys, SORT_STRING);
        if ($keys !== ['fields', 'label', 'structure_id']) {
            throw new StorageRejected('storage_workbench_structure_descriptor_invalid');
        }
        $structureId = is_string($descriptor['structure_id'] ?? null) ? trim($descriptor['structure_id']) : '';
        $label = $this->normalizeLabel($descriptor['label'] ?? null, 'storage_workbench_structure_label_invalid');
        $fields = $descriptor['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields) || $fields === [] || count($fields) > self::MAX_FIELDS) {
            throw new StorageRejected('storage_workbench_structure_fields_invalid');
        }
        $normalized = [];
        $positions = [];
        $keysSeen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new StorageRejected('storage_workbench_structure_field_invalid');
            }
            $fieldKeys = array_keys($field);
            sort($fieldKeys, SORT_STRING);
            // `localized` is optional so that every existing caller stays valid: a
            // field that says nothing is not localized, which is what the whole
            // installed base means today.
            // `hidden` is optional in the same way: a field that says nothing is shown.
            // Hiding is presentation only; the field and its values stay.
            // `searchable` and `indexed` are the field's витрина options (StorageViewFieldOptions),
            // optional in the same way: a field that says nothing has the defaults.
            // `encrypted` («Шифровать») is optional in the same way; it goes into the storage schema, kept only when true.
            $optional = array_values(array_intersect($fieldKeys, ['encrypted', 'hidden', 'indexed', 'localized', 'searchable']));
            $expected = array_merge(['constraints', 'key', 'label', 'position', 'required', 'type', 'type_version', 'visibility'], $optional);
            sort($expected, SORT_STRING);
            if ($fieldKeys !== $expected) {
                throw new StorageRejected('storage_workbench_structure_field_invalid');
            }

            foreach ($optional as $flag) {
                if (!is_bool($field[$flag])) {
                    throw new StorageRejected(in_array($flag, StorageViewFieldOptions::KEYS, true)
                        ? StorageViewFieldOptions::REASON_OPTION_INVALID
                        : 'storage_workbench_structure_field_invalid');
                }
            }
            $key = is_string($field['key'] ?? null) ? trim($field['key']) : '';
            $position = $field['position'] ?? null;
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || str_starts_with($key, 'larena_')
                || isset($keysSeen[$key])
                || !is_int($position)
                || $position < 0
                || $position > 10000
                || isset($positions[$position])) {
                throw new StorageRejected('storage_workbench_structure_field_invalid');
            }
            $keysSeen[$key] = true;
            $positions[$position] = true;
            $normalized[] = [
                'key' => $key,
                'label' => $this->normalizeLabel($field['label'] ?? null, 'storage_workbench_structure_field_label_invalid'),
                'position' => $position,
                'type' => $field['type'] ?? null,
                'type_version' => $field['type_version'] ?? null,
                'required' => $field['required'] ?? null,
                'visibility' => $field['visibility'] ?? null,
                'constraints' => $field['constraints'] ?? null,
                'localized' => $field['localized'] ?? false,
            ] + (($field['hidden'] ?? false) === true ? ['hidden' => true] : [])
                + (($field['encrypted'] ?? false) === true ? ['encrypted' => true] : [])
                // The витрина options are kept as given (true or false) and only when given.
                + array_intersect_key($field, array_flip(StorageViewFieldOptions::KEYS));
        }
        usort($normalized, static fn (array $left, array $right): int => [$left['position'], $left['key']] <=> [$right['position'], $right['key']]);
        // The localized flag also goes into the storage schema, where the localized
        // value writer reads it; it is kept there only when true, so a structure
        // with no localized field has the schema it always had.
        $storageFields = array_map(static fn (array $field): array => [
            'key' => $field['key'],
            'type' => $field['type'],
            'type_version' => $field['type_version'],
            'required' => $field['required'],
            'visibility' => $field['visibility'],
            'constraints' => $field['constraints'],
        ] + ($field['localized'] === true ? ['localized' => true] : [])
            + (($field['encrypted'] ?? false) === true ? ['encrypted' => true] : []), $normalized);
        $validated = $this->normalizer->normalize([
            'schema_id' => $structureId,
            'owner_package' => 'larena/storage',
            'fields' => array_merge($this->reservedSchemaFields(), $storageFields),
        ]);
        $validatedByKey = [];
        foreach ($validated['fields'] as $field) {
            $validatedByKey[(string) $field['key']] = $field;
        }
        foreach ($normalized as &$field) {
            $validatedField = $validatedByKey[$field['key']] ?? null;
            if (!is_array($validatedField)) {
                throw new StorageRejected('storage_workbench_structure_field_invalid');
            }
            foreach (['type', 'type_version', 'required', 'visibility', 'constraints'] as $semantic) {
                $field[$semantic] = $validatedField[$semantic];
            }
        }
        unset($field);
        StorageViewFieldOptions::validate($normalized);

        return ['structure_id' => $structureId, 'label' => $label, 'fields' => $normalized];
    }

    /** @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function schemaDefinition(string $structureId, array $fields): array
    {
        return $this->normalizer->normalize([
            'schema_id' => $structureId,
            'owner_package' => 'larena/storage',
            'fields' => array_merge($this->reservedSchemaFields(), array_map(
                static fn (array $field): array => [
                    'key' => $field['key'],
                    'type' => $field['type'],
                    'type_version' => $field['type_version'],
                    'required' => $field['required'],
                    'visibility' => $field['visibility'],
                    'constraints' => $field['constraints'],
                ] + (($field['localized'] ?? false) === true ? ['localized' => true] : [])
                    + (($field['encrypted'] ?? false) === true ? ['encrypted' => true] : []),
                $fields,
            )),
        ]);
    }

    /** @param list<array<string, mixed>> $candidateFields
     * @return array<string, mixed>
     */
    private function schemaDefinitionForUpdate(StorageSchemaVersion $current, array $candidateFields): array
    {
        $candidateByKey = [];
        foreach ($candidateFields as $field) {
            $candidateByKey[(string) $field['key']] = $field;
        }
        $currentUserKeys = [];
        $target = [];
        foreach ($current->fields as $field) {
            $key = (string) $field['key'];
            if (in_array($key, [self::SCOPE_FIELD, self::STATE_FIELD], true)) {
                $target[] = $field;
                continue;
            }
            $candidate = $candidateByKey[$key] ?? null;
            if (!is_array($candidate)) {
                throw new StorageRejected('storage_workbench_structure_field_removed');
            }
            // Whether a field is localized may change: it applies to revisions written
            // under the new schema version, and a translation of an older revision
            // keeps the rule of its own version. Everything else must stay.
            $stored = $field;
            // Encryption may switch too, on a field without values (assertEncryptionChange checks it).
            unset($stored['localized'], $stored['encrypted']);
            $semantic = $this->semanticField($candidate);
            if ($this->canonicalJson($semantic) !== $this->canonicalJson($stored)) {
                throw new StorageRejected('storage_workbench_structure_field_changed');
            }
            $currentUserKeys[$key] = true;
            $target[] = $stored + (($candidate['localized'] ?? false) === true ? ['localized' => true] : [])
                + (($candidate['encrypted'] ?? false) === true ? ['encrypted' => true] : []);
        }
        foreach ($candidateFields as $field) {
            $key = (string) $field['key'];
            if (isset($currentUserKeys[$key])) {
                continue;
            }
            // An added field is optional and unconstrained, so no stored record changes meaning; a
            // record@1 field carries only its target, and a choice field only its options (and, for
            // several choices, how many), which existing records (without the field) ignore. Other
            // constraints go through migrateStructure, which adds the field the same way.
            $constraints = $field['constraints'] ?? null;
            $constraintKeys = is_array($constraints) ? array_keys($constraints) : null;
            if ($constraintKeys !== null) {
                sort($constraintKeys);
            }
            $allowedKeys = match ($field['type'] ?? null) {
                'record' => [['target_structure_id']],
                'choice' => [['options']],
                'choices' => [['options'], ['max_items', 'options']],
                default => [],
            };
            if (($field['required'] ?? null) !== false
                || ($constraints !== [] && !in_array($constraintKeys, $allowedKeys, true))) {
                throw new StorageRejected('storage_workbench_structure_addition_incompatible');
            }
            $target[] = $this->semanticField($field) + (($field['localized'] ?? false) === true ? ['localized' => true] : [])
                + (($field['encrypted'] ?? false) === true ? ['encrypted' => true] : []);
        }

        return $this->normalizer->normalize([
            'schema_id' => $current->ref->schemaId,
            'owner_package' => $current->ownerPackage,
            'fields' => $target,
        ]);
    }

    /**
     * In a structure change's transaction: encryption switches only on fields without values, an encrypted field is
     * not retyped and keeps its constraints while it has values (StorageEncryptionPolicy::assertChange). The storage's
     * data key is made when it gets its first encrypted field (changeStructure asked for the manage right before).
     *
     * A field with values whose encryption switches needs an encryption run (slice B): returned as its mode and
     * fields. Without the runs table or a sealer it stays refused (storage_field_encryption_rewrite_required).
     *
     * @param array<string, mixed> $candidateDefinition
     * @return array{mode: string, fields: list<string>}|null
     */
    private function assertEncryptionChange(StorageSchemaVersion $current, array $candidateDefinition): ?array
    {
        $from = StorageRecordConverter::byKey($current->fields);
        $to = StorageRecordConverter::byKey(is_array($candidateDefinition['fields'] ?? null) ? $candidateDefinition['fields'] : []);
        $schemaId = $current->ref->schemaId;
        $switch = StorageEncryptionPolicy::assertSwitch(
            $from,
            $to,
            fn (string $key): bool => StorageEncryptionPolicy::fieldHasValues($this->database, $schemaId, $key),
        );
        if ($switch['rewrite'] !== [] && ($this->encryption === null || !$this->encryptionRuns->available())) {
            throw new StorageRejected(StorageEncryptionPolicy::REWRITE_REQUIRED);
        }
        $before = StorageEncryptionPolicy::encryptedKeys($from);
        $after = StorageEncryptionPolicy::encryptedKeys($to);
        sort($before, SORT_STRING);
        sort($after, SORT_STRING);
        if ($before !== $after && $after !== []) {
            if ($this->encryption === null) {
                throw new StorageRejected(StorageEncryptionPolicy::UNAVAILABLE);
            }
            $this->encryption->keys()->ensureActive($schemaId);
        }
        if ($switch['rewrite'] === []) {
            return null;
        }
        $modes = [];
        foreach ($switch['rewrite'] as $key) {
            $modes[StorageEncryptionPolicy::isEncrypted($to[$key] ?? []) ? StorageWorkbenchEncryptionRun::MODE_ENCRYPT : StorageWorkbenchEncryptionRun::MODE_DECRYPT] = true;
        }
        if (count($modes) !== 1) {
            // One run seals or opens: a change that does both is two changes.
            throw new StorageRejected(StorageEncryptionPolicy::CHANGE_COMBINED);
        }
        $fields = $switch['rewrite'];
        sort($fields, SORT_STRING);

        return ['mode' => (string) array_key_first($modes), 'fields' => $fields];
    }

    /** Making or changing encrypted fields: the manage right and a working key (sodium, the key-encryption key). */
    private function assertEncryptionManaged(string $actor): void
    {
        $this->authorizer->assertAllowed($actor, 'storage.encryption.manage');
        if ($this->encryption === null) {
            throw new StorageRejected(StorageEncryptionPolicy::UNAVAILABLE);
        }
        $this->encryption->keys()->assertAvailable();
    }

    /** @return array<string, mixed> */
    private function schemaDefinitionFromVersion(StorageSchemaVersion $schema): array
    {
        return [
            'schema_id' => $schema->ref->schemaId,
            'owner_package' => $schema->ownerPackage,
            'fields' => $schema->fields,
        ];
    }

    /** @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function semanticField(array $field): array
    {
        return [
            'key' => $field['key'],
            'type' => $field['type'],
            'type_version' => $field['type_version'],
            'required' => $field['required'],
            'visibility' => $field['visibility'],
            'constraints' => $field['constraints'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function reservedSchemaFields(): array
    {
        return [
            ['key' => self::SCOPE_FIELD, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 1, 'max_length' => 191]],
            ['key' => self::STATE_FIELD, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 6, 'max_length' => 8]],
        ];
    }

    /** @param self::LOCK_*|null $lock */
    private function readStructureInternal(
        string $scopeRef,
        string $structureId,
        ?string $lock = null,
        bool $requireActive = false,
    ): StorageWorkbenchStructure {
        try {
            $head = $this->structureHead($scopeRef, $structureId, $lock);
            if ($requireActive && $this->headArchivedAt($head) !== null) {
                throw new StorageRejected('storage_workbench_structure_archived');
            }

            return $this->hydrateStructureVersion($head, $lock);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * Record writes read the structure with a shared lock, so they run side by side;
     * structure changes take it for update and so wait for them.
     *
     * @param self::LOCK_*|null $lock
     */
    private function structureHead(string $scopeRef, string $structureId, ?string $lock): stdClass
    {
        $query = $this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $scopeRef)
            ->where('structure_id', $structureId);
        $this->applyLock($query, $lock);
        $head = $query->first();
        if (!$head instanceof stdClass) {
            throw new StorageRejected('storage_workbench_structure_unknown');
        }
        if (($head->import_run_ref ?? null) !== null) {
            // A SitePack import is still writing it: nothing reads or changes it until the import ends.
            throw new StorageRejected('storage_workbench_structure_importing');
        }

        return $head;
    }

    /** @param self::LOCK_*|null $lock */
    private function hydrateStructureVersion(stdClass $head, ?string $lock = null): StorageWorkbenchStructure
    {
        $query = $this->database->table('larena_storage_workbench_structure_versions')
            ->where('scope_ref', (string) $head->scope_ref)
            ->where('structure_id', (string) $head->structure_id)
            ->where('version', (int) $head->current_version);
        $this->applyLock($query, $lock);
        $row = $query->first();
        if (!$row instanceof stdClass) {
            throw new StorageRejected('storage_workbench_structure_corrupt');
        }
        try {
            $fields = json_decode((string) $row->fields_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_workbench_structure_corrupt');
        }
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new StorageRejected('storage_workbench_structure_corrupt');
        }
        $hash = $this->descriptorHash(
            (string) $row->structure_id,
            (string) $row->scope_ref,
            (int) $row->version,
            (int) $row->schema_version,
            (string) $row->label,
            $fields,
        );
        if (!hash_equals((string) $row->descriptor_hash, $hash)
            || !hash_equals((string) $head->current_hash, $hash)
            || (string) $head->scope_ref !== (string) $row->scope_ref
            || (string) $head->storage_schema_id !== (string) $row->storage_schema_id
            || !hash_equals(
                ScopedStorageWorkbenchSchemaIdentity::derive((string) $row->scope_ref, (string) $row->structure_id),
                (string) $row->storage_schema_id,
            )
            || (int) $head->current_schema_version !== (int) $row->schema_version) {
            throw new StorageRejected('storage_workbench_structure_corrupt');
        }
        // A schema version never changes once written; only a structure change locks it.
        $schema = $this->storage->schemaVersion(new StorageSchemaVersionRef(
            (string) $row->storage_schema_id,
            (int) $row->schema_version,
        ), $lock === self::LOCK_UPDATE);
        if ($schema->ownerPackage !== 'larena/storage') {
            throw new StorageRejected('storage_workbench_structure_corrupt');
        }

        return new StorageWorkbenchStructure(
            (string) $row->structure_id,
            (string) $row->scope_ref,
            (int) $row->version,
            $schema->ref,
            (string) $row->label,
            $fields,
            $hash,
            (string) $row->created_at,
            (int) $row->version === 1 ? 'create' : 'update',
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            $this->headArchivedAt($head),
            $this->headArchivedAt($head) === null || !is_string($head->archived_by ?? null) ? null : $head->archived_by,
            isset($head->history_keep) ? (int) $head->history_keep : null,
        );
    }

    /**
     * After a write, the record's history is trimmed to what the structure keeps. Trimming
     * never fails the write: a history that could not be trimmed now is trimmed by the next one.
     *
     * @param list<string> $recordIds
     */
    private function keepHistory(StorageWorkbenchStructure $structure, array $recordIds, string $actor, ?string $correlationId): void
    {
        if ($structure->historyKeep === null || $recordIds === []) {
            return;
        }
        try {
            $this->storage->pruneHistory($structure->schema->schemaId, $recordIds, $structure->historyKeep, $actor, $correlationId);
        } catch (Throwable) {
        }
    }

    /** @param self::LOCK_*|null $lock */
    private function applyLock(Builder $query, ?string $lock): void
    {
        if ($lock === self::LOCK_UPDATE) {
            $query->lockForUpdate();
        } elseif ($lock === self::LOCK_SHARED) {
            $query->sharedLock();
        }
    }

    private function headArchivedAt(stdClass $head): ?string
    {
        $archivedAt = $head->archived_at ?? null;

        return $archivedAt === null ? null : (string) $archivedAt;
    }

    private function readRecordInternal(
        StorageWorkbenchStructure $structure,
        string $scopeRef,
        string $recordId,
        string $actor,
        bool $forUpdate = false,
    ): StorageWorkbenchRecord {
        $headQuery = $this->database->table('larena_storage_records')
            ->where('schema_id', $structure->schema->schemaId)
            ->where('record_id', $recordId);
        if ($forUpdate) {
            $headQuery->lockForUpdate();
        }
        $head = $headQuery->first();
        if (!$head instanceof stdClass) {
            throw new StorageRejected('storage_workbench_record_unknown');
        }
        $version = $this->storage->readAdminVersion(
            new StorageRecordVersionRef($structure->schema->schemaId, $recordId, (int) $head->current_revision),
            $actor,
            $forUpdate,
        );
        if (($version->values[self::SCOPE_FIELD] ?? null) !== $scopeRef
            || !hash_equals($version->contentHash, (string) $head->current_hash)
            || $version->schema->version !== (int) $head->current_schema_version) {
            throw new StorageRejected('storage_workbench_record_scope_mismatch');
        }

        return $this->workbenchRecord($structure, $version);
    }

    private function workbenchRecord(StorageWorkbenchStructure $structure, StorageRecordVersion $version): StorageWorkbenchRecord
    {
        $scope = $version->values[self::SCOPE_FIELD] ?? null;
        $state = $version->values[self::STATE_FIELD] ?? null;
        if (!is_string($scope) || !in_array($state, [self::STATE_ACTIVE, self::STATE_ARCHIVED], true)
            || $version->ref->schemaId !== $structure->schema->schemaId
            || !str_starts_with($version->ownerRef, 'workbench.record:')) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }
        $values = $version->values;
        if ($version->schema->version !== $structure->schema->version) {
            // Written under an older schema version: read under the structure's current one.
            $values = $this->converter->convert($structure->schema->schemaId, $version->schema->version, $structure->schema->version, $values);
        }
        unset($values[self::SCOPE_FIELD], $values[self::STATE_FIELD]);
        $values = $this->maskPlaintext($structure, $version->ref->recordId, $values);

        return new StorageWorkbenchRecord(
            $structure->structureId,
            $scope,
            $version->ref->recordId,
            $version->ref->revision,
            $version->schema->version,
            $state,
            $values,
            $version->contentHash,
            $version->operation,
            $version->createdAt,
            $version->createdBy,
            $version->correlationId,
        );
    }

    /**
     * While an encryption run has not reached a version, a field that is encrypted now may still hold plaintext:
     * every reader shows it as an envelope (sealed for this read, StorageFieldSealer::maskPlaintext), or leaves it out
     * where nothing can seal it here.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function maskPlaintext(StorageWorkbenchStructure $structure, string $recordId, #[\SensitiveParameter] array $values): array
    {
        $plain = [];
        foreach (StorageEncryptionPolicy::encryptedKeys($structure->fields) as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null && !StorageFieldEnvelope::isEnvelope($values[$key])) {
                $plain[] = $key;
            }
        }
        if ($plain === []) {
            return $values;
        }
        if ($this->encryption === null) {
            return array_diff_key($values, array_flip($plain));
        }

        return $this->encryption->maskPlaintext($structure->schema->schemaId, $recordId, null, $plain, $values);
    }

    /**
     * The structure's fields as its lists see them: a field a decrypt run still opens is encrypted for the list (no
     * filter, no sort, no search, no column) until the run is done.
     *
     * @return list<array<string, mixed>>
     */
    private function listFields(StorageWorkbenchStructure $structure): array
    {
        return StorageFieldEncryptionRuns::viewFields($structure->fields, $this->encryptionRuns->sealedForView($structure->schema->schemaId));
    }

    /** A list hydrates without recomputing each record's hash; a single read verifies it. */
    private function hydrateRawRecordVersion(stdClass $row, bool $verify = true): StorageRecordVersion
    {
        try {
            $values = json_decode((string) $row->values_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }
        if (!is_array($values) || array_is_list($values)) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }
        $hash = $verify ? hash('sha256', $this->canonicalJson($values)) : (string) $row->content_hash;
        if ($verify && !hash_equals((string) $row->content_hash, $hash)) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }

        return new StorageRecordVersion(
            new StorageRecordVersionRef((string) $row->schema_id, (string) $row->record_id, (int) $row->revision),
            (string) $row->owner_ref,
            new StorageSchemaVersionRef((string) $row->schema_id, (int) $row->schema_version),
            $values,
            $hash,
            (string) $row->operation,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
        );
    }

    /** @return array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} */
    private function normalizeRecordQuery(StorageWorkbenchRecordQuery $query, StorageWorkbenchStructure $structure): array
    {
        if ($query->limit < 1 || $query->limit > 100) {
            throw new StorageRejected('storage_workbench_record_list_limit_invalid');
        }
        if (strlen($this->cursorKey) < 32) {
            throw new StorageRejected('storage_workbench_cursor_key_missing');
        }
        $fields = [];
        foreach ($this->listFields($structure) as $field) {
            $fields[(string) $field['key']] = $field;
        }

        return $this->queryNormalizer->normalize($fields, $query->filters, $query->search, $query->sort, $query->includeArchived);
    }

    private function decodeContinuation(?string $continuation, string $queryHash, string $scopeHash, int $maxOffset): int
    {
        if ($continuation === null) {
            return 0;
        }
        if ($continuation === '' || strlen($continuation) > 2048) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }
        $encoded = strtr($continuation, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode($encoded, true);
        try {
            $payload = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['offset', 'query', 'scope', 'signature', 'version']
            || ($payload['version'] ?? null) !== 1
            || !is_int($payload['offset'] ?? null)
            || $payload['offset'] < 0
            || $payload['offset'] > $maxOffset
            || !is_string($payload['query'] ?? null)
            || !is_string($payload['scope'] ?? null)
            || !is_string($payload['signature'] ?? null)) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }
        $signed = ['version' => 1, 'query' => $payload['query'], 'scope' => $payload['scope'], 'offset' => $payload['offset']];
        $expected = hash_hmac('sha256', $this->canonicalJson($signed), $this->cursorKey);
        if (!hash_equals($expected, $payload['signature'])
            || !hash_equals($queryHash, $payload['query'])
            || !hash_equals($scopeHash, $payload['scope'])) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }

        return $payload['offset'];
    }

    private function encodeContinuation(int $offset, string $queryHash, string $scopeHash): string
    {
        $payload = ['version' => 1, 'query' => $queryHash, 'scope' => $scopeHash, 'offset' => $offset];
        $payload['signature'] = hash_hmac('sha256', $this->canonicalJson($payload), $this->cursorKey);

        return rtrim(strtr(base64_encode($this->canonicalJson($payload)), '+/', '-_'), '=');
    }

    /** @param array<array-key, mixed> $values */
    private function assertUserValues(#[\SensitiveParameter] array $values): void
    {
        if ($values !== [] && array_is_list($values)) {
            throw new StorageRejected('storage_workbench_record_values_invalid');
        }
        if (count($values) > self::MAX_FIELDS) {
            throw new StorageRejected('storage_workbench_record_values_too_large');
        }
        foreach ($values as $key => $_value) {
            if (!is_string($key) || str_starts_with($key, 'larena_')) {
                throw new StorageRejected('storage_workbench_record_reserved_field');
            }
        }
        if (strlen($this->canonicalJson($values)) > 1048576) {
            throw new StorageRejected('storage_workbench_record_values_too_large');
        }
    }

    private function ownerRef(string $storageSchemaId, string $recordId): string
    {
        $ownerRef = $this->database->table('larena_storage_records')
            ->where('schema_id', $storageSchemaId)
            ->where('record_id', $recordId)
            ->value('owner_ref');
        if (!is_string($ownerRef) || !str_starts_with($ownerRef, 'workbench.record:')) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }

        return $ownerRef;
    }

    /** @param list<array<string, mixed>> $fields */
    private function insertStructureVersion(
        string $structureId,
        int $version,
        string $scopeRef,
        string $storageSchemaId,
        string $label,
        array $fields,
        int $schemaVersion,
        string $hash,
        string $actor,
        string $correlationId,
        string $createdAt,
    ): void {
        $this->database->table('larena_storage_workbench_structure_versions')->insert([
            'structure_id' => $structureId,
            'version' => $version,
            'scope_ref' => $scopeRef,
            'storage_schema_id' => $storageSchemaId,
            'label' => $label,
            'fields_json' => $this->canonicalJson($fields),
            'schema_version' => $schemaVersion,
            'descriptor_hash' => $hash,
            'created_by' => $actor,
            'correlation_id' => $correlationId,
            'created_at' => $createdAt,
        ]);
    }

    /** @param list<array<string, mixed>> $fields */
    private function descriptorHash(
        string $structureId,
        string $scopeRef,
        int $version,
        int $schemaVersion,
        string $label,
        array $fields,
    ): string {
        return hash('sha256', $this->canonicalJson([
            'structure_id' => $structureId,
            'scope_ref' => $scopeRef,
            'version' => $version,
            'schema_version' => $schemaVersion,
            'label' => $label,
            'fields' => $fields,
        ]));
    }

    private function normalizeLabel(mixed $label, string $reason): string
    {
        if (!is_string($label)) {
            throw new StorageRejected($reason);
        }
        $label = trim($label);
        if ($label === '' || strlen($label) > 120 || str_contains($label, '<') || str_contains($label, '>')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label) === 1) {
            throw new StorageRejected($reason);
        }

        return $label;
    }

    private function assertStructureId(string $structureId): void
    {
        if (preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/', $structureId) !== 1) {
            throw new InvalidArgumentException('storage_workbench_structure_id_invalid');
        }
    }

    private function assertRecordId(string $recordId): void
    {
        if (preg_match('/^record-[a-f0-9]{32}$/', $recordId) !== 1) {
            throw new InvalidArgumentException('storage_workbench_record_id_invalid');
        }
    }

    private function assertScopeRef(string $scopeRef): void
    {
        if (preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/', $scopeRef) !== 1) {
            throw new InvalidArgumentException('storage_workbench_scope_ref_invalid');
        }
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 191) {
            throw new InvalidArgumentException('storage_workbench_actor_invalid');
        }
    }

    private function correlationId(?string $correlationId): string
    {
        if ($correlationId === null) {
            return 'storage-workbench-' . bin2hex(random_bytes(12));
        }
        if (trim($correlationId) === '' || strlen($correlationId) > 191) {
            throw new InvalidArgumentException('storage_workbench_correlation_id_invalid');
        }

        return 'storage-workbench-' . hash('sha256', $correlationId);
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function canonicalJson(mixed $value): string
    {
        return $this->normalizer->canonicalJson($value);
    }

    private function isConstraintConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate') || str_contains($message, 'constraint');
    }

    private function isLockConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'database is locked') || str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }

    // A connection that cannot describe its schema is treated as having every
    // table, so a check it cannot run never turns into a skipped one.
    private function tableExists(string $table): bool
    {
        return !$this->database instanceof Connection || $this->database->getSchemaBuilder()->hasTable($table);
    }
}
