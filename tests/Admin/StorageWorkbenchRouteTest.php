<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/../../config/larena-admin-storage.php';
$configSource = (string) file_get_contents(__DIR__ . '/../../config/larena-admin-storage.php');
$routeSource = (string) file_get_contents(__DIR__ . '/../../routes/admin/workbench.php');
$rootRouteSource = (string) file_get_contents(__DIR__ . '/../../src/Providers/StorageServiceProvider.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../src/Admin/Http/Controllers/StorageWorkbenchController.php');
$providerSource = (string) file_get_contents(__DIR__ . '/../../src/Admin/StorageSectionBindings.php');
$viewSource = (string) file_get_contents(__DIR__ . '/../../resources/views/admin/workbench.blade.php');

$workbench = $config['storage_workbench'] ?? null;
assert(is_array($workbench));
assert(($workbench['enabled'] ?? null) === false);
assert(($workbench['uri'] ?? null) === 'admin/storage-workbench');
assert(($workbench['name_prefix'] ?? null) === 'larena.admin.workbench.');
assert(($workbench['middleware'] ?? null) === [
    'web',
    'larena-auth.entry',
    'larena-auth.admin-required',
    'larena-admin.locale',
    'access:admin.frontend.read',
]);
assert(($workbench['operation_code'] ?? null) === 'admin.frontend.read');
assert(($workbench['csrf_required'] ?? null) === true);
assert(($workbench['owner_gateway_required'] ?? null) === true);
assert(str_contains($configSource, "getenv('LARENA_ADMIN_STORAGE_WORKBENCH')"));

assert(str_contains($rootRouteSource, "\$this->loadRoutesFrom(__DIR__ . '/../../routes/admin/workbench.php');"));
assert(str_contains($routeSource, "config('larena-admin.storage_workbench.enabled', false)"));
assert(str_contains($routeSource, "Route::get('/', [StorageWorkbenchController::class, 'show'])"));
assert(str_contains($routeSource, "Route::post('/', [StorageWorkbenchController::class, 'dispatch'])"));
assert(str_contains($routeSource, "config('larena-admin.storage_workbench.middleware'"));
assert(str_contains($viewSource, '@csrf'));
assert(substr_count($viewSource, 'method="post"') >= 6);
assert(str_contains($viewSource, 'data-larena-workbench-form'));
assert(str_contains($viewSource, 'enctype="multipart/form-data"'));
assert(str_contains($viewSource, "in_array((\$publication['status'] ?? null), ['draft', 'revoked'], true)"));

assert(str_contains($controllerSource, "'larena_access_actor'"));
assert(str_contains($controllerSource, 'AccessDeniedHttpException'));
assert(str_contains($controllerSource, 'MAX_INPUT_BYTES'));
assert(str_contains($controllerSource, 'MAX_OUTPUT_BYTES'));
assert(str_contains($controllerSource, 'MAX_UPLOAD_BYTES'));
assert(str_contains($controllerSource, 'catch (Throwable)'));
assert(str_contains($controllerSource, "'previous'"));
assert(str_contains($controllerSource, "'storage_key'"));
assert(!str_contains($controllerSource, 'getPrevious()'));
assert(!str_contains($controllerSource, '$exception->getMessage()'));
assert(preg_match('/Larena\\\\Storage\\\\(?!Admin\\\\)/', $controllerSource) === 0);
assert(!str_contains($controllerSource, 'Larena\\Filesystem\\'));
assert(!str_contains($controllerSource, 'Larena\\Content\\'));
assert(!str_contains($controllerSource, 'Larena\\Layout\\'));
// Storage's own section when Storage, Access, Filesystem and the product's scope are bound; otherwise unavailable.
assert(str_contains($providerSource, 'bindIf(StorageWorkbenchGateway::class, static fn (Application $app): StorageWorkbenchGateway => self::section($app)'));
assert(str_contains($providerSource, '?? new UnavailableStorageWorkbenchGateway()'));
assert(str_contains($providerSource, '[StorageWorkbench::class, ActorOperationAuthorizer::class, StorageSectionScope::class, PersistentLogicalFileInspector::class]'));

echo "StorageWorkbenchRouteTest passed.\n";
