<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;

require_once __DIR__ . '/../../vendor/autoload.php';

final class VersionedStorageDatabaseRecordingAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<string> */
    private array $operations = [];

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->operations[] = $operation;
    }

    /** @return list<string> */
    public function operations(): array
    {
        return $this->operations;
    }
}

final readonly class VersionedStorageDatabaseDenyAllAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
        throw new AccessMutationRejected('access_actor_forbidden');
    }
}

final class VersionedStorageDatabaseRecordingAuditSink implements AuditSink
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<AuditEvent> */
    public function events(): array
    {
        return $this->events;
    }
}

final readonly class VersionedStorageDatabaseFailingAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return $descriptor->type() === 'storage.record.updated';
    }

    public function write(AuditEvent $event): void
    {
        throw new RuntimeException('forced_storage_audit_failure');
    }
}

/**
 * @return array{capsule: Capsule, connection: Connection}
 */
function versionedStorageDatabaseOpen(string $path): array
{
    if (!is_file($path) && file_put_contents($path, '') === false) {
        throw new RuntimeException('storage_test_database_create_failed');
    }

    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $connection = $capsule->getConnection();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.connection', $connection);
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return ['capsule' => $capsule, 'connection' => $connection];
}

function versionedStorageDatabaseMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php';
}

function versionedStorageDatabaseExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function versionedStorageDatabaseSchemaDefinition(int $revision): array
{
    $fields = [
        [
            'key' => 'summary',
            'type' => 'string',
            'type_version' => 1,
            'required' => true,
            'visibility' => 'public',
            'constraints' => ['min_length' => 1, 'max_length' => 120],
        ],
        [
            'key' => 'priority',
            'type' => 'integer',
            'type_version' => 1,
            'required' => true,
            'visibility' => 'admin',
            'constraints' => ['min' => -100, 'max' => 100],
        ],
        [
            'key' => 'featured',
            'type' => 'boolean',
            'type_version' => 1,
            'required' => true,
            'visibility' => 'public',
            'constraints' => [],
        ],
        [
            'key' => 'internal_note',
            'type' => 'string',
            'type_version' => 1,
            'required' => true,
            'visibility' => 'admin',
            'constraints' => ['max_length' => 200],
        ],
    ];
    if ($revision === 2) {
        $fields[] = [
            'key' => 'subtitle',
            'type' => 'string',
            'type_version' => 1,
            'required' => false,
            'visibility' => 'public',
            'constraints' => ['max_length' => 120],
        ];
    }

    return [
        'schema_id' => 'docara.page.article',
        'owner_package' => 'larena/docara',
        'fields' => $fields,
    ];
}

/** @return array<string, mixed> */
function versionedStorageDatabaseValues(
    string $summary,
    string $priority,
    string $featured,
    string $internalNote,
    ?string $subtitle = null,
): array {
    $values = [
        'summary' => $summary,
        'priority' => $priority,
        'featured' => $featured,
        'internal_note' => $internalNote,
    ];
    if ($subtitle !== null) {
        $values['subtitle'] = $subtitle;
    }

    return $values;
}

$primaryPath = tempnam(sys_get_temp_dir(), 'larena-storage-versioned-');
$cleanPath = tempnam(sys_get_temp_dir(), 'larena-storage-migration-');
if (!is_string($primaryPath) || !is_string($cleanPath)) {
    throw new RuntimeException('storage_test_tempfile_failed');
}

