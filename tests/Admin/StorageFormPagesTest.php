<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminFrameworkForm;
use Larena\Admin\Runtime\AdminRenderedPart;
use Larena\Storage\Admin\Runtime\StorageFieldEditor;
use Larena\Storage\Admin\Runtime\StorageImportPage;
use Larena\Storage\Admin\Runtime\StorageJobsPanel;
use Larena\Ui\Facades\Smart;
use Larena\Ui\Runtime\SmartManager;

// The Storage section's form pages described as typed parts and drawn by the admin's element Document renderer (developer remark 3', stage 5):
// the storage field editor, the CSV import page and the jobs panel. The field names, values,
// CSRF fields and method fields the pages post are the ones the Blade views wrote; the views print the result with {{ }}.
Smart::resolveUsing(static fn (): SmartManager => SmartManager::withDefaults());
$renderer = new AdminElementDocumentRenderer();
$translator = new class implements Translator {
    public function get($key, array $replace = [], $locale = null)
    {
        return $key.($replace === [] ? '' : json_encode($replace, JSON_UNESCAPED_UNICODE));
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $key;
    }

    public function getLocale()
    {
        return 'en';
    }

    public function setLocale($locale)
    {
    }
};

// ── The storage field editor: names through the form's list, in the order the view wrote them. ──
$form = AdminFrameworkForm::start();
$editor = new StorageFieldEditor($translator);
$rows = array_map(static fn (int $index) => $editor->describe($form, $index, null, ['workbench.cities' => 'Cities'], firstRequired: $index === 0, allowRequired: true), [0, 1]);
$html = (string) $renderer->tree(...[...$rows, AdminRenderedPart::control($form->names())]);
assert(substr_count($html, '<fieldset class="larena-workbench-fieldset larena-choice-grid" data-larena-storage-field>') === 2);
$firstRow = ['key', 'type', 'target_structure_id', 'options_text', 'required', 'label', 'position'];
$names = json_decode(html_entity_decode((string) preg_replace('/^.*name="'.AdminFrameworkForm::NAMES_FIELD.'" value="([^"]*)".*$/s', '$1', $html)), true);
assert(is_array($names) && array_slice($names, 0, 7) === array_map(static fn (string $key): string => 'payload[fields][0]['.$key.']', $firstRow) && count($names) === 14,
    'the rows\' names, in order, in one list');
$existing = (string) $renderer->tree($editor->describe(AdminFrameworkForm::start(), 3, ['key' => 'status', 'label' => 'Status', 'type' => 'choice', 'required' => true,
    'constraints' => ['options' => [['value' => 'a', 'label' => 'A', 'color' => 'red']]]], allowMigrate: true));
assert(str_contains($existing, 'id="storage-field-status"') && str_contains($existing, '<input type="hidden" name="payload[fields][3][key]" value="status">')
    && str_contains($existing, '<input type="hidden" name="payload[fields][3][required]" value="0">') && str_contains($existing, 'a | A | red'), 'an existing field keeps its key');

// ── The jobs panel and the import page. ──
$job = ['ref' => str_repeat('f', 32), 'kind' => 'import', 'stage' => 'check', 'state' => 'completed', 'created_at' => '', 'done' => 2, 'total' => 2, 'total_exact' => true,
    'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'reason' => null, 'can_retry' => false, 'can_cancel' => true, 'can_download' => false, 'can_apply' => true,
    'plan' => ['counts' => ['create' => 1, 'update' => 0, 'same' => 0], 'error_count' => 0, 'digest' => 'd"g']];
$panel = new StorageJobsPanel($translator);
$items = $panel->items([$job], '/admin/storage/w/jobs', 'TOKEN', true, '/admin/storage/w/import');
$html = (string) $renderer->tree($panel->describe($items));
assert(str_starts_with($html, '<section class="larena-panel larena-storage-jobs" aria-labelledby="storage-jobs-title" data-larena-storage-jobs>'));
assert(str_contains($html, '<form method="post" action="/admin/storage/w/jobs/'.str_repeat('f', 32).'/apply" class="larena-storage-jobs__action"><input type="hidden" name="_token" value="TOKEN"><input type="hidden" name="digest" value="d&quot;g">'),
    '«Применить» posts the plan\'s digest');
assert(str_contains((string) $renderer->tree($panel->describe([])), '<p>larena-storage::admin.section.jobs.empty</p>'), 'no jobs');
$page = (string) $renderer->tree(...(new StorageImportPage($translator))->describe([
    'upload_url' => 'https://larena.test/admin/storage/w/import', 'token' => 'TOKEN', 'records_path' => '/admin/storage/w', 'jobs_path' => '/admin/storage/w/jobs',
    'form' => AdminFrameworkForm::start(), 'error' => 'Refused <x>', 'queued' => true, 'checking' => null,
    'plan' => ['counts' => ['create' => 1, 'update' => 2, 'same' => 0], 'columns' => [['header' => 'id', 'field' => '_id', 'skipped' => null]], 'errors' => [], 'error_count' => 0, 'more_errors' => 0],
    'apply' => ['action' => '/admin/storage/w/import/apply', 'fields' => ['token' => 't', 'digest' => 'd'], 'text' => 'Apply'], 'jobs' => $items, 'panel' => $panel,
]));
assert(str_starts_with($page, '<div role="alert"><sf-alert'), 'a refused write first');
assert(str_contains($page, '<form method="post" enctype="multipart/form-data" action="https://larena.test/admin/storage/w/import" data-larena-storage-import-upload><input type="hidden" name="_token" value="TOKEN" autocomplete="off"><label class="larena-storage-import__file">'),
    'the upload posts the file with the CSRF field');
assert(str_contains($page, '<form method="post" action="/admin/storage/w/import/apply" data-larena-storage-import-apply><input type="hidden" name="_token" value="TOKEN" autocomplete="off"><input type="hidden" name="token" value="t"><input type="hidden" name="digest" value="d">'),
    '«Применить» posts the upload token and the digest');
assert(str_contains($page, 'larena-storage::admin.section.import.queued_note') && str_contains($page, 'data-larena-storage-jobs') && str_ends_with($page, '<a href="/admin/storage/w">larena-storage::admin.section.import.back</a></p>'));

// The hosts print what the presenters drew.
foreach (['admin/import', 'admin/jobs', 'admin/sitepack-import'] as $host) {
    $source = (string) file_get_contents(__DIR__.'/../../resources/views/'.$host.'.blade.php');
    assert(!str_contains($source, '{!!') && !str_contains($source, '@php') && !str_contains($source, '@foreach'), $host.' is a thin host');
}
assert(!is_file(__DIR__.'/../../resources/views/admin/_field.blade.php') && !is_file(__DIR__.'/../../resources/views/admin/_jobs.blade.php'), 'the partials are described parts now');

echo "StorageFormPagesTest passed.\n";
