<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// The product Storage section: its own routes, menu group, and a controller
// that reads and writes only through the owner gateway.
$config = require __DIR__ . '/../../config/larena-admin-storage.php';
$routeSource = (string) file_get_contents(__DIR__ . '/../../routes/admin/storage-section.php');
$rootRouteSource = (string) file_get_contents(__DIR__ . '/../../src/Providers/StorageServiceProvider.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../src/Admin/Http/Controllers/StorageSectionController.php');
$providerSource = (string) file_get_contents(__DIR__ . '/../../src/Admin/StorageSectionBindings.php');
$views = [];
foreach (['index', 'records', 'settings'] as $name) {
    $views[$name] = (string) file_get_contents(__DIR__ . '/../../resources/views/admin/' . $name . '.blade.php');
}

$section = $config['storage_section'] ?? null;
assert(is_array($section));
assert(($section['uri'] ?? null) === 'admin/storage');
assert(($section['name_prefix'] ?? null) === 'larena.admin.storage.');
assert(($section['middleware'] ?? null) === ['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale', 'access:admin.frontend.read']);
// On by default in every environment; an explicit false turns it off.
assert(($section['enabled'] ?? null) === (getenv('LARENA_ADMIN_STORAGE_SECTION') === false || filter_var(getenv('LARENA_ADMIN_STORAGE_SECTION'), FILTER_VALIDATE_BOOL)));

// Storage's provider loads the section's routes, only where the admin is installed.
assert(str_contains($rootRouteSource, "if (\$this->composesAdmin()) {")
    && str_contains($rootRouteSource, "\$this->loadRoutesFrom(__DIR__ . '/../../routes/admin/storage-section.php');"));
assert(str_contains($routeSource, "config('larena-admin.storage_section.enabled', false)"));
foreach (["'index'])->name('index')", "'dispatch'])->name('dispatch')", "'records'])", "'settings'])"] as $needle) {
    assert(str_contains($routeSource, $needle), $needle);
}
assert(str_contains($routeSource, '[a-z][a-z0-9_.:-]{0,109}'));
// The record panel's reference fields take their choices from the section's own scope-bound sources.
assert(str_contains($routeSource, "Route::get('/filter-options', RecordFilterOptionsController::class)->name('filter-options')"));
assert(str_contains($routeSource, "Route::get('/relation-options', RecordRelationOptionsController::class)->name('relation-options')"));
assert(str_contains($controllerSource, '$this->container->bound(RecordFilterOptionsGateway::class)'));

// The removed Minimal CMS demo's address is a permanent redirect into the section (owner decision 2026-10-29).
$formerCms = (string) file_get_contents(__DIR__ . '/../../src/Admin/Http/Controllers/FormerCmsAddressController.php');
assert(str_contains($routeSource, "Route::get('admin/cms', FormerCmsAddressController::class)->middleware(['web'])"));
assert(str_contains($formerCms, "->to(\$target, 301)") && str_contains($formerCms, "'records', ['structure' => \$structure]"));

// Menu: its own group with the storages item, guarded by the list permission.
assert(str_contains($providerSource, "'larena.navigation.storage'"));
assert(str_contains($providerSource, "'accessScope' => 'storage.workbench.structure.list'"));
assert(str_contains($providerSource, "'parentId' => 'larena.navigation.storage'"));

// The controller never takes the actor or scope from input and names a closed action list.
assert(str_contains($controllerSource, "'larena_access_actor'"));
assert(str_contains($controllerSource, '$this->gateway->dispatch($actor, null, $action, $payload)'));
assert(str_contains($controllerSource, "['surface' => 'storage_section'] + \$query"));
foreach (['structure.create', 'structure.configure', 'structure.archive', 'structure.restore', 'structure.purge',
    'record.create', 'record.update', 'record.archive', 'record.restore', 'record.purge'] as $action) {
    assert(str_contains($controllerSource, "'" . $action . "'"), $action);
}
assert(!str_contains($controllerSource, "input('scope_ref')"));
assert(preg_match('/Larena\\\\Storage\\\\(?!Admin\\\\)/', $controllerSource) === 0);
assert(!str_contains($controllerSource, '$exception->getMessage()'));

