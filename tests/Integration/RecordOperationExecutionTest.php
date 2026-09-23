<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Core\Contracts\OperationAccessGate;
use Larena\Core\Contracts\OperationAuditRecorder;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\CatalogOperationHandler;
use Larena\Core\Runtime\ConnectionOperationTransactionBoundary;
use Larena\Core\Runtime\OperationHandlerCatalog;
use Larena\Core\Runtime\RegistryOperationRuntime;
use Larena\Core\Runtime\SyncOperationRuntime;
use Larena\Core\Runtime\UndeclaredCapabilityGate;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Registry\StorageOperationProvider;
use Larena\Storage\Runtime\DatabaseAdminRecordTreeReader;
use Larena\Storage\Runtime\RecordOperationHandlers;
use Larena\Storage\Runtime\VersionedStorage;

require_once __DIR__ . '/../Support/structure-role-schema.php';

// Record create and update as registry operations: the registry names the
// handler, core's catalog serves it, and an update is a compare-and-swap.
function record_operation_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class RecordOperationAllowAll implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final class RecordOperationNullSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
    }
}

final class RecordOperationGate implements OperationAccessGate
{
    /** @var list<string> */
    public array $asked = [];

    public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        $this->asked[] = (string) $descriptor->accessScope;

        return OperationDecision::allowed(OperationExecutionMode::Sync);
    }
}

final class RecordOperationAudit implements OperationAuditRecorder
{
    public function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision, string $phase): array
    {
        return ['phase' => $phase];
    }

    public function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result, string $phase): array
    {
        return ['phase' => $phase];
    }
}

$path = tempnam(sys_get_temp_dir(), 'record-operations-') . '.sqlite';
file_put_contents($path, '');
$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.connection', $connection);
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());
(require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
(require __DIR__ . '/../../database/migrations/platform/2026_09_27_000001_create_larena_storage_record_relations_table.php')->up();

$storage = new VersionedStorage(
    $connection,
    PropertyTypeRegistry::builtIns(),
    new RecordOperationAllowAll(),
    new AuditEventPipeline(new DefaultAuditRedactor(), [new RecordOperationNullSink()]),
);
$schema = $storage->registerSchemaVersion([
    'schema_id' => 'site.pages',
    'owner_package' => 'larena/storage',
    'fields' => [
        ['key' => 'slug', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
        ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
    ],
], null, 'actor:installer');

$registry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider(), new StorageOperationProvider()]);
$catalog = new OperationHandlerCatalog();
$catalog->register('storage.handler.record', static fn (): RecordOperationHandlers => new RecordOperationHandlers($storage));
$handler = new CatalogOperationHandler($registry, $catalog);
$gate = new RecordOperationGate();
$runtime = new RegistryOperationRuntime(
    $registry,
    new SyncOperationRuntime($gate, new UndeclaredCapabilityGate(), new RecordOperationAudit(), $handler, new ConnectionOperationTransactionBoundary($connection)),
    $handler,
);
$context = static fn (array $input): OperationContext => new OperationContext('actor:admin', 'correlation-' . bin2hex(random_bytes(4)), metadata: $input);

$created = $runtime->execute('storage.record.create', $context([
    'schema_id' => 'site.pages', 'schema_version' => $schema->ref->version, 'owner_ref' => 'site.pages:about',
    'values' => ['slug' => 'about', 'title' => 'About'],
]));
record_operation_expect($created->successful(), 'create runs: ' . json_encode($created->runtimeTrace));
$record = $created->payload['record'];
record_operation_expect($record['revision'] === 1 && str_starts_with($record['record_id'], 'record-'), 'first revision');
record_operation_expect($gate->asked === ['storage.record.create'], 'the gate is asked for the declared scope');

$update = [
    'schema_id' => 'site.pages', 'schema_version' => $schema->ref->version, 'owner_ref' => 'site.pages:about',
    'record_id' => $record['record_id'], 'expected_revision' => 1, 'values' => ['slug' => 'about', 'title' => 'About us'],
];
$updated = $runtime->execute('storage.record.update', $context($update));
record_operation_expect($updated->successful(), 'update runs: ' . json_encode($updated->runtimeTrace));
record_operation_expect($updated->payload['record']['revision'] === 2, 'second revision');

// A stale revision is refused and writes nothing.
$stale = $runtime->execute('storage.record.update', $context($update));
record_operation_expect(!$stale->successful(), 'a stale compare-and-swap is refused');
record_operation_expect($connection->table('larena_storage_record_versions')->where('record_id', $record['record_id'])->count() === 2, 'nothing written by the refused update');

// Input that is not a field map is refused before anything is written.
$bad = $runtime->execute('storage.record.create', $context([
    'schema_id' => 'site.pages', 'schema_version' => $schema->ref->version, 'owner_ref' => 'site.pages:x', 'values' => ['about'],
]));
record_operation_expect(!$bad->successful(), 'a list is not a field map');

$proposal = $runtime->propose('storage.record.update', $context($update));
record_operation_expect($proposal->successful() && $proposal->payload['receipt']['intended_change']['kind'] === 'update_record', 'an update can be proposed');

// The editor's tree read: current heads, drafts included, with their parent.
$child = $runtime->execute('storage.record.create', $context([
    'schema_id' => 'site.pages', 'schema_version' => $schema->ref->version, 'owner_ref' => 'site.pages:team',
    'values' => ['slug' => 'team', 'title' => 'Team'],
]));
$childId = $child->payload['record']['record_id'];
$connection->table('larena_storage_record_relations')->insert([
    'relation_id' => 'rel-test-1', 'relation_key' => 'site_tree_parent', 'schema_id' => 'site.pages',
    'from_record_id' => $childId, 'to_record_id' => $record['record_id'], 'kind' => 'tree_parent',
    'tree_child_key' => $childId, 'path' => $record['record_id'] . '/' . $childId, 'depth' => 1, 'order_index' => 0,
    'delete_policy' => 'restrict', 'status' => 'active', 'created_by' => 'actor:admin',
]);
$tree = (new DatabaseAdminRecordTreeReader($connection, new RecordOperationAllowAll()))->tree('site.pages', 'site_tree_parent', 'actor:admin');
$byId = array_column($tree, null, 'record_id');
record_operation_expect(count($tree) === 2, 'two current records');
record_operation_expect($byId[$record['record_id']]['parent_record_id'] === null && $byId[$record['record_id']]['values']['title'] === 'About us', 'the root carries its current values');
record_operation_expect($byId[$childId]['parent_record_id'] === $record['record_id'], 'the child names its parent');

@unlink($path);
echo "Record operation execution passed.\n";
