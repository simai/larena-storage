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
use Larena\Storage\Contracts\StorageRecordListPage;
use Larena\Storage\Contracts\StorageRecordListQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;

require_once __DIR__ . '/../../vendor/autoload.php';

final class RecordListAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<string> */
    public array $operations = [];

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->operations[] = $operation;
    }
}

final class RecordListScopeProvider implements QueryScopeProvider
{
    public function __construct(
        private readonly string $tenant,
        private readonly bool $allowed = true,
        private readonly bool $supported = true,
        private readonly string $responseMode = 'valid',
    ) {
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return $this->supported
            && $resourceType === 'storage.record'
            && $operation === 'storage.record.list';
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        if ($this->responseMode === 'missing_schema') {
            return ['filters' => $query['filters'] ?? []];
        }
        $filters = is_array($query['filters'] ?? null) ? $query['filters'] : [];
        if ($this->responseMode === 'wrong_schema') {
            return ['filters' => $filters, 'schema_id' => 'tampered.schema'];
        }
        if ($this->responseMode === 'remove_caller') {
            unset($filters['rank']);
        }
        if ($this->responseMode === 'change_caller') {
            $filters['rank'] = ['operator' => 'eq', 'value' => 99];
        }
        if ($this->responseMode === 'unknown_field') {
            $filters['provider_unknown'] = ['operator' => 'eq', 'value' => 'PROTECTED_SCOPE_SENTINEL'];
        }
        if ($this->responseMode === 'admin_field') {
            $filters['private_note'] = ['operator' => 'eq', 'value' => 'PROTECTED_SCOPE_SENTINEL'];
        }
        if ($this->responseMode === 'unknown_operator') {
            $filters['tenant'] = ['operator' => 'contains', 'value' => $this->tenant];

            return ['filters' => $filters, 'schema_id' => $query['schema_id'] ?? null];
        }
        if ($this->responseMode === 'public_field') {
            $filters['kind'] = ['operator' => 'eq', 'value' => 'standard'];
        }
        $filters['tenant'] = ['value' => $this->tenant, 'operator' => 'eq'];

        return ['filters' => $filters, 'schema_id' => $query['schema_id'] ?? null];
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        if ($context !== ['resource_type' => $resourceType]) {
            return AccessDecision::deny($operation, $actor, $resourceType, 'record_list_context_invalid');
        }

        return $this->allowed
            ? AccessDecision::allow($operation, $actor, 'storage.scope:' . $this->tenant, 'record_list_scope_resolved')
            : AccessDecision::deny($operation, $actor, 'storage.scope:' . $this->tenant, 'record_list_scope_denied');
    }
}

final class RecordListAuditSink implements AuditSink
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

/** @return array{capsule: Capsule, connection: Connection} */
function recordListOpen(string $path): array
{
    if (!is_file($path) && file_put_contents($path, '') === false) {
        throw new RuntimeException('record_list_database_create_failed');
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

function recordListStorage(Connection $connection, QueryScopeProvider $scope, ?RecordListAuditSink $sink = null): VersionedStorage
{
    return new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        new RecordListAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [$sink ?? new RecordListAuditSink()]),
        $scope,
        's-storage-01-test-cursor-key-32-bytes-minimum',
    );
}

/** @return array<string, mixed> */
function recordListSchema(string $schemaId, string $labelField): array
{
    return [
        'schema_id' => $schemaId,
        'owner_package' => 'larena/storage',
        'fields' => [
            [
                'key' => $labelField,
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'public',
                'constraints' => ['min_length' => 1, 'max_length' => 100],
            ],
            [
                'key' => 'tenant',
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'protected',
                'constraints' => ['min_length' => 1, 'max_length' => 40],
            ],
            [
                'key' => 'kind',
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'public',
                'constraints' => ['min_length' => 1, 'max_length' => 40],
            ],
            [
                'key' => 'rank',
                'type' => 'integer',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'public',
                'constraints' => ['min' => 0, 'max' => 100],
            ],
            [
                'key' => 'private_note',
                'type' => 'string',
                'type_version' => 1,
                'required' => true,
                'visibility' => 'admin',
                'constraints' => ['max_length' => 200],
            ],
        ],
    ];
}

function recordListExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(): mixed $action */
function recordListRejects(callable $action, string $reason): void
{
    try {
        $action();
    } catch (StorageRejected $exception) {
        recordListExpect($exception->reasonCode === $reason, "expected {$reason}, got {$exception->reasonCode}");
        foreach (['PROTECTED_SCOPE_ALPHA', 'PROTECTED_SCOPE_BETA', 'PROTECTED_SCOPE_SENTINEL'] as $protectedValue) {
            recordListExpect(
                !str_contains($exception->getMessage(), $protectedValue),
                'protected provider value leaked into exception diagnostics',
            );
        }
        return;
    }
    throw new RuntimeException("expected rejection {$reason}");
}