// Every write is a CSRF form; destructive ones ask for confirmation.
foreach ($views as $name => $source) {
    assert(substr_count($source, 'method="post"') === substr_count($source, '@csrf'), $name);
}
// The structure forms of the settings page are described by the controller (developer remark 3', stage 3), each with its token.
assert(str_contains($controllerSource, "\$controls->input('payload[confirm_structure_id]'"));
assert(str_contains($controllerSource, "'data-larena-storage-purge-structure', 'structure.purge'"));
assert(str_contains($controllerSource, "new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => \$token, 'autocomplete' => 'off'])"));
// Record writes live in the shared record panel: CSRF, revision and confirmation for archive and permanent deletion.
assert(str_contains($controllerSource, '\'_token\' => $token'));
assert(str_contains($controllerSource, '\'action\' => \'record.purge\', \'payload[expected_revisions][\'.$recordId.\']\' => $revision'));
assert(str_contains($controllerSource, '\'confirm\' => $this->copy(\'confirm_purge\')'));
assert(str_contains($controllerSource, '\'confirm\' => $this->copy(\'confirm_archive\')'));
$panelSource = (string) file_get_contents(__DIR__ . '/../../vendor/larena/admin/src/Runtime/AdminRecordPanelPresenter.php');
assert(str_contains($panelSource, '\'name\' => $action[\'confirm_name\']'));
assert(str_contains($controllerSource, '$request->input(\'confirmed\') === \'1\''));
assert(!str_contains($views['records'], 'method="post"'));
// The records list reaches its port through the shared data view page structure.
assert(str_contains($views['records'], "'portUrl' => \$portUrl"));
assert(str_contains((string) file_get_contents(__DIR__ . '/../../vendor/larena/admin/resources/views/dataview/page.blade.php'), 'data-larena-port-url="{{ $portUrl }}"'));

// «Отменить изменение» of a running field change: its own POST route in the guarded group, a CSRF form that asks
// first and is drawn only for a person with the right to migrate the structure; the scope is never taken from input.
assert(str_contains($routeSource, 'Route::post(\'/{structure}/field-change/cancel\', [StorageSectionController::class, \'cancelFieldChange\'])->where(\'structure\', $structure)->name(\'field-change.cancel\')'));
// «Показать» of an encrypted field: one POST, under the section's middleware (the access check and CSRF of the web group).
assert(str_contains($routeSource, "Route::post('/{structure}/reveal', StorageFieldRevealController::class)->where('structure', \$structure)->name('reveal')"));
assert(str_contains($controllerSource, "'structure.field_change_cancel'"));
assert(str_contains($controllerSource, "(\$permissions['structure.migrate'] ?? false) === true ? \$this->fieldChanges()->cancelOffer(\$selected) : null"));
assert(str_contains($controllerSource, "'data-larena-confirm-submit' => \$offer['question']") && str_contains($controllerSource, "new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' =>"));
assert(str_contains($controllerSource, "StorageSectionGateway::FIELD_CHANGE_NOT_RUNNING => \$this->fieldChanges()->cancelEnded()"));
$formState = (string) file_get_contents(__DIR__ . '/../../vendor/larena/admin/resources/js/form-state.js');
assert(str_contains($formState, 'event.target.dataset?.larenaConfirmSubmit') && str_contains($formState, '!window.confirm(question)'));

