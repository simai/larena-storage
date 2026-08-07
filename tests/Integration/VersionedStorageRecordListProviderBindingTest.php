<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\AccessSubjectDirectory;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\Runtime\PersistentGlobalRoleQueryScopeProvider;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Contracts\PropertyTypeRegistry as PropertyTypeRegistryContract;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageRecordListQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Providers\StorageServiceProvider;
use Larena\Storage\Runtime\VersionedStorage;

require_once __DIR__ . '/../../vendor/autoload.php';

final class RecordListProviderTestApplication extends Container implements Application
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

final readonly class RecordListProviderAllowAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final readonly class RecordListProviderSubjects implements AccessSubjectDirectory
{
    public function exists(string $subjectRef): bool
    {
        return true;
    }

    public function isActive(string $subjectRef): bool
    {
        return true;
    }
}

final readonly class RecordListProviderAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
    }
}

function recordListProviderExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function recordListProviderApplication(DatabaseManager $database): RecordListProviderTestApplication
{
    $app = new RecordListProviderTestApplication();
    $app->singleton(AccessOperationRegistry::class, static fn (): AccessOperationRegistry => new AccessOperationRegistry());
    $app->instance(DatabaseManager::class, $database);
    $app->instance(PropertyTypeRegistryContract::class, PropertyTypeRegistry::builtIns());
    $app->instance(ActorOperationAuthorizer::class, new RecordListProviderAllowAuthorizer());
    $app->instance(AuditEventPipeline::class, new AuditEventPipeline(
        new DefaultAuditRedactor(),
        [new RecordListProviderAuditSink()],
    ));
    $app->instance('config', new readonly class {
        public function get(string $key): ?string
        {
            return $key === 'app.key' ? 's-storage-01-provider-binding-key-32-bytes' : null;
        }
    });
    (new StorageServiceProvider($app))->register();

    return $app;
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$connection = $capsule->getConnection();
$connection->getSchemaBuilder()->create('larena_access_operations', static function (Blueprint $table): void {
    $table->string('code', 160)->primary();
    $table->string('owner_package');
    $table->string('label_key');
    $table->string('target');
    $table->string('required_grant');
    $table->string('risk');
    $table->boolean('audit_denials');
    $table->timestamps();
});

$selectedApp = recordListProviderApplication($capsule->getDatabaseManager());
$registry = $selectedApp->make(AccessOperationRegistry::class);
$descriptor = $registry->get('storage.record.list');
recordListProviderExpect($descriptor?->target === 'storage.record:all', 'Storage list descriptor target mismatch');
$connection->table('larena_access_operations')->insert([
    'code' => 'storage.record.list',
    'owner_package' => 'larena/storage',
    'label_key' => 'larena-storage::operations.record_list',
    'target' => 'storage.record:all',
    'required_grant' => 'read',
    'risk' => 'high',
    'audit_denials' => true,
    'created_at' => '2026-08-07 20:00:00',
    'updated_at' => '2026-08-07 20:00:00',
]);

$realProvider = new PersistentGlobalRoleQueryScopeProvider(
    $registry,
    new PersistentAccessStore($connection),
    new RecordListProviderSubjects(),
    new RecordListProviderAllowAuthorizer(),
);
recordListProviderExpect(
    $realProvider->supports('storage.record', 'storage.record.list'),
    'real portfolio provider rejected canonical Storage resource type',
);
recordListProviderExpect(
    !$realProvider->supports('storage.record:inventory.widget', 'storage.record.list'),
    'schema-suffixed resource unexpectedly remained compatible',
);
$identityQuery = ['schema_id' => 'inventory.widget', 'filters' => []];
recordListProviderExpect(
    $realProvider->scope(
        $identityQuery,
        'actor:reader:1',
        'storage.record.list',
        ['resource_type' => 'storage.record'],
    ) === $identityQuery,
    'real provider did not preserve exact schema query input',
);

$selectedApp->instance(QueryScopeProvider::class, $realProvider);
$selectedStorage = $selectedApp->make(VersionedStorage::class);
$scopeProperty = new ReflectionProperty(VersionedStorage::class, 'queryScopeProvider');
recordListProviderExpect(
    $scopeProperty->getValue($selectedStorage) === $realProvider,
    'explicit consumer-selected QueryScopeProvider was not injected',
);

$missingApp = recordListProviderApplication($capsule->getDatabaseManager());
recordListProviderExpect(
    !$missingApp->bound(QueryScopeProvider::class),
    'Storage provider created an implicit QueryScopeProvider binding',
);
$missingStorage = $missingApp->make(VersionedStorage::class);
recordListProviderExpect(
    $scopeProperty->getValue($missingStorage) === null,
    'missing QueryScopeProvider did not remain null',
);
try {
    $missingStorage->listCurrentRecords(
        new StorageRecordListQuery('inventory.widget'),
        'actor:reader:1',
    );
    throw new RuntimeException('missing QueryScopeProvider unexpectedly allowed a list');
} catch (StorageRejected $exception) {
    recordListProviderExpect(
        $exception->reasonCode === 'storage_record_list_scope_missing',
        'missing QueryScopeProvider rejection reason mismatch',
    );
}

echo "VersionedStorage real-provider/container binding tests passed: 7 scenarios.\n";
