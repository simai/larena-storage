<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Larena\Admin\Runtime\PropertyTypeSettingsForm;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;

// The Storage section's pages follow the admin's rules for every product page (they were checked by larena/admin's
// AdminDataviewPageArchitectureGuardTest and FrameworkFormNamesTest until the section moved to Storage, owner decision
// 2026-10-29): the data view composite and the record panel come from the admin's shared presenters only; the
// converted pages render Framework controls; the routes restore Framework form names.
$root = dirname(__DIR__, 2);
$files = [];
foreach (['src/Admin', 'resources/views/admin'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.php$/', $file->getFilename()) === 1) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);
assert($files !== []);

// Nobody here draws the sf-data-view element, its slots or its table: the admin's AdminDataviewPagePresenter does.
foreach ($files as $file) {
    $source = (string) file_get_contents($root.'/'.$file);
    assert(preg_match("/render\\(\\s*'sf-data-view'|'type'\\s*=>\\s*'sf-data-view'|, 'sf-data-view'[,)]|<sf-data-view|slot=\\\\?\"(content|pagination)\\\\?\"|'<sf-table slot=/", $source) !== 1,
        'Data view assembled outside the admin\'s shared presenter: '.$file);
    // One record panel: the admin's AdminRecordPanelPresenter draws it.
    assert(!str_contains($source, 'data-larena-record-drawer'), 'Record panel drawn outside the admin\'s shared presenter: '.$file);
}
foreach (['src/Admin/Http/Controllers/StorageSectionController.php', 'src/Admin/Http/Controllers/StorageWorkbenchController.php'] as $user) {
    $source = (string) file_get_contents($root.'/'.$user);
    assert(str_contains($source, 'new AdminDataviewPage('), $user.' does not describe its data view');
    assert(!str_contains($source, 'staticTableHtml') && !str_contains($source, "render('sf-table'"), $user.' renders a bare table');
}
foreach (['index', 'records', 'settings'] as $view) {
    $source = (string) file_get_contents($root.'/resources/views/admin/'.$view.'.blade.php');
    assert(str_contains($source, "@include('larena-admin::dataview.page'"), $view.' does not use the shared data view structure');
}
$section = (string) file_get_contents($root.'/src/Admin/Http/Controllers/StorageSectionController.php');
assert(str_contains($section, 'rowLinks:'), 'the records list gives no row addresses');
assert(str_contains($section, 'new AdminRecordPanel('), 'the records page does not describe its record panel');
foreach (['index', 'records'] as $view) {
    assert(!str_contains((string) file_get_contents($root.'/resources/views/admin/'.$view.'.blade.php'), 'method="post"'), $view.' places a form beside the list');
}
assert(!file_exists($root.'/resources/views/admin/_input.blade.php') && !file_exists($root.'/resources/views/admin/_value.blade.php'));

// Converted pages render Framework controls, accordions and shared data views only; hidden inputs still carry fixed values.
foreach (['settings', 'import', 'jobs'] as $page) {
    $view = (string) file_get_contents($root.'/resources/views/admin/'.$page.'.blade.php');
    foreach (['<button', '<select', '<textarea', '<details', '<summary', '<table'] as $rawControl) {
        assert(!str_contains($view, $rawControl), $page . ' renders no raw ' . $rawControl);
    }
    assert(!str_contains($view, '<style'), $page . ' carries no inline style');
    assert(preg_match_all('/<input\b(?![^>]*(?:type="hidden"|class="larena-visually-hidden"))/', $view) === 0, $page . ' renders no visible raw input');
}
assert(str_contains((string) file_get_contents($root.'/routes/admin/storage-section.php'), "'larena-admin.framework-form'"), 'storage-section routes restore Framework form names');

// The Storage field editor's type settings are the admin's shared editor, whole (the settings catalog takes its part).
assert(array_keys(StorageFieldPropertyViewPort::editorSettings()) === array_keys(PropertyTypeSettingsForm::editorSettings()));

echo "StorageSectionAdminPagesTest passed.\n";