/** @return array<string, mixed> */
function recordListPagePayload(StorageRecordListPage $page): array
{
    return [
        'items' => array_map(static fn ($item): array => [
            'schema' => $item->ref->schemaId,
            'record' => $item->ref->recordId,
            'revision' => $item->ref->revision,
            'values' => $item->values,
        ], $page->items),
        'continuation' => $page->continuation,
    ];
}

function recordListRestartRead(string $path): array
{
    $opened = recordListOpen($path);
    $storage = recordListStorage($opened['connection'], new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA'));
    $page = $storage->listCurrentRecords(new StorageRecordListQuery(
        'inventory.widget',
        ['rank' => ['operator' => 'eq', 'value' => 7]],
        1,
    ), 'actor:reader:alpha');

    return recordListPagePayload($page);
}

$restartPath = getenv('S_STORAGE_01_RESTART_DB');
if (is_string($restartPath) && $restartPath !== '') {
    echo json_encode(recordListRestartRead($restartPath), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$databasePath = tempnam(sys_get_temp_dir(), 's-storage-01-');
$copyPath = tempnam(sys_get_temp_dir(), 's-storage-01-copy-');
if (!is_string($databasePath) || !is_string($copyPath)) {
    throw new RuntimeException('record_list_tempfile_failed');
}

try {
    $opened = recordListOpen($databasePath);
    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
    $connection = $opened['connection'];
    $sink = new RecordListAuditSink();
    $authorizer = new RecordListAuthorizer();
    $storage = new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        $authorizer,
        new AuditEventPipeline(new DefaultAuditRedactor(), [$sink]),
        new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA'),
        's-storage-01-test-cursor-key-32-bytes-minimum',
    );

    $widget = $storage->registerSchemaVersion(recordListSchema('inventory.widget', 'title'), null, 'actor:admin');
    $note = $storage->registerSchemaVersion(recordListSchema('catalog.note', 'caption'), null, 'actor:admin');
    $widgetAlphaOne = $storage->create('inventory:widget:alpha-1', $widget->ref, [
        'title' => 'Alpha one', 'tenant' => 'PROTECTED_SCOPE_ALPHA', 'kind' => 'standard', 'rank' => '7', 'private_note' => 'SECRET_ALPHA_ONE',
    ], 'actor:admin')->version;
    $storage->create('inventory:widget:alpha-2', $widget->ref, [
        'title' => 'Alpha two', 'tenant' => 'PROTECTED_SCOPE_ALPHA', 'kind' => 'standard', 'rank' => 7, 'private_note' => 'SECRET_ALPHA_TWO',
    ], 'actor:admin');
    $storage->create('inventory:widget:beta-1', $widget->ref, [
        'title' => 'Beta one', 'tenant' => 'PROTECTED_SCOPE_BETA', 'kind' => 'standard', 'rank' => 7, 'private_note' => 'SECRET_BETA',
    ], 'actor:admin');
    $storage->create('catalog:note:alpha-1', $note->ref, [
        'caption' => 'Alpha note', 'tenant' => 'PROTECTED_SCOPE_ALPHA', 'kind' => 'standard', 'rank' => 4, 'private_note' => 'SECRET_NOTE_ALPHA',
    ], 'actor:admin');
    $storage->create('catalog:note:beta-1', $note->ref, [
        'caption' => 'Beta note', 'tenant' => 'PROTECTED_SCOPE_BETA', 'kind' => 'standard', 'rank' => 4, 'private_note' => 'SECRET_NOTE_BETA',
    ], 'actor:admin');

    $updated = $storage->compareAndSwap(
        'inventory:widget:alpha-1',
        $widgetAlphaOne->ref,
        $widget->ref,
        ['title' => 'Alpha one updated', 'tenant' => 'PROTECTED_SCOPE_ALPHA', 'kind' => 'standard', 'rank' => 7, 'private_note' => 'SECRET_UPDATED'],
        'actor:admin',
    )->version;
    recordListExpect(
        $storage->readAdminVersion($widgetAlphaOne->ref, 'actor:admin')->values['title'] === 'Alpha one',
        'exact historical read did not preserve revision one',
    );

    $first = $storage->listCurrentRecords(new StorageRecordListQuery(
        'inventory.widget',
        ['rank' => ['operator' => 'eq', 'value' => '7']],
        1,
    ), 'actor:reader:alpha');
    recordListExpect(count($first->items) === 1 && $first->continuation !== null, 'bounded first page missing continuation');
    recordListExpect(!array_key_exists('private_note', $first->items[0]->values), 'admin value leaked from schema-owned projection');
    recordListExpect(!array_key_exists('tenant', $first->items[0]->values), 'protected provider scope leaked from schema-owned projection');
    $second = $storage->listCurrentRecords(new StorageRecordListQuery(
        'inventory.widget',
        ['rank' => ['value' => 7, 'operator' => 'eq']],
        1,
        $first->continuation,
    ), 'actor:reader:alpha');
    $listed = [...$first->items, ...$second->items];
    recordListExpect(count($listed) === 2 && $second->continuation === null, 'alpha scope did not return exactly two heads');
    $listedIds = array_map(static fn ($item): string => $item->ref->recordId, $listed);
    recordListExpect(count(array_unique($listedIds)) === 2, 'historical version duplicated a current head');
    $updatedRows = array_values(array_filter($listed, static fn ($item): bool => $item->ref->recordId === $updated->ref->recordId));
    recordListExpect(count($updatedRows) === 1 && $updatedRows[0]->ref->revision === 2, 'list did not expose only the CAS current head');

    $notes = $storage->listCurrentRecords(new StorageRecordListQuery('catalog.note', [], 100), 'actor:reader:alpha');
    recordListExpect(
        count($notes->items) === 1 && $notes->items[0]->values['caption'] === 'Alpha note',
        'same generic contract did not list the unrelated second schema',
    );
    $publicAndProtectedScope = recordListStorage(
        $connection,
        new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'public_field'),
    )->listCurrentRecords(new StorageRecordListQuery('inventory.widget', [], 100), 'actor:reader:alpha');
    recordListExpect(
        count($publicAndProtectedScope->items) === 2,
        'provider could not combine schema-known public and protected scope filters',
    );

    $permutedA = $storage->listCurrentRecords(new StorageRecordListQuery('inventory.widget', [
        'kind' => ['operator' => 'eq', 'value' => 'standard'],
        'rank' => ['operator' => 'eq', 'value' => 7],
    ], 1), 'actor:reader:alpha');
    $permutedB = $storage->listCurrentRecords(new StorageRecordListQuery('inventory.widget', [
        'rank' => ['value' => '7', 'operator' => 'eq'],
        'kind' => ['value' => 'standard', 'operator' => 'eq'],
    ], 1), 'actor:reader:alpha');
    recordListExpect(
        recordListPagePayload($permutedA) === recordListPagePayload($permutedB),
        'filter JSON key permutation changed logical result or continuation identity',
    );

    $headCount = $connection->table('larena_storage_records')->count();
    $versionCount = $connection->table('larena_storage_record_versions')->count();
    $missingScope = new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        new RecordListAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new RecordListAuditSink()]),
        null,
        's-storage-01-test-cursor-key-32-bytes-minimum',
    );
    recordListRejects(
        static fn () => $missingScope->listCurrentRecords(new StorageRecordListQuery('inventory.widget'), 'actor:reader'),
        'storage_record_list_scope_missing',
    );
    $missingCursorKey = new VersionedStorage(
        $connection,
        PropertyTypeRegistry::builtIns(),
        new RecordListAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [new RecordListAuditSink()]),
        new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA'),
        null,
    );
    recordListRejects(
        static fn () => $missingCursorKey->listCurrentRecords(new StorageRecordListQuery('inventory.widget'), 'actor:reader'),
        'storage_record_list_cursor_key_missing',
    );
    foreach ([
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', false)), new StorageRecordListQuery('inventory.widget'), 'storage_record_list_scope_denied'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'missing_schema')), new StorageRecordListQuery('inventory.widget'), 'storage_record_list_scope_invalid'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'wrong_schema')), new StorageRecordListQuery('inventory.widget'), 'storage_record_list_scope_invalid'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'remove_caller')), new StorageRecordListQuery('inventory.widget', ['rank' => ['operator' => 'eq', 'value' => 7]]), 'storage_record_list_scope_invalid'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'change_caller')), new StorageRecordListQuery('inventory.widget', ['rank' => ['operator' => 'eq', 'value' => 7]]), 'storage_record_list_scope_invalid'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'unknown_field')), new StorageRecordListQuery('inventory.widget'), 'storage_record_list_scope_filter_field_unknown'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'admin_field')), new StorageRecordListQuery('inventory.widget'), 'storage_record_list_scope_filter_field_forbidden'],
        [recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_ALPHA', true, true, 'unknown_operator')), new StorageRecordListQuery('inventory.widget'), 'storage_record_list_scope_filter_operator_unknown'],
        [$storage, new StorageRecordListQuery('missing.schema'), 'storage_record_list_schema_unknown'],
        [$storage, new StorageRecordListQuery('inventory.widget', ['unknown' => ['operator' => 'eq', 'value' => 'x']]), 'storage_record_list_filter_field_unknown'],
        [$storage, new StorageRecordListQuery('inventory.widget', ['rank' => ['operator' => 'contains', 'value' => 7]]), 'storage_record_list_filter_operator_unknown'],
        [$storage, new StorageRecordListQuery('inventory.widget', ['private_note' => ['operator' => 'eq', 'value' => 'SECRET_UPDATED']]), 'storage_record_list_filter_field_not_public'],
        [$storage, new StorageRecordListQuery('inventory.widget', ['tenant' => ['operator' => 'eq', 'value' => 'PROTECTED_SCOPE_ALPHA']]), 'storage_record_list_filter_field_not_public'],
        [$storage, new StorageRecordListQuery('inventory.widget', [], 0), 'storage_record_list_limit_invalid'],
        [$storage, new StorageRecordListQuery('inventory.widget', [], 101), 'storage_record_list_limit_invalid'],
    ] as [$candidate, $query, $reason]) {
        recordListRejects(static fn () => $candidate->listCurrentRecords($query, 'actor:reader:alpha'), $reason);
    }
    $tampered = (string) $first->continuation;
    $tampered[10] = $tampered[10] === 'a' ? 'b' : 'a';
    recordListRejects(
        static fn () => $storage->listCurrentRecords(new StorageRecordListQuery(
            'inventory.widget', ['rank' => ['operator' => 'eq', 'value' => 7]], 1, $tampered,
        ), 'actor:reader:alpha'),
        'storage_record_list_continuation_invalid',
    );
    recordListRejects(
        static fn () => $storage->listCurrentRecords(new StorageRecordListQuery(
            'inventory.widget', ['rank' => ['operator' => 'eq', 'value' => 8]], 1, $first->continuation,
        ), 'actor:reader:alpha'),
        'storage_record_list_continuation_invalid',
    );
    $betaStorage = recordListStorage($connection, new RecordListScopeProvider('PROTECTED_SCOPE_BETA'));
    recordListRejects(
        static fn () => $betaStorage->listCurrentRecords(new StorageRecordListQuery(
            'inventory.widget', ['rank' => ['operator' => 'eq', 'value' => 7]], 1, $first->continuation,
        ), 'actor:reader:alpha'),
        'storage_record_list_continuation_invalid',
    );
    recordListExpect(
        $connection->table('larena_storage_records')->count() === $headCount
            && $connection->table('larena_storage_record_versions')->count() === $versionCount,
        'rejected list query mutated durable storage',
    );
    recordListExpect(in_array('storage.record.list', $authorizer->operations, true), 'list did not request its exact Access operation');

    $auditJson = json_encode($sink->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    foreach (['SECRET_ALPHA_ONE', 'SECRET_ALPHA_TWO', 'SECRET_BETA', 'SECRET_UPDATED', 'PROTECTED_SCOPE_ALPHA', 'PROTECTED_SCOPE_BETA', 'PROTECTED_SCOPE_SENTINEL'] as $privateValue) {
        recordListExpect(!str_contains($auditJson, $privateValue), 'private value leaked into Audit diagnostics');
    }
    $outputJson = json_encode([
        recordListPagePayload($first),
        recordListPagePayload($second),
        recordListPagePayload($notes),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    foreach (['PROTECTED_SCOPE_ALPHA', 'PROTECTED_SCOPE_BETA', 'PROTECTED_SCOPE_SENTINEL'] as $protectedValue) {
        recordListExpect(!str_contains($outputJson, $protectedValue), 'protected provider scope leaked into list output');
    }

    $opened['capsule']->getDatabaseManager()->disconnect();
    if (!copy($databasePath, $copyPath)) {
        throw new RuntimeException('record_list_database_copy_failed');
    }
    $runRestart = static function (string $path): array {
        $command = [PHP_BINARY, __FILE__];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            ...getenv(),
            'S_STORAGE_01_RESTART_DB' => $path,
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('record_list_restart_process_failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        recordListExpect($exit === 0, 'restart process failed: ' . trim((string) $stderr));
        $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        recordListExpect(is_array($decoded), 'restart process returned invalid payload');
        return $decoded;
    };
    $restartOriginal = $runRestart($databasePath);
    $restartCopy = $runRestart($copyPath);
    recordListExpect($restartOriginal === $restartCopy, 'identical persisted bytes changed across clean database paths');
    recordListExpect(count($restartOriginal['items'] ?? []) === 1, 'new PHP process did not read durable list state');

    echo "VersionedStorage durable scoped record-list tests passed: 30 scenarios.\n";
} finally {
    @unlink($databasePath);
    @unlink($copyPath);
}
