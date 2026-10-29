<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\Http\Controllers\StorageSectionController;
use Larena\Storage\Admin\Http\Controllers\StorageSitePackController;
use Larena\Storage\Admin\FilesystemStorageSitePackFiles;
use Larena\Storage\Admin\StorageSitePackExports;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Storage\Admin\Runtime\StorageSitePackPanel;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Core\Scope\ScopeRef;
use Larena\Filesystem\Contracts\PortableLogicalFileStore;
use Larena\Filesystem\Exceptions\PortableLogicalFileFailed;
use Larena\Filesystem\ValueObjects\PortableLogicalFileDescriptor;
use Larena\Filesystem\ValueObjects\PortableLogicalFileImportResult;
use Larena\Filesystem\ValueObjects\PortableLogicalFileSnapshot;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\StorageSitePackArchiveFile;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SitePack\StorageSitePackFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// «Перенос на другой сайт» of a storage (larena-specs docs/architecture/2026-10-22-storage-sitepack.md): the file
// port over Filesystem limited to the exported site, the export panel of the settings page with its states and plain
// reasons, hidden without storage.sitepack.export, and the run routes that reach only the person's own runs.

function sitepack_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('StorageSitePackExportTest failed: '.$message);
    }
}

const SITEPACK_FILE = '0b5c8a52-6c43-4a8e-9e0a-0d0b6c9a1f11';
const SITEPACK_FOREIGN = '1c6d9b63-7d54-4b9f-8f1b-1e1c7d0b2a22';
const SITEPACK_GONE = '2d7eac74-8e65-4ca0-9a2c-2f2d8e1c3b33';
const SITEPACK_BROKEN = '3e8fbd85-9f76-4db1-8b3d-3a3e9f2d4c44';

final class SitePackFakeBindings implements ScopeResourceBindings
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, string> $owners logical ref => scope ref */
    public function __construct(private array $owners)
    {
    }

    public function bind(string $resourceType, string $resourceRef, ScopeRef $scope): void
    {
    }

    public function scopeOf(string $resourceType, string $resourceRef): ?ScopeRecord
    {
        return null;
    }

    public function belongsTo(string $resourceType, string $resourceRef, ScopeRef $scope): bool
    {
        $this->asked[] = $resourceType.' '.$resourceRef.' '.$scope->toString();

        return $resourceType === 'filesystem.logical_file' && ($this->owners[$resourceRef] ?? null) === $scope->toString();
    }

    public function listRefs(ScopeRef $scope, string $resourceType, ?string $cursor = null, int $limit = 50): array
    {
        return ['items' => [], 'next_cursor' => null];
    }
}

final class SectionSitePackFakeFiles implements PortableLogicalFileStore
{
    public function snapshot(string $logicalRef): PortableLogicalFileSnapshot
    {
        if ($logicalRef === SITEPACK_GONE) {
            throw new PortableLogicalFileFailed('filesystem_portable_file_not_found');
        }
        if ($logicalRef === SITEPACK_BROKEN) {
            throw new PortableLogicalFileFailed('filesystem_portable_blob_unavailable');
        }

        return new PortableLogicalFileSnapshot(new PortableLogicalFileDescriptor(
            logicalRef: $logicalRef,
            publicId: 'aa5c8a52-6c43-4a8e-9e0a-0d0b6c9a1f11',
            displayName: 'Договор',
            originalName: 'contract.pdf',
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: 5,
            sha256: hash('sha256', 'hello'),
            visibility: 'private',
            altText: null,
        ), static function (): mixed {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, 'hello');
            rewind($stream);

            return $stream;
        });
    }

    public function import(PortableLogicalFileDescriptor $descriptor, mixed $stream): PortableLogicalFileImportResult
    {
        throw new RuntimeException('not_used');
    }

    public function contentMatches(string $sha256, int $sizeBytes, string $extension, ?callable $belongs = null): array
    {
        return [];
    }

    public function logicalRefTaken(string $logicalRef): bool
    {
        return false;
    }

    public function publicIdTaken(string $publicId): bool
    {
        return false;
    }
}

final class SitePackFakeStorage implements StorageSitePack
{
    /** @var array<string, StorageSitePackRun> */
    public array $runs = [];
    public bool $queue = true;
    public ?string $refuseStart = null;
    public bool $denied = false;
    public int $inlineRuns = 0;
    public ?string $archivePath = null;
    public int $downloads = 0;

