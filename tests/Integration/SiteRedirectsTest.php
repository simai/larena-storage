<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\SiteMatch;
use Larena\Core\Site\CurrentSite;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Console\Commands\StorageSiteRedirectsCommand;
use Larena\Storage\Contracts\RequestSiteScope;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\FirstRun\SiteFirstRunContributor;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\SiteRedirect\SiteRedirectCache;
use Larena\Storage\SiteRedirect\SiteRedirectInvalidation;
use Larena\Storage\SiteRedirect\SiteRedirectMiddleware;
use Larena\Storage\SiteRedirect\SiteRedirectRules;
use Larena\Storage\SiteRedirect\SiteRedirects;
use Larena\Storage\SiteRedirect\SiteRedirectsStorage;
use Larena\Storage\SiteRedirect\SiteRedirectValidator;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackExporter;
use Larena\Storage\View\SqliteStorageViewDdl;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-sitepack-import-fixture.php';

// The site redirects (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, steps 2.4–2.6):
// - each site's «Переадресации» storage (workbench.site_redirects, bound to site_redirect@v1 in the site): the first
//   run makes it once it has committed; larena:storage:site-redirects --dry-run writes nothing, the command makes it
//   on an existing installation and changes nothing when run again;
// - a rule's write is checked (paths, same path, duplicate, cycles) and collapses chains both ways: A → B while B → C
//   is stored as A → C; once D → E is written, X → D becomes X → E as a new revision, published again;
// - SiteRedirects::ensure() writes an automatic rule, published, and changes nothing when asked the same again;
// - the middleware answers published rules only, GET and HEAD only, never the admin or the API: exact, prefix with
//   the rest of the path, the query kept; its per-site map is cached (no database read when cached) and dropped on a
//   rule's publication change and write;
// - a pack of the storage moves the rules into another installation as ordinary records with their publication.

const SITE_REDIRECTS_ADMIN = 'user:admin_identity:1';
const SITE_REDIRECTS_SCOPE = 'scope:main';
const SITE_REDIRECTS_SITE = 'site:main';

/**
 * One installation on its own SQLite file: the site (site:main, data scope scope:main), the workbench with
 * the site redirect checks, the publication lifecycle telling the maps, and the SitePack runtime.
 *
 * @return array<string, mixed>
 */
