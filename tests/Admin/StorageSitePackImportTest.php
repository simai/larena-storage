<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\Http\Controllers\StorageSectionController;
use Larena\Storage\Admin\Http\Controllers\StorageSitePackImportController;
use Larena\Storage\Admin\FilesystemStorageSitePackFileImports;
use Larena\Storage\Admin\StorageSitePackImportNotes;
use Larena\Storage\Admin\StorageSitePackImports;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Storage\Admin\Runtime\StorageSitePackImportPage;
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
use Larena\Storage\Contracts\StorageSitePackPlan;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SitePack\StorageSitePackFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// «Загрузить хранилище» (larena-specs docs/architecture/2026-10-22-storage-sitepack.md): the file port an import writes
// through (reuse, create, remap, idempotent, nothing left on a failure), the upload and its checks, the plan in plain
// words for every code, the person's choices planned again, «Загрузить» bound to the plan's digest, the action hidden
// from people without the rights, and the run pages that reach only the person's own runs.

function import_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('StorageSitePackImportTest failed: '.$message);
    }
}

const IMPORT_FILE = '0b5c8a52-6c43-4a8e-9e0a-0d0b6c9a1f11';
const IMPORT_PUBLIC = '4f9a0c96-0a87-4ec2-9c4e-4b4f0a3e5d55';
const IMPORT_FOREIGN = '1c6d9b63-7d54-4b9f-8f1b-1e1c7d0b2a22';
const IMPORT_NEW = '5a0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e66';
const IMPORT_NEW_PUBLIC = '6b1c2eb8-2ca9-4ae4-9e6a-6d6b2c5a7f77';

final class SitePackImportFakeBindings implements ScopeResourceBindings
{
    /** @var list<string> */
    public array $bound = [];

    /** @param array<string, string> $owners logical ref => scope ref */
    public function __construct(public array $owners)
    {
    }

    public function bind(string $resourceType, string $resourceRef, ScopeRef $scope): void
    {
        $owner = $this->owners[$resourceRef] ?? null;
        if ($owner !== null && $owner !== $scope->toString()) {
            throw new RuntimeException('bound_elsewhere');
        }
        $this->owners[$resourceRef] = $scope->toString();
        $this->bound[] = $resourceType.' '.$resourceRef.' '.$scope->toString();
    }

    public function scopeOf(string $resourceType, string $resourceRef): ?ScopeRecord
    {
        return null;
    }

    public function belongsTo(string $resourceType, string $resourceRef, ScopeRef $scope): bool
    {
        return $resourceType === 'filesystem.logical_file' && ($this->owners[$resourceRef] ?? null) === $scope->toString();
    }

    public function listRefs(ScopeRef $scope, string $resourceType, ?string $cursor = null, int $limit = 50): array
    {
        return ['items' => [], 'next_cursor' => null];
    }
}

final class SitePackImportFakeFiles implements PortableLogicalFileStore
{
    /** @var array<string, array{descriptor: PortableLogicalFileDescriptor, content: string}> */
    public array $files = [];
    /** @var list<string> */
    public array $imported = [];
    public int $compensated = 0;

    public function snapshot(string $logicalRef): PortableLogicalFileSnapshot
    {
        $file = $this->files[$logicalRef] ?? throw new PortableLogicalFileFailed('filesystem_portable_file_not_found');
        $content = $file['content'];

        return new PortableLogicalFileSnapshot($file['descriptor'], static function () use ($content): mixed {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $content);
            rewind($stream);

            return $stream;
        });
    }

    public function import(PortableLogicalFileDescriptor $descriptor, mixed $stream): PortableLogicalFileImportResult
    {
        $this->imported[] = $descriptor->logicalRef.' '.$descriptor->publicId;
        $this->files[$descriptor->logicalRef] = ['descriptor' => $descriptor, 'content' => (string) stream_get_contents($stream)];

        return new PortableLogicalFileImportResult($descriptor->logicalRef, 'created', function () use ($descriptor): void {
            $this->compensated++;
            unset($this->files[$descriptor->logicalRef]);
        });
    }

    public function contentMatches(string $sha256, int $sizeBytes, string $extension, ?callable $belongs = null): array
    {
        $refs = [];
        foreach ($this->files as $ref => $file) {
            if ($file['descriptor']->sha256 === $sha256 && $file['descriptor']->sizeBytes === $sizeBytes && $file['descriptor']->extension === $extension
                && ($belongs === null || $belongs($ref) === true)) {
                $refs[] = (string) $ref;
            }
        }
        sort($refs);

        return $refs;
    }

    public function logicalRefTaken(string $logicalRef): bool
    {
        return isset($this->files[$logicalRef]);
    }

    public function publicIdTaken(string $publicId): bool
    {
        foreach ($this->files as $file) {
            if ($file['descriptor']->publicId === $publicId) {
                return true;
            }
        }

        return false;
    }
}

/** Storage's SitePack import, in memory: runs of the person who started them, a plan per options, a digest. */
final class SitePackImportFakeStorage implements StorageSitePack
{
    /** @var array<string, StorageSitePackRun> */
    public array $runs = [];
    public bool $queue = false;
    public ?string $refuseReceive = null;
    public bool $denied = false;
    /** @var list<array{code: string, count: int, subject: ?string}> */
    public array $errors = [];
    /** @var list<array{code: string, count: int, subject: ?string}> */
    public array $warnings = [];
    /** @var array<string, string>|null */
    public ?array $replay = null;
    public ?StorageSitePackImportOptions $planned = null;
    /** @var list<string> */
    public array $received = [];
    public int $applied = 0;

    public function startExport(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_export_denied');
    }

    public function archive(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackArchiveFile
    {
        throw new StorageRejected('storage_sitepack_run_not_done');
    }

    public function run(string $runRef, string $actor): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if ($run->phase === 'plan') {
            return $this->runs[$runRef] = self::make($runRef, $actor, 'ready', 'planned', $this->planFor($run), 0, 12);
        }

        return $this->runs[$runRef] = self::make($runRef, $actor, 'done', 'done', null, 12, 12, ['records' => 12, 'files_created' => 2, 'files_reused' => 1], 'workbench.people_2');
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
            throw new StorageRejected('storage_sitepack_import_denied');
        }

