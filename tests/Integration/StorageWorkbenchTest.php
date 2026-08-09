<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

require_once __DIR__ . '/../../vendor/autoload.php';

final class WorkbenchAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<string> */
    public array $operations = [];

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->operations[] = $operation;
    }
}

final readonly class WorkbenchScopeProvider implements QueryScopeProvider
{
    /** @param array<string, string> $actorScopes */
    public function __construct(private array $actorScopes, private bool $tamper = false)
    {
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return in_array($resourceType, ['storage.workbench.structure', 'storage.workbench.record'], true)
            && str_starts_with($operation, 'storage.workbench.');
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        if ($this->tamper) {
            return ['scope_ref' => 'scope:tenant-tampered'];
        }

        return ['scope_ref' => $this->actorScopes[$actor] ?? 'scope:denied'];
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        $scope = $this->actorScopes[$actor] ?? null;
        $requested = $context['requested_scope_ref'] ?? null;
        $validContext = ($context['resource_type'] ?? null) === $resourceType
            && count($context) === 2;
        if (!$validContext || !is_string($scope) || $scope !== $requested) {
            return AccessDecision::deny($operation, $actor, $resourceType . ':denied', 'workbench_scope_denied');
        }

        return AccessDecision::allow($operation, $actor, $resourceType . ':' . $scope, 'workbench_scope_allowed');
    }
}

final class WorkbenchAuditSink implements AuditSink
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

/** @return array{connection: Connection, capsule: Capsule} */
function workbenchOpen(string $path): array
{
    if (!is_file($path) && file_put_contents($path, '') === false) {
        throw new RuntimeException('workbench_database_create_failed');
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

    return ['connection' => $connection, 'capsule' => $capsule];
}

function workbenchInstall(): void
{
    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_08_09_000001_create_larena_storage_workbench_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_08_09_000002_scope_larena_storage_workbench_identity.php')->up();
}

/** @return array{workbench: DatabaseStorageWorkbench, authorizer: WorkbenchAuthorizer, sink: WorkbenchAuditSink} */
function workbenchRuntime(Connection $connection, ?WorkbenchScopeProvider $scope = null): array
{
    $authorizer = new WorkbenchAuthorizer();
    $sink = new WorkbenchAuditSink();
    $properties = PropertyTypeRegistry::builtIns();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [$sink]);
    $scope ??= new WorkbenchScopeProvider([
        'actor:admin:alpha' => 'scope:tenant-alpha',
        'actor:admin:beta' => 'scope:tenant-beta',
    ]);
    $versioned = new VersionedStorage(
        $connection,
        $properties,
        $authorizer,
        $audit,
        $scope,
        'goal2-storage-workbench-test-cursor-key-minimum-32',
    );
    $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $policies->protect(
        'larena/storage',
        static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {
            if (!$capability instanceof DatabaseStorageWorkbench
                || !str_starts_with($context->source->schemaId, 'workbench.')) {
                throw new RuntimeException('capability_invalid');
            }
        },
        'workbench.',
    );
    $policies->seal();
    $evolution = new DatabaseStorageSchemaEvolution($connection, $properties, $authorizer, $audit, $policies);
    $workbench = new DatabaseStorageWorkbench(
        $connection,
        $properties,
        $authorizer,
        $scope,
        $versioned,
        $evolution,
        $policies,
        'goal2-storage-workbench-test-cursor-key-minimum-32',
    );

    return ['workbench' => $workbench, 'authorizer' => $authorizer, 'sink' => $sink];
}

