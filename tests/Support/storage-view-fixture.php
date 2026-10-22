<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;

/**
 * A workbench wired with the витрина, for the витрина tests on SQLite and MySQL,
 * and a parity check: the витрина must hold exactly what the current versions say.
 */
final readonly class StorageViewFixtureAccess implements ActorOperationAuthorizer, QueryScopeProvider
{
    public function __construct(private string $actor, private string $scopeRef)
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        if ($actor !== $this->actor) {
            throw new RuntimeException('storage_view_fixture_actor_denied');
        }
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return true;
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        return array_key_exists('scope_ref', $query) ? ['scope_ref' => $this->scopeRef] : $query;
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return $actor === $this->actor
            ? AccessDecision::allow($operation, $actor, $resourceType . ':' . $this->scopeRef, 'storage_view_fixture_allowed')
            : AccessDecision::deny($operation, $actor, $resourceType . ':denied', 'storage_view_fixture_denied');
    }
}

/** The storages whose витрина rebuild a workbench queued, in order. */
final class StorageViewQueuedBuilds
{
    /** @var list<string> */
    private array $ids = [];

    public function add(string $schemaId): void
    {
        $this->ids[] = $schemaId;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->ids;
    }

    public function reset(): void
    {
        $this->ids = [];
    }

    /** The workbench's `viewBuilds` closure. */
    public function closure(): Closure
    {
        return fn (string $schemaId) => $this->add($schemaId);
    }
}

function storageViewExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function storageViewRejects(callable $call, string $reason): void
{
    try {
        $call();
    } catch (StorageRejected $exception) {
        storageViewExpect($exception->reasonCode === $reason, 'expected ' . $reason . ', got ' . $exception->reasonCode);

        return;
    }
    throw new RuntimeException('expected rejection ' . $reason);
}

/** Runs the package migrations the workbench and the витрина need, on the swapped Schema facade. */
function storageViewInstall(Connection $connection): void
{
    Schema::swap($connection->getSchemaBuilder());
    $root = __DIR__ . '/../../database/migrations';
    foreach ([
        '/2026_07_13_000001_create_larena_storage_version_tables.php',
        '/2026_07_14_000002_create_larena_storage_schema_migration_tables.php',
        '/2026_08_09_000001_create_larena_storage_workbench_tables.php',
        '/2026_08_09_000002_scope_larena_storage_workbench_identity.php',
        '/2026_09_30_000001_add_larena_storage_workbench_structure_archive_state.php',
        '/2026_10_19_000001_add_larena_storage_workbench_history_keep.php',
        '/platform/2026_10_20_000001_create_larena_storage_views_table.php',
        '/platform/2026_10_21_000001_create_larena_storage_field_changes_table.php',
    ] as $file) {
        (require $root . $file)->up();
    }
}

/**
 * @param Closure(string): void|null $viewBuilds Receives the storages whose витрина a structure change left to fill.
 * @param Closure(string): bool|null $fieldChangeJobs Queues a field change; without it every change runs inline.
 * @param int $inlineViewBuildLimit Up to this many records a list builds a витрина that is not ready; above it, refuses.
 * @return array{workbench: DatabaseStorageWorkbench, storage: VersionedStorage, views: StorageViewSync, evolution: DatabaseStorageSchemaEvolution}
 */
function storageViewRuntime(Connection $connection, StorageViewDdl $ddl, string $actor, string $scopeRef, ?StorageMetrics $metrics = null, ?Closure $viewBuilds = null, ?Closure $fieldChangeJobs = null, int $inlineFieldChangeLimit = DatabaseStorageWorkbench::INLINE_FIELD_CHANGE_LIMIT, int $inlineViewBuildLimit = DatabaseStorageWorkbench::INLINE_VIEW_BUILD_LIMIT): array
{
    $metrics ??= new StorageMetrics();
    $properties = PropertyTypeRegistry::builtIns();
    $access = new StorageViewFixtureAccess($actor, $scopeRef);
    $events = new NullStorageSecurityEventSink();
    $views = new StorageViewSync($connection, new StorageViewRegistry($connection, 0.0), $ddl);
    $cursorKey = 'storage-view-fixture-cursor-key-minimum-32-bytes';
    $storage = new VersionedStorage($connection, $properties, $access, $events, $access, $cursorKey, null, $views, $metrics);
    $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $policies->protect(
        'larena/storage',
        static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {
            if (!$capability instanceof DatabaseStorageWorkbench) {
                throw new RuntimeException('capability_invalid');
            }
        },
        'workbench.',
    );
    $policies->seal();
    $evolution = new DatabaseStorageSchemaEvolution($connection, $properties, $access, $events, $policies, $views);
    $workbench = new DatabaseStorageWorkbench($connection, $properties, $access, $access, $storage, $policies, $cursorKey, $events, $views, null, $metrics, $viewBuilds, $fieldChangeJobs, $inlineFieldChangeLimit, $inlineViewBuildLimit);

    return ['workbench' => $workbench, 'storage' => $storage, 'views' => $views, 'evolution' => $evolution];
}

