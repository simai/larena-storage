<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem as Disk;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\ScopeStatus;
use Larena\Core\Scope\ScopeRef;
use Larena\Filesystem\Persistence\DatabaseLogicalFileRepository;
use Larena\Filesystem\Runtime\UnrecordedPersistentFileAuditTrail;
use Larena\Filesystem\Services\SafeFileService;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\Http\Controllers\StorageDocumentEditorController;
use Larena\Storage\Admin\StorageDocumentEditor;
use Larena\Storage\DocumentPort\DatabaseDocumentPorts;
use Larena\Storage\DocumentPort\DocumentPortBinding;
use Larena\Storage\DocumentPort\DocumentPortRights;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Ui\Frontend\FrontendRuntimeLock;
use Larena\Ui\Frontend\SourceBackedComponentRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// The page editor's routes over a real Storage on SQLite and the real safe file service (document port, block 2):
// load, save, a conflict from a stale revision, a save refused for a reader without the right, an ended session,
// a picture kept through the safe file service (an allowed kind and a refused one) and its address; the page with the
// pinned Framework (no sf-editor yet: it says so), the editor element with the port's addresses on it once the Framework
// carries sf-editor, and a read-only page for a reader without the right to change the record.

// The package's test install has neither Laravel's validation nor its translator: the safe file service's refusals are
// thrown as they are in the product through this one class, and the words are read from the files the page is served from.
if (!class_exists(\Illuminate\Validation\ValidationException::class)) {
    eval('namespace Illuminate\\Validation; class ValidationException extends \\Exception { public array $errors = [];'
        . ' public static function withMessages(array $messages): static { $e = new static("The given data was invalid."); $e->errors = $messages; return $e; } }');
}
if (!function_exists('route')) {
    function route(string $name, array $parameters = [], bool $absolute = true): string
    {
        return \Illuminate\Container\Container::getInstance()->make('url')->route($name, $parameters, $absolute);
    }
}
if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

final class DocumentEditorConfig implements \Illuminate\Contracts\Config\Repository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}
    public function has($key) { return $this->get($key) !== null; }
    public function get($key, $default = null)
    {
        $value = $this->items;
        foreach (explode('.', (string) $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
    public function all() { return $this->items; }
    public function set($key, $value = null) {}
    public function prepend($key, $value) {}
    public function push($key, $value) {}
}

final class DocumentEditorWords implements \Illuminate\Contracts\Translation\Translator
{
    /** @param array<string, mixed> $words */
    public function __construct(private array $words) {}
    public function get($key, array $replace = [], $locale = null)
    {
        $value = (new DocumentEditorConfig($this->words))->get(substr((string) $key, strlen('larena-storage::admin.')));
        $text = is_string($value) ? $value : (string) $key;
        foreach ($replace as $name => $with) {
            $text = str_replace(':' . $name, (string) $with, $text);
        }

        return $text;
    }
    public function choice($key, $number, array $replace = [], $locale = null) { return $this->get($key, $replace); }
    public function getLocale() { return 'ru'; }
    public function setLocale($locale) {}
}

function documentEditorExpect(bool $condition, string $message, mixed $context = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($context === null ? '' : ': ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}

$editor = 'user:admin_identity:1';
$reader = 'user:admin_identity:2';
$stranger = 'user:admin_identity:3';
$scope = 'scope:site-main';
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$connection = $capsule->getConnection();

// The application the safe file service reaches through Laravel's helpers: the database, words and the public address.
$container = new Container();
Container::setInstance($container);
// The facades of the safe file service (DB) read this container.
(new ReflectionMethod(Facade::class, 'setFacadeApplication'))->invoke(null, $container);
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $connection->getSchemaBuilder());
$routes = new RouteCollection();
$routes->add((new Route(['GET'], '/media/{file}/{name}', []))->name('larena.filesystem.public'));
$routes->refreshNameLookups();
$container->instance('url', new UrlGenerator($routes, Request::create('https://larena.test/admin')));

publishedSiteViewInstall($connection);
(require __DIR__ . '/../../database/migrations/platform/2026_11_20_000002_create_larena_storage_document_port_receipts_table.php')->up();
foreach (glob(__DIR__ . '/../../vendor/larena/filesystem/database/migrations/*.php') ?: [] as $migration) {
    (require $migration)->up();
}
// The reader is a person of the scope who may read its records (the fixture's readers); the stranger is not.
$runtime = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), $editor, $scope, readers: [$reader]);
$workbench = $runtime['workbench'];
$publication = new Larena\Storage\Runtime\DatabasePublicationLifecycle($connection, static fn (string $schemaId, string $recordId, int $revision): bool
    => $connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists());
$workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
    publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], false, true),
    publishedSiteViewField('body', 20, 'composition', 'public'),
]], $editor);
$document = static fn (string $text): array => ['schema' => 'simai.composition.document', 'schema_version' => 1, 'id' => 'page',
    'profile' => 'ui-layout', 'locale' => 'ru', 'root' => ['id' => 'root', 'type' => 'layout.page', 'slots' => ['default' => [
        ['id' => 'main-1', 'type' => 'content.paragraph', 'data' => ['content' => [['text' => $text]]]],
    ]]]];
