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