/** @return array<string, mixed> */
function inventoryDescriptor(bool $versionTwo = false, bool $reordered = false): array
{
    $fields = [
        ['key' => 'name', 'label' => 'Asset name', 'position' => $reordered ? 20 : 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['min_length' => 1, 'max_length' => 100]],
        ['key' => 'quantity', 'label' => 'Quantity', 'position' => $reordered ? 10 : 20, 'type' => 'integer', 'type_version' => 1, 'required' => true, 'visibility' => 'admin', 'constraints' => ['min' => 0, 'max' => 10000]],
        ['key' => 'document', 'label' => 'Document', 'position' => 30, 'type' => 'file', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
    ];
    if ($versionTwo) {
        $fields[] = ['key' => 'note', 'label' => 'Note', 'position' => 40, 'type' => 'text', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []];
    }

    return ['structure_id' => 'workbench.inventory_asset', 'label' => 'Inventory assets', 'fields' => $fields];
}

/** @return array<string, mixed> */
function trainingDescriptor(): array
{
    return [
        'structure_id' => 'workbench.training_session',
        'label' => 'Training sessions',
        'fields' => [
            ['key' => 'topic', 'label' => 'Topic', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['max_length' => 120]],
            ['key' => 'session_date', 'label' => 'Date', 'position' => 20, 'type' => 'date', 'type_version' => 1, 'required' => true, 'visibility' => 'admin', 'constraints' => []],
            ['key' => 'remote', 'label' => 'Remote', 'position' => 30, 'type' => 'boolean', 'type_version' => 1, 'required' => true, 'visibility' => 'admin', 'constraints' => []],
        ],
    ];
}

/** @return array<string, mixed> */
function betaInventoryDescriptor(bool $versionTwo = false): array
{
    $fields = [
        ['key' => 'sku', 'label' => 'SKU', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['min_length' => 1, 'max_length' => 40]],
        ['key' => 'available', 'label' => 'Available', 'position' => 20, 'type' => 'boolean', 'type_version' => 1, 'required' => true, 'visibility' => 'admin', 'constraints' => []],
    ];
    if ($versionTwo) {
        $fields[] = ['key' => 'note', 'label' => 'Beta note', 'position' => 30, 'type' => 'text', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []];
    }

    return ['structure_id' => 'workbench.inventory_asset', 'label' => 'Beta inventory assets', 'fields' => $fields];
}

function workbenchExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function workbenchCanonicalJson(mixed $value): string
{
    return (new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns()))->canonicalJson($value);
}

