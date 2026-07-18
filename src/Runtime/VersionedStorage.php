<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Audit\StorageVersionAuditEventDescriptor;
use Larena\Storage\Contracts\StoragePublicProjection;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWriteResult;
use Larena\Storage\Contracts\VersionedStorage as VersionedStorageContract;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use stdClass;
use Throwable;

final readonly class VersionedStorage implements VersionedStorageContract
{
    private SchemaDefinitionNormalizer $normalizer;

    public function __construct(
        private ConnectionInterface $database,
        PropertyTypeRegistry $propertyTypes,
        private ActorOperationAuthorizer $authorizer,
        private AuditEventPipeline $audit,
    ) {
        $this->normalizer = new SchemaDefinitionNormalizer($propertyTypes);
    }

    public function connection(): ConnectionInterface
    {
        return $this->database;
    }

    public function registerSchemaVersion(
        array $definition,
        ?int $expectedHeadVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageSchemaVersion {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed(
            $actor,
            $expectedHeadVersion === null ? 'storage.schema.create' : 'storage.schema.version',
        );
        if ($expectedHeadVersion !== null) {
            $safeCorrelationId = $this->correlationId($correlationId, 'storage-schema');
            $safeSchemaId = $this->safeSchemaId($definition);
            try {
                $this->emit(
                    'storage.schema.version_rejected',
                    $actor,
                    'storage-schema:' . $safeSchemaId,
                    $safeCorrelationId,
                    [
                        'schema_id' => $safeSchemaId,
                        'expected_head_version' => $expectedHeadVersion,
                        'reason_code' => 'storage_schema_version_requires_migration_plan',
                    ],
                );
            } catch (Throwable $exception) {
                throw StoragePersistenceFailed::from($exception);
            }
            throw new StorageRejected('storage_schema_version_requires_migration_plan');
        }
        $normalized = $this->normalizer->normalize($definition);
        $schemaId = $normalized['schema_id'];
        $ownerPackage = $normalized['owner_package'];
        $fields = $normalized['fields'];
        $definitionJson = $this->canonicalJson($normalized);
        $definitionHash = hash('sha256', $definitionJson);
        $correlationId = $this->correlationId($correlationId, 'storage-schema');

        try {
            return $this->database->transaction(function () use (
                $schemaId,
                $ownerPackage,
                $fields,
                $definitionJson,
                $definitionHash,
                $actor,
                $correlationId,
            ): StorageSchemaVersion {
                $now = $this->timestamp();
                if ($this->database->table('larena_storage_schemas')->where('schema_id', $schemaId)->exists()) {
                    throw new StorageConflict('storage_schema_already_exists');
                }
                $version = 1;
                $this->database->table('larena_storage_schemas')->insert([
                    'schema_id' => $schemaId,
                    'current_version' => $version,
                    'current_hash' => $definitionHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->database->table('larena_storage_schema_versions')->insert([
                    'schema_id' => $schemaId,
                    'version' => $version,
                    'definition' => $definitionJson,
                    'definition_hash' => $definitionHash,
                    'owner_package' => $ownerPackage,
                    'created_by' => $actor,
                    'correlation_id' => $correlationId,
                    'created_at' => $now,
                ]);

                $schema = new StorageSchemaVersion(
                    new StorageSchemaVersionRef($schemaId, $version),
                    $ownerPackage,
                    $fields,
                    $definitionHash,
                    $actor,
                    $correlationId,
                    $now,
                );
                $this->emit('storage.schema.created', $actor, 'storage-schema:' . $schema->ref->key(), $correlationId, [
                    'schema_id' => $schemaId,
                    'schema_version' => $version,
                    'owner_package' => $ownerPackage,
                    'field_count' => count($fields),
                ]);

                return $schema;
            });
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintConflict($exception)) {
                throw new StorageConflict('storage_schema_version_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function create(
        string $ownerRef,
        StorageSchemaVersionRef $schema,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWriteResult {
        $this->assertOwnerRef($ownerRef);
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.record.create');
        $correlationId = $this->correlationId($correlationId, 'storage-record');

        try {
            return $this->database->transaction(function () use ($ownerRef, $schema, $values, $actor, $correlationId): StorageWriteResult {
                $schemaHead = $this->database->table('larena_storage_schemas')
                    ->where('schema_id', $schema->schemaId)
                    ->lockForUpdate()
                    ->first();
                $schemaVersion = $this->schemaVersion($schema);
                if (!$schemaHead instanceof stdClass
                    || (int) $schemaHead->current_version !== $schema->version
                    || !hash_equals((string) $schemaHead->current_hash, $schemaVersion->definitionHash)) {
                    throw new StorageRejected('storage_record_schema_not_current');
                }
                $normalizedValues = $this->normalizer->normalizeValues($schemaVersion, $values);
                $valuesJson = $this->canonicalJson($normalizedValues);
                $contentHash = hash('sha256', $valuesJson);
                $recordId = 'record-' . bin2hex(random_bytes(16));
                $now = $this->timestamp();

                $this->database->table('larena_storage_records')->insert([
                    'record_id' => $recordId,
                    'schema_id' => $schema->schemaId,
                    'owner_ref' => $ownerRef,
                    'current_revision' => 1,
                    'current_schema_version' => $schema->version,
                    'current_hash' => $contentHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->insertRecordVersion(
                    $schema,
                    $recordId,
                    1,
                    $ownerRef,
                    $valuesJson,
                    $contentHash,
                    'create',
                    $actor,
                    $correlationId,
                    $now,
                );

                $record = new StorageRecordVersion(
                    new StorageRecordVersionRef($schema->schemaId, $recordId, 1),
                    $ownerRef,
                    $schema,
                    $normalizedValues,
                    $contentHash,
                    'create',
                    $actor,
                    $correlationId,
                    $now,
                );
                $this->emitRecord('storage.record.created', $record, $actor, $correlationId);

                return new StorageWriteResult($record);
            });
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintConflict($exception)) {
                throw new StorageConflict('storage_record_owner_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function compareAndSwap(
        string $ownerRef,
        StorageRecordVersionRef $expected,
        StorageSchemaVersionRef $schema,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWriteResult {
        $this->assertOwnerRef($ownerRef);
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.record.update');

        return $this->writeNextVersion(
            ownerRef: $ownerRef,
            expected: $expected,
            schema: $schema,
            values: $values,
            actor: $actor,
            correlationId: $this->correlationId($correlationId, 'storage-record'),
        );
    }

    public function schemaVersion(StorageSchemaVersionRef $ref): StorageSchemaVersion
    {
        try {
            $row = $this->database->table('larena_storage_schema_versions')
                ->where('schema_id', $ref->schemaId)
                ->where('version', $ref->version)
                ->first();
            if (!$row instanceof stdClass) {
                throw new StorageRejected('storage_schema_version_unknown');
            }

            return $this->hydrateSchemaVersion($row);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function readAdminVersion(StorageRecordVersionRef $ref, string $actor): StorageRecordVersion
    {
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.record.read');

        return $this->recordVersionInternal($ref);
    }

    public function readAdminCurrentVersion(
        string $schemaId,
        string $ownerRef,
        string $actor,
    ): ?StorageRecordVersion {
        $this->assertSchemaId($schemaId);
        $this->assertOwnerRef($ownerRef);
        $this->assertActor($actor);
        $this->authorizer->assertAllowed($actor, 'storage.record.read');

        try {
            $head = $this->database->table('larena_storage_records')
                ->where('schema_id', $schemaId)
                ->where('owner_ref', $ownerRef)
                ->first();
            if (!$head instanceof stdClass) {
                return null;
            }

            $record = $this->recordVersionInternal(new StorageRecordVersionRef(
                $schemaId,
                (string) $head->record_id,
                (int) $head->current_revision,
            ));
            if ($record->ownerRef !== $ownerRef
                || $record->schema->version !== (int) $head->current_schema_version
                || !hash_equals($record->contentHash, (string) $head->current_hash)) {
                throw new StorageRejected('storage_record_head_corrupt');
            }

            return $record;
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    public function projectPublicVersion(StorageRecordVersionRef $ref): StoragePublicProjection
    {
        $record = $this->recordVersionInternal($ref);
        $schema = $this->schemaVersion($record->schema);
        $public = [];
        foreach ($schema->fields as $field) {
            $key = (string) $field['key'];
            if (($field['visibility'] ?? null) === 'public' && array_key_exists($key, $record->values)) {
                $public[$key] = $record->values[$key];
            }
        }

        return new StoragePublicProjection($record->ref, $record->ownerRef, $record->schema, $public);
    }

    private function recordVersionInternal(StorageRecordVersionRef $ref): StorageRecordVersion
    {
        try {
            $row = $this->database->table('larena_storage_record_versions')
                ->where('schema_id', $ref->schemaId)
                ->where('record_id', $ref->recordId)
                ->where('revision', $ref->revision)
                ->first();
            if (!$row instanceof stdClass) {
                throw new StorageRejected('storage_record_version_unknown');
            }

            return $this->hydrateRecordVersion($row);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function writeNextVersion(
        string $ownerRef,
        StorageRecordVersionRef $expected,
        StorageSchemaVersionRef $schema,
        array $values,
        string $actor,
        string $correlationId,
    ): StorageWriteResult {
        try {
            return $this->database->transaction(function () use (
                $ownerRef,
                $expected,
                $schema,
                $values,
                $actor,
                $correlationId,
            ): StorageWriteResult {
                $schemaHead = $this->database->table('larena_storage_schemas')
                    ->where('schema_id', $schema->schemaId)
                    ->lockForUpdate()
                    ->first();
                $schemaVersion = $this->schemaVersion($schema);
                if (!$schemaHead instanceof stdClass
                    || (int) $schemaHead->current_version !== $schema->version
                    || !hash_equals((string) $schemaHead->current_hash, $schemaVersion->definitionHash)) {
                    throw new StorageRejected('storage_record_schema_not_current');
                }
                $head = $this->database->table('larena_storage_records')
                    ->where('record_id', $expected->recordId)
                    ->where('schema_id', $expected->schemaId)
                    ->lockForUpdate()
                    ->first();
                if (!$head instanceof stdClass) {
                    throw new StorageRejected('storage_record_unknown');
                }
                if ((string) $head->owner_ref !== $ownerRef) {
                    throw new StorageRejected('storage_record_owner_mismatch');
                }
                if ($schemaVersion->ref->schemaId !== $expected->schemaId) {
                    throw new StorageRejected('storage_record_schema_mismatch');
                }
                if ((int) $head->current_revision !== $expected->revision) {
                    throw new StorageConflict('storage_record_revision_conflict');
                }
                $expectedVersion = $this->recordVersionInternal($expected);
                if ($expectedVersion->ownerRef !== $ownerRef
                    || $expectedVersion->schema->version !== (int) $head->current_schema_version
                    || $expectedVersion->schema->version !== $schema->version
                    || !hash_equals($expectedVersion->contentHash, (string) $head->current_hash)) {
                    throw new StorageRejected('storage_record_head_corrupt');
                }
                $normalizedValues = $this->normalizer->normalizeValues($schemaVersion, $values);
                $valuesJson = $this->canonicalJson($normalizedValues);
                $contentHash = hash('sha256', $valuesJson);
                $nextRevision = $expected->revision + 1;
                $now = $this->timestamp();

                $updated = $this->database->table('larena_storage_records')
                    ->where('record_id', $expected->recordId)
                    ->where('schema_id', $expected->schemaId)
                    ->where('owner_ref', $ownerRef)
                    ->where('current_revision', $expected->revision)
                    ->update([
                        'current_revision' => $nextRevision,
                        'current_schema_version' => $schema->version,
                        'current_hash' => $contentHash,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new StorageConflict('storage_record_revision_conflict');
                }

                $this->insertRecordVersion(
                    $schema,
                    $expected->recordId,
                    $nextRevision,
                    $ownerRef,
                    $valuesJson,
                    $contentHash,
                    'update',
                    $actor,
                    $correlationId,
                    $now,
                );
                $record = new StorageRecordVersion(
                    new StorageRecordVersionRef($schema->schemaId, $expected->recordId, $nextRevision),
                    $ownerRef,
                    $schema,
                    $normalizedValues,
                    $contentHash,
                    'update',
                    $actor,
                    $correlationId,
                    $now,
                );
                $this->emitRecord('storage.record.updated', $record, $actor, $correlationId);

                return new StorageWriteResult($record);
            });
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintConflict($exception) || $this->isLockConflict($exception)) {
                throw new StorageConflict('storage_record_revision_conflict');
            }
            throw StoragePersistenceFailed::from($exception);
        } catch (Throwable $exception) {
            throw StoragePersistenceFailed::from($exception);
        }
    }

    private function insertRecordVersion(
        StorageSchemaVersionRef $schema,
        string $recordId,
        int $revision,
        string $ownerRef,
        string $valuesJson,
        string $contentHash,
        string $operation,
        string $actor,
        string $correlationId,
        string $createdAt,
    ): void {
        $this->database->table('larena_storage_record_versions')->insert([
            'schema_id' => $schema->schemaId,
            'record_id' => $recordId,
            'revision' => $revision,
            'owner_ref' => $ownerRef,
            'schema_version' => $schema->version,
            'values_json' => $valuesJson,
            'content_hash' => $contentHash,
            'operation' => $operation,
            'created_by' => $actor,
            'correlation_id' => $correlationId,
            'created_at' => $createdAt,
        ]);
    }

    private function hydrateSchemaVersion(stdClass $row): StorageSchemaVersion
    {
        try {
            $definition = $this->normalizer->normalize(
                $this->decodeObject((string) $row->definition, 'storage_schema_definition_corrupt'),
                false,
            );
        } catch (StorageRejected) {
            throw new StorageRejected('storage_schema_definition_corrupt');
        }
        $definitionHash = hash('sha256', $this->canonicalJson($definition));
        if (!hash_equals((string) $row->definition_hash, $definitionHash)
            || (string) $row->schema_id !== $definition['schema_id']
            || (string) $row->owner_package !== $definition['owner_package']) {
            throw new StorageRejected('storage_schema_definition_corrupt');
        }

        return new StorageSchemaVersion(
            new StorageSchemaVersionRef((string) $row->schema_id, (int) $row->version),
            (string) $row->owner_package,
            $definition['fields'],
            $definitionHash,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
        );
    }

    private function hydrateRecordVersion(stdClass $row): StorageRecordVersion
    {
        $values = $this->decodeObject((string) $row->values_json, 'storage_record_values_corrupt');
        $contentHash = hash('sha256', $this->canonicalJson($values));
        if (!hash_equals((string) $row->content_hash, $contentHash)) {
            throw new StorageRejected('storage_record_values_corrupt');
        }

        return new StorageRecordVersion(
            new StorageRecordVersionRef((string) $row->schema_id, (string) $row->record_id, (int) $row->revision),
            (string) $row->owner_ref,
            new StorageSchemaVersionRef((string) $row->schema_id, (int) $row->schema_version),
            $values,
            $contentHash,
            (string) $row->operation,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
        );
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $reason): array
    {
        return $this->normalizer->decodeObject($json, $reason);
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $eventType, string $actor, string $subject, string $correlationId, array $payload): void
    {
        $descriptor = new StorageVersionAuditEventDescriptor($eventType);
        $this->audit->route($descriptor, AuditEvent::create(
            sourcePackage: $descriptor->sourcePackage(),
            category: $descriptor->category(),
            type: $descriptor->type(),
            actor: $actor,
            subject: $subject,
            severity: AuditSeverity::Security,
            retentionClass: AuditRetentionClass::Security,
            correlationId: $correlationId,
            payload: $payload,
        ));
    }

    private function emitRecord(string $eventType, StorageRecordVersion $record, string $actor, string $correlationId): void
    {
        $this->emit($eventType, $actor, 'storage-record:' . $record->ref->recordId, $correlationId, [
            'schema_id' => $record->ref->schemaId,
            'schema_version' => $record->schema->version,
            'record_id' => $record->ref->recordId,
            'record_revision' => $record->ref->revision,
            'operation' => $record->operation,
            'field_count' => count($record->values),
        ]);
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 191) {
            throw new InvalidArgumentException('storage_actor_invalid');
        }
    }

    private function assertOwnerRef(string $ownerRef): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,190}$/', $ownerRef) !== 1) {
            throw new InvalidArgumentException('storage_owner_ref_invalid');
        }
    }

    private function assertSchemaId(string $schemaId): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaId) !== 1) {
            throw new InvalidArgumentException('storage_schema_id_invalid');
        }
    }

    /** @param array<string, mixed> $definition */
    private function safeSchemaId(array $definition): string
    {
        $schemaId = is_string($definition['schema_id'] ?? null) ? trim($definition['schema_id']) : '';

        return preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaId) === 1 ? $schemaId : 'unknown';
    }

    private function correlationId(?string $correlationId, string $prefix): string
    {
        if ($correlationId === null) {
            return $prefix . '-' . bin2hex(random_bytes(12));
        }
        if (trim($correlationId) === '' || strlen($correlationId) > 191) {
            throw new InvalidArgumentException('storage_correlation_id_invalid');
        }

        return $prefix . '-' . hash('sha256', $correlationId);
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
}