    public function startExport(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        if ($this->denied) {
            throw new StorageRejected('storage_sitepack_export_denied');
        }
        if ($this->refuseStart !== null) {
            throw new StorageRejected($this->refuseStart);
        }
        $ref = 'storage-sitepack-'.str_pad(dechex(count($this->runs) + 1), 32, '0', STR_PAD_LEFT);

        return $this->runs[$ref] = self::make($ref, $scopeRef, $structureId, $actor, 'queued', 'prepare', $this->queue ? 'job-'.$ref : null);
    }

    public function run(string $runRef, string $actor): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        $this->inlineRuns++;

        return $this->runs[$runRef] = self::make($runRef, $run->scopeRef, (string) $run->structureId, $actor, 'done', 'done', null,
            ['records' => 12, 'files' => 3, 'blob_bytes' => 2048], 12, 12, 4096);
    }

    public function read(string $runRef, string $actor): StorageSitePackRun
    {
        $run = $this->runs[$runRef] ?? null;
        if ($run === null || $run->actor !== $actor) {
            throw new StorageRejected('storage_sitepack_run_unknown');
        }

        return $run;
    }

    public function recent(string $actor, int $limit = 20): array
    {
        if ($this->denied) {
            throw new StorageRejected('storage_sitepack_export_denied');
        }

        return array_values(array_reverse(array_filter($this->runs, static fn (StorageSitePackRun $run): bool => $run->actor === $actor)));
    }

    public function archive(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackArchiveFile
    {
        $run = $this->read($runRef, $actor);
        if (!$run->isDone() || $this->archivePath === null) {
            throw new StorageRejected('storage_sitepack_run_not_done');
        }
        $this->downloads++;

        return new StorageSitePackArchiveFile($runRef, $this->archivePath, 4096, str_repeat('a', 64), 'package-1', 'people-20261022.sitepack.zip');
    }

    public function cancel(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if (in_array($run->state, ['done', 'cancelled'], true)) {
            throw new StorageRejected('storage_sitepack_run_finished');
        }

        return $this->runs[$runRef] = self::make($runRef, $run->scopeRef, (string) $run->structureId, $actor, 'cancelled', $run->phase, $run->jobId);
    }

    public function retry(string $runRef, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if (!in_array($run->state, ['failed', 'cancelled'], true)) {
            throw new StorageRejected('storage_sitepack_run_not_retryable');
        }

        return $this->runs[$runRef] = self::make($runRef, $run->scopeRef, (string) $run->structureId, $actor, 'queued', 'prepare', $run->jobId);
    }

    // The import side is not used by the export page; it is answered as refused.
    public function receive(string $scopeRef, string $archivePath, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_import_denied');
    }

    public function plan(string $runRef, StorageSitePackImportOptions $options, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_import_denied');
    }

    public function apply(string $runRef, string $planDigest, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_import_denied');
    }

    /** @param array<string, int> $counts */
    public static function make(string $ref, string $scope, string $structure, string $actor, string $state, string $phase, ?string $jobId,
        array $counts = [], int $done = 0, int $total = 0, ?int $bytes = null, ?string $reason = null, string $kind = 'export'): StorageSitePackRun
    {
        return new StorageSitePackRun($ref, $kind, $scope, 'site:main', $structure, $actor, $state, $phase, $done, $total, $counts,
            $state === 'done' ? 'package-1' : null, $state === 'done' ? str_repeat('a', 64) : null, $bytes, $jobId, $reason, null,
            '2026-10-22T09:00:00+00:00', '2026-10-22T09:00:00+00:00', null);
    }
}

/** The admin's own texts, read from its language file: «larena-admin::admin.<path>» with :replacements. */
final class SitePackTexts implements \Illuminate\Contracts\Translation\Translator
{
    /** @param array<string, mixed> $copy */
    public function __construct(private array $copy)
    {
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        $value = $this->copy;
        foreach (explode('.', substr((string) $key, strlen('larena-admin::admin.'))) as $part) {
            $value = is_array($value) && array_key_exists($part, $value) ? $value[$part] : null;
        }
        if (!is_string($value) || !str_starts_with((string) $key, 'larena-admin::admin.')) {
            return $asked;
        }
        foreach ($replace as $name => $text) {
            $value = str_replace(':'.$name, (string) $text, $value);
        }

        return $value;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->get($key, $replace);
    }