$record = $workbench->createRecord($scope, 'workbench.pages', ['title' => 'О компании', 'body' => ['ru' => $document('Первый текст')]], $editor);

/** The section's gateway as the editor needs it: the reader's scope, a stranger is not let in. */
$gateway = new class ($scope, $stranger) implements StorageWorkbenchGateway {
    public function __construct(private string $scope, private string $stranger) {}

    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        return $actor === $this->stranger ? ['status' => 'denied'] : ['status' => 'ok', 'scope_ref' => $this->scope,
            'selected_structure' => ['structure_id' => $query['structure_id'] ?? null, 'label' => 'Pages', 'archived' => false]];
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        return ['status' => 'unavailable'];
    }
};
/** Access as the port reads it: the editor saves, the reader only reads, the stranger reads nothing. */
$rights = new class ($editor, $reader) implements DocumentPortRights {
    public function __construct(private string $editor, private string $reader) {}

    public function rights(DocumentPortBinding $binding, string $actor): ?array
    {
        return match ($actor) {
            $this->editor => ['save' => true, 'publish' => false],
            $this->reader => ['save' => false, 'publish' => false],
            default => null,
        };
    }
};
$authorizer = new class ($editor) implements ActorOperationAuthorizer {
    public function __construct(private string $editor) {}

    public function assertAllowed(string $actor, string $operation): void
    {
        if ($actor !== $this->editor || $operation !== 'filesystem.file.attach') {
            throw new RuntimeException('denied');
        }
    }
};
$site = new ScopeRecord(ScopeRef::parse('site:main'), ScopeKind::Site, null, 'Main', ScopeStatus::Active, 'test', null, $scope);
$scopes = new class ($site) implements ScopeRefResolver {
    public function __construct(private ScopeRecord $site) {}

    public function resolve(string $reference): ?ScopeRecord
    {
        return in_array($reference, [(string) $this->site->ref, $this->site->legacyRef], true) ? $this->site : null;
    }

    public function exists(string $reference): bool
    {
        return $this->resolve($reference) !== null;
    }
};
$resources = new class implements ScopeResourceBindings {
    /** @var array<string, string> */
    public array $bound = [];

    public function bind(string $resourceType, string $resourceRef, ScopeRef $scope): void
    {
        $this->bound[$resourceType . ':' . $resourceRef] = (string) $scope;
    }

    public function scopeOf(string $resourceType, string $resourceRef): ?ScopeRecord
    {
        return null;
    }

    public function belongsTo(string $resourceType, string $resourceRef, ScopeRef $scope): bool
    {
        return ($this->bound[$resourceType . ':' . $resourceRef] ?? null) === (string) $scope;
    }

    public function listRefs(ScopeRef $scope, string $resourceType, ?string $cursor = null, int $limit = 50): array
    {
        return ['items' => [], 'next_cursor' => null];
    }
};
$blobs = new class implements Filesystems {
    public ?Disk $disk = null;

    public function disk($name = null): Disk
    {
        return $this->disk;
    }
};
$blobs->disk = new class implements Disk {
    /** @var array<string, string> */
    public array $files = [];

    public function path($path) { return $path; }
    public function exists($path) { return isset($this->files[$path]); }
    public function get($path) { return $this->files[$path] ?? null; }
    public function readStream($path) { return null; }
    public function put($path, $contents, $options = []) { $this->files[$path] = is_resource($contents) ? (string) stream_get_contents($contents) : (string) $contents; return true; }
    public function putFile($path, $file = null, $options = []) { return false; }
    public function putFileAs($path, $file, $name = null, $options = []) { return false; }
    public function writeStream($path, $resource, array $options = []) { return false; }
    public function getVisibility($path) { return 'private'; }
    public function setVisibility($path, $visibility) { return true; }
    public function prepend($path, $data) { return true; }
    public function append($path, $data) { return true; }
    public function delete($paths) { foreach ((array) $paths as $path) { unset($this->files[$path]); } return true; }
    public function copy($from, $to) { return true; }
    public function move($from, $to) { return true; }
    public function size($path) { return strlen($this->files[$path] ?? ''); }
    public function lastModified($path) { return 0; }
    public function files($directory = null, $recursive = false) { return []; }
    public function allFiles($directory = null) { return []; }
    public function directories($directory = null, $recursive = false) { return []; }
    public function allDirectories($directory = null) { return []; }
    public function makeDirectory($path) { return true; }
    public function deleteDirectory($directory) { return true; }
};
$config = new DocumentEditorConfig(['larena-filesystem' => ['disk' => 'local', 'root' => 'larena/media/blobs', 'max_bytes' => 1048576,
    'allowed_types' => ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'application/pdf' => ['pdf'], 'text/plain' => ['txt']]]]);
