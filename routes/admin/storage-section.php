<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\Admin\Http\Controllers\RecordFilterOptionsController;
use Larena\Admin\Http\Controllers\RecordRelationOptionsController;
use Larena\Storage\Admin\Http\Controllers\FormerCmsAddressController;
use Larena\Storage\Admin\Http\Controllers\StorageFieldRevealController;
use Larena\Storage\Admin\Http\Controllers\StorageJobsController;
use Larena\Storage\Admin\Http\Controllers\StorageSectionController;
use Larena\Storage\Admin\Http\Controllers\StorageSitePackController;
use Larena\Storage\Admin\Http\Controllers\StorageSitePackImportController;
use Larena\Storage\Admin\Http\Controllers\StoragePropertyViewPortController;

// Storage's own section of the admin (/admin/storage): loaded by Storage's provider only where larena/admin is installed
// (Larena\Storage\Admin\StorageSectionBindings::adminInstalled()), under the section's flag.
if ((bool) config('larena-admin.storage_section.enabled', false)) {
    Route::prefix((string) config('larena-admin.storage_section.uri', 'admin/storage'))
        ->name((string) config('larena-admin.storage_section.name_prefix', 'larena.admin.storage.'))
        ->middleware([...(array) config('larena-admin.storage_section.middleware', ['web', 'access:admin.frontend.read']), 'larena-admin.framework-form'])
        ->group(static function (): void {
            $structure = 'workbench\.[a-z][a-z0-9_.:-]{0,109}';
            Route::get('/', [StorageSectionController::class, 'index'])->name('index');
            Route::post('/', [StorageSectionController::class, 'dispatch'])->name('dispatch');
            // Paged, scope-bound choices for the list filters and the record panel's user, file,
            // record and relation fields; the shared record option sources.
            Route::get('/filter-options', RecordFilterOptionsController::class)->name('filter-options');
            Route::get('/relation-options', RecordRelationOptionsController::class)->name('relation-options');
            // «Перенос на другой сайт» (storage.sitepack.export): a run is the person's own, by its ref; a storage ID always
            // starts with «workbench.», so these never meet the /{structure} routes.
            $run = 'storage-sitepack-[0-9a-f]{32}';
            Route::post('/sitepack/{run}/cancel', [StorageSitePackController::class, 'cancel'])->where('run', $run)->name('sitepack.cancel');
            Route::post('/sitepack/{run}/retry', [StorageSitePackController::class, 'retry'])->where('run', $run)->name('sitepack.retry');
            Route::get('/sitepack/{run}/download', [StorageSitePackController::class, 'download'])->where('run', $run)->name('sitepack.download');
            // «Загрузить хранилище» (storage.sitepack.import): the upload, then the person's run by its ref, its plan
            // again, «Загрузить», cancel and retry. «sitepack» is never a storage ID, so these never meet /{structure}.
            Route::get('/sitepack/import', [StorageSitePackImportController::class, 'form'])->name('sitepack.import');
            Route::post('/sitepack/import', [StorageSitePackImportController::class, 'upload'])->name('sitepack.import.upload');
            Route::get('/sitepack/import/{run}', [StorageSitePackImportController::class, 'show'])->where('run', $run)->name('sitepack.import.show');
            Route::post('/sitepack/import/{run}/plan', [StorageSitePackImportController::class, 'plan'])->where('run', $run)->name('sitepack.import.plan');
            Route::post('/sitepack/import/{run}/apply', [StorageSitePackImportController::class, 'apply'])->where('run', $run)->name('sitepack.import.apply');
            Route::post('/sitepack/import/{run}/cancel', [StorageSitePackImportController::class, 'cancel'])->where('run', $run)->name('sitepack.import.cancel');
            Route::post('/sitepack/import/{run}/retry', [StorageSitePackImportController::class, 'retry'])->where('run', $run)->name('sitepack.import.retry');
            Route::post('/{structure}/sitepack/export', [StorageSitePackController::class, 'export'])->where('structure', $structure)->name('sitepack.export');
            Route::get('/{structure}', [StorageSectionController::class, 'records'])->where('structure', $structure)->name('records');
            Route::post('/{structure}', [StorageSectionController::class, 'dispatch'])->where('structure', $structure)->name('structure.dispatch');
            Route::get('/{structure}/settings', [StorageSectionController::class, 'settings'])->where('structure', $structure)->name('settings');
            // «Отменить изменение» of the storage's field change running in the background (storage.workbench.structure.migrate).
            Route::post('/{structure}/field-change/cancel', [StorageSectionController::class, 'cancelFieldChange'])->where('structure', $structure)->name('field-change.cancel');
            // «Шифрование» (storage.encryption.manage, Storage asks for it too): «Сменить ключ шифрования» and «Повторить» of a
            // stopped encryption run. Both stand under /{structure}/encryption, so they never meet another storage route.
            Route::post('/{structure}/encryption/rotate', [StorageSectionController::class, 'rotateEncryptionKey'])->where('structure', $structure)->name('encryption.rotate');
            Route::post('/{structure}/encryption/retry', [StorageSectionController::class, 'retryEncryptionRun'])->where('structure', $structure)->name('encryption.retry');
            // «Показать» of an encrypted field in the record panel (storage.workbench.record.decrypt, every reveal audited by Storage).
            Route::post('/{structure}/reveal', StorageFieldRevealController::class)->where('structure', $structure)->name('reveal');
            // Records from a CSV file: the file, its plan, then the write (owner decision 2026-10-19).
            Route::get('/{structure}/import', [StorageSectionController::class, 'import'])->where('structure', $structure)->name('import');
            Route::post('/{structure}/import', [StorageSectionController::class, 'importPreview'])->where('structure', $structure)->name('import.preview');
            Route::post('/{structure}/import/apply', [StorageSectionController::class, 'importApply'])->where('structure', $structure)->name('import.apply');
            // The person's background imports and exports (storage.import.plan, storage.import.apply, storage.export.csv):
            // progress, «Применить» of a checked import, retry, cancel, an export of the list by the queue and its
            // download, to the person who started it only.
            $job = '[a-f0-9]{32}';
            Route::get('/{structure}/jobs', [StorageJobsController::class, 'index'])->where('structure', $structure)->name('jobs');
            Route::post('/{structure}/jobs/export', [StorageJobsController::class, 'export'])->where('structure', $structure)->name('jobs.export');
            Route::post('/{structure}/jobs/{job}/apply', [StorageJobsController::class, 'apply'])->where(['structure' => $structure, 'job' => $job])->name('jobs.apply');
            Route::post('/{structure}/jobs/{job}/retry', [StorageJobsController::class, 'retry'])->where(['structure' => $structure, 'job' => $job])->name('jobs.retry');
            Route::post('/{structure}/jobs/{job}/cancel', [StorageJobsController::class, 'cancel'])->where(['structure' => $structure, 'job' => $job])->name('jobs.cancel');
            Route::get('/{structure}/jobs/{job}/download', [StorageJobsController::class, 'download'])->where(['structure' => $structure, 'job' => $job])->name('jobs.download');
            // The storage's settings as lists (owner decision 2026-10-16): its fields as records of a host port.
            Route::post('/{structure}/schema-port', [StorageSectionController::class, 'schemaPort'])->where('structure', $structure)->name('schema-port');
        });
    // A record of the section in the Framework property view (simai.property-view-port 1.0.0). Outside the group:
    // a file travels as multipart beside its payload and must reach the port unchanged.
    Route::post(trim((string) config('larena-admin.storage_section.uri', 'admin/storage'), '/').'/property-port',
        StoragePropertyViewPortController::class)
        ->middleware((array) config('larena-admin.storage_section.middleware', ['web', 'access:admin.frontend.read']))
        ->name('larena.storage-section.property-port');
    // The address of the removed Minimal CMS demo (owner decision 2026-10-29): a permanent redirect into the section,
    // /admin/cms?structure_id=X to that storage.
    Route::get('admin/cms', FormerCmsAddressController::class)->middleware(['web'])
        ->name((string) config('larena-admin.storage_section.name_prefix', 'larena.admin.storage.').'former-cms');
}