function siteRedirectsInstallation(bool $site = true): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-site-redirects-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_site_redirects_tempfile_failed');
    }
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    $connection->setTransactionManager(new DatabaseTransactionsManager());
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());
    sitePackImportInstall($connection);
    $core = __DIR__ . '/../../vendor/larena/core/database/migrations/platform';
    foreach ([
        $core . '/2026_09_24_000001_create_larena_core_scopes_table.php',
        $core . '/2026_09_24_000002_create_larena_core_planes_table.php',
        $core . '/2026_09_24_000003_create_larena_core_plane_nodes_table.php',
        $core . '/2026_10_12_000004_add_legacy_ref_to_larena_core_scopes_table.php',
        __DIR__ . '/../../database/migrations/2026_10_29_000001_add_larena_storage_workbench_structure_adoption.php',
    ] as $file) {
        (require $file)->up();
    }
    $admin = SITE_REDIRECTS_ADMIN;
    $runtime = sitePackImportRuntime($connection, new SqliteStorageViewDdl($connection), [$admin => []], [$admin]);
    $scopes = new DatabaseScopeRegistry($connection);
    $baseline = new ScopeBaselineInstaller($connection, $scopes, new DatabasePlaneRegistry($connection, $scopes));
    if ($site) {
        // Root's main site: its data carry the older reference (RootScopeSites::MAIN).
        $baseline->apply();
        $scopes->assignLegacyRef(ScopeRef::parse(SITE_REDIRECTS_SITE), SITE_REDIRECTS_SCOPE);
    }
    $store = new CacheRepository(new ArrayStore());
    $rules = new SiteRedirectRules($connection);
    $maps = new ArrayObject(['cache' => new SiteRedirectCache($rules, $store)]);
    $cache = static fn (): SiteRedirectCache => $maps['cache'];
    $publication = new DatabasePublicationLifecycle(
        $connection,
        static fn (string $schemaId, string $recordId, int $revision): bool => $connection->table('larena_storage_record_versions')
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists(),
        new SiteRedirectInvalidation($connection, $rules, $cache),
    );
    $validator = new SiteRedirectValidator($connection, $rules, $publication, $cache);
    $access = new SitePackImportAccess([$admin => []]);
    $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $policies->protect('larena/storage', static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {
        if (!$capability instanceof DatabaseStorageWorkbench) {
            throw new RuntimeException('capability_invalid');
        }
    }, 'workbench.');
    $policies->seal();
    $workbench = new DatabaseStorageWorkbench($connection, PropertyTypeRegistry::builtIns(), $access, $access, $runtime['storage'], $policies,
        'storage-site-redirects-cursor-key-of-32-bytes-min', null, $runtime['views'], recordValidators: static fn (): array => [$validator]);
    $starterRoles = new StarterStructureRoles($runtime['roles']);
    $storage = new SiteRedirectsStorage($connection, $workbench, $runtime['roles'], $starterRoles, $rules,
        static fn (): array => array_map(static fn ($record): string => $record->ref->toString(), $scopes->list(ScopeKind::Site)),
        static fn (string $siteRef, string $actor): string => $scopes->read(ScopeRef::parse($siteRef))?->dataRef() ?? throw new RuntimeException('site_unknown'));
    // The «Хранилища» section lists only storages whose field keys pass its safe-key rule (no path, html, raw…).
    foreach ($storage->descriptor('en')['fields'] as $field) {
        assert(preg_match('/(?:^|_)(?:path|disk|storage_key|private|secret|password|token|html|raw)(?:_|$)/i', $field['key']) !== 1, $field['key']);
    }
    $redirects = new SiteRedirects($connection, $workbench, $publication, $rules, $validator, static fn (string $siteRef): string => 'en');
    $starter = new StarterSite($connection, $runtime['storage'], $runtime['roles'], $starterRoles, $baseline);

    return [
        'connection' => $connection, 'path' => $path, 'runtime' => $runtime, 'workbench' => $workbench, 'publication' => $publication,
        'rules' => $rules, 'store' => $store, 'maps' => $maps, 'storage' => $storage, 'redirects' => $redirects, 'starter' => $starter,
        'sitePack' => ($runtime['sitePack'])(['publication' => $publication]),
    ];
}

/** @param array<string, mixed> $installation @param array<string, mixed> $values */
function siteRedirectCreate(array $installation, array $values): StorageWorkbenchRecord
{
    return $installation['workbench']->createRecord(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID,
        $values + ['status_code' => '301', 'match' => 'exact', 'origin' => 'manual'], SITE_REDIRECTS_ADMIN);
}

/** @param array<string, mixed> $installation @param array<string, mixed> $changes */
function siteRedirectUpdate(array $installation, StorageWorkbenchRecord $record, array $changes): StorageWorkbenchRecord
{
    $values = array_filter($record->values, static fn (string|int $key): bool => !str_starts_with((string) $key, 'larena_'), ARRAY_FILTER_USE_KEY);

    return $installation['workbench']->updateRecord(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, $record->recordId,
        $record->revision, $changes + $values, SITE_REDIRECTS_ADMIN);
}

/** @param array<string, mixed> $installation */
function siteRedirectRead(array $installation, string $recordId): StorageWorkbenchRecord
{
    return $installation['workbench']->readRecord(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, $recordId, SITE_REDIRECTS_ADMIN);
}

/** @param array<string, mixed> $installation */
function siteRedirectPublish(array $installation, StorageWorkbenchRecord $record, string $locale = 'en'): void
{
    $installation['publication']->publish(siteRedirectSchema($installation), $record->recordId, SITE_REDIRECTS_SITE, $locale, $record->revision, SITE_REDIRECTS_ADMIN);
}

/** @param array<string, mixed> $installation */
function siteRedirectSchema(array $installation): string
{
    return $installation['workbench']->readStructure(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, SITE_REDIRECTS_ADMIN)->schema->schemaId;
}

/** A new request's map cache over the installation's configured cache: what the next request starts with. @param array<string, mixed> $installation */
function siteRedirectNextRequest(array $installation): void
{
    $installation['maps']['cache'] = new SiteRedirectCache($installation['rules'], $installation['store']);
}