$files = new SafeFileService($config, $blobs, new DatabaseLogicalFileRepository(), new UnrecordedPersistentFileAuditTrail());

$editorService = new StorageDocumentEditor($gateway, $workbench, new DatabaseDocumentPorts($connection, $workbench, $publication, $rights), $rights,
    null, null, ['ru', 'en'], $files, $authorizer, $scopes, $resources);
$words = new DocumentEditorWords(require __DIR__ . '/../../resources/lang/ru/admin.php');
$pages = new class implements ViewFactory {
    /** @var array<string, mixed> */
    public array $data = [];
    public function exists($view) { return true; }
    public function file($path, $data = [], $mergeData = []) { return $this->make($path, $data); }
    public function make($view, $data = [], $mergeData = []): View
    {
        $this->data = ['view' => $view] + $data;

        return new class ($view) implements View {
            public function __construct(private string $view) {}
            public function name() { return $this->view; }
            public function with($key, $value = null) { return $this; }
            public function getData() { return []; }
            public function render() { return ''; }
        };
    }
    public function share($key, $value = null) { return $value; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
};
$controller = new StorageDocumentEditorController($editorService, new DocumentEditorConfig(), $words, $pages);
$base = '/admin/storage/workbench.pages/' . $record->recordId . '/document/body';
$send = static function (?string $actor, string $intent, array|object $payload = []) use ($controller, $record): array {
    $request = Request::create('https://larena.test/admin/x/port', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode(['intent' => $intent, 'payload' => (object) $payload], JSON_UNESCAPED_UNICODE));
    if ($actor !== null) {
        $request->attributes->set('larena_access_actor', $actor);
    }
    $response = $controller->port($request, 'workbench.pages', $record->recordId, 'body');
    documentEditorExpect($response->getStatusCode() === 200 && $response->headers->get('Cache-Control') === 'no-store, private', 'a port answer is HTTP 200, never stored', $response->headers->all());

    return json_decode((string) $response->getContent(), true);
};

// 1. load: the record's page document, its revision, rights and limits; the element is a page of that record.
$loaded = $send($editor, 'document.load');
documentEditorExpect($loaded['answer'] === 'applied' && $loaded['data']['languages'] === ['ru', 'en']
    && $loaded['data']['values']['ru']['root']['slots']['default'][0]['data']['content'][0]['text'] === 'Первый текст'
    && $loaded['data']['rights'] === ['save' => true, 'publish' => false]
    && $loaded['data']['target'] === ['level' => 'page', 'ref' => 'record:workbench.pages/' . $record->recordId . '/body', 'title' => 'О компании'], 'load', $loaded);
$first = $loaded['data']['revision'];

// 2. save: a new draft revision, kept.
$saved = $send($editor, 'document.save', ['expected_revision' => $first, 'values' => ['ru' => $document('Второй текст')]]);
documentEditorExpect($saved['answer'] === 'applied' && $saved['data']['revision'] !== $first, 'save', $saved);
$kept = $workbench->readRecord($scope, 'workbench.pages', $record->recordId, $editor)->values['body']['ru']['root']['slots']['default'][0]['data']['content'][0]['text'];
documentEditorExpect($kept === 'Второй текст', 'the save is kept in the record', $kept);

// 3. conflict: a second tab saves against the revision it loaded, after the first tab saved.
$stale = $send($editor, 'document.save', ['expected_revision' => $first, 'values' => ['ru' => $document('Из второй вкладки')]]);
documentEditorExpect($stale['answer'] === 'conflict' && $stale['reason'] === 'stale-revision' && $stale['current_revision'] === $saved['data']['revision']
    && $stale['current']['values']['ru']['root']['slots']['default'][0]['data']['content'][0]['text'] === 'Второй текст', 'a stale save is a conflict carrying the stored page', $stale);

// 4. A reader without the right to change the record: load says so, a save is refused access-denied and nothing moves.
$read = $send($reader, 'document.load');
documentEditorExpect($read['answer'] === 'applied' && $read['data']['rights']['save'] === false, 'a reader loads read-only', $read);
$refused = $send($reader, 'document.save', ['expected_revision' => $saved['data']['revision'], 'values' => ['ru' => $document('Чужая правка')]]);
documentEditorExpect($refused['answer'] === 'refused' && $refused['reason'] === 'access-denied', 'a save without the right is refused', $refused);
$denied = $send($stranger, 'document.load');
documentEditorExpect($denied['answer'] === 'refused' && $denied['reason'] === 'access-denied', 'a stranger is denied', $denied);
documentEditorExpect($workbench->readRecord($scope, 'workbench.pages', $record->recordId, $editor)->revision === 2, 'a refused save writes nothing');

// 5. An ended session: no reader is session-expired, as a JSON answer, never a redirect.
documentEditorExpect($send(null, 'document.save', ['expected_revision' => '1', 'values' => []])['reason'] === 'session-expired', 'no session is session-expired');
documentEditorExpect($send($editor, 'document.unknown')['reason'] === 'unknown-intent', 'an unknown intent');
// Another record's field, a field that is not a page document, a record that is not there: not-found.
documentEditorExpect($controller->port(tap(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"intent":"document.load"}'),
    static fn (Request $request) => $request->attributes->set('larena_access_actor', $editor)), 'workbench.pages', $record->recordId, 'title')->getContent()
    === '{"answer":"refused","reason":"not-found","message":"refused: not-found"}', 'a field that is not a page document is not-found');