/** @param array<string, mixed> $constraints @return array<string, mixed> */
function storageViewField(string $key, int $position, string $type, array $constraints = [], bool $required = false): array
{
    return [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => $type, 'type_version' => 1,
        'required' => $required, 'visibility' => 'admin', 'constraints' => $constraints,
    ];
}

/**
 * The витрина of the schema holds one row per current record, equal to the
 * projection of its current version, and the side rows of its multi-values.
 */
function storageViewParity(Connection $connection, StorageViewSync $views, string $schemaId, string $message): void
{
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration !== null && $registration->state === 'ready', $message . ': the витрина is not ready');
    $layout = $registration->layout;
    $expected = [];
    $expectedMulti = [];
    $expectedSearch = [];
    foreach ($connection->table('larena_storage_records as heads')
        ->join('larena_storage_record_versions as versions', static function ($join): void {
            $join->on('versions.schema_id', '=', 'heads.schema_id')
                ->on('versions.record_id', '=', 'heads.record_id')
                ->on('versions.revision', '=', 'heads.current_revision');
        })
        ->where('heads.schema_id', $schemaId)
        ->get(['heads.record_id', 'heads.current_revision', 'heads.created_at', 'heads.updated_at', 'versions.schema_version', 'versions.values_json', 'versions.content_hash']) as $row) {
        $values = json_decode((string) $row->values_json, true, 512, JSON_THROW_ON_ERROR);
        // A version written under an older schema version is projected through the read converter.
        $values = $views->converter()->convert($schemaId, (int) $row->schema_version, $layout->schemaVersion, is_array($values) ? $values : []);
        $projection = $views->projector()->project(
            $layout,
            (string) $row->record_id,
            (int) $row->current_revision,
            (int) $row->schema_version,
            (string) $row->content_hash,
            $values,
            (string) $row->created_at,
            (string) $row->updated_at,
        );
        $expected[(string) $row->record_id] = $projection['row'];
        foreach ($projection['multi'] as $item) {
            $expectedMulti[] = $item['record_id'] . '|' . $item['field_key'] . '|' . $item['value'];
        }
        if ($projection['search'] !== null) {
            $expectedSearch[$projection['search']['record_id']] = $projection['search']['search_text'];
        }
    }
    $decimals = [];
    foreach ($layout->written() as $column) {
        if ($column->sqlType === StorageViewColumn::SQL_DECIMAL && $column->column !== null) {
            $decimals[$column->column] = true;
        }
    }
    $actual = [];
    foreach ($connection->table($registration->tableName)->get() as $row) {
        $actual[(string) $row->record_id] = (array) $row;
    }
    ksort($actual, SORT_STRING);
    ksort($expected, SORT_STRING);
    storageViewExpect(array_keys($actual) === array_keys($expected), $message . ': the витрина rows are not the current records');
    foreach ($expected as $recordId => $row) {
        foreach ($row as $column => $value) {
            storageViewExpect(array_key_exists($column, $actual[$recordId]), $message . ': column ' . $column . ' is missing');
            $stored = $actual[$recordId][$column];
            $same = isset($decimals[$column])
                ? ($value === null ? $stored === null : $stored !== null && (float) $stored === (float) $value)
                : ($value === null ? $stored === null : $stored !== null && (string) $stored === (string) $value);
            storageViewExpect($same, $message . ': ' . $recordId . '.' . $column . ' is ' . var_export($stored, true) . ', expected ' . var_export($value, true));
        }
    }
    $multi = [];
    foreach ($connection->table($registration->tableName . '_m')->get() as $row) {
        $multi[] = $row->record_id . '|' . $row->field_key . '|' . $row->value;
    }
    sort($multi);
    sort($expectedMulti);
    storageViewExpect($multi === $expectedMulti, $message . ': the side rows are not the current multi-values');
    // The search table holds one row per record with the text of the search fields.
    storageViewExpect($layout->searchTable(), $message . ': the layout has no search fields');
    $search = [];
    foreach ($connection->table($registration->tableName . '_s')->get() as $row) {
        $search[(string) $row->record_id] = $row->search_text === null ? null : (string) $row->search_text;
    }
    ksort($search, SORT_STRING);
    ksort($expectedSearch, SORT_STRING);
    storageViewExpect($search === $expectedSearch, $message . ': the search rows are not the search text of the current records');
}