    public function getLocale()
    {
        return 'ru';
    }

    public function setLocale($locale)
    {
    }
}

final class SitePackConfig implements \Illuminate\Contracts\Config\Repository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public function has($key)
    {
        return array_key_exists($key, $this->items);
    }

    public function get($key, $default = null)
    {
        return is_string($key) && array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function all()
    {
        return $this->items;
    }

    public function set($key, $value = null)
    {
        $this->items[(string) $key] = $value;
    }

    public function prepend($key, $value)
    {
    }

    public function push($key, $value)
    {
    }
}

final class SitePackFakeGateway implements StorageWorkbenchGateway
{
    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        $structure = $query['structure_id'] ?? null;

        return $structure === 'workbench.people'
            ? ['status' => 'ok', 'scope_ref' => 'scope:alpha', 'selected_structure' => ['structure_id' => 'workbench.people', 'label' => 'Люди'], 'permissions' => []]
            : ['status' => 'ok', 'scope_ref' => 'scope:alpha', 'selected_structure' => null, 'permissions' => []];
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        throw new RuntimeException('not_used');
    }
}

// ── The file port: only files of the exported site; absent ones are null, a broken one throws. ──
$bindings = new SitePackFakeBindings([SITEPACK_FILE => 'site:main', SITEPACK_FOREIGN => 'site:other', SITEPACK_GONE => 'site:main', SITEPACK_BROKEN => 'site:main']);
$port = new FilesystemStorageSitePackFiles(new SectionSitePackFakeFiles(), $bindings);
sitepack_assert($port->available(), 'The port reads files.');
$file = $port->snapshot('site:main', SITEPACK_FILE);
sitepack_assert($file instanceof StorageSitePackFile && $file->logicalRef() === SITEPACK_FILE && $file->sha256() === hash('sha256', 'hello')
    && $file->sizeBytes() === 5 && array_keys($file->descriptor) === StorageSitePackFile::DESCRIPTOR_KEYS, 'A file of the site carries its portable descriptor.');
sitepack_assert(stream_get_contents($file->openStream()) === 'hello', 'Its bytes come from Filesystem.');
sitepack_assert($port->snapshot('site:main', SITEPACK_FOREIGN) === null, 'A file of another site is absent.');
sitepack_assert($port->snapshot('site:main', SITEPACK_GONE) === null, 'A deleted file is absent.');
sitepack_assert($port->snapshot('not a scope', SITEPACK_FILE) === null, 'A malformed site is absent.');
try {
    $port->snapshot('site:main', SITEPACK_BROKEN);
    sitepack_assert(false, 'A file that cannot be read fails.');
} catch (PortableLogicalFileFailed $exception) {
    sitepack_assert($exception->reasonCode === 'filesystem_portable_blob_unavailable', 'The failure is Filesystem\'s own.');
}
sitepack_assert(in_array('filesystem.logical_file '.SITEPACK_FOREIGN.' site:main', $bindings->asked, true), 'Ownership is asked of core\'s bindings.');

// The application binds the port lazily: it replaces Storage's default only when it is resolved, with Filesystem and core bound.
$provider = (string) file_get_contents(__DIR__.'/../../src/Admin/StorageSectionBindings.php');
sitepack_assert(str_contains($provider, '$app->extend(\\Larena\\Storage\\SitePack\\StorageSitePackFiles::class')
    && str_contains($provider, '$files instanceof \\Larena\\Storage\\SitePack\\UnavailableStorageSitePackFiles')
    && str_contains($provider, '$app->bound(\\Larena\\Filesystem\\Contracts\\PortableLogicalFileStore::class) && $app->bound(\\Larena\\Core\\Contracts\\ScopeResourceBindings::class)'),
    'The provider extends the port lazily, only over the default and only when Filesystem and core are bound.');
$container = new Container();
$container->bind(\Larena\Storage\SitePack\StorageSitePackFiles::class, \Larena\Storage\SitePack\UnavailableStorageSitePackFiles::class);
$extender = static fn (\Larena\Storage\SitePack\StorageSitePackFiles $files, \Illuminate\Contracts\Container\Container $app): \Larena\Storage\SitePack\StorageSitePackFiles
    => $files instanceof \Larena\Storage\SitePack\UnavailableStorageSitePackFiles && $app->bound(PortableLogicalFileStore::class) && $app->bound(ScopeResourceBindings::class)
        ? new FilesystemStorageSitePackFiles($app->make(PortableLogicalFileStore::class), $app->make(ScopeResourceBindings::class)) : $files;
