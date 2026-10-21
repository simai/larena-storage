<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistration;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-monitoring-stubs.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The record list never scans the current versions: it answers from the витрина.
// - A list of a storage whose витрина is not ready builds it in the request when the
//   storage holds at most the inline limit of records (mode inline), and answers from it.
// - Above the limit it is refused with storage_view_not_ready (mode not_ready), runs no
//   query on the record versions, and queues the rebuild once: a build already walking
//   is not queued again. Once the rebuild has run, the list answers.
// - Inside a transaction the build runs only where the DDL rolls back with it (SQLite);
//   with MySQL-like DDL the list is refused and nothing is queued from the transaction.
// - A query no витрина answers (a sort by a choices field) is refused as unsupported,
//   with nothing built; without the витрина machinery every list is unavailable.
// - A purge's incoming references come from the referencing storage's витрина: built
//   inline before the purge when small, refused while it is built when large.
// - The workbench has no versions-scan list left (no MAX_SCAN).

/** @return array{connection: Connection, path: string} */
function storageReadinessOpen(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-view-readiness-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_view_readiness_tempfile_failed');
    }
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return ['connection' => $connection, 'path' => $path];
}

/**
 * The SQL a call ran.
 *
 * @return list<string>
 */
function storageReadinessQueries(Connection $connection, callable $call): array
{
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    try {
        $call();
    } finally {
        $log = $connection->getQueryLog();
        $connection->disableQueryLog();
    }

    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
}

/** @param list<string> $queries @return list<string> The queries that read the record versions. */
function storageReadinessVersionReads(array $queries): array
{
    return array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'larena_storage_record_versions')));
}