        return array_values(array_reverse(array_filter($this->runs, static fn (StorageSitePackRun $run): bool => $run->actor === $actor)));
    }

    public function cancel(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if (in_array($run->state, ['done', 'cancelled'], true)) {
            throw new StorageRejected('storage_sitepack_run_finished');
        }

        return $this->runs[$runRef] = self::make($runRef, $actor, 'cancelled', $run->phase, null, $run->done, $run->total, ['files_created' => 1]);
    }

    public function retry(string $runRef, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if (!in_array($run->state, ['failed', 'cancelled'], true)) {
            throw new StorageRejected('storage_sitepack_run_not_retryable');
        }

        return $this->runs[$runRef] = self::make($runRef, $actor, 'queued', 'plan', null, 0, 12, [], null, $this->queue ? 'job' : null);
    }

    public function receive(string $scopeRef, string $archivePath, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        if ($this->denied) {
            throw new StorageRejected('storage_sitepack_import_denied');
        }
        if ($this->refuseReceive !== null) {
            throw new StorageRejected($this->refuseReceive);
        }
        $this->received[] = $scopeRef.' '.(string) file_get_contents($archivePath, false, null, 0, 2);
        $ref = 'storage-sitepack-'.str_pad(dechex(count($this->runs) + 1), 32, '0', STR_PAD_LEFT);

        return $this->runs[$ref] = self::make($ref, $actor, 'ready', 'received', null, 0, 12);
    }

    public function plan(string $runRef, StorageSitePackImportOptions $options, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if ($run->state !== 'ready') {
            throw new StorageRejected('storage_sitepack_run_not_plannable');
        }
        $this->planned = $options;

        return $this->runs[$runRef] = self::make($runRef, $actor, 'queued', 'plan', null, 0, 12, [], null, $this->queue ? 'job' : null);
    }

    public function apply(string $runRef, string $planDigest, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        $run = $this->read($runRef, $actor);
        if ($run->state !== 'ready' || $run->plan === null || $run->planDigest === null) {
            throw new StorageRejected('storage_sitepack_run_not_planned');
        }
        if (!hash_equals($run->planDigest, $planDigest)) {
            throw new StorageRejected('storage_sitepack_plan_digest_mismatch');
        }
        if (!$run->plan->ok()) {
            throw new StorageRejected('storage_sitepack_plan_has_errors');
        }
        $this->applied++;

        return $this->runs[$runRef] = self::make($runRef, $actor, 'queued', 'structure', null, 0, 12, [], null, $this->queue ? 'job' : null);
    }

    private function planFor(StorageSitePackRun $run): StorageSitePackPlan
    {
        $options = $this->planned ?? new StorageSitePackImportOptions();
        $target = $options->targetStructureId ?? 'workbench.people_2';

        return new StorageSitePackPlan(
            ['source_id' => 'workbench.people', 'target_id' => $target, 'kept' => $target === 'workbench.people', 'label' => $options->targetLabel ?? 'Люди',
                'history_keep' => null, 'fields' => 4],
            ['total' => 12, 'keep' => 10, 'new' => 2, 'archived' => 1, 'versions' => 12],
            ['total' => 3, 'reuse' => 1, 'create' => 1, 'remap' => 1, 'missing' => 1, 'bytes' => 2048],
            ['en', 'ru'],
            ['total' => 5, 'resolved' => 3, 'unresolved' => 2],
            [['role_ref' => 'role.editors', 'action' => 'bind'], ['role_ref' => 'role.ghosts', 'action' => 'skip']],
            ['mode' => $options->publication, 'published' => 7, 'scheduled' => 1],
            ['total' => 4, 'missing' => 1],
            $this->replay,
            $this->warnings,
            $this->errors,
            $options->toArray(),
            ['rows' => 15, 'sha256' => str_repeat('c', 64)],
        );
    }

    /** @param array<string, int> $counts */
    public static function make(string $ref, string $actor, string $state, string $phase, ?StorageSitePackPlan $plan, int $done, int $total,
        array $counts = [], ?string $structure = null, ?string $jobId = null, ?string $reason = null): StorageSitePackRun
    {
        return new StorageSitePackRun($ref, 'import', 'scope:alpha', 'site:main', $structure ?? 'workbench.people', $actor, $state, $phase, $done, $total,
            $counts, 'package-1', str_repeat('a', 64), 4096, $jobId, $reason, null, '2026-10-22T09:00:00+00:00', '2026-10-22T09:00:00+00:00', null,
            $plan === null ? null : 'sha256:'.hash('sha256', json_encode($plan->toArray(), JSON_THROW_ON_ERROR)), $plan);
    }
}

final class SitePackImportTexts implements \Illuminate\Contracts\Translation\Translator
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
        return $this->get($key, $replace, $locale);
    }

    public function getLocale()
    {
        return 'ru';
    }

    public function setLocale($locale)
    {
    }
}

final class SitePackImportConfig implements \Illuminate\Contracts\Config\Repository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public function has($key)
    {
        return array_key_exists((string) $key, $this->items);
    }

    public function get($key, $default = null)
    {
        return $this->items[(string) $key] ?? $default;
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

final class SitePackImportFakeGateway implements StorageWorkbenchGateway
{
    /** @var array<string, bool> */
    public array $permissions = ['structure.create' => true, 'record.create' => true, 'sitepack.import' => true, 'publication.publish' => true];

    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        return ['status' => 'ok', 'scope_ref' => 'scope:alpha', 'permissions' => $this->permissions, 'structures' => [
            ['structure_id' => 'workbench.people', 'label' => 'Люди'],
            ['structure_id' => 'workbench.cities', 'label' => 'Города'],
            ['structure_id' => 'workbench.old', 'label' => 'Старое', 'archived' => true],
        ]];
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        throw new RuntimeException('not_used');
    }
}

final class SitePackImportView implements \Illuminate\Contracts\View\View
{
    /** @param array<string, mixed> $data */
    public function __construct(private string $view, private array $data)
    {
    }

    public function name()
    {
        return $this->view;
    }