$container->extend(\Larena\Storage\SitePack\StorageSitePackFiles::class, $extender);
sitepack_assert($container->make(\Larena\Storage\SitePack\StorageSitePackFiles::class) instanceof \Larena\Storage\SitePack\UnavailableStorageSitePackFiles, 'Without Filesystem the default stays.');
$container->instance(PortableLogicalFileStore::class, new SectionSitePackFakeFiles());
$container->instance(ScopeResourceBindings::class, $bindings);
sitepack_assert($container->make(\Larena\Storage\SitePack\StorageSitePackFiles::class) instanceof FilesystemStorageSitePackFiles, 'With Filesystem and core the files are read.');

// ── The panel: texts, states, «n из N», counts and reasons in plain words. ──
$translator = new SitePackTexts(storage_admin_words('ru'));
$panel = new StorageSitePackPanel($translator);
$admin = 'user:admin_identity:1';
$other = 'user:admin_identity:2';
$done = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('d', 32), 'scope:alpha', 'workbench.people', $admin, 'done', 'done', 'job-1',
    ['records' => 1500, 'files' => 7, 'blob_bytes' => 3_145_728], 1500, 1500, 3_145_728);
$running = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('e', 32), 'scope:alpha', 'workbench.people', $admin, 'running', 'records', 'job-2',
    ['records' => 500], 500, 1500);
$changed = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('f', 32), 'scope:alpha', 'workbench.people', $admin, 'failed', 'records', 'job-3',
    [], 200, 1500, null, 'storage_sitepack_structure_changed');
$html = $panel->render([
    (new ReflectionMethod(StorageSitePackExports::class, 'project'))->invoke(null, $done),
    (new ReflectionMethod(StorageSitePackExports::class, 'project'))->invoke(null, $running),
    (new ReflectionMethod(StorageSitePackExports::class, 'project'))->invoke(null, $changed),
], '/admin/storage/workbench.people/sitepack/export', '/admin/storage/sitepack', 'csrf-token');
foreach (['Перенос на другой сайт', 'Выгрузит структуру, все записи с переводами и публикацией и их файлы. История изменений не переносится.',
    'Выгрузить хранилище', 'action="/admin/storage/workbench.people/sitepack/export"', 'name="_token" value="csrf-token"',
    'Готово', 'Выполняется', '500 из 1 500 записей', 'Записей: 1 500, файлов: 7, размер: 3,0 МБ', 'записи',
    'Структура изменилась во время выгрузки — повторите.',
    'href="/admin/storage/sitepack/storage-sitepack-'.str_repeat('d', 32).'/download"',
    'action="/admin/storage/sitepack/storage-sitepack-'.str_repeat('f', 32).'/retry"',
    'action="/admin/storage/sitepack/storage-sitepack-'.str_repeat('e', 32).'/cancel" class="larena-storage-jobs__action" data-larena-confirm-submit="Отменить выгрузку? Уже собранное будет удалено."'] as $needle) {
    sitepack_assert(str_contains(str_replace("\u{00A0}", ' ', $html), $needle), 'The panel shows: '.$needle);
}
sitepack_assert(!str_contains($html, 'storage-sitepack-'.str_repeat('d', 32).'/cancel') && !str_contains($html, 'storage-sitepack-'.str_repeat('d', 32).'/retry'),
    'A finished export is only downloaded.');
