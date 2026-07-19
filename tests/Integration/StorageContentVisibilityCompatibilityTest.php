<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageRecord;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\ArrayStorageMutation;
use Larena\Storage\Runtime\ArrayStorageQuery;
use Larena\Storage\Runtime\ArrayStorageSchema;
use Larena\Storage\Runtime\InMemoryStorageRuntime;
use Larena\Storage\Runtime\PdoLocalDevStorageAdapter;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;

require_once __DIR__ . '/../../vendor/autoload.php';

final readonly class StorageContentVisibilityAllowAllAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final class StorageContentVisibilityAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
    }
}

function storageContentVisibilityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param callable(): mixed $operation
 */
function storageContentVisibilityExpectRejected(callable $operation, string $reason): void
{
    try {
        $operation();
    } catch (StorageRejected $exception) {
        storageContentVisibilityExpect(
            $exception->reasonCode === $reason,
            'unexpected rejection reason: ' . $exception->reasonCode,
        );
        storageContentVisibilityExpect(
            $exception->getMessage() === $reason,
            'schema rejection message exposed non-contract input',
        );

        return;
    }

    throw new RuntimeException('expected storage rejection: ' . $reason);
}

/**
 * @return array{
 *     path: string,
 *     connection: Connection,
 *     storage: VersionedStorage,
 *     normalizer: SchemaDefinitionNormalizer
 * }
 */
function storageContentVisibilityOpenVersioned(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-content-visibility-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_content_visibility_tempfile_failed');
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

    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();

    $propertyTypes = PropertyTypeRegistry::builtIns();
    $storage = new VersionedStorage(
        $connection,
        $propertyTypes,
        new StorageContentVisibilityAllowAllAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new StorageContentVisibilityAuditSink()]),
    );

    return [
        'path' => $path,
        'connection' => $connection,
        'storage' => $storage,
        'normalizer' => new SchemaDefinitionNormalizer($propertyTypes),
    ];
}

/**
 * @return array<string, mixed>
 */
function storageContentVisibilityDefinition(): array
{
    return [
        'schema_id' => 'content.type.article',
        'owner_package' => 'larena/content',
        'fields' => [
            [
                'key' => 'public_value',
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'public',
                'constraints' => [],
            ],
            [
                'key' => 'protected_value',
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'protected',
                'constraints' => [],
            ],
            [
                'key' => 'admin_value',
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'admin',
                'constraints' => [],
            ],
        ],
    ];
}

/**
 * @return array<string, string>
 */
function storageContentVisibilityValues(): array
{
    return [
        'public_value' => 'PUBLIC_VALUE',
        'protected_value' => 'PROTECTED_VALUE',
        'admin_value' => 'ADMIN_VALUE',
    ];
}

/**
 * @return list<array<string, scalar|null>>
 */
function storageContentVisibilityLegacyFields(): array
{
    return [
        ['name' => 'public_value', 'type' => 'string', 'required' => true, 'visibility' => 'public'],
        ['name' => 'protected_value', 'type' => 'string', 'required' => true, 'visibility' => 'protected'],
        ['name' => 'admin_value', 'type' => 'string', 'required' => true, 'visibility' => 'admin'],
        ['name' => 'hidden_value', 'type' => 'string', 'required' => true, 'visibility' => 'hidden'],
        ['name' => 'encrypted_value', 'type' => 'string', 'required' => true, 'visibility' => 'encrypted'],
        ['name' => 'unknown_value', 'type' => 'string', 'required' => true, 'visibility' => 'future_private'],
        ['name' => 'missing_value', 'type' => 'string', 'required' => true],
        ['name' => 'invalid_value', 'type' => 'string', 'required' => true, 'visibility' => null],
    ];
}

/**
 * @return array<string, string>
 */
function storageContentVisibilityLegacyValues(): array
{
    return [
        'public_value' => 'PUBLIC_VALUE',
        'protected_value' => 'PROTECTED_VALUE',
        'admin_value' => 'ADMIN_VALUE',
        'hidden_value' => 'HIDDEN_VALUE',
        'encrypted_value' => 'ENCRYPTED_VALUE',
        'unknown_value' => 'UNKNOWN_VALUE',
        'missing_value' => 'MISSING_VALUE',
        'invalid_value' => 'INVALID_VALUE',
    ];
}

/**
 * @param list<StorageRecord> $records
 * @return array<string, mixed>
 */