// An empty object the port could not keep is refused would-lose, as the JSON layer says.
$lost = $send($editor, 'document.save', ['expected_revision' => $saved['data']['revision'], 'values' => ['ru' => ['schema' => 'simai.composition.document', 'schema_version' => 1, 'id' => 'page',
    'profile' => 'ui-layout', 'locale' => 'ru', 'root' => ['id' => 'root', 'type' => 'layout.page', 'data' => ['unknown' => ['deep' => new stdClass()]], 'slots' => ['default' => []]]]]]);
documentEditorExpect($lost['answer'] === 'refused' && $lost['reason'] === 'would-lose', 'an empty object inside unknown data is would-lose', $lost);

// 6. Pictures through the safe file service: an allowed kind is kept as a public file of the site and named media:<id>.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
// Real uploaded files: the service reads the kind from the content, as it does in the product.
$file = static function (string $name, string $content): UploadedFile {
    $path = (string) tempnam(sys_get_temp_dir(), 'larena-document-editor-');
    file_put_contents($path, $content);
    register_shutdown_function(static fn () => is_file($path) && unlink($path));

    return new UploadedFile($path, $name, null, null, true);
};
$upload = static function (?string $actor, UploadedFile $file) use ($controller, $record): array {
    $request = Request::create('https://larena.test/admin/x/media', 'POST', [], [], ['file' => $file]);
    if ($actor !== null) {
        $request->attributes->set('larena_access_actor', $actor);
    }

    return json_decode((string) $controller->media($request, 'workbench.pages', $record->recordId, 'body')->getContent(), true);
};
$picture = $upload($editor, $file('hero.png', (string) $png));
documentEditorExpect($picture['answer'] === 'applied' && preg_match('/\Amedia:[0-9a-f-]{36}\z/', $picture['data']['ref']) === 1
    && str_starts_with($picture['data']['url'], 'https://larena.test/media/') && $picture['data']['mime'] === 'image/png', 'an image is kept', $picture);
$id = substr($picture['data']['ref'], 6);
documentEditorExpect($connection->table('larena_files')->where('logical_ref', $id)->value('visibility') === 'public'
    && $resources->bound['filesystem.logical_file:' . $id] === 'site:main', 'the picture is a public file of the site');