sitepack_assert(!str_contains($html, 'storage-sitepack-'.str_repeat('e', 32).'/download'), 'A running export has nothing to download yet.');
sitepack_assert(substr_count($html, 'method="post"') === substr_count($html, 'name="_token"'), 'Every action is a CSRF form.');
foreach ([
    'storage_sitepack_field_change_running' => 'Сейчас меняется поле — дождитесь окончания.',
    'storage_sitepack_structure_changed' => 'Структура изменилась во время выгрузки — повторите.',
    'storage_sitepack_interrupted' => 'Выгрузка продолжится автоматически.',
    'storage_sitepack_run_busy' => 'Выгрузка продолжится автоматически.',
    'storage_sitepack_files_unavailable' => 'В хранилище есть файлы, а файлы на этом сайте сейчас недоступны. Выгрузить его нельзя.',
    'storage_sitepack_something_new' => 'Выгрузка не выполнена.',
] as $code => $text) {
    sitepack_assert($panel->reason($code) === $text, 'Reason '.$code.' in plain words.');
}
sitepack_assert(str_contains($panel->render([], '/x', '/y', 't'), 'Выгрузить хранилище') && !str_contains($panel->render([], '/x', '/y', 't'), 'Ваши выгрузки'),
    'Without runs the panel offers the export alone.');
$en = storage_admin_words('en');
$ru = storage_admin_words('ru');
$keys = static function (array $copy, string $prefix = '') use (&$keys): array {
    $all = [];
    foreach ($copy as $key => $value) {
        $all = array_merge($all, is_array($value) ? $keys($value, $prefix.$key.'.') : [$prefix.$key]);
    }

    return $all;
};
sitepack_assert($keys($ru['larena_storage']['section']['sitepack']) === $keys($en['larena_storage']['section']['sitepack']), 'Russian and English carry the same texts.');

// ── The exports over Storage: the person's runs of this storage only; a run no queue took runs in the request. ──
$storage = new SitePackFakeStorage();
$exports = new StorageSitePackExports($storage);
$storage->runs[$done->runRef] = $done;
$storage->runs['storage-sitepack-'.str_repeat('1', 32)] = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('1', 32), 'scope:alpha', 'workbench.other', $admin, 'done', 'done', 'j');
$storage->runs['storage-sitepack-'.str_repeat('2', 32)] = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('2', 32), 'scope:beta', 'workbench.people', $admin, 'done', 'done', 'j');
$storage->runs['storage-sitepack-'.str_repeat('3', 32)] = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('3', 32), 'scope:alpha', 'workbench.people', $other, 'done', 'done', 'j');
$storage->runs['storage-sitepack-'.str_repeat('4', 32)] = SitePackFakeStorage::make('storage-sitepack-'.str_repeat('4', 32), 'scope:alpha', 'workbench.people', $admin, 'queued', 'prepare', 'j', [], 0, 0, null, null, 'import');
$mine = $exports->runs($admin, 'scope:alpha', 'workbench.people');
sitepack_assert(is_array($mine) && array_column($mine, 'ref') === [$done->runRef], 'Only the person\'s exports of this storage in this scope.');
sitepack_assert($exports->runs($other, 'scope:alpha', 'workbench.people') !== [] && $exports->read($other, $done->runRef) === null, 'Another person\'s run is unknown.');
$queued = $exports->start($admin, 'scope:alpha', 'workbench.people');
sitepack_assert($queued['reason'] === null && $queued['run']['state'] === 'queued' && $storage->inlineRuns === 0, 'With a queue the export is queued.');
$storage->queue = false;
$inline = $exports->start($admin, 'scope:alpha', 'workbench.people');
sitepack_assert($inline['reason'] === null && $inline['run']['state'] === 'done' && $inline['run']['records'] === 12 && $storage->inlineRuns === 1,
    'Without a queue the export runs in the request.');
$storage->refuseStart = 'storage_sitepack_field_change_running';
sitepack_assert($exports->start($admin, 'scope:alpha', 'workbench.people') === ['run' => null, 'reason' => 'storage_sitepack_field_change_running'], 'A refusal comes back as its reason.');
$storage->refuseStart = null;
sitepack_assert($exports->cancel($admin, $done->runRef)['reason'] === 'storage_sitepack_run_finished', 'A finished export cannot be cancelled.');
sitepack_assert($exports->archive($other, $done->runRef) === null && $exports->archive($admin, '../etc/passwd') === null, 'An archive is the owner\'s only.');
$storage->denied = true;
sitepack_assert($exports->runs($admin, 'scope:alpha', 'workbench.people') === null, 'Without the export right there is no panel.');
$storage->denied = false;

