<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaEvolution;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbench as StorageWorkbenchContract;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use stdClass;
use Throwable;

final readonly class DatabaseStorageWorkbench implements StorageWorkbenchContract
{
    private const STRUCTURE_RESOURCE = 'storage.workbench.structure';
    private const RECORD_RESOURCE = 'storage.workbench.record';
    private const SCOPE_FIELD = 'larena_scope_ref';
    private const STATE_FIELD = 'larena_record_state';
    private const STATE_ACTIVE = 'active';
    private const STATE_ARCHIVED = 'archived';
    private const MAX_STRUCTURES = 100;
    private const MAX_FIELDS = 100;
    private const MAX_SCAN = 500;
    private const MAX_FILTERS = 8;
    private const MAX_SORTS = 3;
    private const MAX_BULK = 100;

    private SchemaDefinitionNormalizer $normalizer;

    public function __construct(
        private ConnectionInterface $database,
        private PropertyTypeRegistry $propertyTypes,
        private ActorOperationAuthorizer $authorizer,
        private QueryScopeProvider $scopeProvider,
        private VersionedStorage $storage,
        private StorageSchemaEvolution $schemaEvolution,
        private StorageSchemaEvolutionOwnerPolicyRegistry $ownerPolicies,
        private string $cursorKey,
    ) {
        $this->normalizer = new SchemaDefinitionNormalizer($propertyTypes);
    }

    public function createStructure(
        string $scopeRef,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure {
        $this->assertScope($actor, 'storage.workbench.structure.create', $scopeRef, self::STRUCTURE_RESOURCE);
        $normalized = $this->normalizeDescriptor($descriptor);
        $this->assertStructureId($normalized['structure_id']);
        $correlationId = $this->correlationId($correlationId);
        $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $normalized['structure_id']);

        try {
            return $this->database->transaction(function () use ($scopeRef, $normalized, $storageSchemaId, $actor, $correlationId): StorageWorkbenchStructure {
                $schema = $this->storage->registerSchemaVersion(
                    $this->schemaDefinition($storageSchemaId, $normalized['fields']),
                    null,
                    $actor,
                    $correlationId,
                );
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
                ]);
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
                );
            });
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
        $normalized = $this->normalizeDescriptor($descriptor);
        if ($normalized['structure_id'] !== $structureId) {
            throw new StorageRejected('storage_workbench_structure_identity_changed');
        }
        $correlationId = $this->correlationId($correlationId);

        try {
            return $this->database->transaction(function () use (
                $scopeRef,
                $structureId,
                $expectedVersion,
                $normalized,
                $actor,
                $correlationId,
            ): StorageWorkbenchStructure {
                $head = $this->structureHead($scopeRef, $structureId, true);
                if ((int) $head->current_version !== $expectedVersion) {
                    throw new StorageConflict('storage_workbench_structure_version_conflict');
                }
                $current = $this->hydrateStructureVersion($head, true);
                $currentSchema = $this->storage->schemaVersion($current->schema, true);
                $candidateDefinition = $this->schemaDefinitionForUpdate($currentSchema, $normalized['fields']);
                $schemaVersion = $current->schema->version;
                if ($this->canonicalJson($candidateDefinition) !== $this->canonicalJson(
                    $this->schemaDefinitionFromVersion($currentSchema),
                )) {
                    $result = $this->ownerPolicies->withinTransaction(
                        $this->database,
                        function ($transactionScope) use ($current, $candidateDefinition, $actor, $correlationId) {
                            $plan = $this->schemaEvolution->plan(
                                $current->schema,
                                $candidateDefinition,
                                $actor,
                                $correlationId,
                                $transactionScope,
                                $this,
                            );

                            return $this->schemaEvolution->apply(
                                $plan->planRef,
                                $plan->planHash,
                                $actor,
                                $correlationId,
                                $transactionScope,
                                $this,
                            );
                        },
                    );
                    $schemaVersion = $result->target->version;
                }

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

                return new StorageWorkbenchStructure(
                    $structureId,
                    $scopeRef,
                    $nextVersion,
                    new StorageSchemaVersionRef((string) $head->storage_schema_id, $schemaVersion),
                    $normalized['label'],
                    $normalized['fields'],
                    $hash,
                    $now,
                    'update',
                    $actor,
                    $correlationId,
                );
            });
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
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.structure.read', $scopeRef, self::STRUCTURE_RESOURCE);

        return $this->readStructureInternal($scopeRef, $structureId);
    }

    public function listStructures(string $scopeRef, string $actor): array
    {
        $this->assertScope($actor, 'storage.workbench.structure.list', $scopeRef, self::STRUCTURE_RESOURCE);
        try {
            /** @var list<stdClass> $rows */
            $rows = $this->database->table('larena_storage_workbench_structures')
                ->where('scope_ref', $scopeRef)
                ->orderBy('structure_id')
                ->limit(self::MAX_STRUCTURES + 1)
                ->get()
                ->all();
            if (count($rows) > self::MAX_STRUCTURES) {
                throw new StorageRejected('storage_workbench_structure_list_limit_exceeded');
            }

            return array_map(fn (stdClass $row): StorageWorkbenchStructure => $this->hydrateStructureVersion($row), $rows);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function createRecord(
        string $scopeRef,
        string $structureId,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord {
        $this->assertStructureId($structureId);
        $this->assertScope($actor, 'storage.workbench.record.create', $scopeRef, self::RECORD_RESOURCE);
        $this->assertUserValues($values);
        $structure = $this->readStructureInternal($scopeRef, $structureId);
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
        array $values,
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
            $structure = $this->readStructureInternal($scopeRef, $structureId, true);
            $current = $this->readRecordInternal($structure, $scopeRef, $recordId, $actor, true);
            if ($current->revision !== $expectedRevision) {
                throw new StorageConflict('storage_workbench_record_revision_conflict');
            }
            if ($current->state !== self::STATE_ACTIVE) {
                throw new StorageRejected('storage_workbench_record_archived');
            }
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
            $structure = $this->readStructureInternal($scopeRef, $structureId, true);
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
            $structure = $this->readStructureInternal($scopeRef, $structureId, true);
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
            $structure = $this->readStructureInternal($scopeRef, $structureId, true);
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

            return $archived;
        });
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
        if ($query->page !== null && ($query->continuation !== null || $query->page < 1
            || $query->page > (int) ceil(self::MAX_SCAN / $query->limit))) {
            throw new StorageRejected('storage_workbench_record_page_invalid');
        }
        $offset = $query->page === null
            ? $this->decodeContinuation($query->continuation, $queryHash, $scopeHash)
            : ($query->page - 1) * $query->limit;

        try {
            /** @var list<stdClass> $rows */
            $rows = $this->database->table('larena_storage_records as heads')
                ->join('larena_storage_record_versions as versions', static function ($join): void {
                    $join->on('versions.schema_id', '=', 'heads.schema_id')
                        ->on('versions.record_id', '=', 'heads.record_id')
                        ->on('versions.revision', '=', 'heads.current_revision');
                })
                ->where('heads.schema_id', $structure->schema->schemaId)
                ->orderBy('heads.record_id')
                ->limit(self::MAX_SCAN + 1)
                ->select([
                    'versions.schema_id', 'versions.record_id', 'versions.revision', 'versions.owner_ref',
                    'versions.schema_version', 'versions.values_json', 'versions.content_hash',
                    'versions.operation', 'versions.created_by', 'versions.correlation_id', 'versions.created_at',
                    'heads.current_hash as head_hash',
                ])
                ->get()
                ->all();
            if (count($rows) > self::MAX_SCAN) {
                throw new StorageRejected('storage_workbench_record_scan_limit_exceeded');
            }

            $records = [];
            foreach ($rows as $row) {
                $version = $this->hydrateRawRecordVersion($row);
                if (!hash_equals($version->contentHash, (string) $row->head_hash)) {
                    throw new StorageRejected('storage_workbench_record_head_corrupt');
                }
                if (($version->values[self::SCOPE_FIELD] ?? null) !== $query->scopeRef) {
                    continue;
                }
                $record = $this->workbenchRecord($structure, $version);
                if (!$normalizedQuery['include_archived'] && $record->state === self::STATE_ARCHIVED) {
                    continue;
                }
                if (!$this->recordMatches($record, $normalizedQuery, $structure)) {
                    continue;
                }
                $records[] = $record;
            }
            usort(
                $records,
                fn (StorageWorkbenchRecord $left, StorageWorkbenchRecord $right): int => $this->compareRecords(
                    $left,
                    $right,
                    $normalizedQuery['sort'],
                    $structure,
                ),
            );
            $matchedCount = count($records);
            if ($offset > $matchedCount) {
                throw new StorageRejected('storage_workbench_record_continuation_invalid');
            }
            $items = array_slice($records, $offset, $query->limit);
            $nextOffset = $offset + count($items);
            $continuation = $nextOffset < $matchedCount
                ? $this->encodeContinuation($nextOffset, $queryHash, $scopeHash)
                : null;

            return new StorageWorkbenchRecordPage($items, $continuation, $matchedCount);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
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
            if ($fieldKeys !== ['constraints', 'key', 'label', 'position', 'required', 'type', 'type_version', 'visibility']) {
                throw new StorageRejected('storage_workbench_structure_field_invalid');
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
            ];
        }
        usort($normalized, static fn (array $left, array $right): int => [$left['position'], $left['key']] <=> [$right['position'], $right['key']]);
        $storageFields = array_map(static fn (array $field): array => [
            'key' => $field['key'],
            'type' => $field['type'],
            'type_version' => $field['type_version'],
            'required' => $field['required'],
            'visibility' => $field['visibility'],
            'constraints' => $field['constraints'],
        ], $normalized);
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
                ],
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
            $semantic = $this->semanticField($candidate);
            if ($this->canonicalJson($semantic) !== $this->canonicalJson($field)) {
                throw new StorageRejected('storage_workbench_structure_field_changed');
            }
            $currentUserKeys[$key] = true;
            $target[] = $field;
        }
        foreach ($candidateFields as $field) {
            $key = (string) $field['key'];
            if (isset($currentUserKeys[$key])) {
                continue;
            }
            if (($field['required'] ?? null) !== false || ($field['constraints'] ?? null) !== []) {
                throw new StorageRejected('storage_workbench_structure_addition_incompatible');
            }
            $target[] = $this->semanticField($field);
        }

        return $this->normalizer->normalize([
            'schema_id' => $current->ref->schemaId,
            'owner_package' => $current->ownerPackage,
            'fields' => $target,
        ]);
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

    private function readStructureInternal(string $scopeRef, string $structureId, bool $forUpdate = false): StorageWorkbenchStructure
    {
        try {
            $head = $this->structureHead($scopeRef, $structureId, $forUpdate);

            return $this->hydrateStructureVersion($head, $forUpdate);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    private function structureHead(string $scopeRef, string $structureId, bool $forUpdate): stdClass
    {
        $query = $this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $scopeRef)
            ->where('structure_id', $structureId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $head = $query->first();
        if (!$head instanceof stdClass) {
            throw new StorageRejected('storage_workbench_structure_unknown');
        }

        return $head;
    }

    private function hydrateStructureVersion(stdClass $head, bool $forUpdate = false): StorageWorkbenchStructure
    {
        $query = $this->database->table('larena_storage_workbench_structure_versions')
            ->where('scope_ref', (string) $head->scope_ref)
            ->where('structure_id', (string) $head->structure_id)
            ->where('version', (int) $head->current_version);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
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
        $schema = $this->storage->schemaVersion(new StorageSchemaVersionRef(
            (string) $row->storage_schema_id,
            (int) $row->schema_version,
        ), $forUpdate);
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
        );
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
        unset($values[self::SCOPE_FIELD], $values[self::STATE_FIELD]);

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

    private function hydrateRawRecordVersion(stdClass $row): StorageRecordVersion
    {
        try {
            $values = json_decode((string) $row->values_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }
        if (!is_array($values) || array_is_list($values)) {
            throw new StorageRejected('storage_workbench_record_corrupt');
        }
        $hash = hash('sha256', $this->canonicalJson($values));
        if (!hash_equals((string) $row->content_hash, $hash)) {
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

    /** @return array{filters: array<string, array{operator: string, value: mixed}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} */
    private function normalizeRecordQuery(StorageWorkbenchRecordQuery $query, StorageWorkbenchStructure $structure): array
    {
        if ($query->limit < 1 || $query->limit > 100) {
            throw new StorageRejected('storage_workbench_record_list_limit_invalid');
        }
        if (strlen($this->cursorKey) < 32) {
            throw new StorageRejected('storage_workbench_cursor_key_missing');
        }
        if ($query->filters !== [] && array_is_list($query->filters)) {
            throw new StorageRejected('storage_workbench_record_filters_invalid');
        }
        if (count($query->filters) > self::MAX_FILTERS || count($query->sort) > self::MAX_SORTS) {
            throw new StorageRejected('storage_workbench_record_query_too_large');
        }
        $fields = [];
        foreach ($structure->fields as $field) {
            $fields[(string) $field['key']] = $field;
        }
        $filters = [];
        foreach ($query->filters as $field => $filter) {
            if (!is_string($field) || !isset($fields[$field]) || !is_array($filter)) {
                throw new StorageRejected('storage_workbench_record_filter_invalid');
            }
            $keys = array_keys($filter);
            sort($keys, SORT_STRING);
            if ($keys !== ['operator', 'value'] || ($filter['operator'] ?? null) !== 'eq') {
                throw new StorageRejected('storage_workbench_record_filter_invalid');
            }
            $definition = $fields[$field];
            $result = $this->propertyTypes->normalizeAndValidate(
                (string) $definition['type'],
                (int) $definition['type_version'],
                $filter['value'] ?? null,
                is_array($definition['constraints'] ?? null) ? $definition['constraints'] : [],
            );
            if (!$result->canBePersistedByOwner()) {
                throw new StorageRejected('storage_workbench_record_filter_invalid');
            }
            $filters[$field] = ['operator' => 'eq', 'value' => $result->normalizedValue];
        }
        ksort($filters, SORT_STRING);
        $search = $query->search;
        if ($search !== null) {
            $search = trim($search);
            if ($search === '' || strlen($search) > 100 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $search) === 1) {
                throw new StorageRejected('storage_workbench_record_search_invalid');
            }
        }
        $sort = [];
        $seen = [];
        foreach ($query->sort as $item) {
            if (!is_array($item)) {
                throw new StorageRejected('storage_workbench_record_sort_invalid');
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            $field = $item['field'] ?? null;
            $direction = $item['direction'] ?? null;
            if ($keys !== ['direction', 'field']
                || !is_string($field)
                || (!isset($fields[$field]) && !in_array($field, ['_record_id', '_revision'], true))
                || isset($seen[$field])
                || !in_array($direction, ['asc', 'desc'], true)) {
                throw new StorageRejected('storage_workbench_record_sort_invalid');
            }
            $seen[$field] = true;
            $sort[] = ['field' => $field, 'direction' => $direction];
        }

        return ['filters' => $filters, 'search' => $search, 'sort' => $sort, 'include_archived' => $query->includeArchived];
    }

    /** @param array{filters: array<string, array{operator: string, value: mixed}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $query */
    private function recordMatches(StorageWorkbenchRecord $record, array $query, StorageWorkbenchStructure $structure): bool
    {
        foreach ($query['filters'] as $field => $filter) {
            if (!array_key_exists($field, $record->values) || $record->values[$field] !== $filter['value']) {
                return false;
            }
        }
        if ($query['search'] === null) {
            return true;
        }
        $needle = $this->lower($query['search']);
        foreach ($structure->fields as $field) {
            if (!in_array($field['type'] ?? null, ['string', 'text'], true)) {
                continue;
            }
            $value = $record->values[(string) $field['key']] ?? null;
            if (is_string($value) && str_contains($this->lower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{field: string, direction: string}> $sort */
    private function compareRecords(
        StorageWorkbenchRecord $left,
        StorageWorkbenchRecord $right,
        array $sort,
        StorageWorkbenchStructure $structure,
    ): int {
        $fieldTypes = [];
        foreach ($structure->fields as $field) {
            $fieldTypes[(string) $field['key']] = (string) $field['type'];
        }
        foreach ($sort as $item) {
            $field = $item['field'];
            $leftValue = $field === '_record_id'
                ? $left->recordId
                : ($field === '_revision' ? $left->revision : ($left->values[$field] ?? null));
            $rightValue = $field === '_record_id'
                ? $right->recordId
                : ($field === '_revision' ? $right->revision : ($right->values[$field] ?? null));
            $comparison = $this->compareValues($leftValue, $rightValue, $fieldTypes[$field] ?? null);
            if ($comparison !== 0) {
                return $item['direction'] === 'desc' ? -$comparison : $comparison;
            }
        }

        return strcmp($left->recordId, $right->recordId);
    }

    private function compareValues(mixed $left, mixed $right, ?string $type): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === null) {
            return -1;
        }
        if ($right === null) {
            return 1;
        }
        if ($type === 'integer' || is_int($left) && is_int($right)) {
            return (int) $left <=> (int) $right;
        }
        if ($type === 'number' && is_string($left) && is_string($right)) {
            return $this->compareDecimals($left, $right);
        }
        if (is_bool($left) && is_bool($right)) {
            return (int) $left <=> (int) $right;
        }

        return strcmp((string) $left, (string) $right);
    }

    private function compareDecimals(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $leftParts = explode('.', ltrim($left, '-'), 2);
        $rightParts = explode('.', ltrim($right, '-'), 2);
        $integer = strlen($leftParts[0]) <=> strlen($rightParts[0]);
        if ($integer === 0) {
            $integer = strcmp($leftParts[0], $rightParts[0]);
        }
        if ($integer === 0) {
            $scale = max(strlen($leftParts[1] ?? ''), strlen($rightParts[1] ?? ''));
            $integer = strcmp(str_pad($leftParts[1] ?? '', $scale, '0'), str_pad($rightParts[1] ?? '', $scale, '0'));
        }

        return $leftNegative ? -$integer : $integer;
    }

    private function decodeContinuation(?string $continuation, string $queryHash, string $scopeHash): int
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
            || $payload['offset'] > self::MAX_SCAN
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
    private function assertUserValues(array $values): void
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

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
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
}
