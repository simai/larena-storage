<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\Http\Controllers\StorageSectionController;
use Larena\Admin\Http\Middleware\AdminRegionMiddleware;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminLiveRegion;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Storage\Admin\Runtime\StorageLiveRegions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// The Storage section's parts that follow background work (the admin's AdminLiveRegion): which parts carry the live
// mark in which states, and that the records page answers its field change part alone, as the person.

function live_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('StorageLiveRegionsTest: ' . $message);
    }
}


// ── When each Storage part is live. ──
foreach (['queued' => true, 'waiting' => true, 'running' => true, 'retrying' => true, 'cancelling' => true, 'completed' => false, 'failed' => false, 'cancelled' => false, 'missing' => false] as $state => $expected) {
    live_assert(StorageLiveRegions::jobsActive([['state' => 'completed'], ['state' => $state]]) === $expected, 'Jobs panel, a job ' . $state);
}
live_assert(!StorageLiveRegions::jobsActive([]), 'No jobs, nothing asked.');
live_assert(StorageLiveRegions::jobsSaid([['title' => 'Экспорт', 'state' => 'Выполняется'], ['title' => 'Импорт', 'state' => 'Готово']]) === 'Экспорт: Выполняется; Импорт: Готово',
    'The jobs panel says each job\'s state.');
foreach (['queued' => true, 'running' => true, 'done' => false, 'failed' => false, 'cancelled' => false] as $state => $expected) {
    live_assert(StorageLiveRegions::sitePackExportActive([['state' => 'done'], ['state' => $state]]) === $expected, 'SitePack export, a run ' . $state);
}
live_assert(!StorageLiveRegions::sitePackExportActive(null) && !StorageLiveRegions::sitePackExportActive([]), 'No exports, nothing asked.');
live_assert(StorageLiveRegions::sitePackImportActive(['state' => 'queued', 'active' => true, 'planning' => true]), 'An archive being checked is live.');
live_assert(StorageLiveRegions::sitePackImportActive(['state' => 'running', 'active' => true, 'planning' => false]), 'An import running is live.');
foreach (['ready', 'done', 'failed', 'cancelled'] as $state) {
    live_assert(!StorageLiveRegions::sitePackImportActive(['state' => $state, 'active' => false, 'planning' => false]), 'A SitePack import ' . $state . ' is not live.');
}
foreach (['running' => true, 'encryption_running' => true, 'failed' => false, 'cancelled' => false, 'encryption_failed' => false] as $kind => $expected) {
    live_assert(StorageLiveRegions::fieldChangeActive(['kind' => $kind]) === $expected, 'Field change notice ' . $kind);
}
live_assert(!StorageLiveRegions::fieldChangeActive(null), 'No change, nothing asked.');

// ── The records page's field change notice alone: the person's own snapshot, without the list. ──
final class LiveRegionTexts implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;

    public function __construct()
    {
        $this->admin = storage_admin_words('ru');
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        $value = $this->admin;
        foreach (explode('.', substr((string) $key, strlen('larena-admin::admin.'))) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $asked;
            }
            $value = $value[$part];
        }
        if (!is_string($value)) {
            return $key;
        }
        foreach ($replace as $name => $text) {
            $value = str_replace(':' . $name, (string) $text, $value);
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

final class LiveRegionGateway implements StorageWorkbenchGateway
{
    /** @var array<string, mixed> */
    public array $state = [];
    /** @var list<array<string, mixed>> */
    public array $asked = [];

    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        $this->asked[] = ['actor' => $actor] + $query;

        return $this->state;
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        throw new LogicException('a part is only read');
    }
}

