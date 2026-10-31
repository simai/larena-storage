<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Site\DatabaseSiteBindings;
use Larena\Core\Site\SiteBindingsArtifact;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StructureRoleChoice;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\NewSiteStorages;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\SiteRedirect\SiteRedirectRules;
use Larena\Storage\SiteRedirect\SiteRedirectsStorage;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-sitepack-import-fixture.php';

// Sites plan steps 2.8 and 2.9 in Storage:
// - a new site's storages: an ordinary «Страницы сайта» of its own bound to site_node, the content fields, a Home page,
//   «Переадресации»; running again changes nothing;
// - a role is read in the scope asked for, never another site's binding; two sites keep one address apart; where a
//   site has several storages for the role, the choice answers, and a choice bound nowhere is ignored;
// - a site's page tree carries its domains: off by default, added when asked, a domain another site holds refuses the
//   plan by name and nothing moves; a pack without domains plans as before; without the right, refused.

const SEVERAL_SITES_ADMIN = 'user:admin_identity:1';

/** @return array{connection: Connection, runtime: array<string, mixed>, scopes: DatabaseScopeRegistry, bindings: DatabaseSiteBindings, starter: StarterSite, pages: SitePagesStorage, new: NewSiteStorages, path: string} */
function severalSitesInstallation(array $denied = []): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-several-sites-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_several_sites_tempfile_failed');
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
        $core . '/2026_10_30_000001_create_larena_core_site_bindings_table.php',
        $core . '/2026_10_30_000002_create_larena_core_default_site_table.php',
        __DIR__ . '/../../database/migrations/2026_10_29_000001_add_larena_storage_workbench_structure_adoption.php',
    ] as $file) {
        (require $file)->up();
    }
    $runtime = sitePackImportRuntime($connection, new SqliteStorageViewDdl($connection), [SEVERAL_SITES_ADMIN => $denied], [SEVERAL_SITES_ADMIN]);
    $roles = $runtime['roles'];
    $scopes = new DatabaseScopeRegistry($connection);
    $starter = new StarterSite($connection, $runtime['storage'], $roles, new StarterStructureRoles($roles),
        new ScopeBaselineInstaller($connection, $scopes, new DatabasePlaneRegistry($connection, $scopes)));
    $pages = new SitePagesStorage($connection, $runtime['workbench'], static fn (string $actor): string => 'scope:main');
    $redirects = new SiteRedirectsStorage($connection, $runtime['workbench'], $roles, new StarterStructureRoles($roles), new SiteRedirectRules($connection),
        static fn (): array => [], static fn (string $site, string $actor): string => (string) $scopes->read(ScopeRef::parse($site))?->dataRef());

    return ['connection' => $connection, 'runtime' => $runtime, 'scopes' => $scopes, 'bindings' => new DatabaseSiteBindings($connection, $scopes),
        'starter' => $starter, 'pages' => $pages, 'path' => $path,
        'new' => new NewSiteStorages($connection, $runtime['workbench'], $roles, new StarterStructureRoles($roles), $pages, $redirects)];
}

/** A site with its data scope, as core's SiteScopes makes it. @param array<string, mixed> $installation */
function severalSitesSite(array $installation, string $key): ScopeRef
{
    $ref = ScopeRef::of(ScopeKind::Site, $key);
    $installation['scopes']->create($ref, ucfirst($key), SEVERAL_SITES_ADMIN);
    $installation['scopes']->assignLegacyRef($ref, 'scope:' . $key);

    return $ref;
}

/** @param array<string, mixed> $installation */
function severalSitesPage(array $installation, string $site, string $scope, string $schema, string $slug, string $title): void
{
    $record = $installation['runtime']['workbench']->createRecord($scope, SitePagesStorage::STRUCTURE_ID,
        ['slug' => $slug, 'title' => $title, 'order_index' => 0, 'target' => '/' . $slug], SEVERAL_SITES_ADMIN);
    $installation['runtime']['publication']->publish($schema, $record->recordId, $site, 'en', $record->revision, SEVERAL_SITES_ADMIN);
}

