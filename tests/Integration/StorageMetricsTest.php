<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Monitoring\Contracts\Metrics;
use Larena\Monitoring\Runtime\MetricCatalog;
use Larena\Property\Contracts\PropertyTypeRegistry as PropertyTypeRegistryContract;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Providers\StorageServiceProvider;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewSync;
use Larena\Storage\View\StorageViewRebuildDeferredHandler;
use Larena\Storage\SchemaEvolution\StorageFieldChangeJob;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\View\StorageViewRebuildJob;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-monitoring-stubs.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';
require_once __DIR__ . '/../Support/storage-view-queue-fixture.php';

// Storage's hot-path metrics: storage.list (mode sql|legacy, count exact|approx|skip),
// storage.read, storage.write (operation create|update|archive|restore|purge) and
// the rebuild's storage.view.rebuild and storage.view.rebuild_rows, each with an
// outcome. Every record is declared in monitoring.yaml, its tags are codes from
// the declared keys and never ids, titles or values; without monitoring, or with
// a monitoring that throws, storage works unchanged; the provider declares the
// catalog and registers the storage.view.rebuild job.

final class StorageMetricsTestApplication extends Container implements Application
{
    public function version() { return 'test'; }
    public function basePath($path = '') { return __DIR__; }
    public function bootstrapPath($path = '') { return __DIR__; }
    public function configPath($path = '') { return __DIR__; }
    public function databasePath($path = '') { return __DIR__; }
    public function langPath($path = '') { return __DIR__; }
    public function publicPath($path = '') { return __DIR__; }
    public function resourcePath($path = '') { return __DIR__; }
    public function storagePath($path = '') { return __DIR__; }
    public function environment(...$environments) { return $environments === [] ? 'testing' : in_array('testing', $environments, true); }
    public function runningInConsole() { return true; }
    public function runningUnitTests() { return true; }
    public function hasDebugModeEnabled() { return false; }
    public function maintenanceMode() { throw new LogicException('not_used'); }
    public function isDownForMaintenance() { return false; }
    public function registerConfiguredProviders() {}
    public function register($provider, $force = false) { return $provider instanceof ServiceProvider ? $provider : new $provider($this); }
    public function registerDeferredProvider($provider, $service = null) {}
    public function resolveProvider($provider) { return new $provider($this); }
    public function boot() {}
    public function booting($callback) {}
    public function booted($callback) {}
    public function bootstrapWith(array $bootstrappers) {}
    public function getLocale() { return 'en'; }
    public function getNamespace() { return 'App\\'; }
    public function getProviders($provider) { return []; }
    public function hasBeenBootstrapped() { return true; }
    public function loadDeferredProviders() {}
    public function setLocale($locale) {}
    public function shouldSkipMiddleware() { return false; }
    public function terminating($callback) { return $this; }
    public function terminate() {}
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$opened = storageViewQueueOpen();
$connection = $opened['connection'];

try {
    storageViewQueueInstall($connection);
    $ddl = new SqliteStorageViewDdl($connection);
    $recorder = new StorageMetricsRecorder();
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope, new StorageMetrics(static fn (): object => $recorder));
    $workbench = $runtime['workbench'];
    $views = $runtime['views'];

    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.secret_items', 'label' => 'Secret items', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'integer'),
    ]], $actor);
    $schemaId = $structure->schema->schemaId;
    $recorder->reset();

    // Writes: one storage.write per operation, with the operation code.
    $first = $workbench->createRecord($scope, 'workbench.secret_items', ['title' => 'Confidential 4242', 'qty' => 12345678], $actor);
    $second = $workbench->createRecord($scope, 'workbench.secret_items', ['title' => 'Second', 'qty' => 2], $actor);
    $first = $workbench->updateRecord($scope, 'workbench.secret_items', $first->recordId, $first->revision, ['title' => 'Confidential edit', 'qty' => 3], $actor);
    $second = $workbench->archiveRecord($scope, 'workbench.secret_items', $second->recordId, $second->revision, $actor);
    $second = $workbench->restoreRecord($scope, 'workbench.secret_items', $second->recordId, $second->revision, $actor);
    $second = $workbench->archiveRecord($scope, 'workbench.secret_items', $second->recordId, $second->revision, $actor);
    $workbench->purgeRecords($scope, 'workbench.secret_items', [$second->recordId => $second->revision], $actor);
    $writes = $recorder->lines('storage.write');
    storageViewExpect($writes === [
        'storage.write operation=create&outcome=ok',
        'storage.write operation=create&outcome=ok',
        'storage.write operation=update&outcome=ok',
        'storage.write operation=archive&outcome=ok',
        'storage.write operation=restore&outcome=ok',
        'storage.write operation=archive&outcome=ok',
        'storage.write operation=purge&outcome=ok',
    ], 'the writes were not recorded once each with their operation: ' . implode(' | ', $writes));

    // A write the engine refuses is recorded as rejected and its exception reaches the caller unchanged.
    $recorder->reset();
    try {
        $runtime['storage']->create('owner:metrics', $structure->schema, ['qty' => 'not a number'], $actor);
        throw new LogicException('an invalid create was accepted');
    } catch (StorageRejected) {
    }
    storageViewExpect($recorder->lines('storage.write') === ['storage.write operation=create&outcome=rejected'], 'a refused create was not recorded as rejected: ' . implode(' | ', $recorder->lines()));
    // A stale update the engine refuses (the expected revision is not current).
    $recorder->reset();
    try {
        $current = $runtime['storage']->readAdminVersion(new Larena\Storage\Contracts\StorageRecordVersionRef($schemaId, $first->recordId, $first->revision), $actor);
        $runtime['storage']->compareAndSwap($current->ownerRef, new Larena\Storage\Contracts\StorageRecordVersionRef($schemaId, $first->recordId, $first->revision - 1), $structure->schema, $current->values, $actor);
        throw new LogicException('a stale update was accepted');
    } catch (StorageRejected) {
    }
    storageViewExpect(in_array('storage.write operation=update&outcome=rejected', $recorder->lines('storage.write'), true), 'a refused write was not recorded as rejected: ' . implode(' | ', $recorder->lines()));

    // Reads: storage.read with its outcome.
    $recorder->reset();
    $workbench->readRecord($scope, 'workbench.secret_items', $first->recordId, $actor);
    storageViewExpect($recorder->lines('storage.read') === ['storage.read outcome=ok'], 'a read was not recorded: ' . implode(' | ', $recorder->lines()));

    // Lists: the path and the count kind.
    $recorder->reset();
    foreach ([StorageWorkbenchCountMode::Auto, StorageWorkbenchCountMode::Exact, StorageWorkbenchCountMode::Skip] as $mode) {
        $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items', countMode: $mode, limit: 1), $actor);
    }
    $views->registry()->delete($schemaId);
    $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items'), $actor);
    $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items', countMode: StorageWorkbenchCountMode::Skip), $actor);
    try {
        $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items', page: 0), $actor);
        throw new LogicException('a list with page 0 was accepted');
    } catch (StorageRejected) {
    }
    $lists = $recorder->lines('storage.list');
    storageViewExpect($lists === [
        'storage.list count=exact&mode=sql&outcome=ok',
        'storage.list count=exact&mode=sql&outcome=ok',
        'storage.list count=skip&mode=sql&outcome=ok',
        'storage.list count=exact&mode=legacy&outcome=ok',
        'storage.list count=skip&mode=legacy&outcome=ok',
        'storage.list mode=legacy&outcome=rejected',
    ], 'the lists were not recorded with their path and count: ' . implode(' | ', $lists));
    // An estimated count is approx.
    $metrics = new StorageMetrics(static fn (): object => $recorder);
    $recorder->reset();
    $metrics->list('sql', 'approx', 'ok', 1.0);
    storageViewExpect($recorder->lines() === ['storage.list count=approx&mode=sql&outcome=ok'], 'an estimated count was not approx');

    // The rebuild: a timing per chunk and its rows.
    $recorder->reset();
    (new StorageViewRebuilder($views, new StorageMetrics(static fn (): object => $recorder)))->rebuild($schemaId, 1, true);
    storageViewExpect($recorder->lines('storage.view.rebuild') === ['storage.view.rebuild outcome=ok'], 'the rebuild chunk was not timed: ' . implode(' | ', $recorder->lines()));
    storageViewExpect($recorder->lines('storage.view.rebuild_rows') === ['storage.view.rebuild_rows '], 'the rebuild rows were not counted');

    // Every record so far is declared, with its type, and carries code-only tags from the declared keys.
    $catalog = Yaml::parseFile(StorageMetrics::catalogPath());
    storageViewExpect(is_array($catalog) && is_array($catalog['metrics'] ?? null), 'monitoring.yaml has no metrics section');
    $declared = $catalog['metrics'];
    foreach (['storage.list' => 'duration', 'storage.read' => 'duration', 'storage.write' => 'duration', 'storage.view.rebuild' => 'duration', 'storage.view.rebuild_rows' => 'counter'] as $id => $type) {
        storageViewExpect(($declared[$id]['type'] ?? null) === $type, $id . ' is not declared as ' . $type);
        storageViewExpect(preg_match('/^[a-z][a-z0-9_]{0,39}(\.[a-z0-9_]{1,40}){0,3}$/', $id) === 1, $id . ' is not a metric id');
    }
    $recorder->reset();
    $connection->table('larena_storage_views')->delete();
    $views->registry()->forget();
    $more = $workbench->createRecord($scope, 'workbench.secret_items', ['title' => 'Another secret', 'qty' => 99999999], $actor);
    $workbench->readRecord($scope, 'workbench.secret_items', $more->recordId, $actor);
    $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items', search: 'secret'), $actor);
    (new StorageViewRebuilder($views, new StorageMetrics(static fn (): object => $recorder)))->rebuild($schemaId, 1);
    $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items', search: 'secret'), $actor);
    $common = ['package', 'subsystem', 'operation', 'outcome', 'reason'];
    storageViewExpect(count($recorder->records) >= 6, 'too few records to check');
    foreach ($recorder->records as $record) {
        $definition = $declared[$record['id']] ?? null;
        storageViewExpect(is_array($definition), $record['id'] . ' is not declared');
        storageViewExpect(
            ($record['kind'] === 'timing' && in_array($definition['type'], ['duration', 'histogram'], true))
                || ($record['kind'] === 'count' && $definition['type'] === 'counter'),
            $record['id'] . ' is recorded as ' . $record['kind'] . ' but declared ' . $definition['type'],
        );
        $keys = is_array($definition['tags'] ?? null) ? $definition['tags'] : [];
        foreach ($record['tags'] as $key => $value) {
            storageViewExpect(in_array($key, $keys, true) || in_array($key, $common, true), $record['id'] . ' tag ' . $key . ' is not declared');
            storageViewExpect(preg_match('/^[a-z][a-z0-9_.-]{0,59}$/', $value) === 1, $record['id'] . ' tag ' . $key . ' is not a code: ' . $value);
            storageViewExpect(preg_match('/[0-9]{5,}/', $value) !== 1 && preg_match('/(?=[0-9a-f]*[0-9])[0-9a-f]{8,}/', $value) !== 1, $record['id'] . ' tag ' . $key . ' looks like an identifier: ' . $value);
        }
    }
    $encoded = (string) json_encode($recorder->records);
    foreach ([$schemaId, $scope, $first->recordId, $more->recordId, 'secret', 'Secret', 'Confidential', '99999999', 'tenant'] as $leak) {
        storageViewExpect(!str_contains($encoded, $leak), 'a metric carries an id, title or value: ' . $leak);
    }

    // Without monitoring nothing is recorded; a monitoring that throws never breaks storage.
    $silent = StorageMetrics::fromContainer(new Container());
    $silent->write('create', 'ok', 1.0);
    $silent->rebuildChunk(1.0, 5);
    try {
        $silent->timeWrite('create', static function () use ($connection): int {
            if ($connection->getDriverName() === 'sqlite') {
                throw new DomainException('kept');
            }

            return 7;
        });
        throw new LogicException('the timed write swallowed its exception');
    } catch (DomainException $exception) {
        storageViewExpect($exception->getMessage() === 'kept', 'the timed write changed its exception');
    }
    $throwing = new class {
        public function timing(string $id, float $ms, array $tags = []): void
        {
            throw new RuntimeException('monitoring is down');
        }

        public function count(string $id, int $n = 1, array $tags = []): void
        {
            throw new RuntimeException('monitoring is down');
        }
    };
    $broken = storageViewRuntime($connection, $ddl, $actor, $scope, new StorageMetrics(static fn (): object => $throwing));
    $kept = $broken['workbench']->createRecord($scope, 'workbench.secret_items', ['title' => 'Kept', 'qty' => 1], $actor);
    $broken['workbench']->updateRecord($scope, 'workbench.secret_items', $kept->recordId, $kept->revision, ['title' => 'Kept edit', 'qty' => 2], $actor);
    $broken['workbench']->readRecord($scope, 'workbench.secret_items', $kept->recordId, $actor);
    $broken['workbench']->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.secret_items'), $actor);
    (new StorageViewRebuilder($broken['views'], new StorageMetrics(static fn (): object => $throwing)))->rebuild($schemaId, 2, true);
    $unresolvable = new StorageMetrics(static function (): never {
        throw new RuntimeException('container is broken');
    });
    $unresolvable->read('ok', 1.0);
    $unresolvable->rebuildChunk(1.0, 1);
    storageViewParity($connection, $views, $schemaId, 'storage with a broken monitoring');

    // The provider resolves Metrics by name, declares monitoring.yaml in the catalog and registers the job.
    $app = new StorageMetricsTestApplication();
    $app->instance(DatabaseManager::class, $opened['manager']);
    $app->instance(PropertyTypeRegistryContract::class, PropertyTypeRegistry::builtIns());
    $access = new StorageViewFixtureAccess($actor, $scope);
    $app->instance(ActorOperationAuthorizer::class, $access);
    $app->instance(QueryScopeProvider::class, $access);
    $app->instance('config', new readonly class {
        public function get(string $key): ?string
        {
            return $key === 'app.key' ? 'storage-metrics-provider-key-minimum-32-bytes' : null;
        }
    });
    $app->singleton(JobTypeRegistry::class);
    $app->singleton(MetricCatalog::class);
    $providerRecorder = new StorageMetricsRecorder();
    $app->instance(Metrics::class, $providerRecorder);
    (new StorageServiceProvider($app))->register();
    $sources = $app->make(MetricCatalog::class)->sources;
    storageViewExpect(($sources[StorageMetrics::PACKAGE] ?? null) === StorageMetrics::catalogPath() && is_file(StorageMetrics::catalogPath()), 'the provider did not declare monitoring.yaml');
    $app->make(StorageMetrics::class)->write('create', 'ok', 2.0);
    storageViewExpect($providerRecorder->lines() === ['storage.write operation=create&outcome=ok'], 'the provider recorder did not reach the bound Metrics');
    $jobs = $app->make(JobTypeRegistry::class);
    storageViewExpect($jobs->has(StorageViewRebuildJob::JOB_TYPE), 'the provider did not register storage.view.rebuild');
    // Registering builds no handler: the витрина sync (and the connection it holds) is made only when the job runs.
    storageViewExpect(!$app->resolved(StorageViewSync::class), 'registering storage.view.rebuild resolved the витрина sync early');
    storageViewExpect($jobs->handler(StorageViewRebuildJob::JOB_TYPE) instanceof StorageViewRebuildDeferredHandler, 'storage.view.rebuild has another handler');
    // The field change job the same way: the workbench (and its connection) is made only when the job runs.
    storageViewExpect($jobs->has(StorageFieldChangeJob::JOB_TYPE), 'the provider did not register storage.schema.migrate');
    storageViewExpect(!$app->resolved(DatabaseStorageWorkbench::class), 'registering storage.schema.migrate resolved the workbench early');
    storageViewExpect($jobs->handler(StorageFieldChangeJob::JOB_TYPE) instanceof StorageViewRebuildDeferredHandler, 'storage.schema.migrate has another handler');
} finally {
    Facade::clearResolvedInstances();
    @unlink($opened['path']);
}

echo "StorageMetricsTest passed.\n";