$gateway = new LiveRegionGateway();
$session = new Store('test', new ArraySessionHandler(10));
$session->start();
$redirector = new Redirector(new UrlGenerator(new RouteCollection(), Request::create('http://larena.test/admin')));
$redirector->setSession($session);
$config = new class implements \Illuminate\Contracts\Config\Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-admin.storage_section.uri' => 'admin/storage', 'larena-auth.admin_entry.access_actor_attribute' => 'larena_access_actor'];
    public function has($key) { return array_key_exists($key, $this->items); }
    public function get($key, $default = null) { return is_string($key) && array_key_exists($key, $this->items) ? $this->items[$key] : $default; }
    public function all() { return $this->items; }
    public function set($key, $value = null) { $this->items[(string) $key] = $value; }
    public function prepend($key, $value) {}
    public function push($key, $value) {}
};
$views = new class implements \Illuminate\Contracts\View\Factory {
    public function exists($view) { return false; }
    public function file($path, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
    public function make($view, $data = [], $mergeData = []) { throw new RuntimeException('the whole page is not drawn for a part'); }
    public function share($key, $value = null) { return null; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
};
$controller = new StorageSectionController($config, $views, $redirector, new LiveRegionTexts(), $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), new Container());
$part = static function (string $actor = 'user:admin') use ($session): Request {
    $request = Request::create('http://larena.test/admin/storage/workbench.people?record_id=r1&record_mode=view');
    $request->headers->set('X-Larena-Fragment', 'live:' . StorageLiveRegions::FIELD_CHANGE);
    $request->attributes->set('larena_access_actor', $actor);
    $request->setLaravelSession($session);

    return $request;
};
$storage = static fn (?array $change, array $extra = []): array => ['status' => 'ok', 'scope_ref' => 'scope:alpha',
    'permissions' => ['structure.migrate' => true, 'record.create' => true],
    'selected_structure' => ['structure_id' => 'workbench.people', 'label' => 'Люди', 'version' => 3,
        'fields' => [['key' => 'price', 'label' => 'Цена', 'type' => 'number']]] + ($change === null ? [] : ['field_change' => $change]) + $extra];

// Running: the notice with its progress, still live, from the records page; the list is never read for it.
$gateway->state = $storage(['state' => 'running', 'checked' => 120, 'total' => 1000, 'fields' => ['price']]);
$answer = $controller->records($part(), 'workbench.people');
live_assert($answer instanceof JsonResponse && $answer->getStatusCode() === 200, 'The running change answers its part.');
$html = $answer->getData(true)['html'];
live_assert(str_contains($html, 'data-larena-live="/admin/storage/workbench.people"') && str_contains($html, 'data-larena-storage-field-change="running"')
    && str_contains($html, '<progress value="120" max="1000"') && str_contains($html, 'data-larena-live-said="Меняем поле «Цена»'), 'Running, the part is live: ' . $html);
live_assert(($gateway->asked[0]['records'] ?? null) === 'skip' && ($gateway->asked[0]['actor'] ?? null) === 'user:admin', 'The part reads the storage alone, as the person.');

// The encryption run speaks the same way while it goes on.
$gateway->state = $storage(null, ['encryption_run' => ['mode' => 'encrypt', 'state' => 'running', 'fields' => ['price'], 'rewritten' => 5, 'skipped' => 0, 'total' => 50]]);
$html = $controller->records($part(), 'workbench.people')->getData(true)['html'];
live_assert(str_contains($html, 'data-larena-storage-field-change="encryption_running"') && str_contains($html, 'data-larena-live="'), 'A running encryption run is live.');

// Failed or cancelled: the notice stays, the asking stops.
foreach (['failed', 'cancelled'] as $state) {
    $gateway->state = $storage(['state' => $state, 'checked' => 0, 'total' => 0, 'fields' => ['price'], 'reason' => null]);
    $html = $controller->records($part(), 'workbench.people')->getData(true)['html'];
    live_assert(str_contains($html, 'data-larena-storage-field-change="' . $state . '"') && !str_contains($html, 'data-larena-live="'), 'A ' . $state . ' change is not live.');
}
$gateway->state = $storage(null, ['encryption_run' => ['mode' => 'encrypt', 'state' => 'failed', 'fields' => ['price'], 'reason' => 'storage_field_encryption_interrupted']]);
live_assert(!str_contains($controller->records($part(), 'workbench.people')->getData(true)['html'], 'data-larena-live="'), 'A stopped encryption run is not live.');

// Ended well: the holder is empty (the notice clears in place) and says the work has finished.
$gateway->state = $storage(null);
$html = $controller->records($part(), 'workbench.people')->getData(true)['html'];
live_assert($html === '<div class="larena-live-region" data-larena-live-region="storage-field-change" data-larena-live-said="Фоновая работа завершена."></div>', 'Nothing left to show: ' . $html);

// The person's rights: a refused read is refused, a storage that is not there answers 404.
$gateway->state = ['status' => 'denied', 'reason_code' => 'access_denied'];
try {
    $controller->records($part('user:reader'), 'workbench.people');
    live_assert(false, 'A person who may not read the storage gets no part.');
} catch (AccessDeniedHttpException) {
}
$gateway->state = $storage(['state' => 'running', 'checked' => 1, 'total' => 2, 'fields' => ['price']]);
$gone = $controller->records($part(), 'workbench.other');
live_assert($gone instanceof JsonResponse && $gone->getStatusCode() === 404, 'Another storage is not found.');
$gateway->state = ['status' => 'unavailable', 'reason_code' => 'storage_section_gateway_failed'];
live_assert($controller->records($part(), 'workbench.people')->getStatusCode() === 404, 'An unavailable storage stops the asking.');

// ── Every surface lays out the holder drawn in PHP. ──
$read = static fn (string $path): string => (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
live_assert(str_contains($read('resources/views/admin/_field_change.blade.php'), "{{ \$fieldChangeRegion ?? '' }}"), 'The records and settings pages lay out the field change holder.');
live_assert(str_contains($read('resources/views/admin/jobs.blade.php'), '{{ $content }}')
    && str_contains($read('src/Admin/Http/Controllers/StorageJobsController.php'), '$parts[] = new AdminLiveRegionPart(StorageLiveRegions::JOBS,'), 'The jobs page lays out the jobs holder.');
$section = $read('src/Admin/Http/Controllers/StorageSectionController.php');
live_assert(str_contains($section, 'new AdminLiveRegionPart(StorageLiveRegions::SITEPACK_EXPORT') && str_contains($section, 'StorageLiveRegions::sitePackExportActive($runs)'),
    'The settings page\'s SitePack panel is live while an export runs.');
live_assert(substr_count($section, '$this->fieldChangeRegion(') === 3, 'The records page, its part answer and the settings page draw the same holder.');
live_assert(str_contains($read('src/Admin/Http/Controllers/StorageJobsController.php'), 'StorageLiveRegions::jobsActive($listed)'), 'The jobs panel is live while a job runs.');
live_assert(str_contains($read('src/Admin/Http/Controllers/StorageSitePackImportController.php'), 'StorageLiveRegions::sitePackImportActive($found)'), 'A SitePack import run is live while it runs.');

echo "StorageLiveRegionsTest passed.\n";