/** @param array<string, mixed> $installation */
function severalSitesPlan(array $installation, string $pack, string $scope, string $site, StorageSitePackImportOptions $options): StorageSitePackRun
{
    $sitePack = ($installation['runtime']['sitePack'])(['siteBindings' => $installation['bindings'], 'siteOf' => static fn (string $actor): string => $site]);
    $run = $sitePack->receive($scope, $pack, SEVERAL_SITES_ADMIN);
    $run = $sitePack->plan($run->runRef, $options, SEVERAL_SITES_ADMIN, null, false);

    return $sitePack->run($run->runRef, SEVERAL_SITES_ADMIN);
}

$paths = [];
try {
    // ===== A new site's storages. =====
    $one = severalSitesInstallation();
    $paths[] = $one['path'];
    $one['starter']->apply(SEVERAL_SITES_ADMIN, 'Main', 'en');
    $one['pages']->adopt(SEVERAL_SITES_ADMIN, 'scope:main');
    severalSitesSite($one, 'shop');
    $made = $one['new']->create('site:shop', 'scope:shop', 'Shop', 'ru', SEVERAL_SITES_ADMIN);
    $shopSchema = $made['schema_id'];
    storageViewExpect($shopSchema !== StarterSite::SCHEMA_ID && is_string($made['home_record_id']), 'a new site has a schema of its own and a Home page');
    $structure = $one['runtime']['workbench']->readStructure('scope:shop', SitePagesStorage::STRUCTURE_ID, SEVERAL_SITES_ADMIN);
    storageViewExpect($structure->recordOwnerPrefix === null, 'a new site\'s pages adopt nothing');
    storageViewExpect(array_diff(['slug', 'title', 'order_index', 'target', 'description', ...SitePagesStorage::CONTENT_FIELDS], array_column($structure->fields, 'key')) === [], 'the fields of a site page');
    storageViewExpect($one['new']->unfinished('site:shop') === false && $one['new']->unfinished('site:main') === true, 'what a site still lacks');
    $home = $one['runtime']['workbench']->readRecord('scope:shop', SitePagesStorage::STRUCTURE_ID, $made['home_record_id'], SEVERAL_SITES_ADMIN);
    storageViewExpect($home->values['slug'] === 'home' && $home->values['title'] === 'Главная', 'the Home page in the site\'s language');
    $again = $one['new']->create('site:shop', 'scope:shop', 'Shop', 'ru', SEVERAL_SITES_ADMIN);
    storageViewExpect($again['schema_id'] === $shopSchema && $again['home_record_id'] === null
        && count($one['runtime']['roles']->listStructures('site:shop', 'site_node@v1')) === 1
        && $one['connection']->table('larena_storage_records')->where('schema_id', $shopSchema)->count() === 1, 'running again changes nothing');

    // ===== Roles read in their scope; one address on two sites. =====
    severalSitesPage($one, 'site:main', 'scope:main', StarterSite::SCHEMA_ID, 'about', 'Main about');
    severalSitesPage($one, 'site:shop', 'scope:shop', $shopSchema, 'about', 'Shop about');
    $read = new DatabaseReadContracts($one['connection'], $one['runtime']['localized']);
    $title = static fn (DatabaseReadContracts $reader, string $site): ?string => $reader->resolveKey(StarterSite::ROLE_REF, $site, 'en', 'slug', 'about')?->values['title'] ?? null;
    storageViewExpect($title($read, 'site:main') === 'Main about' && $title($read, 'site:shop') === 'Shop about', 'each site reads its own page');
    try {
        $read->resolveKey(StarterSite::ROLE_REF, 'site:nowhere', 'en', 'slug', 'about');
        storageViewExpect(false, 'a scope with no binding has no pages');
    } catch (ReadContractRejected $rejected) {
        storageViewExpect($rejected->reasonCode === 'unknown_role_binding', 'a scope with no binding is refused');
    }
    $second = $one['runtime']['workbench']->createStructure('scope:shop', ['structure_id' => 'workbench.site_pages_other'] + $one['pages']->descriptor('en'), SEVERAL_SITES_ADMIN);
    $one['runtime']['roles']->bindStructure(StarterSite::ROLE_REF, $second->schema->schemaId, 'site:shop',
        array_map(static fn (array $field): array => ['key' => (string) $field['key'], 'type' => (string) $field['type']], $second->fields),
        SEVERAL_SITES_ADMIN, [StarterStructureRoles::SITE_TREE_RELATION], 'publishable');
    $otherRecord = $one['runtime']['workbench']->createRecord('scope:shop', 'workbench.site_pages_other', ['slug' => 'about', 'title' => 'Other about', 'order_index' => 0, 'target' => '/about'], SEVERAL_SITES_ADMIN);
    $one['runtime']['publication']->publish($second->schema->schemaId, $otherRecord->recordId, 'site:shop', 'en', $otherRecord->revision, SEVERAL_SITES_ADMIN);
    $choosing = static fn (?string $chosen): DatabaseReadContracts => new DatabaseReadContracts($one['connection'], $one['runtime']['localized'], roleChoice: new class ($chosen) implements StructureRoleChoice {
        public function __construct(private ?string $chosen)
        {
        }

        public function chosenSchema(string $roleRef, string $scopeRef): ?string
        {
            return $this->chosen;
        }
    });
    storageViewExpect($title($choosing($second->schema->schemaId), 'site:shop') === 'Other about', 'the choice answers');
    storageViewExpect($title($choosing($shopSchema), 'site:shop') === 'Shop about', 'the choice answers, the other way');
    storageViewExpect($title($choosing(StarterSite::SCHEMA_ID), 'site:shop') !== 'Main about', 'a choice bound elsewhere is ignored');
    storageViewExpect($title($choosing($second->schema->schemaId), 'site:main') === 'Main about', 'a site with one tree is not asked');

    // ===== A site's domains in its pack. =====
    $one['bindings']->replace(ScopeRef::parse('site:main'), [['host' => 'example.ru'], ['host' => 'www.example.ru', 'role' => 'redirect']], SEVERAL_SITES_ADMIN);
    $exporter = ($one['runtime']['sitePack'])(['siteBindings' => $one['bindings']]);
    $run = $exporter->startExport('scope:main', SitePagesStorage::STRUCTURE_ID, SEVERAL_SITES_ADMIN, null, false);
    $run = $exporter->run($run->runRef, SEVERAL_SITES_ADMIN);
    storageViewExpect($run->isDone(), 'the export ended: ' . json_encode($run->toArray()));
    $pack = tempnam(sys_get_temp_dir(), 'larena-several-sites-pack-') . '.zip';
    $paths[] = $pack;
    copy($exporter->archive($run->runRef, SEVERAL_SITES_ADMIN)->path, $pack);
    $zip = new ZipArchive();
    storageViewExpect($zip->open($pack) === true, 'the pack opens');
    $lines = array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) $zip->getFromName(SiteBindingsArtifact::PATH)))));
    $zip->close();
    storageViewExpect(array_column($lines, 'host') === ['example.ru', 'www.example.ru'] && $lines[1]['role'] === 'redirect', 'the pack carries the domains in order');

    // Into a clean installation, off by default.
    $clean = severalSitesInstallation();
    $paths[] = $clean['path'];
    $clean['starter']->apply(SEVERAL_SITES_ADMIN, 'Main', 'en');
    $clean['pages']->adopt(SEVERAL_SITES_ADMIN, 'scope:main');
    $site = ScopeRef::parse('site:main');
    $plan = severalSitesPlan($clean, $pack, 'scope:main', 'site:main', new StorageSitePackImportOptions())->plan;
    storageViewExpect($plan?->domains === ['total' => 2, 'transfer' => false, 'hosts' => ['example.ru', 'www.example.ru']], 'the plan names them, off');
    storageViewExpect($plan->errors === [], 'without the domains nothing is wrong: ' . json_encode($plan->errors));

    // A domain another site holds is an error naming it; apply is refused.
    severalSitesSite($clean, 'other');
    $clean['bindings']->replace(ScopeRef::parse('site:other'), [['host' => 'www.example.ru']], SEVERAL_SITES_ADMIN);
    $taken = severalSitesPlan($clean, $pack, 'scope:main', 'site:main', new StorageSitePackImportOptions(transferDomains: true));
    storageViewExpect($taken->plan?->errors === [['code' => 'site_domain_taken', 'count' => 1, 'subject' => 'www.example.ru']], 'named: ' . json_encode($taken->plan?->errors));
    $sitePack = ($clean['runtime']['sitePack'])(['siteBindings' => $clean['bindings']]);
    try {
        $sitePack->apply($taken->runRef, (string) $taken->planDigest, SEVERAL_SITES_ADMIN, null, false);
        storageViewExpect(false, 'a plan with a taken domain does not apply');
    } catch (StorageRejected $rejected) {
        storageViewExpect($rejected->reasonCode === 'storage_sitepack_plan_has_errors', 'refused as a plan with errors');
    }
    storageViewExpect($clean['bindings']->list($site) === [], 'nothing moved');

    // Free again: asked for, the domains come with the import.
    $clean['bindings']->replace(ScopeRef::parse('site:other'), [], SEVERAL_SITES_ADMIN);
    $asked = severalSitesPlan($clean, $pack, 'scope:main', 'site:main', new StorageSitePackImportOptions(transferDomains: true));
    storageViewExpect($asked->plan?->ok() === true && $asked->plan->domains['transfer'] === true, 'the plan may go: ' . json_encode($asked->plan?->errors));
    $sitePack->apply($asked->runRef, (string) $asked->planDigest, SEVERAL_SITES_ADMIN, null, false);
    $done = $sitePack->run($asked->runRef, SEVERAL_SITES_ADMIN);
    storageViewExpect($done->state === StorageSitePackRun::STATE_DONE, 'the import ended: ' . json_encode($done->toArray()));
    storageViewExpect(array_map(static fn ($binding): string => $binding->host, $clean['bindings']->list($site)) === ['example.ru', 'www.example.ru'], 'the domains came');
    storageViewExpect(($done->counts['domains_added'] ?? 0) === 2, 'and are counted');

    // A domain taken between the plan and the import fails the import whole.
    $late = severalSitesInstallation();
    $paths[] = $late['path'];
    $late['starter']->apply(SEVERAL_SITES_ADMIN, 'Main', 'en');
    $late['pages']->adopt(SEVERAL_SITES_ADMIN, 'scope:main');
    severalSitesSite($late, 'other');
    $racing = severalSitesPlan($late, $pack, 'scope:main', 'site:main', new StorageSitePackImportOptions(transferDomains: true));
    $late['bindings']->replace(ScopeRef::parse('site:other'), [['host' => 'example.ru']], SEVERAL_SITES_ADMIN);
    $lateSitePack = ($late['runtime']['sitePack'])(['siteBindings' => $late['bindings']]);
    $lateSitePack->apply($racing->runRef, (string) $racing->planDigest, SEVERAL_SITES_ADMIN, null, false);
    try {
        $lateSitePack->run($racing->runRef, SEVERAL_SITES_ADMIN);
    } catch (Throwable) {
    }
    $failed = $lateSitePack->read($racing->runRef, SEVERAL_SITES_ADMIN);
    storageViewExpect($failed->state === StorageSitePackRun::STATE_FAILED && $failed->reason === 'storage_sitepack_site_domains_refused', 'failed: ' . json_encode($failed->toArray()));
    storageViewExpect($late['bindings']->list(ScopeRef::parse('site:main')) === [], 'no domain stayed');

    // Without the right to change domains the plan says so.
    $denied = severalSitesInstallation(['admin.site_settings.write']);
    $paths[] = $denied['path'];
    $denied['starter']->apply(SEVERAL_SITES_ADMIN, 'Main', 'en');
    $denied['pages']->adopt(SEVERAL_SITES_ADMIN, 'scope:main');
    $refused = severalSitesPlan($denied, $pack, 'scope:main', 'site:main', new StorageSitePackImportOptions(transferDomains: true));
    storageViewExpect($refused->plan?->errorCodes() === ['site_domains_denied'], 'denied: ' . json_encode($refused->plan?->errors));

    fwrite(STDOUT, "SeveralSitesStorageTest passed\n");
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
}
