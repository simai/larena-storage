<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$view = (string) file_get_contents(__DIR__ . '/../../resources/views/admin/workbench.blade.php');
$css = (string) file_get_contents(__DIR__ . '/../../vendor/larena/admin/resources/css/admin-shell.css');
$formState = (string) file_get_contents(__DIR__ . '/../../vendor/larena/admin/resources/js/form-state.js');

foreach ([
    'data-larena-storage-workbench',
    'aria-labelledby="workbench-state-title"',
    'aria-labelledby="workbench-scope-title"',
    'aria-labelledby="workbench-structures-title"',
    'aria-labelledby="workbench-records-title"',
    'aria-labelledby="workbench-integrations-title"',
    'aria-current="page"',
    'tabindex="0"',
    'type="checkbox"',
    'required',
    'value="structure.update"',
    'value="record.update"',
    'value="record.bulk_archive"',
    'value="file.folder_create"',
    'value="file.rename"',
    'value="file.move"',
    'value="content.create"',
    'value="content.update"',
    'value="layout.create"',
    'value="layout.update"',
    'value="publication.draft"',
    'value="publication.publish"',
    'value="publication.unpublish"',
    'data-larena-workbench-history',
    'data-larena-workbench-schema-evolution',
] as $marker) {
    assert(str_contains($view, $marker), 'missing accessibility marker ' . $marker);
}
assert(substr_count($view, '<label') >= 18);
assert(substr_count($view, '<h2') >= 5);
assert(str_contains($view, '@extends(\'larena-admin::layouts.app\')'));
assert(!str_contains($view, '{!!'));
assert(!str_contains($view, '<script'));
assert(!str_contains($view, 'contenteditable'));
assert(!str_contains($view, 'drag'));
assert(str_contains($css, '.larena-workbench-grid'));
assert(str_contains($css, '@media (max-width: 800px)'));
assert(str_contains($css, '@media (max-width: 390px)'));
assert(str_contains($css, 'form[aria-busy="true"]'));
assert(str_contains($formState, "form.setAttribute('aria-busy', 'true')"));

echo "StorageWorkbenchAccessibilityTest passed.\n";
