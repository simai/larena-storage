<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Storage\Admin\Http\Controllers\StorageWorkbenchController;

// A stand over Storage's workbench in the admin: loaded by Storage's provider only where larena/admin is installed.
if ((bool) config('larena-admin.storage_workbench.enabled', false)) {
    Route::prefix((string) config('larena-admin.storage_workbench.uri', 'admin/storage-workbench'))
        ->name((string) config('larena-admin.storage_workbench.name_prefix', 'larena.admin.workbench.'))
        ->middleware((array) config('larena-admin.storage_workbench.middleware', ['web', 'access:admin.frontend.read']))
        ->group(static function (): void {
            Route::get('/', [StorageWorkbenchController::class, 'show'])->name('index');
            Route::post('/', [StorageWorkbenchController::class, 'dispatch'])->name('dispatch');
        });
}