/** Writes records straight into the version tables, as an import before the витрина would: the витрина does not follow. */
function storageReadinessRawRecords(Connection $connection, string $schemaId, string $scope, string $actor, int $from, int $count): void
{
    $normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
    $connection->transaction(static function () use ($connection, $normalizer, $schemaId, $scope, $actor, $from, $count): void {
        $heads = [];
        $versions = [];
        for ($index = $from; $index < $from + $count; $index++) {
            $recordId = 'record-' . sprintf('%032x', 0xc000000 + $index);
            $ownerRef = 'workbench.record:' . sprintf('%032x', 0xc000000 + $index);
            $json = $normalizer->canonicalJson(['larena_record_state' => 'active', 'larena_scope_ref' => $scope, 'title' => 'Raw ' . $index]);
            $hash = hash('sha256', $json);
            $heads[] = ['record_id' => $recordId, 'schema_id' => $schemaId, 'owner_ref' => $ownerRef, 'current_revision' => 1,
                'current_schema_version' => 1, 'current_hash' => $hash, 'created_at' => '2026-10-21 00:00:00', 'updated_at' => '2026-10-21 00:00:00'];
            $versions[] = ['schema_id' => $schemaId, 'record_id' => $recordId, 'revision' => 1, 'owner_ref' => $ownerRef,
                'schema_version' => 1, 'values_json' => $json, 'content_hash' => $hash, 'operation' => 'create', 'created_by' => $actor,
                'correlation_id' => null, 'created_at' => '2026-10-21 00:00:00'];
        }
        foreach (array_chunk($heads, 100) as $chunk) {
            $connection->table('larena_storage_records')->insert($chunk);
        }
        foreach (array_chunk($versions, 100) as $chunk) {
            $connection->table('larena_storage_record_versions')->insert($chunk);
        }
    });
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$opened = storageReadinessOpen();
$connection = $opened['connection'];

try {
    storageViewInstall($connection);
    $ddl = new SqliteStorageViewDdl($connection);
    $recorder = new StorageMetricsRecorder();
    $metrics = new StorageMetrics(static fn (): object => $recorder);
    $queued = new StorageViewQueuedBuilds();
    $queue = $queued->closure();
    // The inline limit is 25 records here, so both sides of it are cheap to reach.
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope, $metrics, $queue, null, DatabaseStorageWorkbench::INLINE_FIELD_CHANGE_LIMIT, 25);
    $workbench = $runtime['workbench'];
    $views = $runtime['views'];

    storageViewExpect(!(new ReflectionClass(DatabaseStorageWorkbench::class))->hasConstant('MAX_SCAN'), 'the versions-scan list limit is still there');

    $fields = [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('tags', 20, 'choices', ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]),
    ];
    $small = $workbench->createStructure($scope, ['structure_id' => 'workbench.small', 'label' => 'Small', 'fields' => $fields], $actor);
    $smallSchema = $small->schema->schemaId;
    $records = [];
    for ($index = 0; $index < 5; $index++) {
        $records[] = $workbench->createRecord($scope, $small->structureId, ['title' => 'Item ' . $index, 'tags' => $index % 2 === 0 ? ['red'] : ['blue']], $actor);
    }
    $query = static fn (string $structureId, array $sort = [], ?string $search = null): StorageWorkbenchRecordQuery
        => new StorageWorkbenchRecordQuery($scope, $structureId, [], $search, $sort, 10);

    // A ready витрина: the page's records are read by their ids, nothing else touches the versions.
    $recorder->reset();
    $reads = storageReadinessVersionReads(storageReadinessQueries($connection, static function () use ($workbench, $query, $small, $actor): void {
        storageViewExpect($workbench->listRecords($query($small->structureId), $actor)->matchedCount === 5, 'the ready list lost records');
    }));
    storageViewExpect(count($reads) === 1 && str_contains($reads[0], '"record_id" in ('), 'a ready list read the versions other than by the page ids: ' . json_encode($reads));
    storageViewExpect($recorder->lines('storage.list') === ['storage.list count=exact&mode=sql&outcome=ok&sort=none'], 'a ready list was not mode sql');

    // A small storage whose витрина is not ready (here: forgotten) builds it on its first list.
    $views->registry()->delete($smallSchema);
    $recorder->reset();
    $page = $workbench->listRecords($query($small->structureId, [['field' => 'title', 'direction' => 'desc']]), $actor);
    storageViewExpect(array_map(static fn ($record): string => $record->values['title'], $page->items) === ['Item 4', 'Item 3', 'Item 2', 'Item 1', 'Item 0'],
        'the first list after the inline build is wrong');
    storageViewExpect($views->registry()->find($smallSchema, true)?->state === StorageViewRegistration::STATE_READY && $queued->ids() === [],
        'the inline build did not leave the витрина ready, or queued a rebuild');
    storageViewParity($connection, $views, $smallSchema, 'inline build');
    storageViewExpect($recorder->lines('storage.list') === ['storage.list count=exact&mode=inline&outcome=ok&sort=unindexed'], 'the inline list was not mode inline: '
        . implode(' | ', $recorder->lines('storage.list')));

    // A sort no витрина answers is refused before anything is built.
    $views->registry()->delete($smallSchema);
    storageViewRejects(static fn () => $workbench->listRecords($query($small->structureId, [['field' => 'tags', 'direction' => 'asc']]), $actor),
        DatabaseStorageWorkbench::QUERY_UNSUPPORTED);
    storageViewExpect($views->registry()->find($smallSchema, true) === null && $queued->ids() === [], 'an unsupported query built or queued the витрина');

    // Inside a transaction: built on SQLite (its DDL rolls back with the transaction) ...
    $connection->transaction(static function () use ($workbench, $query, $small, $actor): void {
        storageViewExpect($workbench->listRecords($query($small->structureId), $actor)->matchedCount === 5, 'the list in a transaction did not build its витрина');
    });
    storageViewExpect($views->registry()->find($smallSchema, true)?->state === StorageViewRegistration::STATE_READY, 'the build in a transaction did not commit');
    // ... and refused with MySQL-like DDL, which would commit it; nothing is queued from inside it.
    $mysqlLike = storageViewRuntime($connection, new SqliteStorageViewDdl($connection, false), $actor, $scope, null, $queue, null, DatabaseStorageWorkbench::INLINE_FIELD_CHANGE_LIMIT, 25);
    $views->registry()->delete($smallSchema);
    $connection->transaction(static function () use ($mysqlLike, $query, $small, $actor): void {
        storageViewRejects(static fn () => $mysqlLike['workbench']->listRecords($query($small->structureId), $actor), DatabaseStorageWorkbench::VIEW_NOT_READY);
    });
    storageViewExpect($queued->ids() === [], 'a list in a transaction queued a rebuild');
    $workbench->listRecords($query($small->structureId), $actor);

    // A large storage (above the limit) whose витрина is not ready is refused without reading a version.
    $large = $workbench->createStructure($scope, ['structure_id' => 'workbench.large', 'label' => 'Large', 'fields' => [storageViewField('title', 10, 'string', [], true)]], $actor);
    $largeSchema = $large->schema->schemaId;
    storageReadinessRawRecords($connection, $largeSchema, $scope, $actor, 0, 26);
    $views->registry()->delete($largeSchema);
    $recorder->reset();
    $reads = storageReadinessVersionReads(storageReadinessQueries($connection, static function () use ($workbench, $query, $large, $actor): void {
        storageViewRejects(static fn () => $workbench->listRecords($query($large->structureId), $actor), DatabaseStorageWorkbench::VIEW_NOT_READY);
        storageViewRejects(static fn () => $workbench->listRecords($query($large->structureId, [], 'raw'), $actor), DatabaseStorageWorkbench::VIEW_NOT_READY);
    }));
    storageViewExpect($reads === [], 'a refused list read the record versions: ' . json_encode($reads));
    storageViewExpect($queued->ids() === [$largeSchema, $largeSchema], 'the refused lists did not queue the rebuild');
    storageViewExpect($recorder->lines('storage.list') === ['storage.list mode=not_ready&outcome=rejected&sort=none', 'storage.list mode=not_ready&outcome=rejected&sort=none'],
        'a refused list was not mode not_ready: ' . implode(' | ', $recorder->lines('storage.list')));
    // While a build walks (its cursor moved) the refusal leaves it to finish instead of queuing another.
    $queued->reset();
    $registry = $views->registry();
    $planned = $views->planner()->plan($large->fields, $large->schema->version, null)->promoted();
    $registry->put(StorageViewRegistration::create($largeSchema, $planned, StorageViewRegistration::STATE_BUILDING)->withState(StorageViewRegistration::STATE_BUILDING, null, 'record-0', 'storage-view-build-walking'));
    storageViewRejects(static fn () => $workbench->listRecords($query($large->structureId), $actor), DatabaseStorageWorkbench::VIEW_NOT_READY);
    storageViewExpect($queued->ids() === [], 'a build under way was queued again');
    // The queued rebuild makes it ready; then it lists.
    (new StorageViewRebuilder($views))->rebuild($largeSchema, 10, true);
    $listed = $workbench->listRecords($query($large->structureId), $actor);
    storageViewExpect($listed->matchedCount === 26 && count($listed->items) === 10, 'the rebuilt large storage does not list');
    storageViewParity($connection, $views, $largeSchema, 'large rebuilt');

    // Incoming references: the referencing storage's витрина answers them; small, it is built before the purge.
    $sections = $workbench->createStructure($scope, ['structure_id' => 'workbench.sections', 'label' => 'Sections', 'fields' => [storageViewField('title', 10, 'string', [], true)]], $actor);
    $section = $workbench->createRecord($scope, $sections->structureId, ['title' => 'Books'], $actor);
    $other = $workbench->createRecord($scope, $sections->structureId, ['title' => 'Games'], $actor);
    $pages = $workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('section', 20, 'record', ['target_structure_id' => 'workbench.sections']),
    ]], $actor);
    $workbench->createRecord($scope, $pages->structureId, ['title' => 'Page', 'section' => $section->recordId], $actor);
    $section = $workbench->archiveRecord($scope, $sections->structureId, $section->recordId, $section->revision, $actor);
    $other = $workbench->archiveRecord($scope, $sections->structureId, $other->recordId, $other->revision, $actor);
    $views->registry()->delete($pages->schema->schemaId);
    storageViewRejects(static fn () => $workbench->purgeRecords($scope, $sections->structureId, [$section->recordId => $section->revision], $actor), 'storage_record_purge_referenced');
    storageViewExpect($views->registry()->find($pages->schema->schemaId, true)?->state === StorageViewRegistration::STATE_READY, 'the referencing витрина was not built before the purge');
    // Large (no inline build at all), the purge waits for that витрина, never scanning its versions.
    $waiting = storageViewRuntime($connection, $ddl, $actor, $scope, null, $queue, null, DatabaseStorageWorkbench::INLINE_FIELD_CHANGE_LIMIT, 0);
    $views->registry()->delete($pages->schema->schemaId);
    $queued->reset();
    $reads = storageReadinessVersionReads(storageReadinessQueries($connection, static function () use ($waiting, $scope, $sections, $other, $actor): void {
        storageViewRejects(static fn () => $waiting['workbench']->purgeRecords($scope, $sections->structureId, [$other->recordId => $other->revision], $actor),
            DatabaseStorageWorkbench::VIEW_NOT_READY);
    }));
    storageViewExpect(array_filter($reads, static fn (string $read): bool => !str_contains($read, '"record_id" = ?')) === [],
        'the purge scanned the referencing versions: ' . json_encode($reads));
    storageViewExpect($queued->ids() === [$pages->schema->schemaId], 'the purge did not queue the referencing rebuild');
    $workbench->listRecords($query($pages->structureId), $actor);
    $receipt = $workbench->purgeRecords($scope, $sections->structureId, [$other->recordId => $other->revision], $actor);
    storageViewExpect($receipt->recordIds === [$other->recordId], 'an unreferenced record was not purged once the витрина was ready');

    // Without the витрина machinery no list can be answered.
    $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $policies->seal();
    $access = new StorageViewFixtureAccess($actor, $scope);
    $bare = new DatabaseStorageWorkbench($connection, PropertyTypeRegistry::builtIns(), $access, $access, $runtime['storage'], $policies,
        'storage-view-fixture-cursor-key-minimum-32-bytes', new NullStorageSecurityEventSink());
    storageViewRejects(static fn () => $bare->listRecords($query($small->structureId), $actor), DatabaseStorageWorkbench::VIEW_UNAVAILABLE);
} finally {
    Facade::clearResolvedInstances();
    foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageViewListReadinessTest passed.\n";
