<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Contracts\PropertyTypeRegistry as PropertyTypeRegistryContract;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Providers\StorageServiceProvider;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;

require_once __DIR__ . '/../../vendor/autoload.php';

final class WorkbenchProviderApplication extends Container implements Application
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

final readonly class WorkbenchProviderAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final readonly class WorkbenchProviderScope implements QueryScopeProvider
{
    public function supports(string $resourceType, string $operation): bool
    {
        return str_starts_with($resourceType, 'storage.workbench.') && str_starts_with($operation, 'storage.workbench.');
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        return $query;
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return AccessDecision::allow($operation, $actor, $resourceType . ':scope:tenant-alpha', 'provider_test');
    }
}

final readonly class WorkbenchProviderAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
    }
}

function workbenchProviderExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app = new WorkbenchProviderApplication();
$app->singleton(AccessOperationRegistry::class, static fn (): AccessOperationRegistry => new AccessOperationRegistry());
$app->instance(DatabaseManager::class, $capsule->getDatabaseManager());
$app->instance(PropertyTypeRegistryContract::class, PropertyTypeRegistry::builtIns());
$app->instance(ActorOperationAuthorizer::class, new WorkbenchProviderAuthorizer());
$scope = new WorkbenchProviderScope();
$app->instance(QueryScopeProvider::class, $scope);
$app->instance(AuditEventPipeline::class, new AuditEventPipeline(new DefaultAuditRedactor(), [new WorkbenchProviderAuditSink()]));
$app->instance('config', new readonly class {
    public function get(string $key): ?string
    {
        return $key === 'app.key' ? 'goal2-workbench-provider-key-minimum-32-bytes' : null;
    }
});

$provider = new StorageServiceProvider($app);
$provider->register();
$workbench = $app->make(StorageWorkbench::class);
workbenchProviderExpect($workbench instanceof DatabaseStorageWorkbench, 'StorageWorkbench contract binding mismatch');
$scopeProperty = new ReflectionProperty(DatabaseStorageWorkbench::class, 'scopeProvider');
workbenchProviderExpect($scopeProperty->getValue($workbench) === $scope, 'selected QueryScopeProvider was not injected');
$registry = $app->make(AccessOperationRegistry::class);
foreach ([
    'storage.workbench.structure.create' => 'storage.workbench.structure:all',
    'storage.workbench.structure.update' => 'storage.workbench.structure:all',
    'storage.workbench.record.create' => 'storage.workbench.record:all',
    'storage.workbench.record.list' => 'storage.workbench.record:all',
    'storage.workbench.record.bulk_archive' => 'storage.workbench.record:all',
] as $operation => $target) {
    $descriptor = $registry->get($operation);
    workbenchProviderExpect($descriptor?->ownerPackage === 'larena/storage', $operation . ' owner mismatch');
    workbenchProviderExpect($descriptor?->target === $target, $operation . ' target mismatch');
}

echo "StorageWorkbenchProviderBindingTest passed.\n";