    public function with($key, $value = null)
    {
        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public function render()
    {
        return (string) ($this->data['html'] ?? '');
    }
}

final class SitePackImportViews implements \Illuminate\Contracts\View\Factory
{
    public function exists($view) { return true; }
    public function file($path, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
    public function make($view, $data = [], $mergeData = []) { return new SitePackImportView((string) $view, (array) $data); }
    public function share($key, $value = null) { return null; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
}

$descriptor = static fn (string $ref, string $public, string $content): array => [
    'logical_ref' => $ref, 'public_id' => $public, 'display_name' => 'Фото', 'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
    'extension' => 'jpg', 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'visibility' => 'public', 'alt_text' => null,
];
$packFile = static fn (array $descriptor, string $content): StorageSitePackFile => new StorageSitePackFile($descriptor, static function () use ($content): mixed {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
});
$toDescriptor = static fn (array $d): PortableLogicalFileDescriptor => new PortableLogicalFileDescriptor($d['logical_ref'], $d['public_id'], $d['display_name'],
    $d['original_name'], $d['mime_type'], $d['extension'], $d['size_bytes'], $d['sha256'], $d['visibility'], $d['alt_text']);

// ── The file port of an import: Filesystem's store, the site's bindings, one transaction per file. ──
$files = new SitePackImportFakeFiles();
$files->files[IMPORT_FILE] = ['descriptor' => $toDescriptor($descriptor(IMPORT_FILE, IMPORT_PUBLIC, 'photo')), 'content' => 'photo'];
$files->files[IMPORT_FOREIGN] = ['descriptor' => $toDescriptor($descriptor(IMPORT_FOREIGN, IMPORT_NEW_PUBLIC, 'photo')), 'content' => 'photo'];
$bindings = new SitePackImportFakeBindings([IMPORT_FILE => 'site:main', IMPORT_FOREIGN => 'site:other']);
$resolver = new ConnectionResolver(['default' => new SQLiteConnection(new PDO('sqlite::memory:'))]);
$resolver->setDefaultConnection('default');
$port = new FilesystemStorageSitePackFileImports($files, $bindings, $resolver);
import_assert($port->importAvailable(), 'Files can be imported.');
// Reuse: the same file in the site, or another file of the site with the same content.
import_assert($port->belongsToSite('site:main', IMPORT_FILE) && !$port->belongsToSite('site:main', IMPORT_FOREIGN)
    && !$port->belongsToSite('site:other', IMPORT_FILE) && !$port->belongsToSite('not a scope', IMPORT_FILE), 'A file belongs to its own site only.');
$bindings->owners[IMPORT_NEW] = 'site:main';
import_assert(!$port->belongsToSite('site:main', IMPORT_NEW), 'A file the bindings name but Filesystem no longer holds is not the site\'s.');
unset($bindings->owners[IMPORT_NEW]);
import_assert($port->contentMatches('site:main', hash('sha256', 'photo'), 5, 'jpg') === [IMPORT_FILE]
    && $port->contentMatches('site:other', hash('sha256', 'photo'), 5, 'jpg') === [IMPORT_FOREIGN]
    && $port->contentMatches('bad', hash('sha256', 'photo'), 5, 'jpg') === [], 'Content matches count the site\'s files only.');
import_assert($port->logicalRefTaken(IMPORT_FOREIGN) && !$port->logicalRefTaken(IMPORT_NEW)
    && $port->publicIdTaken(IMPORT_PUBLIC) && !$port->publicIdTaken(IMPORT_NEW), 'Identity questions are Filesystem\'s.');
// Create: under the pack's identity, bound to the site.
$createdRef = 'aa0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e01';
$created = $port->import('site:main', $packFile($descriptor($createdRef, 'bb0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e02', 'fresh'), 'fresh'), );
import_assert($created === $createdRef && $files->files[$createdRef]['content'] === 'fresh'
    && in_array('filesystem.logical_file '.$createdRef.' site:main', $bindings->bound, true), 'A new file is created and bound to the site.');
// Remap: the planner already put the new identity into the descriptor; the file is created under it.
$remapped = $port->import('site:main', $packFile($descriptor(IMPORT_NEW, IMPORT_NEW_PUBLIC, 'other'), 'other'));
import_assert($remapped === IMPORT_NEW && end($files->imported) === IMPORT_NEW.' '.IMPORT_NEW_PUBLIC
    && $bindings->owners[IMPORT_NEW] === 'site:main', 'A remapped file is created under its new identity.');
// Idempotent: the same ref with the same content already in the site is not written again.
$before = count($files->imported);
import_assert($port->import('site:main', $packFile($descriptor($createdRef, 'bb0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e02', 'fresh'), 'fresh')) === $createdRef
    && count($files->imported) === $before, 'A resumed import finds its file and writes nothing.');
try {
    $port->import('site:main', $packFile($descriptor($createdRef, 'bb0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e02', 'changed'), 'changed'));
    import_assert(false, 'Another content under a ref of the site is refused.');
} catch (StorageRejected $exception) {
    import_assert($exception->reasonCode === 'storage_sitepack_file_import_mismatch', 'It is refused as a mismatch.');
}
// A failure leaves nothing: the binding fails, the stored blob is removed again and the failure is thrown.
$clash = 'cc0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e03';
$bindings->owners[$clash] = 'site:other';
unset($files->files[$clash]);
try {
    $port->import('site:main', $packFile($descriptor($clash, 'dd0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e04', 'clash'), 'clash'));
    import_assert(false, 'A file bound to another site cannot be imported.');
} catch (RuntimeException $exception) {
    import_assert($exception->getMessage() === 'bound_elsewhere' && $files->compensated === 1 && !isset($files->files[$clash]), 'What it stored is removed again.');
}
try {
    $port->import('not a scope', $packFile($descriptor($clash, 'dd0b1da7-1b98-4fd3-8d5f-5c5a1b4f6e04', 'clash'), 'clash'));
    import_assert(false, 'A malformed site is refused.');
} catch (StorageRejected $exception) {
    import_assert($exception->reasonCode === 'storage_sitepack_site_invalid', 'As an invalid site.');
}
$provider = (string) file_get_contents(__DIR__.'/../../src/Admin/StorageSectionBindings.php');
import_assert(str_contains($provider, '$app->extend(\\Larena\\Storage\\SitePack\\StorageSitePackFileImports::class')
    && str_contains($provider, 'new FilesystemStorageSitePackFileImports('), 'The provider extends the import port lazily over the default.');
$container = new Container();
$container->bind(\Larena\Storage\SitePack\StorageSitePackFileImports::class, \Larena\Storage\SitePack\UnavailableStorageSitePackFiles::class);
$container->extend(\Larena\Storage\SitePack\StorageSitePackFileImports::class, static fn (\Larena\Storage\SitePack\StorageSitePackFileImports $default, \Illuminate\Contracts\Container\Container $app): \Larena\Storage\SitePack\StorageSitePackFileImports
    => $default instanceof \Larena\Storage\SitePack\UnavailableStorageSitePackFiles && $app->bound(PortableLogicalFileStore::class) && $app->bound(ScopeResourceBindings::class)
        ? new FilesystemStorageSitePackFileImports($app->make(PortableLogicalFileStore::class), $app->make(ScopeResourceBindings::class), $resolver) : $default);
import_assert($container->make(\Larena\Storage\SitePack\StorageSitePackFileImports::class) instanceof \Larena\Storage\SitePack\UnavailableStorageSitePackFiles, 'Without Filesystem the default stays.');
$container->instance(PortableLogicalFileStore::class, $files);
$container->instance(ScopeResourceBindings::class, $bindings);
import_assert($container->make(\Larena\Storage\SitePack\StorageSitePackFileImports::class) instanceof FilesystemStorageSitePackFileImports, 'With Filesystem and core files are imported.');

// ── The texts: Russian and English carry the same keys; every plan code has its sentence. ──
$ru = storage_admin_words('ru');
$en = storage_admin_words('en');
$keys = static function (array $copy, string $prefix = '') use (&$keys): array {
    $all = [];
    foreach ($copy as $key => $value) {
        $all = array_merge($all, is_array($value) ? $keys($value, $prefix.$key.'.') : [$prefix.$key]);
    }

    return $all;
};
import_assert($keys($ru['larena_storage']['section']['sitepack_import']) === $keys($en['larena_storage']['section']['sitepack_import']), 'Russian and English carry the same texts.');
$errorCodes = ['locale_not_declared', 'already_imported', 'structure_id_taken', 'structure_invalid', 'record_invalid', 'record_duplicate',
    'reference_required_unresolved', 'user_required_missing', 'file_required_missing', 'files_unavailable', 'localized_unavailable',
    'publication_unavailable', 'publication_denied'];
$warningCodes = ['reference_cleared', 'relation_dropped', 'user_missing_cleared', 'user_missing_set_to_importer', 'file_missing_cleared',
    'role_unknown', 'role_binding_skipped'];
$contract = (string) file_get_contents(__DIR__.'/../../src/Contracts/StorageSitePack.php');
foreach ([...$errorCodes, ...$warningCodes] as $code) {
    import_assert(str_contains($contract, $code), 'The code '.$code.' is Storage\'s.');
}
foreach (['errors' => $errorCodes, 'warnings' => $warningCodes] as $kind => $codes) {
    foreach ($codes as $code) {
        import_assert(is_string($ru['larena_storage']['section']['sitepack_import'][$kind][$code] ?? null), 'A sentence for '.$kind.' '.$code.'.');
    }
}

// ── The run pages over a fake Storage. ──
$translator = new SitePackImportTexts($ru);
$page = new StorageSitePackImportPage($translator);
// The page describes its parts; the shared renderer draws them (developer remark 3', stage 5).
$drawRun = static fn (array $run, array $context): string => (string) (new \Larena\Admin\Runtime\AdminElementDocumentRenderer())->tree($page->run($run, $context));
$admin = 'user:admin_identity:1';
$other = 'user:admin_identity:2';
$notesRoot = sys_get_temp_dir().'/larena-sitepack-notes-'.bin2hex(random_bytes(4));
$notes = new StorageSitePackImportNotes($notesRoot);
$storage = new SitePackImportFakeStorage();
$imports = new StorageSitePackImports($storage, $notes);
$config = new SitePackImportConfig(['larena-admin.storage_section.uri' => 'admin/storage', 'larena-auth.admin_entry.access_actor_attribute' => 'larena_access_actor',
    'larena-admin.storage_section.sitepack_import_max_bytes' => 1_048_576]);
$session = new Store('test', new ArraySessionHandler(10));
$session->start();
$redirector = new Redirector(new UrlGenerator(new RouteCollection(), Request::create('http://larena.test/admin')));
$redirector->setSession($session);
$app = new Container();
$app->instance(StorageSitePackImports::class, $imports);
$gateway = new SitePackImportFakeGateway();
$controller = new StorageSitePackImportController($config, new SitePackImportViews(), $redirector, $translator, $gateway, $app);
$request = static function (string $actor, string $uri, string $method = 'GET', array $input = [], array $files = []) use ($session): Request {
    $request = Request::create('http://larena.test'.$uri, $method, $input, [], $files);
    $request->attributes->set('larena_access_actor', $actor);
    $request->setLaravelSession($session);

    return $request;
};
$flash = static function () use ($session): array {
    $errors = $session->get('errors');
    $result = ['status' => $session->get('status'), 'error' => $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors->first('storage') : ''];
    $session->forget(['status', 'errors']);

    return $result;
};

// The upload page: the size the site accepts, a .zip file, the person's imports.
$form = $controller->form($request($admin, '/admin/storage/sitepack/import'));
$formHtml = str_replace("\u{00A0}", ' ', $form->render());
foreach (['Архив переноса', 'enctype="multipart/form-data" action="/admin/storage/sitepack/import"', 'accept=".zip,application/zip"', 'Файл .zip размером до ',
    'Загрузить архив', 'name="_token"'] as $needle) {
    import_assert(str_contains($formHtml, $needle), 'The upload shows: '.$needle);
}

// Upload validation: no file, not a ZIP (by name or by content), too large; then a good one is received and planned.
$upload = static function (string $name, string $content) use ($request, $admin): Request {
    $path = tempnam(sys_get_temp_dir(), 'sitepack-upload');
    file_put_contents($path, $content);

    return $request($admin, '/admin/storage/sitepack/import', 'POST', [], ['archive' => new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true)]);
};
$controller->upload($request($admin, '/admin/storage/sitepack/import', 'POST'));
import_assert($flash()['error'] === 'Выберите файл архива.', 'Without a file the page asks for one.');
$controller->upload($upload('people.txt', "PK\x03\x04rest"));
import_assert($flash()['error'] === 'Это не архив переноса: нужен файл .zip, выгруженный кнопкой «Выгрузить хранилище».', 'A file not named .zip is refused.');
$controller->upload($upload('people.zip', 'name,city'));
import_assert(str_starts_with($flash()['error'], 'Это не архив переноса'), 'A .zip that is not a ZIP is refused.');
$controller->upload($upload('people.zip', "PK\x03\x04".str_repeat('x', 1_048_577)));
import_assert($flash()['error'] === 'Файл больше, чем принимает сайт.', 'A file over the limit is refused.');
import_assert($storage->received === [], 'Nothing refused reaches Storage.');
$storage->refuseReceive = 'storage_sitepack_format_unsupported';
$controller->upload($upload('people.zip', "PK\x03\x04data"));
import_assert($flash()['error'] === 'Это не архив хранилища или он сделан несовместимой версией.', 'Storage\'s refusal is said in plain words.');
$storage->refuseReceive = 'sitepack_archive_entry_symlink';
$controller->upload($upload('people.zip', "PK\x03\x04data"));
import_assert($flash()['error'] === 'Архив повреждён или не является архивом переноса хранилища.', 'Any broken archive is said as one.');
$storage->refuseReceive = null;
$redirect = $controller->upload($upload('people-20261022.sitepack.zip', "PK\x03\x04data"));
$runRef = array_key_last($storage->runs);
import_assert($storage->received === ['scope:alpha PK'] && $redirect->getTargetUrl() === 'http://larena.test/admin/storage/sitepack/import/'.$runRef
    && $flash()['status'] === 'Архив проверен. Посмотрите, что будет загружено.', 'A good archive is received into the person\'s scope and planned at once.');
import_assert($storage->planned instanceof StorageSitePackImportOptions && $storage->planned->toArray() === (new StorageSitePackImportOptions())->toArray(),
    'The first plan uses the defaults: the pack\'s id and label, drafts.');
import_assert(($notes->read($admin, $runRef)['name'] ?? null) === 'people-20261022.sitepack.zip' && $notes->read($other, $runRef) === null, 'The page keeps the file\'s name for the person.');

// The plan, with every error and warning code in plain words, the replay notice and the choices.
$storage->errors = array_map(static fn (string $code): array => ['code' => $code, 'count' => 3, 'subject' => match ($code) {
    'locale_not_declared' => 'de', 'already_imported' => 'workbench.people', 'structure_id_taken' => 'workbench.people_2', 'record_invalid' => 'storage_sitepack_field_not_localized',
    default => null,
}], $errorCodes);
$storage->warnings = array_map(static fn (string $code): array => ['code' => $code, 'count' => 2, 'subject' => str_starts_with($code, 'role_') ? 'role.ghosts' : null], $warningCodes);
$storage->replay = ['run_ref' => 'storage-sitepack-'.str_repeat('9', 32), 'structure_id' => 'workbench.people', 'imported_at' => '2026-10-20 10:00:00'];
$notes->keep($admin, $runRef, ['name' => 'people-20261022.sitepack.zip', 'source_site' => 'site:main', 'source_label' => 'Люди', 'external' => ['workbench.cities', 'workbench.tags']]);
$controller->plan($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/plan', 'POST', ['publication' => 'draft']), $runRef);
$flash();
$show = $controller->show($request($admin, '/admin/storage/sitepack/import/'.$runRef), $runRef);
$html = str_replace("\u{00A0}", ' ', $show->render());
foreach ($errorCodes as $code) {
    import_assert(str_contains($html, 'data-larena-sitepack-import-error="'.$code.'"'), 'The error '.$code.' is listed.');
    import_assert(str_contains($html, htmlspecialchars($page->issue('errors', $code, 3, match ($code) {
        'locale_not_declared' => 'de', 'already_imported' => 'workbench.people', 'structure_id_taken' => 'workbench.people_2', default => null,
    }), ENT_QUOTES)), 'The error '.$code.' is said in plain words.');
}
foreach ($warningCodes as $code) {
    import_assert(str_contains($html, 'data-larena-sitepack-import-warning="'.$code.'"')
        && str_contains($html, htmlspecialchars($page->issue('warnings', $code, 2, 'role.ghosts'), ENT_QUOTES)), 'The warning '.$code.' is said in plain words.');
}
import_assert(!str_contains($html, 'storage_sitepack_field_not_localized'), 'A subject that is a code is never shown.');
foreach ([
    'Язык de не включён на этом сайте.', 'ID workbench.people_2 уже занят. Укажите другой.', 'Записей с неверными значениями: 3.',
    'Роли role.ghosts нет на этом сайте', 'Этот архив уже загружен как workbench.people (20.10.2026 10:00) — можно загрузить только копией.',
    'Загрузить копией', 'Источник: файл «people-20261022.sitepack.zip», 4,0 КБ · хранилище Люди (workbench.people) · сайт site:main',
    '«Люди», ID workbench.people_2 (в архиве — workbench.people, такой ID уже занят)', 'Всего 12: с прежним ID 10, с новым ID 2, из них в архиве 1',
    'en, ru — нет на этом сайте: de', 'Всего 3: уже есть на сайте 1, будут созданы 1, созданы под новым ID 1; размер 2,0 КБ', 'Отсутствуют в архиве: 1.',
    'Всего 5: найдены на этом сайте 3, не найдены 2', 'role.editors — будет назначена', 'role.ghosts — не будет назначена',
    'Все записи придут черновиками (в архиве опубликовано: 7, запланировано: 1)', 'Упоминаний 4, из них нет на этом сайте: 1',
    'name="target_label"', 'name="target_structure_id"', 'Как черновики (рекомендуется)', 'Сохранить публикацию',
    'name="reference_from_0" value="workbench.cities"', 'name="reference_from_1" value="workbench.tags"', 'Хранилище с тем же ID', 'Города (workbench.cities)',
    'Проверить снова', 'Исправьте ошибки и проверьте архив снова.', 'native-type="submit" disabled',
] as $needle) {
    import_assert(str_contains($html, htmlspecialchars($needle, ENT_QUOTES)) || str_contains($html, $needle), 'The plan shows: '.$needle);
}
import_assert(!str_contains($html, 'Старое (workbench.old)'), 'An archived storage is not offered for references.');
import_assert(!preg_match('/<form method="post" action="[^"]+\/apply"[^>]*>(?:(?!<\/form>).)*sf-button[^>]*native-type="submit"(?! disabled)/s', $html),
    '«Загрузить» cannot be pressed while the plan has errors.');
import_assert(substr_count($html, 'method="post"') === substr_count($html, 'name="_token"'), 'Every action is a CSRF form.');
$controller->apply($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/apply', 'POST', ['digest' => (string) $storage->runs[$runRef]->planDigest]), $runRef);
import_assert($flash()['error'] === 'Загрузить нельзя, пока в плане есть ошибки.' && $storage->applied === 0, 'A plan with errors is not applied.');

// Without the publish right the publication is drafts only.
$gateway->permissions['publication.publish'] = false;
$draftOnly = $controller->show($request($admin, '/admin/storage/sitepack/import/'.$runRef), $runRef)->render();
import_assert(!str_contains($draftOnly, 'Сохранить публикацию') && str_contains($draftOnly, 'Записи придут черновиками.')
    && str_contains($draftOnly, 'name="publication" value="draft"'), 'Without the publish right there is no «Сохранить публикацию».');
$gateway->permissions['publication.publish'] = true;

// The choices go to Storage and come back on the page: target id and name, reference map, publication, copy.
$storage->errors = [];
$storage->warnings = [];
$controller->plan($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/plan', 'POST', [
    'target_structure_id' => ' workbench.staff ', 'target_label' => 'Сотрудники', 'publication' => 'keep', 'copy' => '1',
    'reference_from_0' => 'workbench.cities', 'reference_to_0' => 'workbench.cities',
    'reference_from_1' => 'workbench.tags', 'reference_to_1' => StorageSitePackImportPage::SAME_ID,
]), $runRef);
import_assert($flash()['status'] === 'Архив проверен снова с вашими настройками.', 'The plan is made again.');
import_assert($storage->planned->toArray() === ['target_structure_id' => 'workbench.staff', 'target_label' => 'Сотрудники', 'publication' => 'keep',
    'reference_targets' => ['workbench.cities' => 'workbench.cities'], 'copy' => true, 'declared_locales' => null, 'transfer_domains' => false], 'Every choice reaches Storage; «the same ID» maps nothing.');
$html = $controller->show($request($admin, '/admin/storage/sitepack/import/'.$runRef), $runRef)->render();
foreach (['value="Сотрудники"', 'value="workbench.staff"', 'name="publication" value="keep" checked', 'name="copy" value="1"',
    'Выбрана загрузка копией: все записи получат новые ID.', 'Публикация сохранится: опубликовано 7, запланировано 1'] as $needle) {
    import_assert(str_contains(str_replace("\u{00A0}", ' ', $html), $needle), 'The page keeps the choice: '.$needle);
}
import_assert(preg_match('/name="reference_to_0"[^>]*value="workbench.cities"|value="workbench.cities"[^>]*name="reference_to_0"/', $html) === 1, 'The chosen storage for references is kept.');
$copyForm = $drawRun(['ref' => $runRef, 'state' => 'ready', 'plan' => array_replace($storage->runs[$runRef]->plan->toArray(), ['options' => ['publication' => 'keep',
    'target_label' => 'Сотрудники', 'reference_targets' => ['workbench.cities' => 'workbench.cities'], 'copy' => false]]), 'plan_digest' => 'x', 'can_apply' => true],
    ['run_path' => '/r', 'storage_path' => '/s', 'token' => 't', 'can_publish' => true, 'structures' => []]);
import_assert(preg_match('/<form method="post" action="\/r\/plan"[^>]*>(?:(?!<\/form>).)*name="copy" value="1"(?:(?!<\/form>).)*name="publication" value="keep"|<form method="post" action="\/r\/plan"[^>]*>(?:(?!<\/form>).)*name="publication" value="keep"(?:(?!<\/form>).)*name="copy" value="1"/s', $copyForm) === 1
    && str_contains($copyForm, 'name="reference_to_0" value="workbench.cities"'), '«Загрузить копией» plans again with the other choices kept.');
$controller->plan($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/plan', 'POST', ['target_structure_id' => 'people']), $runRef);
import_assert($flash()['error'] === 'Настройки загрузки указаны неверно: проверьте ID и название.', 'A wrong storage id is refused in plain words.');

// «Загрузить» is bound to the digest of the plan the person saw.
$digest = (string) $storage->runs[$runRef]->planDigest;
$html = $controller->show($request($admin, '/admin/storage/sitepack/import/'.$runRef), $runRef)->render();
import_assert(str_contains($html, 'name="digest" value="'.$digest.'"') && str_contains($html, 'Будет создано хранилище «Сотрудники» (workbench.staff) с записями: 12.'),
    'The apply form carries the plan\'s digest.');
$controller->apply($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/apply', 'POST', ['digest' => 'nope']), $runRef);
import_assert($flash()['error'] === 'План изменился. Посмотрите его ещё раз и нажмите «Загрузить».' && $storage->applied === 0, 'A malformed digest never reaches Storage.');
$controller->apply($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/apply', 'POST', ['digest' => str_repeat('0', 64)]), $runRef);
import_assert($flash()['error'] === 'План изменился. Посмотрите его ещё раз и нажмите «Загрузить».' && $storage->applied === 0, 'Another plan\'s digest is refused.');

// Owner only: another person's run is not found for every page and action.
foreach (['show', 'plan', 'apply', 'cancel', 'retry'] as $action) {
    try {
        $controller->{$action}($request($other, '/admin/storage/sitepack/import/'.$runRef.($action === 'show' ? '' : '/'.$action), $action === 'show' ? 'GET' : 'POST', ['digest' => $digest]), $runRef);
        import_assert(false, 'Another person\'s run is not found for '.$action.'.');
    } catch (NotFoundHttpException) {
    }
}
try {
    $controller->show($request($admin, '/admin/storage/sitepack/import/storage-sitepack-'.str_repeat('f', 32)), 'storage-sitepack-'.str_repeat('f', 32));
    import_assert(false, 'A run that does not exist is not found.');
} catch (NotFoundHttpException) {
}

// The import runs in the request without a queue: progress, then the end with a link to the new storage.
$storage->queue = true;
$controller->apply($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/apply', 'POST', ['digest' => $digest]), $runRef);
import_assert($flash()['status'] === 'Загрузка началась.' && $storage->applied === 1, 'The approved plan is applied.');
$running = str_replace("\u{00A0}", ' ', $drawRun(['ref' => $runRef, 'state' => 'running', 'phase' => 'records', 'active' => true, 'done' => 5, 'total' => 12],
    ['run_path' => '/r', 'storage_path' => '/s', 'token' => 't', 'can_publish' => true, 'structures' => []]));
import_assert(str_contains($running, '5 из 12 записей') && str_contains($running, 'Сейчас: записи') && str_contains($running, 'action="/r/cancel"')
    && str_contains($running, 'Обновить') && !str_contains($running, '/r/apply'), 'During the import: «n из N записей» and «Отменить».');
$planning = $drawRun(['ref' => $runRef, 'state' => 'queued', 'phase' => 'plan', 'active' => true, 'planning' => true, 'done' => 0, 'total' => 0],
    ['run_path' => '/r', 'storage_path' => '/s', 'token' => 't', 'can_publish' => true, 'structures' => []]);
import_assert(str_contains($planning, 'Архив проверяется') && str_contains($planning, 'Ждёт запуска') && str_contains($planning, 'action="/r/cancel"'), 'While the plan is made: its progress and «Отменить».');
$failed = $drawRun(['ref' => $runRef, 'state' => 'failed', 'phase' => 'files', 'reason' => 'storage_sitepack_file_import_mismatch', 'can_retry' => true, 'can_cancel' => true,
    'counts' => ['files_created' => 2]], ['run_path' => '/r', 'storage_path' => '/s', 'token' => 't', 'can_publish' => true, 'structures' => []]);
import_assert(str_contains($failed, 'Один из файлов не удалось записать. Загрузку можно повторить.') && str_contains($failed, 'action="/r/retry"')
    && str_contains($failed, 'Созданные файлы остались на сайте: 2.'), 'A failure says why and offers «Повторить».');
$storage->queue = false;
$storage->runs[$runRef] = SitePackImportFakeStorage::make($runRef, $admin, 'queued', 'structure', null, 0, 12);
// While the import waits or runs its part reads itself again (live), from the run's own page.
$waiting = $controller->show($request($admin, '/admin/storage/sitepack/import/'.$runRef), $runRef)->render();
import_assert(str_contains($waiting, '<!--larena-live:storage-sitepack-import--><div class="larena-live-region" data-larena-live-region="storage-sitepack-import" data-larena-live="/admin/storage/sitepack/import/'.$runRef.'" data-larena-live-interval="4000"'),
    'A running import is live.');
$storage->runs[$runRef] = $storage->run($runRef, $admin);
$done = str_replace("\u{00A0}", ' ', $controller->show($request($admin, '/admin/storage/sitepack/import/'.$runRef), $runRef)->render());
import_assert(str_contains($done, 'data-larena-live-region="storage-sitepack-import"') && !str_contains($done, 'data-larena-live="'), 'A finished import is not read again.');
import_assert(str_contains($done, 'Загружено записей: 12. Файлов создано: 2, взято с сайта: 1.')
    && str_contains($done, 'href="/admin/storage/workbench.people_2" data-larena-sitepack-import-open'), 'At the end: what came and a link to the new storage.');
$controller->cancel($request($admin, '/admin/storage/sitepack/import/'.$runRef.'/cancel', 'POST'), $runRef);
import_assert($flash()['error'] === 'Загрузка уже закончилась.', 'A finished import cannot be cancelled.');

// Cancel and retry of a planned run.
$second = $imports->receive($admin, 'scope:alpha', (string) tempnam(sys_get_temp_dir(), 'pack'), 'second.zip');
$secondRef = (string) $second['run']['ref'];
$controller->cancel($request($admin, '/admin/storage/sitepack/import/'.$secondRef.'/cancel', 'POST'), $secondRef);
import_assert($flash()['status'] === 'Загрузка отменена.' && $storage->runs[$secondRef]->state === 'cancelled', 'The person cancels their import.');
$cancelled = $controller->show($request($admin, '/admin/storage/sitepack/import/'.$secondRef), $secondRef)->render();
import_assert(!str_contains($cancelled, 'data-larena-live="'), 'A cancelled import is not read again.');
import_assert(str_contains($cancelled, 'Загрузка отменена. Уже записанное удалено.') && str_contains($cancelled, 'Созданные файлы остались на сайте: 1.')
    && str_contains($cancelled, '/retry'), 'A cancelled import says what stays and offers «Повторить».');
$controller->retry($request($admin, '/admin/storage/sitepack/import/'.$secondRef.'/retry', 'POST'), $secondRef);
import_assert($storage->runs[$secondRef]->state === 'ready' && $storage->runs[$secondRef]->phase === 'planned', 'A retry plans again, in the request without a queue.');
$list = $controller->form($request($admin, '/admin/storage/sitepack/import'))->render();
import_assert(str_contains($list, 'Ваши загрузки') && str_contains($list, 'href="/admin/storage/sitepack/import/'.$secondRef.'">second.zip</a>'), 'The upload page lists the person\'s imports.');

// ── Permission hiding: the pages and the header action need the import right and the right to create storages and records. ──
foreach (['sitepack.import', 'structure.create', 'record.create'] as $right) {
    $gateway->permissions[$right] = false;
    try {
        $controller->form($request($admin, '/admin/storage/sitepack/import'));
        import_assert(false, 'Without '.$right.' the import page is not found.');
    } catch (NotFoundHttpException) {
    }
    $gateway->permissions[$right] = true;
}
$section = new StorageSectionController($config, new SitePackImportViews(), $redirector, $translator, $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), $app);
$action = new ReflectionMethod(StorageSectionController::class, 'sitePackImportAction');
$state = $gateway->snapshot($admin, null);
import_assert($action->invoke($section, $state) === [['text' => 'Загрузить хранилище', 'href' => '/admin/storage/sitepack/import',
    'attributes' => ['data-larena-storage-sitepack-import' => '']]], 'With the rights the list offers «Загрузить хранилище».');
foreach (['sitepack.import', 'structure.create', 'record.create'] as $right) {
    import_assert($action->invoke($section, ['status' => 'ok', 'permissions' => [$right => false] + $state['permissions']]) === [], 'Without '.$right.' there is no action.');
}
$bare = new StorageSectionController($config, new SitePackImportViews(), $redirector, $translator, $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), new Container());
import_assert($action->invoke($bare, $state) === [], 'Without SitePack installed there is no action.');
$storage->denied = true;
import_assert($imports->runs($admin) === null && $imports->receive($admin, 'scope:alpha', __FILE__, 'x.zip')['reason'] === 'storage_sitepack_import_denied',
    'Storage refuses a person without the right again.');
$storage->denied = false;
$gatewaySource = (string) file_get_contents(__DIR__.'/../../src/Admin/StorageSectionGateway.php');
import_assert(str_contains($gatewaySource, "'sitepack.import' => 'storage.sitepack.import'") && str_contains($gatewaySource, "'publication.publish' => 'storage.publication.publish'"),
    'The section asks the import and publish rights like its other rights.');

// ── The routes: declared before /{structure}, a run by its ref only, POST for every change. ──
$routes = (string) file_get_contents(__DIR__.'/../../routes/admin/storage-section.php');
foreach ([
    "Route::get('/sitepack/import', [StorageSitePackImportController::class, 'form'])->name('sitepack.import')",
    "Route::post('/sitepack/import', [StorageSitePackImportController::class, 'upload'])->name('sitepack.import.upload')",
    "Route::get('/sitepack/import/{run}', [StorageSitePackImportController::class, 'show'])->where('run', \$run)->name('sitepack.import.show')",
    "Route::post('/sitepack/import/{run}/plan', [StorageSitePackImportController::class, 'plan'])->where('run', \$run)->name('sitepack.import.plan')",
    "Route::post('/sitepack/import/{run}/apply', [StorageSitePackImportController::class, 'apply'])->where('run', \$run)->name('sitepack.import.apply')",
    "Route::post('/sitepack/import/{run}/cancel', [StorageSitePackImportController::class, 'cancel'])->where('run', \$run)->name('sitepack.import.cancel')",
    "Route::post('/sitepack/import/{run}/retry', [StorageSitePackImportController::class, 'retry'])->where('run', \$run)->name('sitepack.import.retry')",
] as $needle) {
    import_assert(str_contains($routes, $needle), 'Route: '.$needle);
}
import_assert(strpos($routes, "'/sitepack/import'") < strpos($routes, "Route::get('/{structure}', "), 'The import routes come before the storage routes.');
import_assert(preg_match('/\A'.StorageSitePackImportController::RUN_PATTERN.'\z/', 'import') === 0, '«import» is never a run.');
import_assert(preg_match('/Larena\\\\Storage\\\\(?!Admin\\\\)/', (string) file_get_contents(__DIR__.'/../../src/Admin/Http/Controllers/StorageSitePackImportController.php')) === 0,
    'The controller needs nothing of Storage but its own section.');
$view = (string) file_get_contents(__DIR__.'/../../resources/views/admin/sitepack-import.blade.php');
import_assert(str_contains($view, '{{ $html }}') && !str_contains($view, '{!!') && !str_contains($view, '@if') && !str_contains($view, '@foreach'), 'The view only prints what the shared renderer drew.');

foreach (glob($notesRoot.'/*/*.json') ?: [] as $note) {
    @unlink($note);
}
foreach (glob($notesRoot.'/*') ?: [] as $directory) {
    @rmdir($directory);
}
@rmdir($notesRoot);

// QA 2026-10-22, the plan: the summary is a list of terms and values (not the big counters of the CSV import), each
// warning is a sentence once (three encrypted fields gave «Замечаний: 1.» three times), an encrypted field's warning
// names it, and cancelling before «Загрузить» says nothing on the site changes.
$encryptedPlan = $drawRun(['ref' => 'r', 'state' => 'ready', 'plan_digest' => 'x', 'can_apply' => true, 'plan' => [
    'structure' => ['source_id' => 'workbench.people', 'target_id' => 'workbench.people', 'label' => 'Люди', 'fields' => 3],
    'warnings' => [
        ['code' => 'encrypted_values_omitted', 'count' => 1, 'subject' => 'passport'],
        ['code' => 'encrypted_values_omitted', 'count' => 1, 'subject' => 'inn'],
        ['code' => 'unknown_one', 'count' => 1, 'subject' => null],
        ['code' => 'unknown_two', 'count' => 1, 'subject' => null],
    ],
]], ['run_path' => '/r', 'storage_path' => '/s', 'token' => 't', 'can_publish' => true, 'structures' => []]);
import_assert(str_contains($encryptedPlan, '<dl class="larena-storage-import__summary" data-larena-sitepack-import-summary>')
    && !str_contains($encryptedPlan, 'larena-storage-import__counts'), 'The plan summary is a list of terms, not counters.');
import_assert(str_contains($encryptedPlan, 'Зашифрованное поле «passport» придёт без значений') && str_contains($encryptedPlan, 'Зашифрованное поле «inn» придёт без значений'),
    'An encrypted field\'s warning names the field.');
import_assert(substr_count($encryptedPlan, 'Замечаний: 1.') === 1, 'One sentence is said once.');
import_assert(str_contains($encryptedPlan, htmlspecialchars('Отменить загрузку? На сайте ничего не изменится: архив и его план будут удалены.', ENT_QUOTES))
    && !str_contains($encryptedPlan, 'Уже записанное будет удалено'), 'Cancelling a plan says nothing changes.');
import_assert(str_contains($planning, htmlspecialchars('На сайте ничего не изменится', ENT_QUOTES)) && str_contains($running, 'Уже записанное будет удалено'),
    'While the archive is checked nothing is written; while it is imported the question says what goes.');
import_assert(!array_key_exists('status_checking', $ru['larena_storage']['section']['sitepack_import']), 'No notice is left behind once the check ends.');
$css = (string) file_get_contents(__DIR__.'/../../vendor/larena/admin/resources/css/admin-shell.css');
import_assert(str_contains($css, ':is(.larena-storage, .larena-storage > .larena-live-region) > .larena-panel { display: grid; gap: .875rem; padding: 1.375rem; }'),
    'A panel read again in place (SitePack, jobs) keeps the storage panel\'s padding and heading.');

echo "StorageSitePackImportTest passed.\n";