// A large storage's list still being built (Storage's storage_view_not_ready): the records page says so in words
// from PHP, a permanent deletion that waits for a linked storage's list says that, and a reference picker shows the
// host's sentence instead of its general one.
assert(str_contains($controllerSource, "\$state = \$this->snapshot(\$request, \$query + ['records' => 'skip']);"));
assert(str_contains($controllerSource, "'listNotice' => \$listNotReady ? \$this->listNotice(StorageSectionGateway::VIEW_NOT_READY) : null"));
assert(str_contains($controllerSource, "in_array(\$action, ['record.purge', 'structure.purge'], true)\n                ? 'notice_related_list_not_ready' : 'notice_list_not_ready'"));
assert(str_contains($views['records'], "data-larena-storage-list-state=\"{{ \$listNotice['reason'] }}\""));
assert(str_contains((string) file_get_contents(__DIR__ . '/../../resources/views/admin/_state.blade.php'), "is_array(\$stateNotice ?? null)"));
$optionsSource = (string) file_get_contents(__DIR__ . '/../../vendor/larena/admin/src/Http/Controllers/RecordFilterOptionsController.php');
assert(str_contains($optionsSource, "\$exception->reasonCode === RecordOptionsRejected::LIST_NOT_READY"));
assert(str_contains($optionsSource, "'larena-admin::admin.record_options.reference_list_not_ready'"));
// Storage's refusals reach the pickers as the admin's own, with their reason code.
assert((new ReflectionClassConstant(\Larena\Admin\Record\RecordOptionsRejected::class, 'LIST_NOT_READY'))->getValue()
    === (new ReflectionClassConstant(\Larena\Storage\Admin\StorageSectionGateway::class, 'VIEW_NOT_READY'))->getValue());
assert(str_contains((string) file_get_contents(__DIR__ . '/../../src/Admin/StorageRecordFilterOptions.php'),
    "} catch (StorageRejected \$rejected) {\n            throw new RecordOptionsRejected(\$rejected->reasonCode, \$rejected);"));
assert(str_contains($formState, "status.textContent = said || (russian ? 'Варианты сейчас недоступны' : 'Options are unavailable');"));
foreach (['ru', 'en'] as $locale) {
    $copy = storage_admin_words($locale);
    foreach (['notice_list_not_ready', 'notice_related_list_not_ready', 'list_not_ready_title', 'list_not_ready_text',
        'list_unavailable_title', 'list_unavailable_text', 'reference_list_not_ready'] as $key) {
        assert(is_string($copy['larena_storage']['section'][$key] ?? null) && $copy['larena_storage']['section'][$key] !== '', $locale . ':' . $key);
    }
    assert(is_string($copy['larena_storage']['section']['jobs']['reasons']['storage_export_view_not_ready'] ?? null), $locale);
}

// «Создать» of a storage's records (QA 2026-10-22: gone from the toolbar and «⋮»): from data view port 1.10.0 the list
// offers it, and the person's column settings, only when the page names them; «Создать» only to a person who may
// create records in a storage that is not archived, never in the archive view.
assert(str_contains($controllerSource, "            'personal-column-settings',\n"));
assert(str_contains($controllerSource, "(\$permissions['record.create'] ?? false) === true && !\$structureArchived && !\$archiveView ? 'create-record' : null,"));
assert(str_contains($controllerSource, "'portCapabilities' => \$publishable !== null ? StorageSectionPublicationPortController::capabilities(\$permissions) : \$capabilities,"));

// Times of the section (a record's versions, jobs, SitePack runs) are the site's (QA 2026-10-22: 01:44 shown for a local
// 04:44 without a word): in its time zone, «UTC» named while the site keeps UTC, as monitoring shows them.
foreach (['src/Admin/Http/Controllers/StorageSectionController.php', 'src/Admin/Runtime/StorageJobsPanel.php', 'src/Admin/Runtime/StorageSitePackPanel.php',
    'src/Admin/Runtime/StorageSitePackImportPage.php'] as $file) {
    assert(str_contains((string) file_get_contents(__DIR__ . '/../../' . $file),
        "private static function when(string \$stored): string\n    {\n        return \\Larena\\Admin\\Settings\\AdminSiteSettings::shown(\$stored);"), $file);
}
$siteContainer = \Illuminate\Container\Container::getInstance();
assert(\Larena\Admin\Settings\AdminSiteSettings::shown('2026-10-22 01:44:00') === '22.10.2026 01:44');
$siteContainer->instance(\Larena\Admin\Settings\AdminSiteSettings::class,
    (new ReflectionClass(\Larena\Admin\Settings\AdminSiteSettings::class))->newInstanceWithoutConstructor());