try {
    $primary = versionedStorageDatabaseOpen($primaryPath);
    $migration = versionedStorageDatabaseMigration();
    $migration->up();

    $connection = $primary['connection'];
    $tables = [
        'larena_storage_schemas',
        'larena_storage_schema_versions',
        'larena_storage_records',
        'larena_storage_record_versions',
    ];
    foreach ($tables as $table) {
        versionedStorageDatabaseExpect(
            $connection->getSchemaBuilder()->hasTable($table),
            'storage migration did not create ' . $table,
        );
    }

    $auditSink = new VersionedStorageDatabaseRecordingAuditSink();
    $authorizer = new VersionedStorageDatabaseRecordingAuthorizer();
    $storage = new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        $authorizer,
        new AuditEventPipeline(new DefaultAuditRedactor(), [$auditSink]),
    );

    $invalidConstraintSchema = versionedStorageDatabaseSchemaDefinition(1);
    $invalidConstraintSchema['schema_id'] = 'docara.page.invalid_constraints';
    $invalidConstraintSchema['fields'][0]['constraints'] = ['min_length' => null];
    try {
        $storage->registerSchemaVersion(
            $invalidConstraintSchema,
            null,
            'user:admin:1',
            'storage-schema-invalid-constraint',
        );
        throw new RuntimeException('null schema constraint unexpectedly accepted');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_schema_constraint_invalid',
            'null schema constraint reason mismatch',
        );
    }
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_schemas')->count() === 0,
        'invalid schema constraint persisted a schema head',
    );
    versionedStorageDatabaseExpect($auditSink->events() === [], 'invalid schema emitted success audit');

    $correlationSentinel = 'PRIVATE_CORRELATION_VALUE_MUST_NOT_LEAK';
    $schemaV1 = $storage->registerSchemaVersion(
        versionedStorageDatabaseSchemaDefinition(1),
        null,
        'user:admin:1',
        $correlationSentinel,
    );
    $schemaV2 = $storage->registerSchemaVersion(
        versionedStorageDatabaseSchemaDefinition(2),
        1,
        'user:admin:1',
        'storage-schema-v2',
    );
    versionedStorageDatabaseExpect($schemaV1->ref->version === 1, 'schema v1 ref mismatch');
    versionedStorageDatabaseExpect(
        $schemaV1->correlationId !== $correlationSentinel
            && str_starts_with((string) $schemaV1->correlationId, 'storage-schema-'),
        'caller correlation id was not converted to a safe opaque identifier',
    );
    versionedStorageDatabaseExpect(
        !$connection->table('larena_storage_schema_versions')
            ->where('correlation_id', $correlationSentinel)
            ->exists(),
        'raw caller correlation id persisted in the schema version',
    );
    versionedStorageDatabaseExpect($schemaV2->ref->version === 2, 'schema v2 ref mismatch');
    versionedStorageDatabaseExpect(count($storage->schemaVersion($schemaV1->ref)->fields) === 4, 'schema v1 mutated');
    versionedStorageDatabaseExpect(count($storage->schemaVersion($schemaV2->ref)->fields) === 5, 'schema v2 missing field');
    versionedStorageDatabaseExpect(
        $storage->schemaVersion($schemaV1->ref)->definitionHash !== $schemaV2->definitionHash,
        'schema versions must have distinct immutable hashes',
    );
    versionedStorageDatabaseExpect(
        in_array('storage.schema.create', $authorizer->operations(), true)
            && in_array('storage.schema.version', $authorizer->operations(), true),
        'schema create and version must use distinct Access operations',
    );

    $v1Raw = versionedStorageDatabaseValues(
        '  public v1 is exact  ',
        '42',
        'true',
        'admin v1 must stay private',
    );
    $created = $storage->create(
        'docara:page:typed-1',
        $schemaV1->ref,
        $v1Raw,
        'user:admin:1',
        'storage-record-v1',
    );
    $recordV1 = $created->version;
    versionedStorageDatabaseExpect($recordV1->ref->revision === 1, 'record v1 ref mismatch');
    versionedStorageDatabaseExpect(
        $storage->readAdminCurrentVersion(
            $recordV1->ref->schemaId,
            'docara:page:typed-1',
            'user:admin:1',
        )?->ref->revision === 1,
        'actor-checked current record read did not return v1',
    );
    versionedStorageDatabaseExpect($recordV1->values === [
        'summary' => '  public v1 is exact  ',
        'priority' => 42,
        'featured' => true,
        'internal_note' => 'admin v1 must stay private',
    ], 'string/integer/boolean normalization mismatch');

    $v2Raw = versionedStorageDatabaseValues(
        'public v2',
        '-7',
        'false',
        'admin v2 must stay private',
        'public optional v2',
    );
    $updated = $storage->compareAndSwap(
        'docara:page:typed-1',
        $recordV1->ref,
        $schemaV2->ref,
        $v2Raw,
        'user:admin:1',
        'storage-record-v2',
    );
    $recordV2 = $updated->version;
    versionedStorageDatabaseExpect($recordV2->ref->revision === 2, 'record v2 ref mismatch');
    versionedStorageDatabaseExpect($recordV2->operation === 'update', 'CAS must create an update version');
    versionedStorageDatabaseExpect($recordV2->values['priority'] === -7, 'integer normalization not persisted');
    versionedStorageDatabaseExpect($recordV2->values['featured'] === false, 'boolean normalization not persisted');
    versionedStorageDatabaseExpect(
        $storage->readAdminVersion($recordV1->ref, 'user:admin:1')->values == $recordV1->values,
        'record v1 mutated after v2 write',
    );

    try {
        $storage->compareAndSwap(
            'docara:page:typed-1',
            $recordV1->ref,
            $schemaV2->ref,
            versionedStorageDatabaseValues('stale loser', '8', 'true', 'stale private', 'stale subtitle'),
            'user:admin:1',
            'storage-record-stale',
        );
        throw new RuntimeException('stale CAS unexpectedly succeeded');
    } catch (StorageConflict $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_record_revision_conflict',
            'stale CAS reason must be stable and sanitized',
        );
    }
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_record_versions')->count() === 2,
        'stale CAS persisted a record version',
    );

    versionedStorageDatabaseExpect(
        $storage->readAdminCurrentVersion(
            $recordV2->ref->schemaId,
            'docara:page:typed-1',
            'user:admin:1',
        )?->ref->revision === 2,
        'actor-checked current record read did not return v2',
    );
    versionedStorageDatabaseExpect(
        $storage->readAdminVersion($recordV2->ref, 'user:admin:1')->values == $recordV2->values,
        'record v2 mutated after exact historical read',
    );
    foreach (['storage.record.create', 'storage.record.read', 'storage.record.update'] as $requiredOperation) {
        versionedStorageDatabaseExpect(
            in_array($requiredOperation, $authorizer->operations(), true),
            'missing Access operation ' . $requiredOperation,
        );
    }
    versionedStorageDatabaseExpect(
        !in_array('storage.record.restore', $authorizer->operations(), true),
        'restore Access operation must not be requested',
    );

    $public = $storage->projectPublicVersion($recordV1->ref);
    versionedStorageDatabaseExpect(
        $public->ownerRef === 'docara:page:typed-1',
        'public projection lost its safe owner reference',
    );
    versionedStorageDatabaseExpect($public->values === [
        'summary' => '  public v1 is exact  ',
        'featured' => true,
    ], 'public projection leaked admin-only values or lost public values');
    versionedStorageDatabaseExpect(!array_key_exists('priority', $public->values), 'priority leaked publicly');
    versionedStorageDatabaseExpect(!array_key_exists('internal_note', $public->values), 'internal note leaked publicly');

    $beforeOwnerMismatch = $connection->table('larena_storage_record_versions')->count();
    try {
        $storage->compareAndSwap(
            'docara:page:other-owner',
            $recordV2->ref,
            $schemaV2->ref,
            $v2Raw,
            'user:admin:1',
            'storage-record-owner-mismatch',
        );
        throw new RuntimeException('owner mismatch unexpectedly succeeded');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_record_owner_mismatch',
            'owner mismatch reason must be stable and sanitized',
        );
    }
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_record_versions')->count() === $beforeOwnerMismatch,
        'owner mismatch wrote a record version',
    );

    $deniedAudit = new VersionedStorageDatabaseRecordingAuditSink();
    $deniedStorage = new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        new VersionedStorageDatabaseDenyAllAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [$deniedAudit]),
    );
    try {
        $deniedStorage->create(
            'docara:page:denied',
            $schemaV2->ref,
            $v2Raw,
            'user:reader:2',
            'storage-record-denied',
        );
        throw new RuntimeException('denied create unexpectedly succeeded');
    } catch (AccessMutationRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'access_actor_forbidden',
            'access denial reason mismatch',
        );
    }
    versionedStorageDatabaseExpect($deniedAudit->events() === [], 'denied mutation emitted success audit');
    versionedStorageDatabaseExpect(
        !$connection->table('larena_storage_records')->where('owner_ref', 'docara:page:denied')->exists(),
        'denied mutation persisted a record',
    );
    try {
        $deniedStorage->readAdminCurrentVersion(
            $recordV2->ref->schemaId,
            'docara:page:typed-1',
            'user:reader:2',
        );
        throw new RuntimeException('denied current-version read unexpectedly succeeded');
    } catch (AccessMutationRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'access_actor_forbidden',
            'current-version read denial reason mismatch',
        );
    }

    $invalidSentinel = 'PRIVATE_INVALID_VALUE_MUST_NOT_LEAK';
    try {
        $storage->create(
            'docara:page:invalid',
            $schemaV2->ref,
            versionedStorageDatabaseValues('invalid public', $invalidSentinel, 'true', 'invalid private'),
            'user:admin:1',
            'storage-record-invalid',
        );
        throw new RuntimeException('invalid typed value unexpectedly persisted');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_record_field_invalid',
            'invalid field reason must be stable',
        );
        versionedStorageDatabaseExpect(
            !str_contains($exception->getMessage(), $invalidSentinel),
            'invalid raw value leaked through the exception',
        );
    }

    $auditJson = json_encode(array_map(
        static fn (AuditEvent $event): array => [
            'type' => $event->type,
            'actor' => $event->actor,
            'subject' => $event->subject,
            'correlation_id' => $event->correlationId,
            'payload' => $event->payload,
        ],
        $auditSink->events(),
    ), JSON_THROW_ON_ERROR);
    foreach ([
        $v1Raw['summary'],
        $v1Raw['internal_note'],
        $v2Raw['summary'],
        $v2Raw['internal_note'],
        $v2Raw['subtitle'],
        'stale loser',
        'stale private',
        $invalidSentinel,
        $correlationSentinel,
    ] as $privateValue) {
        versionedStorageDatabaseExpect(
            !str_contains($auditJson, (string) $privateValue),
            'field value leaked into Security Audit payload',
        );
    }
    foreach (['summary', 'priority', 'featured', 'internal_note', 'subtitle'] as $fieldKey) {
        versionedStorageDatabaseExpect(
            !str_contains($auditJson, '"' . $fieldKey . '"'),
            'field key leaked into Security Audit payload',
        );
    }
    versionedStorageDatabaseExpect(
        !str_contains($auditJson, 'storage.record.restored'),
        'restore Audit event must not exist in the versioned Storage flow',
    );

    $beforeAuditFailureHead = (array) $connection->table('larena_storage_records')
        ->where('record_id', $recordV2->ref->recordId)
        ->first();
    $beforeAuditFailureCount = $connection->table('larena_storage_record_versions')->count();
    $auditFailureSentinel = 'AUDIT_FAILURE_VALUE_MUST_ROLL_BACK';
    $failingStorage = new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        new VersionedStorageDatabaseRecordingAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new VersionedStorageDatabaseFailingAuditSink()]),
    );
    try {
        $failingStorage->compareAndSwap(
            'docara:page:typed-1',
            $recordV2->ref,
            $schemaV2->ref,
            versionedStorageDatabaseValues($auditFailureSentinel, '9', 'false', 'audit private', 'audit subtitle'),
            'user:admin:1',
            'storage-record-audit-failure',
        );
        throw new RuntimeException('audit failure unexpectedly committed');
    } catch (StoragePersistenceFailed $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_persistence_failed',
            'audit failure reason must be stable',
        );
        versionedStorageDatabaseExpect(
            !str_contains($exception->getMessage(), $auditFailureSentinel),
            'audit failure exposed a field value',
        );
    }
    versionedStorageDatabaseExpect(
        (array) $connection->table('larena_storage_records')
            ->where('record_id', $recordV2->ref->recordId)
            ->first() === $beforeAuditFailureHead,
        'audit failure changed the record head',
    );
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_record_versions')->count() === $beforeAuditFailureCount,
        'audit failure persisted an immutable record version',
    );

    $restart = versionedStorageDatabaseOpen($primaryPath);
    $restartedStorage = new VersionedStorage(
        $restart['connection'],
        PropertyTypeRegistry::builtIns(),
        new VersionedStorageDatabaseRecordingAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new VersionedStorageDatabaseRecordingAuditSink()]),
    );
    versionedStorageDatabaseExpect(
        $restartedStorage->readAdminVersion($recordV1->ref, 'user:admin:1')->values == $recordV1->values,
        'record v1 did not survive a new database connection',
    );
    versionedStorageDatabaseExpect(
        $restartedStorage->readAdminVersion($recordV2->ref, 'user:admin:1')->values == $recordV2->values,
        'record v2 did not survive a new database connection',
    );

    try {
        versionedStorageDatabaseMigration()->down();
        throw new RuntimeException('rollback with data unexpectedly succeeded');
    } catch (RuntimeException $exception) {
        versionedStorageDatabaseExpect(
            $exception->getMessage() === 'storage_typed_content_rollback_would_lose_data',
            'rollback refusal reason mismatch',
        );
    }
    foreach ($tables as $table) {
        versionedStorageDatabaseExpect(
            $restart['connection']->getSchemaBuilder()->hasTable($table),
            'refused rollback partially dropped ' . $table,
        );
    }

    $clean = versionedStorageDatabaseOpen($cleanPath);
    versionedStorageDatabaseMigration()->up();
    versionedStorageDatabaseMigration()->down();
    foreach ($tables as $table) {
        versionedStorageDatabaseExpect(
            !$clean['connection']->getSchemaBuilder()->hasTable($table),
            'clean rollback kept ' . $table,
        );
    }
    versionedStorageDatabaseMigration()->up();
    foreach ($tables as $table) {
        versionedStorageDatabaseExpect(
            $clean['connection']->getSchemaBuilder()->hasTable($table),
            'migration reapply did not recreate ' . $table,
        );
    }
    versionedStorageDatabaseMigration()->down();

    echo "VersionedStorageDatabaseTest passed.\n";
} finally {
    Facade::clearResolvedInstances();
    @unlink($primaryPath);
    @unlink($cleanPath);
}
