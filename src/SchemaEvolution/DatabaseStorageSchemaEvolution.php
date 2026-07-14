<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Audit\StorageSchemaMigrationAuditEventDescriptor;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaCompatibilityReport;
use Larena\Storage\Contracts\StorageSchemaEvolution;
use Larena\Storage\Contracts\StorageSchemaMigrationPlan;
use Larena\Storage\Contracts\StorageSchemaMigrationRecordHead;
use Larena\Storage\Contracts\StorageSchemaMigrationRecordResult;
use Larena\Storage\Contracts\StorageSchemaMigrationResult;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSchemaEvolutionTransactionScope;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;
use Throwable;

final readonly class DatabaseStorageSchemaEvolution implements StorageSchemaEvolution
{
    private SchemaDefinitionNormalizer $normalizer;
    private OptionalFieldCompatibilityAnalyzer $compatibility;

    public function __construct(
        private ConnectionInterface $database,
        PropertyTypeRegistry $propertyTypes,
        private ActorOperationAuthorizer $authorizer,
        private AuditEventPipeline $audit,
        private StorageSchemaEvolutionOwnerPolicyRegistry $ownerPolicies,
    ) {
        $this->normalizer = new SchemaDefinitionNormalizer($propertyTypes);
        $this->compatibility = new OptionalFieldCompatibilityAnalyzer($this->normalizer);
    }

    public function connection(): ConnectionInterface
    {
        return $this->database;
    }

    public function analyze(
        StorageSchemaVersionRef $source,
        array $candidateDefinition,
        string $actor,
        ?string $correlationId = null,
    ): StorageSchemaCompatibilityReport {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.diff');
        $correlationId = $this->correlationId($correlationId);

        try {
            $snapshot = $this->snapshot($source, $candidateDefinition, false);
            $eventType = $snapshot['report']->compatible
                ? 'storage.schema_migration.analyzed'
                : 'storage.schema_migration.rejected';
            $this->emit($eventType, $actor, $source->schemaId, $correlationId, [
                'schema_id' => $source->schemaId,
                'source_version' => $source->version,
                'target_version' => $source->version + 1,
                'compatible' => $snapshot['report']->compatible,
                'compatibility_class' => $snapshot['report']->compatibilityClass,
                'added_optional_count' => $snapshot['report']->addedOptionalFieldCount,
                'record_count' => $snapshot['report']->recordCount,
                'reason_codes' => $snapshot['report']->reasonCodes,
            ]);

            return $snapshot['report'];
        } catch (StorageRejected $exception) {
            $this->emitRejected($actor, $source->schemaId, $source->version, $correlationId, $exception->reasonCode);
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function plan(
        StorageSchemaVersionRef $source,
        array $candidateDefinition,
        string $actor,
        ?string $correlationId = null,
        ?StorageSchemaEvolutionTransactionScope $transactionScope = null,
        ?object $orchestrationCapability = null,
    ): StorageSchemaMigrationPlan {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.plan');
        $correlationId = $this->correlationId($correlationId);

        try {
            $this->assertOwnerOrchestrationForPlan(
                $source,
                $candidateDefinition,
                $actor,
                $transactionScope,
                $orchestrationCapability,
            );
            return $this->database->transaction(function () use ($source, $candidateDefinition, $actor, $correlationId): StorageSchemaMigrationPlan {
                $snapshot = $this->snapshot($source, $candidateDefinition, true);
                $report = $snapshot['report'];
                if (!$report->compatible) {
                    throw new StorageRejected($report->reasonCodes[0] ?? 'storage_schema_migration_plan_incompatible');
                }

                $planRef = 'storage-migration-' . bin2hex(random_bytes(16));
                $createdAt = $this->timestamp();
                $targetDefinitionJson = $this->normalizer->canonicalJson($snapshot['target_definition']);
                $recordMaterial = $this->recordMaterial($snapshot['records']);
                $planMaterial = [
                    'plan_ref' => $planRef,
                    'schema_id' => $source->schemaId,
                    'source_version' => $source->version,
                    'source_hash' => $report->sourceHash,
                    'target_version' => $report->target->version,
                    'target_hash' => $report->targetHash,
                    'target_definition' => $snapshot['target_definition'],
                    'compatibility_class' => $report->compatibilityClass,
                    'added_optional_count' => $report->addedOptionalFieldCount,
                    'record_count' => $report->recordCount,
                    'record_heads_hash' => $report->recordHeadsHash,
                    'created_by' => $actor,
                    'correlation_id' => $correlationId,
                    'created_at' => $createdAt,
                    'records' => $recordMaterial,
                ];
                $planHash = hash('sha256', $this->normalizer->canonicalJson($planMaterial));

                $this->database->table('larena_storage_schema_migration_plans')->insert([
                    'plan_ref' => $planRef,
                    'schema_id' => $source->schemaId,
                    'source_version' => $source->version,
                    'source_hash' => $report->sourceHash,
                    'target_version' => $report->target->version,
                    'target_hash' => $report->targetHash,
                    'target_definition' => $targetDefinitionJson,
                    'compatibility_class' => $report->compatibilityClass,
                    'added_optional_count' => $report->addedOptionalFieldCount,
                    'record_count' => $report->recordCount,
                    'record_heads_hash' => $report->recordHeadsHash,
                    'plan_hash' => $planHash,
                    'created_by' => $actor,
                    'correlation_id' => $correlationId,
                    'created_at' => $createdAt,
                ]);
                foreach ($recordMaterial as $record) {
                    $this->database->table('larena_storage_schema_migration_plan_records')->insert([
                        'plan_ref' => $planRef,
                        'record_id' => $record['record_id'],
                        'owner_ref' => $record['owner_ref'],
                        'expected_revision' => $record['expected_revision'],
                        'expected_schema_version' => $record['expected_schema_version'],
                        'expected_content_hash' => $record['expected_content_hash'],
                    ]);
                }
                $this->emit('storage.schema_migration.planned', $actor, $source->schemaId, $correlationId, [
                    'schema_id' => $source->schemaId,
                    'source_version' => $source->version,
                    'target_version' => $report->target->version,
                    'plan_ref' => $planRef,
                    'plan_hash' => $planHash,
                    'record_count' => $report->recordCount,
                    'added_optional_count' => $report->addedOptionalFieldCount,
                ]);

                return $this->hydratePlanFromMaterial($planMaterial, $planHash);
            });
        } catch (StorageRejected $exception) {
            $this->emitRejected($actor, $source->schemaId, $source->version, $correlationId, $exception->reasonCode);
            throw $exception;
        } catch (QueryException $exception) {
            $this->emitRejected($actor, $source->schemaId, $source->version, $correlationId, 'storage_schema_migration_conflict');
            throw new StorageConflict('storage_schema_migration_conflict');
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function explain(string $planRef, string $actor): StorageSchemaMigrationPlan
    {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.explain');
        $correlationId = $this->correlationId(null);

        try {
            $this->assertPlanRef($planRef);
            return $this->loadVerifiedPlan($planRef, false);
        } catch (StorageRejected $exception) {
            $this->emitRejected($actor, 'unknown', 0, $correlationId, $exception->reasonCode);
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function apply(
        string $planRef,
        string $expectedPlanHash,
        string $actor,
        ?string $correlationId = null,
        ?StorageSchemaEvolutionTransactionScope $transactionScope = null,
        ?object $orchestrationCapability = null,
    ): StorageSchemaMigrationResult {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.schema_migration.dispatch');
        $correlationId = $this->correlationId($correlationId);
        $safePlanRef = null;

        try {
            $this->assertPlanRef($planRef);
            $safePlanRef = $planRef;
            if (preg_match('/^[a-f0-9]{64}$/', $expectedPlanHash) !== 1) {
                throw new StorageRejected('storage_schema_migration_plan_hash_invalid');
            }
            $this->assertOwnerOrchestrationForApply(
                $planRef,
                $expectedPlanHash,
                $actor,
                $transactionScope,
                $orchestrationCapability,
            );
            return $this->database->transaction(function () use ($planRef, $expectedPlanHash, $actor, $correlationId): StorageSchemaMigrationResult {
                $plan = $this->loadVerifiedPlan($planRef, true);
                if (!hash_equals($plan->planHash, $expectedPlanHash)) {
                    throw new StorageRejected('storage_schema_migration_plan_hash_mismatch');
                }
                if ($this->database->table('larena_storage_schema_migration_results')->where('plan_ref', $planRef)->exists()) {
                    throw new StorageRejected('storage_schema_migration_plan_already_applied');
                }

                $schemaHead = $this->database->table('larena_storage_schemas')
                    ->where('schema_id', $plan->source->schemaId)
                    ->lockForUpdate()
                    ->first();
                if (!$schemaHead instanceof stdClass
                    || (int) $schemaHead->current_version !== $plan->source->version
                    || !hash_equals((string) $schemaHead->current_hash, $plan->sourceHash)) {
                    throw new StorageRejected('storage_schema_migration_schema_head_stale');
                }

                $planRow = $this->database->table('larena_storage_schema_migration_plans')->where('plan_ref', $planRef)->first();
                if (!$planRow instanceof stdClass) {
                    throw new StorageRejected('storage_schema_migration_plan_unknown');
                }
                $targetDefinition = $this->normalizer->normalize(
                    $this->normalizer->decodeObject((string) $planRow->target_definition, 'storage_schema_migration_plan_tampered'),
                );
                $sourceDefinition = $this->loadVerifiedDefinition($plan->source);
                if (!hash_equals(
                    hash('sha256', $this->normalizer->canonicalJson($sourceDefinition)),
                    $plan->sourceHash,
                )) {
                    throw new StorageRejected('storage_schema_migration_plan_tampered');
                }
                $compatibility = $this->compatibility->analyze($sourceDefinition, $targetDefinition);
                if (!$compatibility['compatible']) {
                    throw new StorageRejected('storage_schema_migration_plan_tampered');
                }

                $recordHeads = $this->database->table('larena_storage_records')
                    ->where('schema_id', $plan->source->schemaId)
                    ->orderBy('record_id')
                    ->lockForUpdate()
                    ->get();
                $actualById = [];
                foreach ($recordHeads as $head) {
                    $actualById[(string) $head->record_id] = $head;
                }
                if (count($actualById) !== $plan->recordCount || count($plan->records) !== $plan->recordCount) {
                    throw new StorageRejected('storage_schema_migration_record_heads_stale');
                }

                $targetSchema = new StorageSchemaVersion(
                    $plan->target,
                    $targetDefinition['owner_package'],
                    $targetDefinition['fields'],
                    $plan->targetHash,
                    $actor,
                    $correlationId,
                    $this->timestamp(),
                );
                $versionRows = [];
                foreach ($plan->records as $expected) {
                    $head = $actualById[$expected->before->recordId] ?? null;
                    if (!$head instanceof stdClass
                        || (string) $head->owner_ref !== $expected->ownerRef
                        || (int) $head->current_revision !== $expected->before->revision
                        || (int) $head->current_schema_version !== $expected->schemaVersion
                        || !hash_equals((string) $head->current_hash, $expected->contentHash)) {
                        throw new StorageRejected('storage_schema_migration_record_heads_stale');
                    }
                    $version = $this->database->table('larena_storage_record_versions')
                        ->where('schema_id', $expected->before->schemaId)
                        ->where('record_id', $expected->before->recordId)
                        ->where('revision', $expected->before->revision)
                        ->first();
                    if (!$version instanceof stdClass
                        || (string) $version->owner_ref !== $expected->ownerRef
                        || (int) $version->schema_version !== $expected->schemaVersion
                        || !hash_equals((string) $version->content_hash, $expected->contentHash)) {
                        throw new StorageRejected('storage_schema_migration_record_incompatible');
                    }
                    $values = $this->normalizer->decodeObject((string) $version->values_json, 'storage_record_values_corrupt');
                    if (!hash_equals(hash('sha256', $this->normalizer->canonicalJson($values)), $expected->contentHash)) {
                        throw new StorageRejected('storage_schema_migration_record_incompatible');
                    }
                    $normalized = $this->normalizer->normalizeValues($targetSchema, $values);
                    if ($this->normalizer->canonicalJson($normalized) !== $this->normalizer->canonicalJson($values)) {
                        throw new StorageRejected('storage_schema_migration_record_incompatible');
                    }
                    $versionRows[$expected->before->recordId] = ['head' => $head, 'values_json' => $this->normalizer->canonicalJson($values)];
                }

                $now = $this->timestamp();
                $updatedSchema = $this->database->table('larena_storage_schemas')
                    ->where('schema_id', $plan->source->schemaId)
                    ->where('current_version', $plan->source->version)
                    ->where('current_hash', $plan->sourceHash)
                    ->update(['current_version' => $plan->target->version, 'current_hash' => $plan->targetHash, 'updated_at' => $now]);
                if ($updatedSchema !== 1) {
                    throw new StorageConflict('storage_schema_migration_conflict');
                }
                $this->database->table('larena_storage_schema_versions')->insert([
                    'schema_id' => $plan->target->schemaId,
                    'version' => $plan->target->version,
                    'definition' => $this->normalizer->canonicalJson($targetDefinition),
                    'definition_hash' => $plan->targetHash,
                    'owner_package' => $targetDefinition['owner_package'],
                    'created_by' => $actor,
                    'correlation_id' => $correlationId,
                    'created_at' => $now,
                ]);

                $resultRecords = [];
                $resultMaterial = [];
                foreach ($plan->records as $expected) {
                    $stored = $versionRows[$expected->before->recordId];
                    $nextRevision = $expected->before->revision + 1;
                    $updatedRecord = $this->database->table('larena_storage_records')
                        ->where('record_id', $expected->before->recordId)
                        ->where('schema_id', $expected->before->schemaId)
                        ->where('owner_ref', $expected->ownerRef)
                        ->where('current_revision', $expected->before->revision)
                        ->where('current_schema_version', $expected->schemaVersion)
                        ->where('current_hash', $expected->contentHash)
                        ->update([
                            'current_revision' => $nextRevision,
                            'current_schema_version' => $plan->target->version,
                            'current_hash' => $expected->contentHash,
                            'updated_at' => $now,
                        ]);
                    if ($updatedRecord !== 1) {
                        throw new StorageConflict('storage_schema_migration_conflict');
                    }
                    $this->database->table('larena_storage_record_versions')->insert([
                        'schema_id' => $expected->before->schemaId,
                        'record_id' => $expected->before->recordId,
                        'revision' => $nextRevision,
                        'owner_ref' => $expected->ownerRef,
                        'schema_version' => $plan->target->version,
                        'values_json' => $stored['values_json'],
                        'content_hash' => $expected->contentHash,
                        'operation' => 'schema_migration',
                        'created_by' => $actor,
                        'correlation_id' => $correlationId,
                        'created_at' => $now,
                    ]);
                    $after = new StorageRecordVersionRef($expected->before->schemaId, $expected->before->recordId, $nextRevision);
                    $resultRecords[] = new StorageSchemaMigrationRecordResult($expected->ownerRef, $expected->before, $after, $expected->contentHash);
                    $resultMaterial[] = [
                        'record_id' => $expected->before->recordId,
                        'owner_ref' => $expected->ownerRef,
                        'from_revision' => $expected->before->revision,
                        'to_revision' => $nextRevision,
                        'target_schema_version' => $plan->target->version,
                        'content_hash' => $expected->contentHash,
                    ];
                }

                $migratedRecordsHash = hash('sha256', $this->normalizer->canonicalJson($resultMaterial));
                $resultRef = 'storage-result-' . bin2hex(random_bytes(16));
                $resultHash = hash('sha256', $this->normalizer->canonicalJson([
                    'result_ref' => $resultRef,
                    'plan_ref' => $planRef,
                    'target' => $plan->target->key(),
                    'target_hash' => $plan->targetHash,
                    'migrated_record_count' => count($resultMaterial),
                    'migrated_records_hash' => $migratedRecordsHash,
                    'records' => $resultMaterial,
                    'applied_by' => $actor,
                    'correlation_id' => $correlationId,
                    'applied_at' => $now,
                ]));
                $this->database->table('larena_storage_schema_migration_results')->insert([
                    'result_ref' => $resultRef,
                    'plan_ref' => $planRef,
                    'schema_id' => $plan->target->schemaId,
                    'target_version' => $plan->target->version,
                    'target_hash' => $plan->targetHash,
                    'migrated_record_count' => count($resultMaterial),
                    'migrated_records_hash' => $migratedRecordsHash,
                    'result_hash' => $resultHash,
                    'applied_by' => $actor,
                    'correlation_id' => $correlationId,
                    'applied_at' => $now,
                ]);
                foreach ($resultMaterial as $record) {
                    $this->database->table('larena_storage_schema_migration_result_records')->insert(['result_ref' => $resultRef] + $record);
                }
                $this->emit('storage.schema_migration.applied', $actor, $plan->target->schemaId, $correlationId, [
                    'schema_id' => $plan->target->schemaId,
                    'source_version' => $plan->source->version,
                    'target_version' => $plan->target->version,
                    'plan_ref' => $planRef,
                    'plan_hash' => $plan->planHash,
                    'result_ref' => $resultRef,
                    'result_hash' => $resultHash,
                    'record_count' => count($resultMaterial),
                ]);

                return new StorageSchemaMigrationResult(
                    $resultRef,
                    $resultHash,
                    $planRef,
                    $plan->target,
                    $plan->targetHash,
                    count($resultMaterial),
                    $migratedRecordsHash,
                    $resultRecords,
                    $now,
                );
            });
        } catch (StorageRejected $exception) {
            $this->emitRejected($actor, 'unknown', 0, $correlationId, $exception->reasonCode, $safePlanRef);
            throw $exception;
        } catch (QueryException $exception) {
            $this->emitRejected($actor, 'unknown', 0, $correlationId, 'storage_schema_migration_conflict', $safePlanRef);
            throw new StorageConflict('storage_schema_migration_conflict');
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * @param array<string, mixed> $candidateDefinition
     * @return array{report:StorageSchemaCompatibilityReport,target_definition:array{schema_id:string,owner_package:string,fields:list<array<string,mixed>>},records:list<array<string,mixed>>}
     */
    private function snapshot(StorageSchemaVersionRef $source, array $candidateDefinition, bool $lock): array
    {
        $headQuery = $this->database->table('larena_storage_schemas')->where('schema_id', $source->schemaId);
        if ($lock) {
            $headQuery->lockForUpdate();
        }
        $head = $headQuery->first();
        if (!$head instanceof stdClass
            || (int) $head->current_version !== $source->version) {
            throw new StorageRejected('storage_schema_migration_source_not_head');
        }
        $sourceDefinition = $this->loadVerifiedDefinition($source);
        $sourceHash = hash('sha256', $this->normalizer->canonicalJson($sourceDefinition));
        if (!hash_equals((string) $head->current_hash, $sourceHash)) {
            throw new StorageRejected('storage_schema_migration_plan_tampered');
        }
        $targetDefinition = $this->normalizer->normalize($candidateDefinition);
        $targetHash = hash('sha256', $this->normalizer->canonicalJson($targetDefinition));
        $compatibility = $this->compatibility->analyze($sourceDefinition, $targetDefinition);

        $recordsQuery = $this->database->table('larena_storage_records')
            ->where('schema_id', $source->schemaId)
            ->orderBy('record_id');
        if ($lock) {
            $recordsQuery->lockForUpdate();
        }
        $records = [];
        foreach ($recordsQuery->get() as $headRow) {
            $version = $this->database->table('larena_storage_record_versions')
                ->where('schema_id', $source->schemaId)
                ->where('record_id', (string) $headRow->record_id)
                ->where('revision', (int) $headRow->current_revision)
                ->first();
            if (!$version instanceof stdClass
                || (int) $headRow->current_schema_version !== $source->version
                || (int) $version->schema_version !== $source->version
                || (string) $version->owner_ref !== (string) $headRow->owner_ref
                || !hash_equals((string) $headRow->current_hash, (string) $version->content_hash)) {
                throw new StorageRejected('storage_schema_migration_record_incompatible');
            }
            $values = $this->normalizer->decodeObject((string) $version->values_json, 'storage_record_values_corrupt');
            if (!hash_equals(hash('sha256', $this->normalizer->canonicalJson($values)), (string) $headRow->current_hash)) {
                throw new StorageRejected('storage_schema_migration_record_incompatible');
            }
            if ($compatibility['compatible']) {
                $targetSchema = new StorageSchemaVersion(
                    new StorageSchemaVersionRef($source->schemaId, $source->version + 1),
                    $targetDefinition['owner_package'],
                    $targetDefinition['fields'],
                    $targetHash,
                    'system:analysis',
                    null,
                    $this->timestamp(),
                );
                try {
                    $normalized = $this->normalizer->normalizeValues($targetSchema, $values);
                } catch (StorageRejected) {
                    $compatibility['compatible'] = false;
                    $compatibility['compatibility_class'] = 'incompatible';
                    $compatibility['reason_codes'][] = 'storage_schema_migration_record_incompatible';
                    $normalized = [];
                }
                if ($compatibility['compatible']
                    && $this->normalizer->canonicalJson($normalized) !== $this->normalizer->canonicalJson($values)) {
                    $compatibility['compatible'] = false;
                    $compatibility['compatibility_class'] = 'incompatible';
                    $compatibility['reason_codes'][] = 'storage_schema_migration_record_incompatible';
                }
            }
            $records[] = [
                'record_id' => (string) $headRow->record_id,
                'owner_ref' => (string) $headRow->owner_ref,
                'expected_revision' => (int) $headRow->current_revision,
                'expected_schema_version' => (int) $headRow->current_schema_version,
                'expected_content_hash' => (string) $headRow->current_hash,
            ];
        }
        $recordHeadsHash = hash('sha256', $this->normalizer->canonicalJson($records));
        $reasonCodes = array_values(array_unique($compatibility['reason_codes']));
        $report = new StorageSchemaCompatibilityReport(
            $source,
            $sourceHash,
            new StorageSchemaVersionRef($source->schemaId, $source->version + 1),
            $targetHash,
            $compatibility['compatible'],
            $compatibility['compatibility_class'],
            $compatibility['added_optional_count'],
            count($records),
            $recordHeadsHash,
            $reasonCodes,
        );

        return ['report' => $report, 'target_definition' => $targetDefinition, 'records' => $records];
    }

    /** @return array{schema_id:string,owner_package:string,fields:list<array<string,mixed>>} */
    private function loadVerifiedDefinition(StorageSchemaVersionRef $ref): array
    {
        $row = $this->database->table('larena_storage_schema_versions')
            ->where('schema_id', $ref->schemaId)
            ->where('version', $ref->version)
            ->first();
        if (!$row instanceof stdClass) {
            throw new StorageRejected('storage_schema_version_unknown');
        }
        $definition = $this->normalizer->normalize(
            $this->normalizer->decodeObject((string) $row->definition, 'storage_schema_definition_corrupt'),
        );
        $hash = hash('sha256', $this->normalizer->canonicalJson($definition));
        if (!hash_equals((string) $row->definition_hash, $hash)
            || (string) $row->schema_id !== $definition['schema_id']
            || (string) $row->owner_package !== $definition['owner_package']) {
            throw new StorageRejected('storage_schema_migration_plan_tampered');
        }

        return $definition;
    }

    /** @param array<string, mixed> $candidateDefinition */
    private function assertOwnerOrchestrationForPlan(
        StorageSchemaVersionRef $source,
        array $candidateDefinition,
        string $actor,
        ?StorageSchemaEvolutionTransactionScope $transactionScope,
        ?object $orchestrationCapability,
    ): void {
        $sourceDefinition = $this->loadVerifiedDefinition($source);
        $targetDefinition = $this->normalizer->normalize($candidateDefinition);
        $this->ownerPolicies->authorize(
            $sourceDefinition['owner_package'],
            new StorageSchemaEvolutionOwnerContext(
                operation: 'plan',
                actor: $actor,
                source: $source,
                sourceHash: hash('sha256', $this->normalizer->canonicalJson($sourceDefinition)),
                targetHash: hash('sha256', $this->normalizer->canonicalJson($targetDefinition)),
                planRef: null,
                planHash: null,
                connection: $this->database,
                transactionScope: $transactionScope,
            ),
            $orchestrationCapability,
        );
    }

    private function assertOwnerOrchestrationForApply(
        string $planRef,
        string $expectedPlanHash,
        string $actor,
        ?StorageSchemaEvolutionTransactionScope $transactionScope,
        ?object $orchestrationCapability,
    ): void {
        $plan = $this->loadVerifiedPlan($planRef, false);
        $sourceDefinition = $this->loadVerifiedDefinition($plan->source);
        $this->ownerPolicies->authorize(
            $sourceDefinition['owner_package'],
            new StorageSchemaEvolutionOwnerContext(
                operation: 'apply',
                actor: $actor,
                source: $plan->source,
                sourceHash: $plan->sourceHash,
                targetHash: $plan->targetHash,
                planRef: $planRef,
                planHash: $expectedPlanHash,
                connection: $this->database,
                transactionScope: $transactionScope,
            ),
            $orchestrationCapability,
        );
    }

    private function loadVerifiedPlan(string $planRef, bool $lock): StorageSchemaMigrationPlan
    {
        $query = $this->database->table('larena_storage_schema_migration_plans')->where('plan_ref', $planRef);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (!$row instanceof stdClass) {
            throw new StorageRejected('storage_schema_migration_plan_unknown');
        }
        $targetDefinition = $this->normalizer->normalize(
            $this->normalizer->decodeObject((string) $row->target_definition, 'storage_schema_migration_plan_tampered'),
        );
        if (!hash_equals(hash('sha256', $this->normalizer->canonicalJson($targetDefinition)), (string) $row->target_hash)) {
            throw new StorageRejected('storage_schema_migration_plan_tampered');
        }
        $records = [];
        foreach ($this->database->table('larena_storage_schema_migration_plan_records')->where('plan_ref', $planRef)->orderBy('record_id')->get() as $item) {
            $records[] = [
                'record_id' => (string) $item->record_id,
                'owner_ref' => (string) $item->owner_ref,
                'expected_revision' => (int) $item->expected_revision,
                'expected_schema_version' => (int) $item->expected_schema_version,
                'expected_content_hash' => (string) $item->expected_content_hash,
            ];
        }
        $material = [
            'plan_ref' => (string) $row->plan_ref,
            'schema_id' => (string) $row->schema_id,
            'source_version' => (int) $row->source_version,
            'source_hash' => (string) $row->source_hash,
            'target_version' => (int) $row->target_version,
            'target_hash' => (string) $row->target_hash,
            'target_definition' => $targetDefinition,
            'compatibility_class' => (string) $row->compatibility_class,
            'added_optional_count' => (int) $row->added_optional_count,
            'record_count' => (int) $row->record_count,
            'record_heads_hash' => (string) $row->record_heads_hash,
            'created_by' => (string) $row->created_by,
            'correlation_id' => $row->correlation_id === null ? null : (string) $row->correlation_id,
            'created_at' => (string) $row->created_at,
            'records' => $records,
        ];
        $hash = hash('sha256', $this->normalizer->canonicalJson($material));
        if (!hash_equals((string) $row->plan_hash, $hash)
            || count($records) !== (int) $row->record_count
            || !hash_equals(hash('sha256', $this->normalizer->canonicalJson($records)), (string) $row->record_heads_hash)) {
            throw new StorageRejected('storage_schema_migration_plan_tampered');
        }

        return $this->hydratePlanFromMaterial($material, $hash);
    }

    /** @param array<string, mixed> $material */
    private function hydratePlanFromMaterial(array $material, string $planHash): StorageSchemaMigrationPlan
    {
        $records = [];
        foreach ($material['records'] as $record) {
            $records[] = new StorageSchemaMigrationRecordHead(
                $record['owner_ref'],
                new StorageRecordVersionRef($material['schema_id'], $record['record_id'], $record['expected_revision']),
                $record['expected_schema_version'],
                $record['expected_content_hash'],
            );
        }

        return new StorageSchemaMigrationPlan(
            $material['plan_ref'],
            $planHash,
            new StorageSchemaVersionRef($material['schema_id'], $material['source_version']),
            $material['source_hash'],
            new StorageSchemaVersionRef($material['schema_id'], $material['target_version']),
            $material['target_hash'],
            $material['compatibility_class'],
            $material['added_optional_count'],
            $material['record_count'],
            $material['record_heads_hash'],
            $records,
            $material['created_at'],
        );
    }

    /** @param list<array<string, mixed>> $records @return list<array<string, mixed>> */
    private function recordMaterial(array $records): array
    {
        usort($records, static fn (array $left, array $right): int => $left['record_id'] <=> $right['record_id']);

        return $records;
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $type, string $actor, string $schemaId, string $correlationId, array $payload): void
    {
        $descriptor = new StorageSchemaMigrationAuditEventDescriptor($type);
        $this->audit->route($descriptor, AuditEvent::create(
            sourcePackage: $descriptor->sourcePackage(),
            category: $descriptor->category(),
            type: $descriptor->type(),
            actor: $actor,
            subject: 'storage-schema:' . $schemaId,
            severity: AuditSeverity::Security,
            retentionClass: AuditRetentionClass::Security,
            correlationId: $correlationId,
            payload: $payload,
        ));
    }

    private function emitRejected(
        string $actor,
        string $schemaId,
        int $sourceVersion,
        string $correlationId,
        string $reasonCode,
        ?string $planRef = null,
    ): void {
        $payload = [
            'schema_id' => $schemaId,
            'source_version' => $sourceVersion,
            'reason_code' => $reasonCode,
        ];
        if ($planRef !== null) {
            $payload['plan_ref'] = $planRef;
        }
        try {
            $this->emit('storage.schema_migration.rejected', $actor, $schemaId, $correlationId, $payload);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 191) {
            throw new InvalidArgumentException('storage_actor_invalid');
        }
    }

    private function assertPlanRef(string $planRef): void
    {
        if (preg_match('/^storage-migration-[a-f0-9]{32}$/', $planRef) !== 1) {
            throw new StorageRejected('storage_schema_migration_plan_ref_invalid');
        }
    }

    private function correlationId(?string $correlationId): string
    {
        if ($correlationId === null) {
            return 'storage-migration-' . bin2hex(random_bytes(12));
        }
        if (trim($correlationId) === '' || strlen($correlationId) > 191) {
            throw new InvalidArgumentException('storage_correlation_id_invalid');
        }

        return 'storage-migration-' . hash('sha256', $correlationId);
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
