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
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

require_once __DIR__ . '/../../vendor/autoload.php';

final class StorageEvolutionRecordingAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<string> */
    public array $operations = [];

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->operations[] = $operation;
    }
}

final class StorageEvolutionRecordingAuditSink implements AuditSink
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

/** @return array{path: string, connection: Connection} */
function storageEvolutionOpen(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-evolution-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_evolution_tempfile_failed');
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
    (require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php')->up();

    return ['path' => $path, 'connection' => $connection];
}

function storageEvolutionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function storageEvolutionOwnerPolicies(): StorageSchemaEvolutionOwnerPolicyRegistry
{
    static $registry = null;
    if (!$registry instanceof StorageSchemaEvolutionOwnerPolicyRegistry) {
        $registry = new StorageSchemaEvolutionOwnerPolicyRegistry();
        $registry->seal();
    }

    return $registry;
}

/** @param callable(): mixed $callback */
function storageEvolutionExpectRejected(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (StorageRejected $exception) {
        storageEvolutionExpect($exception->reasonCode === $reason, 'reason mismatch: ' . $exception->reasonCode);

        return;
    }

    throw new RuntimeException('expected rejection: ' . $reason);
}

/** @return array<string, mixed> */
function storageEvolutionDefinition(bool $versionTwo = false): array
{
    $fields = [
        ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
        ['key' => 'zero', 'type' => 'integer', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
        ['key' => 'flag', 'type' => 'boolean', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
        ['key' => 'empty', 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
        ['key' => 'nullable', 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
    ];
    if ($versionTwo) {
        $fields[] = ['key' => 'subtitle', 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => []];
    }

    return ['schema_id' => 'docara.page.evolution', 'owner_package' => 'larena/docara', 'fields' => $fields];
}

$opened = storageEvolutionOpen();
try {
    $authorizer = new StorageEvolutionRecordingAuthorizer();
    $auditSink = new StorageEvolutionRecordingAuditSink();
    $propertyTypes = PropertyTypeRegistry::builtIns();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [$auditSink]);
    $storage = new VersionedStorage($opened['connection'], $propertyTypes, $authorizer, $audit);
    $evolution = new DatabaseStorageSchemaEvolution(
        $opened['connection'],
        $propertyTypes,
        $authorizer,
        $audit,
        storageEvolutionOwnerPolicies(),
    );

    $v1 = $storage->registerSchemaVersion(storageEvolutionDefinition(), null, 'user:admin:1', 'schema-v1');
    storageEvolutionExpectRejected(
        static fn () => $storage->registerSchemaVersion(storageEvolutionDefinition(true), 1, 'user:admin:1', 'direct-v2'),
        'storage_schema_version_requires_migration_plan',
    );

    $exactValues = ['title' => 'Привет 🌍', 'zero' => 0, 'flag' => false, 'empty' => ''];
    $recordV1 = $storage->create('docara:page:evolution-1', $v1->ref, $exactValues, 'user:admin:1', 'record-v1')->version;
    storageEvolutionExpect($recordV1->values === $exactValues, 'v1 values were not preserved exactly');

    $report = $evolution->analyze($v1->ref, storageEvolutionDefinition(true), 'user:admin:1', 'analyze-v2');
    storageEvolutionExpect($report->compatible, 'optional-only schema must be compatible');
    storageEvolutionExpect($report->addedOptionalFieldCount === 1, 'added optional field count mismatch');
    storageEvolutionExpect($report->recordCount === 1, 'analyzed record count mismatch');

    $plan = $evolution->plan($v1->ref, storageEvolutionDefinition(true), 'user:admin:1', 'plan-v2');
    storageEvolutionExpect($plan->source->version === 1 && $plan->target->version === 2, 'plan refs mismatch');
    storageEvolutionExpect($plan->recordCount === 1 && count($plan->records) === 1, 'plan records mismatch');
    storageEvolutionExpect($evolution->explain($plan->planRef, 'user:admin:1')->planHash === $plan->planHash, 'plan explain mismatch');

    $result = $evolution->apply($plan->planRef, $plan->planHash, 'user:admin:1', 'apply-v2');
    storageEvolutionExpect($result->target->version === 2, 'result target mismatch');
    storageEvolutionExpect($result->migratedRecordCount === 1, 'migrated record count mismatch');
    $recordV2 = $storage->readAdminCurrentVersion('docara.page.evolution', 'docara:page:evolution-1', 'user:admin:1');
    storageEvolutionExpect($recordV2 !== null && $recordV2->ref->revision === 2, 'record head was not migrated');
    storageEvolutionExpect($recordV2->schema->version === 2, 'record schema was not advanced');
    storageEvolutionExpect($recordV2->operation === 'schema_migration', 'record operation mismatch');
    $v1StoredJson = (string) $opened['connection']->table('larena_storage_record_versions')
        ->where('record_id', $recordV1->ref->recordId)
        ->where('revision', 1)
        ->value('values_json');
    $v2StoredJson = (string) $opened['connection']->table('larena_storage_record_versions')
        ->where('record_id', $recordV1->ref->recordId)
        ->where('revision', 2)
        ->value('values_json');
    $canonicalV1Values = json_decode($v1StoredJson, true, 512, JSON_THROW_ON_ERROR);
    storageEvolutionExpect(
        $recordV2->values === $canonicalV1Values,
        '0/false/empty/Unicode or absent optional key changed during migration: ' . json_encode($recordV2->values, JSON_UNESCAPED_UNICODE),
    );
    storageEvolutionExpect(($recordV2->values['zero'] ?? null) === 0, 'integer zero changed type or value');
    storageEvolutionExpect(array_key_exists('flag', $recordV2->values) && $recordV2->values['flag'] === false, 'boolean false changed type or value');
    storageEvolutionExpect(($recordV2->values['empty'] ?? null) === '', 'empty string changed type or value');
    storageEvolutionExpect(($recordV2->values['title'] ?? null) === 'Привет 🌍', 'Unicode string changed');
    storageEvolutionExpect(!array_key_exists('nullable', $recordV2->values), 'absent optional source field was materialized');
    storageEvolutionExpect(!array_key_exists('subtitle', $recordV2->values), 'new optional field was materialized');
    storageEvolutionExpect($recordV2->contentHash === $recordV1->contentHash, 'schema migration changed the exact record content hash');
    storageEvolutionExpect($v2StoredJson === $v1StoredJson, 'schema migration changed canonical values JSON');

    $planRowBeforeRepeat = (array) $opened['connection']->table('larena_storage_schema_migration_plans')
        ->where('plan_ref', $plan->planRef)
        ->first();
    $resultRowBeforeRepeat = (array) $opened['connection']->table('larena_storage_schema_migration_results')
        ->where('plan_ref', $plan->planRef)
        ->first();

    storageEvolutionExpectRejected(
        static fn () => $evolution->apply($plan->planRef, $plan->planHash, 'user:admin:1', 'repeat-v2'),
        'storage_schema_migration_plan_already_applied',
    );
    storageEvolutionExpect(
        (array) $opened['connection']->table('larena_storage_schema_migration_plans')->where('plan_ref', $plan->planRef)->first() === $planRowBeforeRepeat,
        'repeat apply mutated immutable plan row',
    );
    storageEvolutionExpect(
        (array) $opened['connection']->table('larena_storage_schema_migration_results')->where('plan_ref', $plan->planRef)->first() === $resultRowBeforeRepeat,
        'repeat apply mutated immutable result row',
    );
    foreach (['storage.schema_migration.diff', 'storage.schema_migration.plan', 'storage.schema_migration.explain', 'storage.schema_migration.dispatch'] as $operation) {
        storageEvolutionExpect(in_array($operation, $authorizer->operations, true), 'missing Access operation ' . $operation);
    }

    $auditJson = json_encode($auditSink->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['title', 'subtitle', 'Привет 🌍'] as $forbidden) {
        storageEvolutionExpect(!str_contains($auditJson, $forbidden), 'audit leaked schema/value material');
    }

    storageEvolutionExpect($opened['connection']->table('larena_storage_schema_migration_plans')->count() === 1, 'plan was not immutable/persisted once');
    storageEvolutionExpect($opened['connection']->table('larena_storage_schema_migration_results')->count() === 1, 'result was not persisted once');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageSchemaEvolutionTest passed.\n";