// ── The settings page: the panel is drawn only for a person with storage.sitepack.export. ──
$config = new SitePackConfig(['larena-admin.storage_section.uri' => 'admin/storage', 'larena-auth.admin_entry.access_actor_attribute' => 'larena_access_actor']);
$session = new Store('test', new ArraySessionHandler(10));
$session->start();
$redirector = new Redirector(new UrlGenerator(new RouteCollection(), Request::create('http://larena.test/admin')));
$redirector->setSession($session);
$app = new Container();
$app->instance(StorageSitePack::class, $storage);
$gateway = new SitePackFakeGateway();
$section = new StorageSectionController($config, new class implements \Illuminate\Contracts\View\Factory {
    public function exists($view) { return false; }
    public function file($path, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
    public function make($view, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
    public function share($key, $value = null) { return null; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
}, $redirector, $translator, $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), $app);
$request = static function (string $actor, string $uri = '/admin/storage/workbench.people/settings', string $method = 'GET') use ($session): Request {
    $request = Request::create('http://larena.test'.$uri, $method);
    $request->attributes->set('larena_access_actor', $actor);
    $request->setLaravelSession($session);

    return $request;
};
$sitePackPanel = new ReflectionMethod(StorageSectionController::class, 'sitePackPanel');
$state = ['status' => 'ok', 'scope_ref' => 'scope:alpha'];
sitepack_assert($sitePackPanel->invoke($section, $request($admin), $state, 'workbench.people', ['sitepack.export' => false]) === null, 'Without the right the page has no panel.');
$shown = $sitePackPanel->invoke($section, $request($admin), $state, 'workbench.people', ['sitepack.export' => true]);
sitepack_assert($shown instanceof \Illuminate\Support\HtmlString, 'The panel is drawn for the view to print with {{ }}.');
$shown = (string) $shown;
sitepack_assert(str_contains($shown, 'data-larena-storage-sitepack') && str_contains($shown, '/admin/storage/workbench.people/sitepack/export'), 'With it the panel is drawn.');
// A queued export is still among the person's runs: the panel reads itself again from the settings page.
sitepack_assert(str_contains($shown, '<!--larena-live:storage-sitepack-export--><div class="larena-live-region" data-larena-live-region="storage-sitepack-export" data-larena-live="/admin/storage/workbench.people/settings"'),
    'While an export is queued or running the panel is live.');
$storage->denied = true;
sitepack_assert($sitePackPanel->invoke($section, $request($admin), $state, 'workbench.people', ['sitepack.export' => true]) === null, 'A refusal by Storage hides the panel.');
$storage->denied = false;
sitepack_assert($sitePackPanel->invoke($section, $request($admin), $state, 'workbench.people', ['sitepack.export' => true]) !== null
    && $sitePackPanel->invoke(new StorageSectionController($config, new class implements \Illuminate\Contracts\View\Factory {
        public function exists($view) { return false; }
        public function file($path, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
        public function make($view, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
        public function share($key, $value = null) { return null; }
        public function composer($views, $callback) { return []; }
        public function creator($views, $callback) { return []; }
        public function addNamespace($namespace, $hints) { return $this; }
        public function replaceNamespace($namespace, $hints) { return $this; }
    }, $redirector, $translator, $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), new Container()), $request($admin), $state, 'workbench.people', ['sitepack.export' => true]) === null,
    'Without SitePack installed there is no panel.');
$gatewaySource = (string) file_get_contents(__DIR__.'/../../src/Admin/StorageSectionGateway.php');
sitepack_assert(str_contains($gatewaySource, "'sitepack.export' => 'storage.sitepack.export'"), 'The section asks the export right like its other rights.');
$settingsView = (string) file_get_contents(__DIR__.'/../../resources/views/admin/settings.blade.php');
sitepack_assert(str_contains($settingsView, "{{ \$sitePackPanel ?? '' }}"), 'The settings page prints the panel drawn by the shared renderer.');

// ── The routes: start, cancel, retry and download, the person's own runs only. ──
$controller = new StorageSitePackController($config, $redirector, $translator, $gateway, $app);
$storage->queue = true;
$started = $controller->export($request($admin, '/admin/storage/workbench.people/sitepack/export', 'POST'), 'workbench.people');
sitepack_assert($started->getTargetUrl() === 'http://larena.test/admin/storage/workbench.people/settings'
    && $session->get('status') === 'Выгрузка началась. Когда она закончится, здесь появится «Скачать».', 'The export starts and the page says so.');
try {
    $controller->export($request($admin, '/admin/storage/workbench.secret/sitepack/export', 'POST'), 'workbench.secret');
    sitepack_assert(false, 'A storage the person cannot see is not found.');
} catch (NotFoundHttpException) {
}
$storage->refuseStart = 'storage_sitepack_field_change_running';
$controller->export($request($admin, '/admin/storage/workbench.people/sitepack/export', 'POST'), 'workbench.people');
$errors = $session->get('errors');
sitepack_assert($errors instanceof \Illuminate\Support\ViewErrorBag && $errors->first('storage') === 'Сейчас меняется поле — дождитесь окончания.', 'A refused start says why.');
$storage->refuseStart = null;
$runningRef = array_key_last($storage->runs);
$cancelled = $controller->cancel($request($admin, '/admin/storage/sitepack/'.$runningRef.'/cancel', 'POST'), $runningRef);
sitepack_assert($cancelled->getTargetUrl() === 'http://larena.test/admin/storage/workbench.people/settings' && $storage->runs[$runningRef]->state === 'cancelled'
    && $session->get('status') === 'Выгрузка отменена.', 'The person cancels their export.');
$controller->retry($request($admin, '/admin/storage/sitepack/'.$runningRef.'/retry', 'POST'), $runningRef);
sitepack_assert($storage->runs[$runningRef]->state === 'queued' && $session->get('status') === 'Выгрузка запущена снова.', 'And retries it.');
foreach (['cancel', 'retry'] as $action) {
    try {
        $controller->{$action}($request($other, '/admin/storage/sitepack/'.$runningRef.'/'.$action, 'POST'), $runningRef);
        sitepack_assert(false, 'Another person\'s run is not found for '.$action.'.');
    } catch (NotFoundHttpException) {
    }
}
$archive = tempnam(sys_get_temp_dir(), 'sitepack');
file_put_contents($archive, 'PK');
$storage->archivePath = $archive;
$download = $controller->download($request($admin, '/admin/storage/sitepack/'.$done->runRef.'/download'), $done->runRef);
sitepack_assert($download->getFile()->getPathname() === $archive
    && str_contains((string) $download->headers->get('Content-Disposition'), 'people-20261022.sitepack.zip')
    && $download->headers->get('Content-Type') === 'application/zip' && str_contains((string) $download->headers->get('Cache-Control'), 'no-store')
    && $storage->downloads === 1, 'The owner downloads the archive under its name.');
foreach ([[$other, $done->runRef], [$admin, $runningRef]] as [$actor, $ref]) {
    try {
        $controller->download($request($actor, '/admin/storage/sitepack/'.$ref.'/download'), $ref);
        sitepack_assert(false, 'Someone else\'s or an unfinished export is not found.');
    } catch (NotFoundHttpException) {
    }
}
sitepack_assert($storage->downloads === 1, 'A refused download is not counted.');
@unlink($archive);

$routes = (string) file_get_contents(__DIR__.'/../../routes/admin/storage-section.php');
foreach ([
    "Route::post('/sitepack/{run}/cancel', [StorageSitePackController::class, 'cancel'])->where('run', \$run)->name('sitepack.cancel')",
    "Route::post('/sitepack/{run}/retry', [StorageSitePackController::class, 'retry'])->where('run', \$run)->name('sitepack.retry')",
    "Route::get('/sitepack/{run}/download', [StorageSitePackController::class, 'download'])->where('run', \$run)->name('sitepack.download')",
    "Route::post('/{structure}/sitepack/export', [StorageSitePackController::class, 'export'])->where('structure', \$structure)->name('sitepack.export')",
    "\$run = 'storage-sitepack-[0-9a-f]{32}';",
] as $needle) {
    sitepack_assert(str_contains($routes, $needle), 'Route: '.$needle);
}
sitepack_assert(strpos($routes, "'/sitepack/{run}/cancel'") < strpos($routes, "Route::get('/{structure}', "), 'The run routes come before the storage routes.');
sitepack_assert(preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', 'sitepack') === 0, 'A storage ID never reads as «sitepack».');
sitepack_assert(preg_match('/Larena\\\\Storage\\\\(?!Admin\\\\)/', (string) file_get_contents(__DIR__.'/../../src/Admin/Http/Controllers/StorageSitePackController.php')) === 0,
    'The controller needs nothing of Storage but its own section.');

echo "StorageSitePackExportTest passed.\n";