// A kind the service refuses (a page, a script) is refused invalid and nothing is kept.
$before = $connection->table('larena_files')->count();
$html = $upload($editor, $file('page.html', '<p>x</p>'));
documentEditorExpect($html['answer'] === 'refused' && $html['reason'] === 'invalid' && $html['data']['diagnostics'][0]['code'] === 'file-kind-refused', 'html is refused', $html);
$text = $upload($editor, $file('photo.png', 'not a picture'));
documentEditorExpect($text['answer'] === 'refused' && $text['reason'] === 'invalid', 'a file whose content is not its kind is refused', $text);
documentEditorExpect($connection->table('larena_files')->count() === $before, 'a refused file is not kept');
// A reader without the right to change the page, or without a session, adds nothing.
documentEditorExpect($upload($reader, $file('hero.png', (string) $png))['reason'] === 'access-denied', 'a reader uploads nothing');
documentEditorExpect($upload(null, $file('hero.png', (string) $png))['reason'] === 'session-expired', 'no session uploads nothing');
// The address of a picture on the page; another name is not-found.
$address = static function (string $actor, mixed $ref) use ($controller, $record): array {
    $request = Request::create('https://larena.test/admin/x/media', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['ref' => $ref]));
    $request->attributes->set('larena_access_actor', $actor);

    return json_decode((string) $controller->media($request, 'workbench.pages', $record->recordId, 'body')->getContent(), true);
};
documentEditorExpect($address($reader, $picture['data']['ref']) === ['answer' => 'applied', 'data' => ['ref' => $picture['data']['ref'], 'url' => $picture['data']['url']]], 'a reader gets the address');
documentEditorExpect($address($editor, 'media:00000000-0000-4000-8000-000000000000')['reason'] === 'not-found', 'an unknown picture is not-found');
documentEditorExpect($address($editor, 'https://elsewhere.example/x.png')['reason'] === 'not-found', 'only media: names are answered');

// 7. The page: with the pinned Framework (no sf-editor yet) it says the editor is not connected; never a blank editor.
$page = static function (string $actor) use ($controller, $pages, $record): array {
    $request = Request::create('https://larena.test' . '/admin/storage/workbench.pages/' . $record->recordId . '/document/body');
    $request->attributes->set('larena_access_actor', $actor);
    $controller->page($request, 'workbench.pages', $record->recordId, 'body');

    return $pages->data;
};
$shown = $page($editor);
$html = (string) $shown['content'];
documentEditorExpect($shown['view'] === 'larena-storage::admin.document-editor' && $shown['heading'] === 'О компании — Body'
    && str_contains($html, 'Редактор страницы ещё не подключён') && !str_contains($html, '<sf-editor')
    && $shown['recordPath'] === '/admin/storage/workbench.pages?record_id=' . $record->recordId . '&record_mode=view&record_page=1', 'the page waits for the editor', $shown);
// A reader without the right to change the record sees that the page is read only.
documentEditorExpect(str_contains((string) $page($reader)['content'], 'Только просмотр'), 'a reader is told the page is read only');
try {
    $page($stranger);
    documentEditorExpect(false, 'a stranger opened the page');
} catch (AccessDeniedHttpException) {
}

// Once the pinned Framework carries sf-editor, the page draws it hidden, with the port's addresses, edition, capabilities and rights.
$lock = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vendor/larena/ui/resources/sf/runtime-lock.json'), true);
$lock['components']['sf-editor'] = ['source' => 'pro/smart/editor', 'javascript' => 'pro/smart/editor/js/editor.min.js', 'css' => 'pro/smart/editor/css/editor.min.css',
    'requires' => [], 'attributes' => ['heading', 'caption', 'label', 'preferences-key', 'screen'], 'package' => 'pro'];
$withEditor = new StorageDocumentEditorController($editorService, new DocumentEditorConfig(), $words, $pages,
    new SourceBackedComponentRegistry(FrontendRuntimeLock::fromArray($lock)));
$bound = $editorService->bind($editor, 'workbench.pages', $record->recordId, 'body');
documentEditorExpect(is_array($bound), 'the element is bound');
$node = $withEditor->editorNode($bound, ['save' => true, 'publish' => false], $base);
documentEditorExpect($node instanceof AdminSfElement && $node->tag === 'sf-editor' && $node->props['heading'] === 'О компании'
    && $node->host === ['hidden' => '', 'data-larena-document-port' => $base . '/port', 'data-larena-document-media' => $base . '/media',
        'data-larena-document-port-edition' => '1.0.1', 'data-larena-document-capabilities' => '["history"]',
        'data-larena-document-rights' => '{"save":true,"publish":false}'], 'the editor carries its port', $node);
$readOnly = $withEditor->editorNode($bound, ['save' => false, 'publish' => false], $base);
documentEditorExpect($node instanceof AdminSfElement && $readOnly instanceof AdminSfElement
    && json_decode($node->host['data-larena-document-rights'], true)['save'] === true
    && json_decode($readOnly->host['data-larena-document-rights'], true)['save'] === false, 'the editor is told when the reader may not save');

echo "StorageDocumentEditorTest passed: load, save, conflict, read-only, session-expired, pictures through the safe file service, the page.\n";