function storageContentVisibilitySingleProjection(array $records): array
{
    storageContentVisibilityExpect(count($records) === 1, 'expected exactly one projected record');

    return $records[0]->projection();
}

$opened = storageContentVisibilityOpenVersioned();

try {
    storageContentVisibilityExpect(!FieldVisibility::Public->requiresProtectedProjection(), 'public visibility was classified as protected');
    foreach ([
        FieldVisibility::Protected,
        FieldVisibility::Admin,
        FieldVisibility::Hidden,
        FieldVisibility::Encrypted,
    ] as $protectedVisibility) {
        storageContentVisibilityExpect(
            $protectedVisibility->requiresProtectedProjection(),
            $protectedVisibility->value . ' visibility was not classified as protected',
        );
    }

    $definition = storageContentVisibilityDefinition();
    $normalized = $opened['normalizer']->normalize($definition);
    storageContentVisibilityExpect(
        array_column($normalized['fields'], 'visibility') === ['public', 'protected', 'admin'],
        'protected visibility did not survive canonical normalization',
    );
    $canonicalDefinition = $opened['normalizer']->canonicalJson($normalized);
    $canonicalRoundTrip = json_decode($canonicalDefinition, true, 512, JSON_THROW_ON_ERROR);
    storageContentVisibilityExpect(
        $opened['normalizer']->canonicalJson($canonicalRoundTrip) === $canonicalDefinition,
        'protected visibility did not survive canonical JSON round-trip',
    );

    foreach (['hidden', 'encrypted', 'future_private', '', 7, null] as $invalidVisibility) {
        $invalidDefinition = $definition;
        $invalidDefinition['schema_id'] = 'content.type.invalid_' . substr(hash('sha256', serialize($invalidVisibility)), 0, 12);
        $invalidDefinition['fields'][1]['visibility'] = $invalidVisibility;
        storageContentVisibilityExpectRejected(
            static fn () => $opened['normalizer']->normalize($invalidDefinition),
            'storage_schema_field_invalid',
        );
    }
    $missingVisibilityDefinition = $definition;
    $missingVisibilityDefinition['schema_id'] = 'content.type.invalid_missing';
    unset($missingVisibilityDefinition['fields'][1]['visibility']);
    storageContentVisibilityExpectRejected(
        static fn () => $opened['normalizer']->normalize($missingVisibilityDefinition),
        'storage_schema_field_unknown_key',
    );

    $schema = $opened['storage']->registerSchemaVersion(
        $definition,
        null,
        'user:admin:1',
        'content-visibility-schema',
    );
    storageContentVisibilityExpect(
        array_column($schema->fields, 'visibility') === ['public', 'protected', 'admin'],
        'versioned schema did not preserve exact visibility values',
    );

    $storedDefinition = $opened['connection']->table('larena_storage_schema_versions')
        ->where('schema_id', $schema->ref->schemaId)
        ->where('version', $schema->ref->version)
        ->value('definition');
    storageContentVisibilityExpect(is_string($storedDefinition), 'versioned schema definition was not persisted');
    storageContentVisibilityExpect(
        $storedDefinition === $canonicalDefinition,
        'persisted versioned schema was not the canonical normalized definition',
    );

    $write = $opened['storage']->create(
        'content:item:11111111-1111-4111-8111-111111111111',
        $schema->ref,
        storageContentVisibilityValues(),
        'user:admin:1',
        'content-visibility-record',
    );
    $adminVersion = $opened['storage']->readAdminVersion($write->ref(), 'user:admin:1');
    storageContentVisibilityExpect(
        $adminVersion->values == storageContentVisibilityValues(),
        'protected/admin values were not preserved in the immutable admin version',
    );
    $versionedProjection = $opened['storage']->projectPublicVersion($write->ref())->values;
    storageContentVisibilityExpect(
        $versionedProjection === ['public_value' => 'PUBLIC_VALUE'],
        'versioned public projection was not exact-public',
    );

    $legacyValues = storageContentVisibilityLegacyValues();
    $inMemory = new InMemoryStorageRuntime();
    $inMemorySchema = new ArrayStorageSchema(
        'content.legacy.in_memory',
        '1',
        'content.public.read',
        'in_memory',
        storageContentVisibilityLegacyFields(),
    );
    storageContentVisibilityExpect($inMemory->registerSchema($inMemorySchema)->isValid(), 'in-memory legacy schema registration failed');
    storageContentVisibilityExpect(
        $inMemory->mutate(new ArrayStorageMutation(
            $inMemorySchema->id(),
            'record-1',
            MutationType::Create,
            'content.public.read',
            $legacyValues,
        )) === StorageDecisionStatus::Allowed,
        'in-memory compatibility record mutation failed',
    );
    $inMemoryProjection = storageContentVisibilitySingleProjection(
        $inMemory->records(new ArrayStorageQuery($inMemorySchema->id(), 'content.public.read')),
    );

    $pdo = PdoLocalDevStorageAdapter::inMemorySqlite('testing');
    $pdoSchema = new ArrayStorageSchema(
        'content.legacy.pdo',
        '1',
        'content.public.read',
        PdoLocalDevStorageAdapter::localDevProfile()->id(),
        storageContentVisibilityLegacyFields(),
    );
    storageContentVisibilityExpect($pdo->registerSchema($pdoSchema)->isValid(), 'PDO legacy schema registration failed');
    storageContentVisibilityExpect(
        $pdo->mutate(new ArrayStorageMutation(
            $pdoSchema->id(),
            'record-1',
            MutationType::Create,
            'content.public.read',
            $legacyValues,
        )) === StorageDecisionStatus::Allowed,
        'PDO compatibility record mutation failed',
    );
    $pdoProjection = storageContentVisibilitySingleProjection(
        $pdo->records(new ArrayStorageQuery($pdoSchema->id(), 'content.public.read')),
    );

    $expectedProjection = ['public_value' => 'PUBLIC_VALUE'];
    storageContentVisibilityExpect($inMemoryProjection === $expectedProjection, 'in-memory projection was not exact-public');
    storageContentVisibilityExpect($pdoProjection === $expectedProjection, 'PDO projection was not exact-public');
    storageContentVisibilityExpect(
        $versionedProjection === $inMemoryProjection && $inMemoryProjection === $pdoProjection,
        'the three public projection surfaces disagreed',
    );

    $projectionEvidence = json_encode(
        [$versionedProjection, $inMemoryProjection, $pdoProjection],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    foreach (array_diff(array_values($legacyValues), ['PUBLIC_VALUE']) as $protectedValue) {
        storageContentVisibilityExpect(
            !str_contains($projectionEvidence, $protectedValue),
            'a non-public raw value leaked into public projection evidence',
        );
    }

    $invalidInMemorySchema = new ArrayStorageSchema(
        'content.legacy.invalid_in_memory',
        '1',
        'content.public.read',
        'in_memory',
        [
            ['name' => 'public_value', 'type' => 'string', 'required' => true, 'visibility' => 'public'],
            ['name' => 'invalid_value', 'type' => 'string', 'required' => true, 'visibility' => 7],
        ],
    );
    storageContentVisibilityExpect(
        !$inMemory->registerSchema($invalidInMemorySchema)->isValid(),
        'in-memory runtime did not fail closed for non-string visibility',
    );

    $invalidPdo = PdoLocalDevStorageAdapter::inMemorySqlite('testing');
    $invalidPdoSchema = new ArrayStorageSchema(
        'content.legacy.invalid_pdo',
        '1',
        'content.public.read',
        PdoLocalDevStorageAdapter::localDevProfile()->id(),
        [
            ['name' => 'public_value', 'type' => 'string', 'required' => true, 'visibility' => 'public'],
            ['name' => 'invalid_value', 'type' => 'string', 'required' => true, 'visibility' => 7],
        ],
    );
    storageContentVisibilityExpect($invalidPdo->registerSchema($invalidPdoSchema)->isValid(), 'PDO invalid legacy fixture registration failed');
    storageContentVisibilityExpect(
        $invalidPdo->mutate(new ArrayStorageMutation(
            $invalidPdoSchema->id(),
            'record-1',
            MutationType::Create,
            'content.public.read',
            ['public_value' => 'PUBLIC_VALUE', 'invalid_value' => 'INVALID_VALUE'],
        )) === StorageDecisionStatus::Allowed,
        'PDO invalid legacy fixture mutation failed',
    );
    storageContentVisibilityExpect(
        storageContentVisibilitySingleProjection(
            $invalidPdo->records(new ArrayStorageQuery($invalidPdoSchema->id(), 'content.public.read')),
        ) === $expectedProjection,
        'PDO runtime did not fail closed for non-string visibility',
    );
} finally {
    Facade::clearResolvedInstances();
    foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageContentVisibilityCompatibilityTest passed.\n";