function workbenchLegacyUpgradeProof(): void
{
    $path = tempnam(sys_get_temp_dir(), 'larena-workbench-upgrade-');
    if (!is_string($path)) {
        throw new RuntimeException('workbench_upgrade_tempfile_failed');
    }
    $opened = workbenchOpen($path);
    try {
        (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
        (require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php')->up();
        (require __DIR__ . '/../../database/migrations/2026_08_09_000001_create_larena_storage_workbench_tables.php')->up();
        $database = $opened['connection'];
        $structureId = 'workbench.legacy_asset';
        $scopeRef = 'scope:tenant-alpha';
        $recordId = 'record-' . str_repeat('1', 32);
        $createdAt = '2026-08-09 00:00:00';
        $fields = [
            ['key' => 'name', 'label' => 'Name', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['max_length' => 100]],
        ];
        $definition = [
            'schema_id' => $structureId,
            'owner_package' => 'larena/storage',
            'fields' => [
                ['key' => 'larena_scope_ref', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => 191, 'min_length' => 1]],
                ['key' => 'larena_record_state', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => 8, 'min_length' => 6]],
                ['key' => 'name', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => ['max_length' => 100]],
            ],
        ];
        $definitionJson = workbenchCanonicalJson($definition);
        $definitionHash = hash('sha256', $definitionJson);
        $values = ['larena_record_state' => 'active', 'larena_scope_ref' => $scopeRef, 'name' => 'Legacy alpha'];
        $valuesJson = workbenchCanonicalJson($values);
        $contentHash = hash('sha256', $valuesJson);
        $descriptorHash = hash('sha256', workbenchCanonicalJson([
            'structure_id' => $structureId,
            'scope_ref' => $scopeRef,
            'version' => 1,
            'schema_version' => 1,
            'label' => 'Legacy assets',
            'fields' => $fields,
        ]));
        $database->table('larena_storage_schemas')->insert(['schema_id' => $structureId, 'current_version' => 1, 'current_hash' => $definitionHash, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $database->table('larena_storage_schema_versions')->insert(['schema_id' => $structureId, 'version' => 1, 'definition' => $definitionJson, 'definition_hash' => $definitionHash, 'owner_package' => 'larena/storage', 'created_by' => 'actor:admin:alpha', 'correlation_id' => 'legacy-schema', 'created_at' => $createdAt]);
        $database->table('larena_storage_records')->insert(['record_id' => $recordId, 'schema_id' => $structureId, 'owner_ref' => 'workbench.record:' . str_repeat('2', 32), 'current_revision' => 1, 'current_schema_version' => 1, 'current_hash' => $contentHash, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $database->table('larena_storage_record_versions')->insert(['schema_id' => $structureId, 'record_id' => $recordId, 'revision' => 1, 'owner_ref' => 'workbench.record:' . str_repeat('2', 32), 'schema_version' => 1, 'values_json' => $valuesJson, 'content_hash' => $contentHash, 'operation' => 'create', 'created_by' => 'actor:admin:alpha', 'correlation_id' => 'legacy-record', 'created_at' => $createdAt]);
        $database->table('larena_storage_workbench_structures')->insert(['structure_id' => $structureId, 'scope_ref' => $scopeRef, 'current_version' => 1, 'current_schema_version' => 1, 'current_hash' => $descriptorHash, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $database->table('larena_storage_workbench_structure_versions')->insert(['structure_id' => $structureId, 'version' => 1, 'scope_ref' => $scopeRef, 'label' => 'Legacy assets', 'fields_json' => workbenchCanonicalJson($fields), 'schema_version' => 1, 'descriptor_hash' => $descriptorHash, 'created_by' => 'actor:admin:alpha', 'correlation_id' => 'legacy-structure', 'created_at' => $createdAt]);

        $migration = require __DIR__ . '/../../database/migrations/2026_08_09_000002_scope_larena_storage_workbench_identity.php';
        $migration->up();
        $migration->up();
        $runtime = workbenchRuntime($database);
        $structure = $runtime['workbench']->readStructure($scopeRef, $structureId, 'actor:admin:alpha');
        $record = $runtime['workbench']->readRecord($scopeRef, $structureId, $recordId, 'actor:admin:alpha');
        workbenchExpect($structure->structureId === $structureId && $record->values === ['name' => 'Legacy alpha'], 'legacy scoped identity upgrade readback mismatch');
        workbenchExpect($structure->schema->schemaId !== $structureId && !str_contains($structure->schema->schemaId, 'tenant-alpha'), 'legacy upgrade did not produce safe scope-bound schema identity');
        $beforeRollback = workbenchState($database);
        try {
            $migration->down();
            throw new RuntimeException('destructive scoped identity rollback unexpectedly accepted');
        } catch (RuntimeException $exception) {
            workbenchExpect($exception->getMessage() === 'storage_workbench_scoped_identity_rollback_would_lose_data', 'scoped identity rollback reason mismatch');
        }
        workbenchExpect(workbenchState($database) === $beforeRollback, 'refused scoped identity rollback mutated state');
    } finally {
        Facade::clearResolvedInstances();
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
            @unlink($file);
        }
    }
}

/** @param callable(): mixed $callback */
function workbenchRejects(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (StorageRejected $exception) {
        workbenchExpect($exception->reasonCode === $reason, "expected {$reason}, got {$exception->reasonCode}");
        workbenchExpect($exception->getPrevious() === null, 'public rejection retained a private previous exception');

        return;
    }
    throw new RuntimeException("expected rejection {$reason}");
}

/** @return array<string, mixed> */
function workbenchState(Connection $connection): array
{
    $tables = [
        'larena_storage_schemas', 'larena_storage_schema_versions', 'larena_storage_records',
        'larena_storage_record_versions', 'larena_storage_schema_migration_plans',
        'larena_storage_schema_migration_plan_records', 'larena_storage_schema_migration_results',
        'larena_storage_workbench_structures', 'larena_storage_workbench_structure_versions',
    ];
    $state = [];
    foreach ($tables as $table) {
        $state[$table] = json_decode(json_encode($connection->table($table)->get()->all(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    return $state;
}

/** @return array<string, mixed> */
function workbenchRestartProjection(string $path): array
{
    $opened = workbenchOpen($path);
    $runtime = workbenchRuntime($opened['connection']);
    $alphaStructures = $runtime['workbench']->listStructures('scope:tenant-alpha', 'actor:admin:alpha');
    $alphaPage = $runtime['workbench']->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        'workbench.inventory_asset',
        sort: [['field' => 'quantity', 'direction' => 'asc']],
        limit: 100,
        includeArchived: true,
    ), 'actor:admin:alpha');
    $betaStructures = $runtime['workbench']->listStructures('scope:tenant-beta', 'actor:admin:beta');
    $betaPage = $runtime['workbench']->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-beta',
        'workbench.inventory_asset',
        sort: [['field' => 'sku', 'direction' => 'asc']],
        limit: 100,
        includeArchived: true,
    ), 'actor:admin:beta');

    return [
        'alpha_structures' => array_map(static fn ($structure): array => [
            'id' => $structure->structureId,
            'version' => $structure->version,
            'schema_version' => $structure->schema->version,
            'hash' => $structure->descriptorHash,
        ], $alphaStructures),
        'alpha_records' => array_map(static fn ($record): array => [
            'id' => $record->recordId,
            'revision' => $record->revision,
            'state' => $record->state,
            'values' => $record->values,
        ], $alphaPage->items),
        'beta_structures' => array_map(static fn ($structure): array => [
            'id' => $structure->structureId,
            'version' => $structure->version,
            'schema_version' => $structure->schema->version,
            'hash' => $structure->descriptorHash,
        ], $betaStructures),
        'beta_records' => array_map(static fn ($record): array => [
            'id' => $record->recordId,
            'revision' => $record->revision,
            'state' => $record->state,
            'values' => $record->values,
        ], $betaPage->items),
    ];
}

$restartPath = getenv('G2_STORAGE_WORKBENCH_RESTART_DB');
if (is_string($restartPath) && $restartPath !== '') {
    echo json_encode(workbenchRestartProjection($restartPath), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

workbenchLegacyUpgradeProof();

$path = tempnam(sys_get_temp_dir(), 'larena-workbench-');
if (!is_string($path)) {
    throw new RuntimeException('workbench_tempfile_failed');
}
$opened = workbenchOpen($path);
try {
    workbenchInstall();
    $runtime = workbenchRuntime($opened['connection']);
    $workbench = $runtime['workbench'];

    $emptyState = workbenchState($opened['connection']);
    $invalid = inventoryDescriptor();
    $invalid['fields'][0]['type'] = 'unknown';
    workbenchRejects(
        static fn () => $workbench->createStructure('scope:tenant-alpha', $invalid, 'actor:admin:alpha'),
        'storage_schema_field_invalid',
    );
    workbenchExpect(workbenchState($opened['connection']) === $emptyState, 'invalid initial create produced partial durable state');
    workbenchRejects(
        static fn () => $workbench->createStructure('scope:tenant-alpha', inventoryDescriptor(), 'actor:admin:beta'),
        'storage_workbench_scope_denied',
    );
    workbenchExpect(workbenchState($opened['connection']) === $emptyState, 'foreign-scope create mutated state');

    $inventory = $workbench->createStructure('scope:tenant-alpha', inventoryDescriptor(), 'actor:admin:alpha', 'inventory-create');
    $training = $workbench->createStructure('scope:tenant-alpha', trainingDescriptor(), 'actor:admin:alpha', 'training-create');
    $betaInventory = $workbench->createStructure('scope:tenant-beta', betaInventoryDescriptor(), 'actor:admin:beta', 'beta-inventory-create');
    workbenchExpect($inventory->structureId !== $training->structureId, 'generic fixtures collapsed into one model');
    workbenchExpect($inventory->structureId === $betaInventory->structureId, 'public structure identity changed across scopes');
    workbenchExpect($inventory->schema->schemaId !== $betaInventory->schema->schemaId, 'internal storage schema identity was not scope-bound');
    workbenchExpect(
        !str_contains($inventory->schema->schemaId, 'tenant-alpha')
            && !str_contains($betaInventory->schema->schemaId, 'tenant-beta')
            && preg_match('/^workbench\.scoped\.v1\.[a-f0-9]{64}$/', $inventory->schema->schemaId) === 1
            && preg_match('/^workbench\.scoped\.v1\.[a-f0-9]{64}$/', $betaInventory->schema->schemaId) === 1,
        'internal storage schema identity exposed raw scope or was not canonical',
    );
    workbenchExpect(count($workbench->listStructures('scope:tenant-alpha', 'actor:admin:alpha')) === 2, 'structure catalog mismatch');
    workbenchExpect(count($workbench->listStructures('scope:tenant-beta', 'actor:admin:beta')) === 1, 'beta structure catalog mismatch');

    $fileUuid = '018f4f4c-706e-7b1f-9a3e-c93b5656a6f0';
    $alpha = $workbench->createRecord('scope:tenant-alpha', $inventory->structureId, ['name' => 'Alpha asset', 'quantity' => 7, 'document' => $fileUuid], 'actor:admin:alpha');
    $beta = $workbench->createRecord('scope:tenant-alpha', $inventory->structureId, ['name' => 'Beta asset', 'quantity' => 2], 'actor:admin:alpha');
    $gamma = $workbench->createRecord('scope:tenant-alpha', $inventory->structureId, ['name' => 'Gamma asset', 'quantity' => 11], 'actor:admin:alpha');
    $session = $workbench->createRecord('scope:tenant-alpha', $training->structureId, ['topic' => 'Safety', 'session_date' => '2026-08-12', 'remote' => true], 'actor:admin:alpha');
    $betaFirst = $workbench->createRecord('scope:tenant-beta', $betaInventory->structureId, ['sku' => 'B-002', 'available' => true], 'actor:admin:beta');
    $betaSecond = $workbench->createRecord('scope:tenant-beta', $betaInventory->structureId, ['sku' => 'B-001', 'available' => false], 'actor:admin:beta');
    workbenchExpect($session->values['remote'] === true, 'second generic model value mismatch');
    workbenchExpect(!array_key_exists('larena_scope_ref', $alpha->values), 'reserved scope leaked into values');

    $beforeForeign = workbenchState($opened['connection']);
    workbenchRejects(
        static fn () => $workbench->readRecord('scope:tenant-alpha', $inventory->structureId, $alpha->recordId, 'actor:admin:beta'),
        'storage_workbench_scope_denied',
    );
    workbenchRejects(
        static fn () => $workbench->updateRecord('scope:tenant-alpha', $inventory->structureId, $alpha->recordId, 1, ['name' => 'Stolen', 'quantity' => 1], 'actor:admin:beta'),
        'storage_workbench_scope_denied',
    );
    workbenchExpect(workbenchState($opened['connection']) === $beforeForeign, 'foreign direct-id operation mutated state');

    $beforeScopedDirectId = workbenchState($opened['connection']);
    workbenchRejects(
        static fn () => $workbench->readRecord('scope:tenant-beta', $betaInventory->structureId, $alpha->recordId, 'actor:admin:beta'),
        'storage_workbench_record_unknown',
    );
    workbenchRejects(
        static fn () => $workbench->readRecord('scope:tenant-alpha', $inventory->structureId, $betaFirst->recordId, 'actor:admin:alpha'),
        'storage_workbench_record_unknown',
    );
    workbenchExpect(workbenchState($opened['connection']) === $beforeScopedDirectId, 'authorized direct-id cross-scope probe mutated state');

    $betaPage = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-beta',
        $betaInventory->structureId,
        sort: [['field' => 'sku', 'direction' => 'asc']],
        limit: 1,
    ), 'actor:admin:beta');
    workbenchExpect(count($betaPage->items) === 1 && $betaPage->items[0]->recordId === $betaSecond->recordId, 'beta scoped query leaked or sorted incorrectly');
    workbenchExpect($betaPage->continuation !== null, 'beta scoped cursor missing');
    workbenchRejects(
        static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery(
            'scope:tenant-alpha',
            $inventory->structureId,
            sort: [['field' => 'quantity', 'direction' => 'asc']],
            limit: 1,
            continuation: $betaPage->continuation,
        ), 'actor:admin:alpha'),
        'storage_workbench_record_continuation_invalid',
    );
    $betaUpdated = $workbench->updateRecord(
        'scope:tenant-beta',
        $betaInventory->structureId,
        $betaFirst->recordId,
        $betaFirst->revision,
        ['sku' => 'B-002', 'available' => false],
        'actor:admin:beta',
    );
    workbenchExpect($betaUpdated->revision === 2 && $betaUpdated->values['available'] === false, 'beta scoped CAS update mismatch');
    $betaHistory = $workbench->recordHistory('scope:tenant-beta', $betaInventory->structureId, $betaFirst->recordId, 'actor:admin:beta');
    workbenchExpect(array_map(static fn ($record): int => $record->revision, $betaHistory) === [2, 1], 'beta scoped history mismatch');
    $betaEvolved = $workbench->updateStructure('scope:tenant-beta', $betaInventory->structureId, 1, betaInventoryDescriptor(true), 'actor:admin:beta');
    workbenchExpect($betaEvolved->version === 2 && $betaEvolved->schema->version === 2, 'beta scoped schema evolution mismatch');

    $pageOne = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        $inventory->structureId,
        search: 'asset',
        sort: [['field' => 'quantity', 'direction' => 'asc']],
        limit: 2,
    ), 'actor:admin:alpha');
    workbenchExpect(array_map(static fn ($record): int => $record->values['quantity'], $pageOne->items) === [2, 7], 'typed sort page one mismatch');
    workbenchExpect($pageOne->continuation !== null, 'pagination continuation missing');
    $pageTwo = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        $inventory->structureId,
        search: 'asset',
        sort: [['field' => 'quantity', 'direction' => 'asc']],
        limit: 2,
        continuation: $pageOne->continuation,
    ), 'actor:admin:alpha');
    workbenchExpect(array_map(static fn ($record): int => $record->values['quantity'], $pageTwo->items) === [11], 'typed sort page two mismatch');
    $tamperedCursor = substr((string) $pageOne->continuation, 0, -1) . ((string) $pageOne->continuation[-1] === 'A' ? 'B' : 'A');
    workbenchRejects(
        static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery(
            'scope:tenant-alpha',
            $inventory->structureId,
            search: 'asset',
            sort: [['field' => 'quantity', 'direction' => 'asc']],
            limit: 2,
            continuation: $tamperedCursor,
        ), 'actor:admin:alpha'),
        'storage_workbench_record_continuation_invalid',
    );

    $updated = $workbench->updateRecord('scope:tenant-alpha', $inventory->structureId, $alpha->recordId, $alpha->revision, ['quantity' => 8, 'name' => 'Alpha asset', 'document' => $fileUuid], 'actor:admin:alpha');
    workbenchExpect($updated->revision === 2 && $updated->values['quantity'] === 8, 'record CAS update mismatch');
    try {
        $workbench->updateRecord('scope:tenant-alpha', $inventory->structureId, $alpha->recordId, 1, ['quantity' => 9, 'name' => 'Alpha asset'], 'actor:admin:alpha');
        throw new RuntimeException('stale record revision unexpectedly accepted');
    } catch (StorageConflict $exception) {
        workbenchExpect($exception->reasonCode === 'storage_workbench_record_revision_conflict', 'stale revision reason mismatch');
    }

    $metadataOnly = $workbench->updateStructure('scope:tenant-alpha', $inventory->structureId, 1, inventoryDescriptor(false, true), 'actor:admin:alpha');
    workbenchExpect($metadataOnly->version === 2 && $metadataOnly->schema->version === 1, 'metadata-only field order update changed schema');
    $evolved = $workbench->updateStructure('scope:tenant-alpha', $inventory->structureId, 2, inventoryDescriptor(true, true), 'actor:admin:alpha');
    workbenchExpect($evolved->version === 3 && $evolved->schema->version === 2, 'optional field evolution mismatch');
    $afterMigration = $workbench->readRecord('scope:tenant-alpha', $inventory->structureId, $alpha->recordId, 'actor:admin:alpha');
    workbenchExpect($afterMigration->revision === 3 && $afterMigration->schemaVersion === 2, 'record was not schema-migrated');
    $incompatible = inventoryDescriptor(true, true);
    $incompatible['fields'][0]['required'] = false;
    $beforeIncompatible = workbenchState($opened['connection']);
    workbenchRejects(
        static fn () => $workbench->updateStructure('scope:tenant-alpha', $inventory->structureId, 3, $incompatible, 'actor:admin:alpha'),
        'storage_workbench_structure_field_changed',
    );
    workbenchExpect(workbenchState($opened['connection']) === $beforeIncompatible, 'incompatible schema update mutated state');

    $history = $workbench->recordHistory('scope:tenant-alpha', $inventory->structureId, $alpha->recordId, 'actor:admin:alpha');
    workbenchExpect(array_map(static fn ($record): int => $record->revision, $history) === [3, 2, 1], 'immutable record history mismatch');
    $currentBeta = $workbench->readRecord('scope:tenant-alpha', $inventory->structureId, $beta->recordId, 'actor:admin:alpha');
    $currentGamma = $workbench->readRecord('scope:tenant-alpha', $inventory->structureId, $gamma->recordId, 'actor:admin:alpha');
    $beforeAtomic = workbenchState($opened['connection']);
    try {
        $workbench->bulkArchive('scope:tenant-alpha', $inventory->structureId, [$beta->recordId => $currentBeta->revision, $gamma->recordId => 1], 'actor:admin:alpha');
        throw new RuntimeException('stale bulk archive unexpectedly accepted');
    } catch (StorageConflict $exception) {
        workbenchExpect($exception->reasonCode === 'storage_workbench_record_revision_conflict', 'bulk conflict reason mismatch');
    }
    workbenchExpect(workbenchState($opened['connection']) === $beforeAtomic, 'failed bulk archive partially mutated state');
    $archived = $workbench->bulkArchive('scope:tenant-alpha', $inventory->structureId, [$beta->recordId => $currentBeta->revision, $gamma->recordId => $currentGamma->revision], 'actor:admin:alpha');
    workbenchExpect(count($archived) === 2 && $archived[0]->state === 'archived' && $archived[1]->state === 'archived', 'bulk archive outcome mismatch');

    $reservedBefore = workbenchState($opened['connection']);
    workbenchRejects(
        static fn () => $workbench->createRecord('scope:tenant-alpha', $inventory->structureId, ['name' => 'Bad', 'quantity' => 1, 'larena_scope_ref' => 'scope:tenant-beta'], 'actor:admin:alpha'),
        'storage_workbench_record_reserved_field',
    );
    workbenchExpect(workbenchState($opened['connection']) === $reservedBefore, 'reserved-field rejection mutated state');

    $projection = workbenchRestartProjection($path);
    $encoded = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    workbenchExpect(
        !str_contains($encoded, 'larena_scope_ref')
            && !str_contains($encoded, 'workbench.record:')
            && !str_contains($encoded, 'workbench.scoped.v1.'),
        'projection leaked protected or internal identity fields',
    );
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $outputs = [];
    for ($process = 0; $process < 4; $process++) {
        $lines = [];
        $exit = 0;
        exec('G2_STORAGE_WORKBENCH_RESTART_DB=' . escapeshellarg($path) . ' ' . $command, $lines, $exit);
        workbenchExpect($exit === 0, 'fresh process restart failed');
        $outputs[] = implode("\n", $lines);
    }
    workbenchExpect(count(array_unique($outputs)) === 1 && $outputs[0] === $encoded, 'fresh process projection was not byte-identical');

    foreach (['storage.workbench.structure.create', 'storage.workbench.record.create', 'storage.workbench.record.read', 'storage.workbench.record.list', 'storage.workbench.record.update', 'storage.workbench.record.bulk_archive', 'storage.workbench.record.history'] as $operation) {
        workbenchExpect(in_array($operation, $runtime['authorizer']->operations, true), 'missing Access operation ' . $operation);
    }
} finally {
    Facade::clearResolvedInstances();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchTest passed.\n";