/**
 * What the middleware answers: [status, Location] for a redirect, [200, null] when the request passed on.
 *
 * @param array<string, mixed> $installation
 * @return array{0: int, 1: string|null}
 */
function siteRedirectAsk(array $installation, string $uri, string $method = 'GET', ?RequestSiteScope $sites = null): array
{
    $middleware = new SiteRedirectMiddleware($installation['maps']['cache'], static fn (): string => SITE_REDIRECTS_SITE, $sites);
    $response = $middleware->handle(Request::create($uri, $method), static fn (Request $request): Response => new Response('page'));

    return [$response->getStatusCode(), $response->headers->get('Location')];
}

/** @return array{0: int, 1: array<string, mixed>} The command's exit status and its receipt. */
function siteRedirectsCommand(SiteRedirectsStorage $storage, array $options): array
{
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(SiteRedirectsStorage::class, $storage);
    $container->instance(ExceptionHandler::class, new class implements ExceptionHandler {
        public function report(Throwable $e): void
        {
            throw $e;
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void
        {
            throw $e;
        }
    });
    $command = new StorageSiteRedirectsCommand();
    $command->setLaravel($container);
    $output = new BufferedOutput();
    $input = new ArrayInput($options + ['--json' => true]);
    $input->setInteractive(false);
    $status = $command->run($input, $output);
    $receipt = json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($receipt)) {
        throw new RuntimeException('the command printed no receipt');
    }

    return [$status, $receipt];
}

/** @param array<string, mixed> $installation @return array<string, int> */
function siteRedirectsCounts(array $installation): array
{
    $connection = $installation['connection'];

    return [
        'structures' => $connection->table('larena_storage_workbench_structures')->count(),
        'bindings' => $connection->table('larena_storage_structure_role_bindings')->where('role_ref', StarterStructureRoles::SITE_REDIRECT_REF)->count(),
        'record_versions' => $connection->table('larena_storage_record_versions')->count(),
    ];
}

