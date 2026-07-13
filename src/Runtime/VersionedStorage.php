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
use stdClass;
use Throwable;

final readonly class VersionedStorage implements VersionedStorageContract
{
    public function __construct(
        private ConnectionInterface $database,
        private PropertyTypeRegistry $propertyTypes,
        private ActorOperationAuthorizer $authorizer,
        private AuditEventPipeline $audit,
    ) {
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
        $accessOperation = $expectedHeadVersion === null ? 'storage.schema.create' : 'storage.schema.version';
        $auditEvent = $expectedHeadVersion === null ? 'storage.schema.created' : 'storage.schema.versioned';
        $this->authorizer->assertAllowed($actor, $accessOperation);
        $normalized = $this->normalizeSchemaDefinition($definition);
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
                $expectedHeadVersion,
                $actor,
                $correlationId,
                $auditEvent,
            ): StorageSchemaVersion {
                $now = $this->timestamp();
                if ($expectedHeadVersion === null) {
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
                } else {
                    if ($expectedHeadVersion < 1) {
                        throw new StorageRejected('storage_schema_expected_version_invalid');
                    }
                    $version = $expectedHeadVersion + 1;
                    $updated = $this->database->table('larena_storage_schemas')
                        ->where('schema_id', $schemaId)
                        ->where('current_version', $expectedHeadVersion)
                        ->update([
                            'current_version' => $version,
                            'current_hash' => $definitionHash,
                            'updated_at' => $now,
                        ]);
                    if ($updated !== 1) {
                        throw new StorageConflict('storage_schema_version_conflict');
                    }
                }

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
                $this->emit($auditEvent, $actor, 'storage-schema:' . $schema->ref->key(), $correlationId, [
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
                $schemaVersion = $this->schemaVersion($schema);
                $normalizedValues = $this->normalizeValues($schemaVersion, $values);
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

            return $this->recordVersionInternal(new StorageRecordVersionRef(
                $schemaId,
                (string) $head->record_id,
                (int) $head->current_revision,
            ));
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
                $head = $this->database->table('larena_storage_records')
                    ->where('record_id', $expected->recordId)
                    ->where('schema_id', $expected->schemaId)
                    ->first();
                if (!$head instanceof stdClass) {
                    throw new StorageRejected('storage_record_unknown');
                }
                if ((string) $head->owner_ref !== $ownerRef) {
                    throw new StorageRejected('storage_record_owner_mismatch');
                }
                $schemaVersion = $this->schemaVersion($schema);
                if ($schemaVersion->ref->schemaId !== $expected->schemaId) {
                    throw new StorageRejected('storage_record_schema_mismatch');
                }
                $normalizedValues = $this->normalizeValues($schemaVersion, $values);
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

    /**
     * @param array<string, mixed> $definition
     * @return array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>}
     */
    private function normalizeSchemaDefinition(array $definition): array
    {
        $schemaId = is_string($definition['schema_id'] ?? null) ? trim($definition['schema_id']) : '';
        $ownerPackage = is_string($definition['owner_package'] ?? null) ? trim($definition['owner_package']) : '';
        $fields = $definition['fields'] ?? null;
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaId) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]*\/[a-z][a-z0-9_.-]*$/', $ownerPackage) !== 1
            || !is_array($fields)
            || !array_is_list($fields)
            || $fields === []) {
            throw new StorageRejected('storage_schema_definition_invalid');
        }

        $normalizedFields = [];
        $seen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new StorageRejected('storage_schema_field_invalid');
            }
            $key = is_string($field['key'] ?? null) ? trim($field['key']) : '';
            $type = is_string($field['type'] ?? null) ? trim($field['type']) : '';
            $typeVersion = $field['type_version'] ?? 1;
            $required = $field['required'] ?? false;
            $visibility = $field['visibility'] ?? null;
            $constraints = $field['constraints'] ?? [];
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || isset($seen[$key])
                || !is_int($typeVersion)
                || $typeVersion < 1
                || !is_bool($required)
                || !is_string($visibility)
                || !in_array($visibility, ['public', 'admin'], true)
                || !is_array($constraints)
                || ($constraints !== [] && array_is_list($constraints))
                || $this->propertyTypes->resolve($type, $typeVersion) === null) {
                throw new StorageRejected('storage_schema_field_invalid');
            }
            foreach ($constraints as $constraintKey => $constraintValue) {
                if (!is_string($constraintKey) || !is_scalar($constraintValue)) {
                    throw new StorageRejected('storage_schema_constraint_invalid');
                }
            }
            $seen[$key] = true;
            $normalizedFields[] = [
                'key' => $key,
                'type' => $type,
                'type_version' => $typeVersion,
                'required' => $required,
                'visibility' => $visibility,
                'constraints' => $this->canonicalize($constraints),
            ];
        }

        return [
            'schema_id' => $schemaId,
            'owner_package' => $ownerPackage,
            'fields' => $normalizedFields,
        ];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeValues(StorageSchemaVersion $schema, array $values): array
    {
        if ($values !== [] && array_is_list($values)) {
            throw new StorageRejected('storage_record_values_invalid');
        }
        $fields = [];
        foreach ($schema->fields as $field) {
            $fields[(string) $field['key']] = $field;
        }
        foreach ($values as $key => $_value) {
            if (!is_string($key) || !isset($fields[$key])) {
                throw new StorageRejected('storage_record_unknown_field');
            }
        }

        $normalized = [];
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $values)) {
                if (($field['required'] ?? false) === true) {
                    throw new StorageRejected('storage_record_required_field_missing');
                }
                continue;
            }
            $result = $this->propertyTypes->normalizeAndValidate(
                (string) $field['type'],
                (int) $field['type_version'],
                $values[$key],
                is_array($field['constraints'] ?? null) ? $field['constraints'] : [],
            );
            if (!$result->canBePersistedByOwner()) {
                throw new StorageRejected('storage_record_field_invalid');
            }
            $normalized[$key] = $result->normalizedValue;
        }

        return $normalized;
    }

    private function hydrateSchemaVersion(stdClass $row): StorageSchemaVersion
    {
        $definition = $this->decodeObject((string) $row->definition, 'storage_schema_definition_corrupt');
        $fields = $definition['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new StorageRejected('storage_schema_definition_corrupt');
        }

        return new StorageSchemaVersion(
            new StorageSchemaVersionRef((string) $row->schema_id, (int) $row->version),
            (string) $row->owner_package,
            $fields,
            (string) $row->definition_hash,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
        );
    }

    private function hydrateRecordVersion(stdClass $row): StorageRecordVersion
    {
        return new StorageRecordVersion(
            new StorageRecordVersionRef((string) $row->schema_id, (string) $row->record_id, (int) $row->revision),
            (string) $row->owner_ref,
            new StorageSchemaVersionRef((string) $row->schema_id, (int) $row->schema_version),
            $this->decodeObject((string) $row->values_json, 'storage_record_values_corrupt'),
            (string) $row->content_hash,
            (string) $row->operation,
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
        );
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $reason): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected($reason);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new StorageRejected($reason);
        }

        return $decoded;
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
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
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
