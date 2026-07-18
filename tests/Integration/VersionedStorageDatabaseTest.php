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
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

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

function versionedStorageDatabaseEvolutionMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php';
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
            'constraints' => [],
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
    versionedStorageDatabaseEvolutionMigration()->up();

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
    $propertyTypes = PropertyTypeRegistry::builtIns();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [$auditSink]);
    $storage = new VersionedStorage(
        $connection,
        $propertyTypes,
        $authorizer,
        $audit,
    );
    $ownerPolicies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $ownerPolicies->seal();
    $evolution = new DatabaseStorageSchemaEvolution($connection, $propertyTypes, $authorizer, $audit, $ownerPolicies);

    $legacyRegistryWithoutConstraintCapability = new class($propertyTypes) implements \Larena\Property\Contracts\PropertyTypeRegistry
    {
        public function __construct(private \Larena\Property\Contracts\PropertyTypeRegistry $delegate)
        {
        }

        public function version(): int
        {
            return $this->delegate->version();
        }

        public function registryVersion(): int
        {
            return $this->delegate->registryVersion();
        }

        public function fingerprint(): string
        {
            return $this->delegate->fingerprint();
        }

        public function all(): array
        {
            return $this->delegate->all();
        }

        public function descriptors(): array
        {
            return $this->delegate->descriptors();
        }

        public function resolve(string $typeKey, int $version): ?\Larena\Property\Contracts\PropertyTypeDescriptor
        {
            return $this->delegate->resolve($typeKey, $version);
        }

        public function latest(string $typeKey): ?\Larena\Property\Contracts\PropertyTypeDescriptor
        {
            return $this->delegate->latest($typeKey);
        }

        public function normalize(
            string $typeKey,
            int $version,
            mixed $rawValue,
            array $constraints = [],
        ): \Larena\Property\Contracts\PropertyValidationResult {
            return $this->delegate->normalize($typeKey, $version, $rawValue, $constraints);
        }

        public function validate(
            string $typeKey,
            int $version,
            mixed $normalizedValue,
            array $constraints = [],
        ): \Larena\Property\Contracts\PropertyValidationResult {
            return $this->delegate->validate($typeKey, $version, $normalizedValue, $constraints);
        }

        public function normalizeAndValidate(
            string $typeKey,
            int $version,
            mixed $rawValue,
            array $constraints = [],
        ): \Larena\Property\Contracts\PropertyValidationResult {
            return $this->delegate->normalizeAndValidate($typeKey, $version, $rawValue, $constraints);
        }

        public function diff(\Larena\Property\Contracts\PropertyTypeRegistry $other): \Larena\Property\Contracts\PropertyRegistryDiff
        {
            return $this->delegate->diff($other);
        }
    };
    try {
        (new SchemaDefinitionNormalizer($legacyRegistryWithoutConstraintCapability))
            ->normalize(versionedStorageDatabaseSchemaDefinition(1));
        throw new RuntimeException('registry without constraint capability unexpectedly accepted');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_schema_constraint_invalid',
            'missing constraint capability reason mismatch',
        );
    }

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

    $semanticConstraintCases = [
        ['field' => 0, 'constraints' => ['minimum' => 1], 'name' => 'unsupported-string-key'],
        ['field' => 0, 'constraints' => ['min_length' => '1'], 'name' => 'wrong-string-type'],
        ['field' => 0, 'constraints' => ['min_length' => 2, 'max_length' => 1], 'name' => 'contradictory-string-range'],
        ['field' => 1, 'constraints' => ['min' => 2, 'max' => 1], 'name' => 'contradictory-integer-range'],
        ['field' => 2, 'constraints' => ['required' => true], 'name' => 'unsupported-boolean-constraint'],
    ];
    foreach ($semanticConstraintCases as $case) {
        $invalidSemanticSchema = versionedStorageDatabaseSchemaDefinition(1);
        $invalidSemanticSchema['schema_id'] = 'docara.page.invalid.' . $case['name'];
        $invalidSemanticSchema['fields'][$case['field']]['constraints'] = $case['constraints'];
        try {
            $storage->registerSchemaVersion(
                $invalidSemanticSchema,
                null,
                'user:admin:1',
                'storage-schema-' . $case['name'],
            );
            throw new RuntimeException($case['name'] . ' schema constraint unexpectedly accepted');
        } catch (StorageRejected $exception) {
            versionedStorageDatabaseExpect(
                $exception->reasonCode === 'storage_schema_constraint_invalid',
                $case['name'] . ' schema constraint reason mismatch',
            );
        }
    }
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_schemas')->count() === 0,
        'semantic invalid schema constraint persisted a schema head',
    );
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_schema_versions')->count() === 0,
        'semantic invalid schema constraint persisted an immutable version',
    );
    versionedStorageDatabaseExpect($auditSink->events() === [], 'semantic invalid schema emitted success audit');

    $correlationSentinel = 'PRIVATE_CORRELATION_VALUE_MUST_NOT_LEAK';
    $schemaV1 = $storage->registerSchemaVersion(
        versionedStorageDatabaseSchemaDefinition(1),
        null,
        'user:admin:1',
        $correlationSentinel,
    );
    $directV2DefinitionSentinel = 'PRIVATE_DIRECT_V2_DEFINITION_MUST_NOT_LEAK';
    $directV2CorrelationSentinel = 'PRIVATE_DIRECT_V2_CORRELATION_MUST_NOT_LEAK';
    $directV2Definition = versionedStorageDatabaseSchemaDefinition(2);
    $directV2Definition['private_runtime_input'] = $directV2DefinitionSentinel;
    try {
        $storage->registerSchemaVersion(
            $directV2Definition,
            1,
            'user:admin:1',
            $directV2CorrelationSentinel,
        );
        throw new RuntimeException('direct schema v2 unexpectedly succeeded');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_schema_version_requires_migration_plan',
            'direct schema v2 rejection reason mismatch',
        );
    }
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
    versionedStorageDatabaseExpect(
        in_array('storage.schema.create', $authorizer->operations(), true)
            && in_array('storage.schema.version', $authorizer->operations(), true),
        'schema create and version must use distinct Access operations',
    );
    $directV2AuditEvents = array_values(array_filter(
        $auditSink->events(),
        static fn (AuditEvent $event): bool => $event->type === 'storage.schema.version_rejected',
    ));
    versionedStorageDatabaseExpect(count($directV2AuditEvents) === 1, 'direct schema v2 rejection Audit missing');
    versionedStorageDatabaseExpect(
        $directV2AuditEvents[0]->payload === [
            'schema_id' => 'docara.page.article',
            'expected_head_version' => 1,
            'reason_code' => 'storage_schema_version_requires_migration_plan',
        ],
        'direct schema v2 rejection Audit payload is not the exact sanitized contract',
    );
    $directV2AuditJson = json_encode($directV2AuditEvents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ([$directV2DefinitionSentinel, $directV2CorrelationSentinel, 'subtitle'] as $privateDirectV2Material) {
        versionedStorageDatabaseExpect(
            !str_contains($directV2AuditJson, $privateDirectV2Material),
            'direct schema v2 rejection Audit leaked raw definition or correlation material',
        );
    }

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

    $schemaPlan = $evolution->plan(
        $schemaV1->ref,
        versionedStorageDatabaseSchemaDefinition(2),
        'user:admin:1',
        'storage-schema-plan-v2',
    );
    $schemaResult = $evolution->apply(
        $schemaPlan->planRef,
        $schemaPlan->planHash,
        'user:admin:1',
        'storage-schema-apply-v2',
    );
    $schemaV2 = $storage->schemaVersion($schemaResult->target);
    $migratedV2 = $storage->readAdminCurrentVersion(
        $recordV1->ref->schemaId,
        'docara:page:typed-1',
        'user:admin:1',
    );
    if ($migratedV2 === null) {
        throw new RuntimeException('schema evolution lost the record head');
    }
    versionedStorageDatabaseExpect($schemaV2->ref->version === 2, 'schema v2 ref mismatch');
    versionedStorageDatabaseExpect(count($storage->schemaVersion($schemaV1->ref)->fields) === 4, 'schema v1 mutated');
    versionedStorageDatabaseExpect(count($storage->schemaVersion($schemaV2->ref)->fields) === 5, 'schema v2 missing field');
    versionedStorageDatabaseExpect(
        $storage->schemaVersion($schemaV1->ref)->definitionHash !== $schemaV2->definitionHash,
        'schema versions must have distinct immutable hashes',
    );
    versionedStorageDatabaseExpect(
        $migratedV2->ref->revision === 2 && $migratedV2->operation === 'schema_migration',
        'schema evolution did not create immutable record revision 2',
    );
    try {
        $storage->create(
            'docara:page:stale-schema-create',
            $schemaV1->ref,
            $v1Raw,
            'user:admin:1',
            'stale-schema-create',
        );
        throw new RuntimeException('create under stale schema unexpectedly succeeded');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_record_schema_not_current',
            'stale-schema create reason mismatch',
        );
    }
    try {
        $storage->compareAndSwap(
            'docara:page:typed-1',
            $migratedV2->ref,
            $schemaV1->ref,
            $v1Raw,
            'user:admin:1',
            'stale-schema-cas',
        );
        throw new RuntimeException('CAS under stale schema unexpectedly succeeded');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_record_schema_not_current',
            'stale-schema CAS reason mismatch',
        );
    }

    $v2Raw = versionedStorageDatabaseValues(
        'public v2',
        '-7',
        'false',
        'admin v2 must stay private',
        'public optional v2',
    );
    $updated = $storage->compareAndSwap(
        'docara:page:typed-1',
        $migratedV2->ref,
        $schemaV2->ref,
        $v2Raw,
        'user:admin:1',
        'storage-record-v2',
    );
    $recordV2 = $updated->version;
    versionedStorageDatabaseExpect($recordV2->ref->revision === 3, 'record v2 ref mismatch');
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
            $migratedV2->ref,
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
        $connection->table('larena_storage_record_versions')->count() === 3,
        'stale CAS persisted a record version',
    );

    versionedStorageDatabaseExpect(
        $storage->readAdminCurrentVersion(
            $recordV2->ref->schemaId,
            'docara:page:typed-1',
            'user:admin:1',
        )?->ref->revision === 3,
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
        $deniedStorage->registerSchemaVersion(
            ['schema_id' => 'docara.page.denied_version', 'private_runtime_input' => 'DENIED_SCHEMA_SECRET'],
            1,
            'user:reader:2',
            'DENIED_SCHEMA_CORRELATION_SECRET',
        );
        throw new RuntimeException('denied direct schema version unexpectedly reached rejection Audit');
    } catch (AccessMutationRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'access_actor_forbidden',
            'direct schema version Access denial reason mismatch',
        );
    }
    versionedStorageDatabaseExpect($deniedAudit->events() === [], 'Access-denied schema version emitted Audit');
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

    $legacyNormalizer = new SchemaDefinitionNormalizer($propertyTypes);
    $legacyDefinition = versionedStorageDatabaseSchemaDefinition(2);
    $legacyDefinition['schema_id'] = 'docara.page.legacy_constraints';
    $legacyDefinition['fields'][4]['constraints'] = ['min_length' => '2'];
    $legacyDefinitionJson = $legacyNormalizer->canonicalJson($legacyDefinition);
    $legacyDefinitionHash = hash('sha256', $legacyDefinitionJson);
    $legacyValues = [
        'summary' => 'legacy public value',
        'priority' => 7,
        'featured' => true,
        'internal_note' => 'legacy admin value',
    ];
    $legacyValuesJson = $legacyNormalizer->canonicalJson($legacyValues);
    $legacyContentHash = hash('sha256', $legacyValuesJson);
    $legacyRecordId = 'record-00000000000000000000000000000001';
    $legacyNow = gmdate('Y-m-d H:i:s');
    $connection->table('larena_storage_schemas')->insert([
        'schema_id' => $legacyDefinition['schema_id'],
        'current_version' => 1,
        'current_hash' => $legacyDefinitionHash,
        'created_at' => $legacyNow,
        'updated_at' => $legacyNow,
    ]);
    $connection->table('larena_storage_schema_versions')->insert([
        'schema_id' => $legacyDefinition['schema_id'],
        'version' => 1,
        'definition' => $legacyDefinitionJson,
        'definition_hash' => $legacyDefinitionHash,
        'owner_package' => 'larena/docara',
        'created_by' => 'legacy-import',
        'correlation_id' => null,
        'created_at' => $legacyNow,
    ]);
    $connection->table('larena_storage_records')->insert([
        'record_id' => $legacyRecordId,
        'schema_id' => $legacyDefinition['schema_id'],
        'owner_ref' => 'docara:page:legacy-1',
        'current_revision' => 1,
        'current_schema_version' => 1,
        'current_hash' => $legacyContentHash,
        'created_at' => $legacyNow,
        'updated_at' => $legacyNow,
    ]);
    $connection->table('larena_storage_record_versions')->insert([
        'schema_id' => $legacyDefinition['schema_id'],
        'record_id' => $legacyRecordId,
        'revision' => 1,
        'owner_ref' => 'docara:page:legacy-1',
        'schema_version' => 1,
        'values_json' => $legacyValuesJson,
        'content_hash' => $legacyContentHash,
        'operation' => 'create',
        'created_by' => 'legacy-import',
        'correlation_id' => null,
        'created_at' => $legacyNow,
    ]);
    $legacyRef = new \Larena\Storage\Contracts\StorageRecordVersionRef(
        $legacyDefinition['schema_id'],
        $legacyRecordId,
        1,
    );
    versionedStorageDatabaseExpect(
        $storage->schemaVersion(new \Larena\Storage\Contracts\StorageSchemaVersionRef(
            $legacyDefinition['schema_id'],
            1,
        ))->fields[4]['constraints'] === ['min_length' => '2'],
        'legacy immutable schema constraints were rewritten or hidden',
    );
    versionedStorageDatabaseExpect(
        $storage->readAdminVersion($legacyRef, 'user:admin:1')->values == $legacyValues,
        'legacy immutable record became unreadable',
    );
    versionedStorageDatabaseExpect(
        $storage->projectPublicVersion($legacyRef)->values === [
            'summary' => 'legacy public value',
            'featured' => true,
        ],
        'legacy public projection exposed admin data or lost public data',
    );
    $legacyAuditCount = count($auditSink->events());
    $legacyHeadCount = $connection->table('larena_storage_records')->count();
    $legacyVersionCount = $connection->table('larena_storage_record_versions')->count();
    try {
        $storage->create(
            'docara:page:legacy-2',
            new \Larena\Storage\Contracts\StorageSchemaVersionRef($legacyDefinition['schema_id'], 1),
            $legacyValues,
            'user:admin:1',
            'legacy-invalid-constraint-write',
        );
        throw new RuntimeException('legacy invalid schema unexpectedly accepted a new write');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_schema_constraint_invalid',
            'legacy invalid schema write reason mismatch',
        );
    }
    try {
        $storage->compareAndSwap(
            'docara:page:legacy-1',
            $legacyRef,
            new \Larena\Storage\Contracts\StorageSchemaVersionRef($legacyDefinition['schema_id'], 1),
            $legacyValues,
            'user:admin:1',
            'legacy-invalid-constraint-cas',
        );
        throw new RuntimeException('legacy invalid schema unexpectedly accepted compare-and-swap');
    } catch (StorageRejected $exception) {
        versionedStorageDatabaseExpect(
            $exception->reasonCode === 'storage_schema_constraint_invalid',
            'legacy invalid schema compare-and-swap reason mismatch',
        );
    }
    versionedStorageDatabaseExpect(
        !$connection->table('larena_storage_records')
            ->where('owner_ref', 'docara:page:legacy-2')
            ->exists(),
        'legacy invalid schema write persisted a record head',
    );
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_records')->count() === $legacyHeadCount,
        'legacy invalid schema mutation moved or added a record head',
    );
    versionedStorageDatabaseExpect(
        $connection->table('larena_storage_record_versions')->count() === $legacyVersionCount,
        'legacy invalid schema mutation persisted an immutable record version',
    );
    versionedStorageDatabaseExpect(
        count($auditSink->events()) === $legacyAuditCount,
        'legacy invalid schema write emitted a success Audit event',
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