/**
 * The admin list by creation time (`_created_at`) on any driver: both directions,
 * ties broken by the record id in the direction of the key, keyset pages that walk
 * exactly the whole list, archived rows left out unless asked for; then a table
 * built before its (state, created_at, record_id) index: the verifier and the
 * checker report `created_index_missing` (the table still matches its layout and
 * stays ready), the list still answers, and StorageViewSync::addMissingIndexes and
 * a rebuild add the index in place, keeping the rows.
 */
function storageViewCreatedAtChecks(Connection $connection, StorageViewDdl $ddl, DatabaseStorageWorkbench $workbench, StorageViewSync $views, string $scope, string $actor): void
{
    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.created', 'label' => 'Created',
        'fields' => [storageViewField('title', 10, 'string', [], true)]], $actor);
    $schemaId = $structure->schema->schemaId;
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration !== null && $registration->state === 'ready', 'the created-at storage has no ready витрина');
    $table = $registration->tableName;
    storageViewExpect(in_array(StorageViewDdl::stateCreatedIndexName($table), $ddl->indexes($table), true), 'a new витрина has no (state, created_at, record_id) index');
    // Nine records in three seconds, three to a second (ties), the seconds out of record order; one archived.
    $createdAt = ['2026-10-02 10:00:00', '2026-10-01 10:00:00', '2026-10-03 10:00:00'];
    $records = [];
    for ($index = 0; $index < 9; $index++) {
        $record = $workbench->createRecord($scope, $structure->structureId, ['title' => 'Item ' . $index], $actor);
        $records[$record->recordId] = $createdAt[$index % 3];
    }
    foreach ($records as $recordId => $at) {
        $connection->table('larena_storage_records')->where('schema_id', $schemaId)->where('record_id', $recordId)->update(['created_at' => $at]);
        $connection->table($table)->where('record_id', $recordId)->update(['created_at' => $at]);
    }
    $archived = array_keys($records)[4];
    $head = $workbench->readRecord($scope, $structure->structureId, $archived, $actor);
    $workbench->archiveRecord($scope, $structure->structureId, $archived, $head->revision, $actor);
    storageViewParity($connection, $views, $schemaId, 'created-at seeded');

    $expected = static function (string $direction, bool $includeArchived) use (&$records, $archived): array {
        $ids = array_keys($records);
        if (!$includeArchived) {
            $ids = array_values(array_filter($ids, static fn (string $id): bool => $id !== $archived));
        }
        usort($ids, static function (string $left, string $right) use ($records, $direction): int {
            $comparison = strcmp($records[$left], $records[$right]) ?: strcmp($left, $right);

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return $ids;
    };
    $walk = static function (string $direction, bool $includeArchived, int $limit) use ($workbench, $scope, $structure, $actor): array {
        $sort = [['field' => '_created_at', 'direction' => $direction]];
        $walked = [];
        $continuation = null;
        $pages = 0;
        do {
            $page = $workbench->listRecords(new Larena\Storage\Contracts\StorageWorkbenchRecordQuery($scope, $structure->structureId, sort: $sort, limit: $limit,
                continuation: $continuation, includeArchived: $includeArchived), $actor);
            foreach ($page->items as $item) {
                $walked[] = $item->recordId;
            }
            if ($page->continuation !== null) {
                $payload = json_decode((string) base64_decode(strtr($page->continuation, '-_', '+/'), false), true);
                storageViewExpect(is_array($payload) && $payload['version'] === 2 && is_string($payload['after'][0] ?? null), 'a created-at page did not continue by keyset');
            }
            $continuation = $page->continuation;
        } while ($continuation !== null && ++$pages < 20);

        return $walked;
    };
    $check = static function (string $label) use ($walk, $expected): void {
        foreach (['asc', 'desc'] as $direction) {
            foreach ([false, true] as $includeArchived) {
                foreach ([2, 3, 100] as $limit) {
                    $walked = $walk($direction, $includeArchived, $limit);
                    storageViewExpect($walked === $expected($direction, $includeArchived),
                        $label . ': the created-at list ' . $direction . ($includeArchived ? ' with archived' : '') . ' by ' . $limit . ' is ' . json_encode($walked));
                }
            }
        }
    };
    $check('created-at');
    // With another key first, `_created_at` is a later key like any other.
    $two = $workbench->listRecords(new Larena\Storage\Contracts\StorageWorkbenchRecordQuery($scope, $structure->structureId,
        sort: [['field' => '_created_at', 'direction' => 'desc'], ['field' => '_record_id', 'direction' => 'asc']], limit: 100), $actor);
    $twoExpected = $expected('desc', false);
    usort($twoExpected, static fn (string $left, string $right): int => strcmp($records[$right], $records[$left]) ?: strcmp($left, $right));
    storageViewExpect(array_map(static fn ($item): string => $item->recordId, $two->items) === $twoExpected, 'a created-at sort with a second key is wrong');

    // A table built before the index: reported, still read and written, and the index is added in place.
    $index = StorageViewDdl::stateCreatedIndexName($table);
    $dropIndex = static function () use ($connection, $index, $table): void {
        $connection->statement(in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'DROP INDEX `' . $index . '` ON `' . $table . '`'
            : 'DROP INDEX "' . $index . '"');
    };
    $dropIndex();
    $layout = $views->registry()->find($schemaId, true)?->layout;
    storageViewExpect($layout !== null, 'the created-at витрина lost its registration');
    $verifier = new Larena\Storage\View\StorageViewShapeVerifier($ddl);
    storageViewExpect($verifier->problems($table, $layout) === [Larena\Storage\View\StorageViewShapeVerifier::CREATED_INDEX_MISSING],
        'the missing created-at index was not reported: ' . json_encode($verifier->problems($table, $layout)));
    storageViewExpect($verifier->matches($table, $layout), 'a missing created-at index made the table not match its layout');
    $checker = new Larena\Storage\View\StorageViewChecker($views);
    storageViewExpect($checker->check($schemaId)['problems'] === ['created_index_missing'], 'the checker did not report the missing created-at index: ' . json_encode($checker->check($schemaId)));
    $written = $workbench->createRecord($scope, $structure->structureId, ['title' => 'Written meanwhile'], $actor);
    storageViewExpect($views->registry()->find($schemaId, true)?->state === 'ready', 'a write without the created-at index turned the витрина pending');
    $records[$written->recordId] = (string) $connection->table('larena_storage_records')->where('record_id', $written->recordId)->value('created_at');
    $check('created-at without its index');
    $rows = $connection->table($table)->orderBy('record_id')->get()->map(static fn ($row): array => (array) $row)->all();
    storageViewExpect($views->addMissingIndexes($schemaId), 'the missing indexes were not added');
    storageViewExpect(in_array($index, $ddl->indexes($table), true) && $checker->check($schemaId)['problems'] === [], 'addMissingIndexes did not add the created-at index');
    storageViewExpect($connection->table($table)->orderBy('record_id')->get()->map(static fn ($row): array => (array) $row)->all() === $rows, 'adding the index changed the rows');
    // A rebuild adds it as well, before it walks the records.
    $dropIndex();
    (new Larena\Storage\View\StorageViewRebuilder($views))->rebuild($schemaId);
    storageViewExpect(in_array($index, $ddl->indexes($table), true) && $checker->check($schemaId)['problems'] === [], 'a rebuild did not add the created-at index');
    storageViewParity($connection, $views, $schemaId, 'created-at rebuilt');
    $check('created-at rebuilt');
}