$paths = [];
$scratch = sys_get_temp_dir() . '/larena-storage-site-redirects-' . bin2hex(random_bytes(6));
StorageSitePackExporter::directory($scratch);
$admin = SITE_REDIRECTS_ADMIN;
try {
    // ===== 1. A new installation: the site step makes «Переадресации» once the first run has committed. =====
    $fresh = siteRedirectsInstallation();
    $paths[] = $fresh['path'];
    $contributor = new SiteFirstRunContributor($fresh['starter'], $fresh['connection'], null, null, null, null,
        static fn (string $actor, string $locale): array => $fresh['storage']->create($actor, null, $locale));
    $payload = new FirstRunPayload('Administrator', 'admin@example.test', 'Password!2026', 'Larena', 'en', 'UTC');
    $fresh['connection']->transaction(static function () use ($contributor, $payload, $fresh): FirstRunContext {
        $context = $contributor->apply($payload, new FirstRunContext(['auth.subject_ref' => SITE_REDIRECTS_ADMIN]));
        storageViewExpect($fresh['rules']->storages(SITE_REDIRECTS_SITE) === [], 'the redirects storage was made inside the first run');

        return $context;
    });
    $made = $fresh['rules']->storages(SITE_REDIRECTS_SITE);
    $structure = $fresh['workbench']->readStructure(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, $admin);
    storageViewExpect(count($made) === 1 && $made[0]['scope_ref'] === SITE_REDIRECTS_SCOPE && $made[0]['structure_id'] === SiteRedirectsStorage::STRUCTURE_ID
        && $structure->label === 'Redirects' && array_column($structure->fields, 'key') === ['from_address', 'to_address', 'status_code', 'match', 'origin', 'note'],
        'the first run did not make the site\'s redirects storage: ' . json_encode($made));
    $before = siteRedirectsCounts($fresh);
    [$status, $receipt] = siteRedirectsCommand($fresh['storage'], ['--actor' => $admin]);
    storageViewExpect($status === 0 && $receipt['sites'][0]['state'] === SiteRedirectsStorage::ALREADY_PRESENT && $receipt['writes'] === ['structures' => 0, 'bindings' => 0]
        && siteRedirectsCounts($fresh) === $before, 'the command wrote on an installation that has its redirects storage: ' . json_encode($receipt));

    // ===== 2. An installation from before: the dry run writes nothing, the command makes it once. =====
    $site = siteRedirectsInstallation();
    $paths[] = $site['path'];
    [$status, $receipt] = siteRedirectsCommand($site['storage'], ['--actor' => 'system:storage']);
    storageViewExpect($status === 1 && $receipt['reason_code'] === 'storage_site_redirects_input_invalid', 'the command ran without an administrator');
    $before = siteRedirectsCounts($site);
    [$status, $receipt] = siteRedirectsCommand($site['storage'], ['--actor' => $admin, '--dry-run' => true]);
    storageViewExpect($status === 0 && $receipt['status'] === 'planned' && $receipt['sites'][0]['state'] === SiteRedirectsStorage::PLANNED
        && $receipt['sites'][0]['action'] === 'create' && $receipt['sites'][0]['scope_ref'] === SITE_REDIRECTS_SCOPE
        && $receipt['writes'] === ['structures' => 1, 'bindings' => 1] && siteRedirectsCounts($site) === $before,
        'the dry run did not plan the storage or wrote: ' . json_encode($receipt));
    [$status, $receipt] = siteRedirectsCommand($site['storage'], ['--actor' => $admin]);
    storageViewExpect($status === 0 && $receipt['status'] === 'ready' && $receipt['sites'][0]['state'] === SiteRedirectsStorage::CREATED
        && $receipt['sites'][0]['site_ref'] === SITE_REDIRECTS_SITE && siteRedirectsCounts($site) === ['structures' => 1, 'bindings' => 1, 'record_versions' => 0],
        'the command did not make the storage: ' . json_encode($receipt));
    // An archived «Переадресации» that lost its binding is not bound again: its rules stay off until it is restored.
    $archivedSite = siteRedirectsInstallation();
    $paths[] = $archivedSite['path'];
    $archivedSite['storage']->create($admin, null, 'en');
    $archivedSite['connection']->table('larena_storage_structure_role_bindings')->where('role_ref', 'site_redirect@v1')->delete();
    $made = $archivedSite['workbench']->readStructure(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, $admin);
    $archivedSite['workbench']->archiveStructure(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, $made->version, $admin);
    [$status, $receipt] = siteRedirectsCommand($archivedSite['storage'], ['--actor' => $admin]);
    storageViewExpect($status === 1 && ($receipt['sites'][0]['reason_code'] ?? null) === 'site_redirects_storage_archived'
        && $archivedSite['connection']->table('larena_storage_structure_role_bindings')->where('role_ref', 'site_redirect@v1')->count() === 0,
        'an archived redirects storage was bound again: ' . json_encode($receipt));
    storageViewExpect($site['runtime']['roles']->read(StarterStructureRoles::SITE_REDIRECT_REF) !== null
        && $site['runtime']['roles']->read('redirect@v1') !== null, 'the starter roles are not installed with the storage');
    $after = siteRedirectsCounts($site);
    [$status, $receipt] = siteRedirectsCommand($site['storage'], ['--actor' => $admin]);
    storageViewExpect($status === 0 && $receipt['sites'][0]['state'] === SiteRedirectsStorage::ALREADY_PRESENT && siteRedirectsCounts($site) === $after,
        'a second run of the command wrote');

    // ===== 3. Checks on write: paths, same path, duplicate. =====
    $rule = siteRedirectCreate($site, ['from_address' => ' /About/ ', 'to_address' => '/%D0%BE-%D0%BD%D0%B0%D1%81/']);
    storageViewExpect($rule->values['from_address'] === '/About' && $rule->values['to_address'] === '/о-нас', 'the paths are not normalized: ' . json_encode($rule->values));
    foreach ([
        ['about', '/x', 'exact', 'site_redirect_from_invalid'],
        ['/a//b', '/x', 'exact', 'site_redirect_from_invalid'],
        ['/a?x=1', '/x', 'exact', 'site_redirect_from_invalid'],
        ['/a#top', '/x', 'exact', 'site_redirect_from_invalid'],
        ['/a/../b', '/x', 'exact', 'site_redirect_from_invalid'],
        ['/a', 'x', 'exact', 'site_redirect_to_invalid'],
        ['/a', 'http://example.com/x', 'exact', 'site_redirect_to_invalid'],
        ['/a', 'https://', 'exact', 'site_redirect_to_invalid'],
        ['/a', '/x//y', 'exact', 'site_redirect_to_invalid'],
        ['/a', '/x?y=1', 'prefix', 'site_redirect_to_invalid'],
        ['/a', '/a/', 'exact', 'site_redirect_same_path'],
        ['/same', '/same/?x=1', 'exact', 'site_redirect_same_path'],
        ['/About', '/elsewhere', 'exact', 'site_redirect_duplicate'],
    ] as [$from, $to, $match, $reason]) {
        storageViewRejects(static fn () => siteRedirectCreate($site, ['from_address' => $from, 'to_address' => $to, 'match' => $match]), $reason);
    }
    storageViewRejects(static fn () => siteRedirectCreate($site, ['from_address' => '/a', 'to_address' => '/b', 'status_code' => '307']), 'storage_record_field_invalid');
    $prefixTwin = siteRedirectCreate($site, ['from_address' => '/About', 'to_address' => '/team', 'match' => 'prefix']);
    $offsite = siteRedirectCreate($site, ['from_address' => '/shop', 'to_address' => 'https://shop.example.com/catalog?from=site', 'status_code' => '302']);
    storageViewExpect($prefixTwin->values['match'] === 'prefix' && $offsite->values['to_address'] === 'https://shop.example.com/catalog?from=site',
        'an exact and a prefix rule of one path, or an absolute target, were refused');

    // ===== 4. Cycles. =====
    $ab = siteRedirectCreate($site, ['from_address' => '/a', 'to_address' => '/b']);
    storageViewRejects(static fn () => siteRedirectCreate($site, ['from_address' => '/b', 'to_address' => '/a']), 'site_redirect_cycle');
    storageViewRejects(static fn () => siteRedirectCreate($site, ['from_address' => '/p', 'to_address' => '/p/inner', 'match' => 'prefix']), 'site_redirect_cycle');
    storageViewRejects(static fn () => siteRedirectCreate($site, ['from_address' => '/b', 'to_address' => '/a', 'match' => 'prefix']), 'site_redirect_cycle');
    storageViewRejects(static fn () => siteRedirectUpdate($site, $ab, ['to_address' => '/a']), 'site_redirect_same_path');

    // ===== 5. Chains collapse both ways. =====
    $cd = siteRedirectCreate($site, ['from_address' => '/c', 'to_address' => '/d']);
    $xc = siteRedirectCreate($site, ['from_address' => '/x', 'to_address' => '/c']);
    storageViewExpect($xc->values['to_address'] === '/d', 'A → B while B → C was not stored as A → C: ' . $xc->values['to_address']);
    $yd = siteRedirectCreate($site, ['from_address' => '/y', 'to_address' => '/d?ref=y']);
    siteRedirectPublish($site, $yd);
    siteRedirectPublish($site, $yd, 'ru');
    $de = siteRedirectCreate($site, ['from_address' => '/d', 'to_address' => '/e']);
    $schema = siteRedirectSchema($site);
    $cdNow = siteRedirectRead($site, $cd->recordId);
    $xcNow = siteRedirectRead($site, $xc->recordId);
    $ydNow = siteRedirectRead($site, $yd->recordId);
    storageViewExpect($cdNow->values['to_address'] === '/e' && $cdNow->revision === 2 && $xcNow->values['to_address'] === '/e' && $xcNow->revision === 2
        && $ydNow->values['to_address'] === '/e?ref=y' && $ydNow->revision === 2, 'the rules pointing at /d were not rewritten to /e');
    foreach (['en', 'ru'] as $locale) {
        storageViewExpect($site['publication']->head($schema, $yd->recordId, SITE_REDIRECTS_SITE, $locale)?->publishedRevision === 2,
            'a published rule rewritten by a chain collapse was not published again in ' . $locale);
    }
    storageViewExpect($site['publication']->head($schema, $cd->recordId, SITE_REDIRECTS_SITE, 'en') === null, 'an unpublished rewritten rule was published');
    storageViewRejects(static fn () => siteRedirectCreate($site, ['from_address' => '/e', 'to_address' => '/y']), 'site_redirect_cycle');
    // A prefix rule collapses the rules that point under it.
    $deep = siteRedirectCreate($site, ['from_address' => '/legacy', 'to_address' => '/docs/v/start']);
    siteRedirectCreate($site, ['from_address' => '/docs', 'to_address' => '/guide', 'match' => 'prefix']);
    storageViewExpect(siteRedirectRead($site, $deep->recordId)->values['to_address'] === '/guide/v/start', 'a rule under a new prefix was not rewritten');

    // ===== 6. SiteRedirects::ensure(): an automatic rule, published, idempotent. =====
    $ensured = $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/old-page', '/new-page', 301, 'exact', 'page moved', $admin);
    storageViewExpect($ensured['state'] === SiteRedirects::CREATED && $ensured['revision'] === 1 && $ensured['locales'] === ['en'],
        'ensure() did not create the rule: ' . json_encode($ensured));
    $autoRecord = siteRedirectRead($site, $ensured['record_id']);
    storageViewExpect($autoRecord->values['origin'] === 'auto' && $autoRecord->values['note'] === 'page moved'
        && $site['publication']->head($schema, $ensured['record_id'], SITE_REDIRECTS_SITE, 'en')?->publishedRevision === 1, 'the automatic rule is not published');
    $versions = siteRedirectsCounts($site)['record_versions'];
    $again = $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/old-page/', '/new-page', 301, 'exact', 'page moved', $admin);
    storageViewExpect($again['state'] === SiteRedirects::UNCHANGED && $again['record_id'] === $ensured['record_id']
        && siteRedirectsCounts($site)['record_versions'] === $versions, 'ensure() asked the same again wrote: ' . json_encode($again));
    $moved = $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/old-page', '/c', 302, 'exact', 'page moved again', $admin);
    storageViewExpect($moved['state'] === SiteRedirects::UPDATED && $moved['revision'] === 2 && $moved['to_address'] === '/e'
        && $site['publication']->head($schema, $ensured['record_id'], SITE_REDIRECTS_SITE, 'en')?->publishedRevision === 2,
        'ensure() did not update and publish the rule through the chain: ' . json_encode($moved));
    $same = $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/old-page', '/c', 302, 'exact', 'page moved again', $admin);
    storageViewExpect($same['state'] === SiteRedirects::UNCHANGED, 'ensure() through a chain is not idempotent: ' . json_encode($same));
    storageViewRejects(static fn () => $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/q', '/r', 307, 'exact', 'x', $admin), 'site_redirect_code_invalid');
    storageViewRejects(static fn () => $fresh['redirects']->ensure('site:other', '/q', '/r', 301, 'exact', 'x', $admin), 'site_redirects_storage_missing');

    // ===== 7. The middleware: published rules only, GET and HEAD, never the admin or the API. =====
    siteRedirectNextRequest($site);
    storageViewExpect(siteRedirectAsk($site, '/About') === [200, null], 'an unpublished rule redirected');
    siteRedirectPublish($site, $rule);
    siteRedirectPublish($site, $prefixTwin);
    siteRedirectPublish($site, $offsite);
    siteRedirectPublish($site, siteRedirectRead($site, $xc->recordId));
    $docs = $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/admin-guide', '/guide/admin', 301, 'prefix', 'section moved', $admin);
    storageViewExpect($docs['state'] === SiteRedirects::CREATED, 'the prefix rule was not ensured');
    storageViewExpect(siteRedirectAsk($site, '/About') === [301, '/' . rawurlencode('о-нас')], 'the exact rule did not answer: ' . json_encode(siteRedirectAsk($site, '/About')));
    storageViewExpect(siteRedirectAsk($site, '/About/?utm=1') === [301, '/' . rawurlencode('о-нас') . '?utm=1'], 'the exact rule lost the query');
    storageViewExpect(siteRedirectAsk($site, '/About/people/anna?x=1&y=2') === [301, '/team/people/anna?x=1&y=2'], 'the prefix rule did not swap the prefix');
    storageViewExpect(siteRedirectAsk($site, '/admin-guide/users') === [301, '/guide/admin/users'], 'the longest prefix did not answer');
    storageViewExpect(siteRedirectAsk($site, '/Aboutus') === [200, null], 'a prefix answered a path that only starts with its letters');
    storageViewExpect(siteRedirectAsk($site, '/shop?item=7') === [302, 'https://shop.example.com/catalog?from=site&item=7'], 'the absolute target did not take the query');
    storageViewExpect(siteRedirectAsk($site, '/x') === [301, '/e'], 'a rule rewritten by a collapse did not answer with its final target');
    storageViewExpect(siteRedirectAsk($site, '/About', 'HEAD')[0] === 301, 'a HEAD request was not redirected');
    storageViewExpect(siteRedirectAsk($site, '/About', 'POST') === [200, null], 'a POST was redirected');
    storageViewExpect(siteRedirectAsk($site, '/c') === [200, null], 'an unpublished rule answered');
    $adminRule = siteRedirectCreate($site, ['from_address' => '/admin/storage', 'to_address' => '/elsewhere']);
    siteRedirectPublish($site, $adminRule);
    storageViewExpect(siteRedirectAsk($site, '/admin/storage') === [200, null] && siteRedirectAsk($site, '/api/sites') === [200, null],
        'the admin or the API was redirected');
    $other = new class implements RequestSiteScope {
        public function siteScopeRef(Request $request): string
        {
            return 'site:other';
        }
    };
    storageViewExpect(siteRedirectAsk($site, '/About', 'GET', $other) === [200, null], 'another site\'s request took the main site\'s rules');

    // ===== 8. The map is cached and dropped on a rule's publication change and write. =====
    siteRedirectNextRequest($site);
    siteRedirectAsk($site, '/About');
    siteRedirectNextRequest($site);
    $site['connection']->enableQueryLog();
    $site['connection']->flushQueryLog();
    storageViewExpect(siteRedirectAsk($site, '/About')[0] === 301 && siteRedirectAsk($site, '/missing') === [200, null], 'the cached map does not answer');
    storageViewExpect($site['connection']->getQueryLog() === [], 'a request answered from the cache read the database: ' . json_encode($site['connection']->getQueryLog()));
    $site['connection']->disableQueryLog();
    $site['publication']->unpublish($schema, $rule->recordId, SITE_REDIRECTS_SITE, 'en', $admin);
    // The prefix rule of the same path answers now.
    storageViewExpect(siteRedirectAsk($site, '/About') === [301, '/team'], 'an unpublished rule still answered from the cache');
    siteRedirectNextRequest($site);
    storageViewExpect(siteRedirectAsk($site, '/About') === [301, '/team'], 'the next request kept the map of before the unpublish');
    $late = siteRedirectCreate($site, ['from_address' => '/late', 'to_address' => '/later']);
    siteRedirectPublish($site, $late);
    storageViewExpect(siteRedirectAsk($site, '/late') === [301, '/later'], 'a published rule did not answer at once');
    $site['store']->put(SiteRedirectCache::KEY_PREFIX . SITE_REDIRECTS_SITE, ['exact' => ['/stale' => ['/x', 301, 'record-0']], 'prefix' => []]);
    siteRedirectNextRequest($site);
    storageViewExpect(siteRedirectAsk($site, '/stale')[0] === 301, 'the map was not read from the cache');
    siteRedirectUpdate($site, siteRedirectRead($site, $late->recordId), ['note' => 'checked']);
    siteRedirectNextRequest($site);
    storageViewExpect(siteRedirectAsk($site, '/stale') === [200, null] && siteRedirectAsk($site, '/late') === [301, '/later'], 'a rule\'s write did not drop the map');
    $site['publication']->archive($schema, $late->recordId, SITE_REDIRECTS_SITE, 'en', $admin);
    storageViewExpect(siteRedirectAsk($site, '/late') === [200, null], 'an archived rule still answered');

    // ===== 9. SitePack: the rules move with the site as ordinary records. =====
    $pack = $scratch . '/redirects.zip';
    $run = $site['sitePack']->startExport(SITE_REDIRECTS_SCOPE, SiteRedirectsStorage::STRUCTURE_ID, $admin, null, false);
    $run = $site['sitePack']->run($run->runRef, $admin);
    storageViewExpect($run->isDone(), 'the export of the redirects did not end: ' . json_encode($run->toArray()));
    copy($site['sitePack']->archive($run->runRef, $admin)->path, $pack);
    $target = siteRedirectsInstallation();
    $paths[] = $target['path'];
    (new StarterStructureRoles($target['runtime']['roles']))->install($admin);
    /** @var DatabaseStorageSitePack $targetPack */
    $targetPack = $target['sitePack'];
    $planned = sitePackImportPlan($targetPack, SITE_REDIRECTS_SCOPE, $pack, $admin, new StorageSitePackImportOptions(null, null, StorageSitePackImportOptions::PUBLICATION_KEEP));
    storageViewExpect($planned->plan !== null && $planned->plan->roles === [['role_ref' => StarterStructureRoles::SITE_REDIRECT_REF, 'action' => 'bind']],
        'the plan does not bind the redirects role: ' . json_encode($planned->plan?->toArray()));
    $done = sitePackImportApply($targetPack, $planned, $admin);
    storageViewExpect($done->isDone() && $done->structureId === SiteRedirectsStorage::STRUCTURE_ID, 'the import did not end: ' . json_encode($done->toArray()));
    $moved = $target['rules']->storages(SITE_REDIRECTS_SITE);
    storageViewExpect(count($moved) === 1 && $moved[0]['scope_ref'] === SITE_REDIRECTS_SCOPE, 'the imported storage is not the site\'s redirects');
    $sourceRules = array_map(static fn ($rule): string => $rule->from . ' ' . $rule->match . ' → ' . $rule->to, $site['rules']->published(SITE_REDIRECTS_SITE));
    $targetRules = array_map(static fn ($rule): string => $rule->from . ' ' . $rule->match . ' → ' . $rule->to, $target['rules']->published(SITE_REDIRECTS_SITE));
    sort($sourceRules);
    sort($targetRules);
    storageViewExpect($targetRules === $sourceRules && count($targetRules) > 5, 'the published rules did not move: ' . json_encode([$sourceRules, $targetRules]));
    siteRedirectNextRequest($target);
    storageViewExpect(siteRedirectAsk($target, '/About/people') === [301, '/team/people'] && siteRedirectAsk($target, '/x') === [301, '/e'],
        'the moved site does not answer its redirects');
    // The imported storage is checked like any other.
    storageViewRejects(static fn () => siteRedirectCreate($target, ['from_address' => '/e', 'to_address' => '/x']), 'site_redirect_cycle');
    [$status, $receipt] = siteRedirectsCommand($target['storage'], ['--actor' => $admin]);
    storageViewExpect($status === 0 && $receipt['sites'][0]['state'] === SiteRedirectsStorage::ALREADY_PRESENT, 'the command made a second redirects storage after the import');

    // A site in a folder: its rules name addresses inside it, and the target stays in it (sites plan, step 4.4).
    $site['redirects']->ensure(SITE_REDIRECTS_SITE, '/en/old-folder', '/en/new-folder', 301, 'prefix', 'page moved', $admin);
    $inFolder = static function (string $uri) use ($site): array {
        $request = Request::create($uri);
        $request->attributes->set(CurrentSite::ATTRIBUTE, new CurrentSite(ScopeRef::parse(SITE_REDIRECTS_SITE), null, null, '/shop', SiteMatch::Exact));
        $response = (new SiteRedirectMiddleware($site['maps']['cache'], static fn (): string => SITE_REDIRECTS_SITE))->handle($request, static fn (Request $request): Response => new Response('page'));

        return [$response->getStatusCode(), $response->headers->get('Location')];
    };
    storageViewExpect($inFolder('/shop/en/old-folder/a?x=1') === [301, '/shop/en/new-folder/a?x=1'], 'a site in a folder: the rule answers inside it and leads inside it');
    storageViewExpect($inFolder('/shop/nothing-here')[0] === 200, 'a site in a folder: no rule, no redirect');
    storageViewExpect(siteRedirectAsk($site, '/en/old-folder')[0] === 301, 'a site at the root is as before');

    echo "Site redirects passed.\n";
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
}