assert(\Larena\Admin\Settings\AdminSiteSettings::shown('2026-10-22 01:44:00') === '22.10.2026 01:44 UTC');
assert(\Larena\Admin\Settings\AdminSiteSettings::shown('') === '');
$siteContainer->forgetInstance(\Larena\Admin\Settings\AdminSiteSettings::class);

// The records page (QA 2026-10-22): «Свойства хранилища» and «Ко всем хранилищам» beside the list as on the settings
// page; the breadcrumbs name the storage after «Хранилища», which links back; the panel's delete says «В архив».
assert(str_contains($controllerSource, "\$headerActions[] = ['text' => \$this->copy('storage_properties'),"));
assert(str_contains($controllerSource, "\$headerActions[] = ['text' => \$this->copy('back_to_list'), 'href' => \$this->sectionPath()];"));
assert(str_contains($controllerSource, "'delete_text' => \$this->copy('archive'),"));
$crumbs = \Larena\Admin\View\AdminShellViewComposer::withTail([['label' => 'Larena', 'href' => '/admin'], ['label' => 'Данные'], ['label' => 'Хранилища', 'current' => true]],
    ['parent_href' => '/admin/storage', 'items' => [['label' => 'Разделы', 'href' => '/admin/storage/workbench.sections'], ['label' => 'Настройки']]]);
assert($crumbs === [['label' => 'Larena', 'href' => '/admin'], ['label' => 'Данные'], ['label' => 'Хранилища', 'href' => '/admin/storage'],
    ['label' => 'Разделы', 'href' => '/admin/storage/workbench.sections'], ['label' => 'Настройки', 'current' => true]], json_encode($crumbs, JSON_UNESCAPED_UNICODE));
assert(\Larena\Admin\View\AdminShellViewComposer::withTail([['label' => 'A', 'current' => true]], null) === [['label' => 'A', 'current' => true]]);
assert(\Larena\Admin\View\AdminShellViewComposer::withTail([['label' => 'A', 'current' => true]], ['parent_href' => '//evil', 'items' => [['label' => 'B', 'href' => '//x']]])
    === [['label' => 'A'], ['label' => 'B', 'current' => true]]);
// Quick CSV is the list as shown, the background one every field but hidden and encrypted ones: their names say so.
foreach (['ru' => ['видимые колонки', 'все поля', 'кроме скрытых и зашифрованных'], 'en' => ['visible columns', 'all fields', 'except hidden and encrypted']] as $locale => [$quick, $all, $which]) {
    $copy = storage_admin_words($locale);
    assert(str_contains($copy['larena_storage']['section']['export_csv'], $quick) && str_contains($copy['larena_storage']['section']['jobs']['export_action'], $all)
        && str_contains($copy['larena_storage']['section']['jobs']['export_text_all'], $which) && str_contains($copy['larena_storage']['section']['jobs']['export_text_query'], $which), $locale);
}

// The section's write routes, each through the owner gateway or the section's own jobs: 23 here and the workbench
// stand's one (they were the admin's until the section moved to Storage, owner decision 2026-10-29).
$workbenchRoutes = (string) file_get_contents(__DIR__ . '/../../routes/admin/workbench.php');
assert(substr_count($routeSource, 'Route::post(') === 23 && substr_count($workbenchRoutes, 'Route::post(') === 1);
assert(str_contains($routeSource, "Route::post('/{structure}/import/apply', [StorageSectionController::class, 'importApply'])"));
assert(str_contains($routeSource, "Route::post('/{structure}/jobs/{job}/apply', [StorageJobsController::class, 'apply'])"));
assert(str_contains($routeSource, "Route::post('/', [StorageSectionController::class, 'dispatch'])->name('dispatch')"));
assert(str_contains($routeSource, "Route::post('/{structure}', [StorageSectionController::class, 'dispatch'])"));

echo "StorageSectionRouteTest passed.\n";
