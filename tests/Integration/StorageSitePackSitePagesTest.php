<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackExporter;
use Larena\Storage\SitePack\StorageSitePackFormat;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-sitepack-import-fixture.php';

// Moving a site's pages with their content (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, step 1.6):
// - the export of the adopted site pages says so (structure `schema_id`, `adoption`; each record its `owner_ref`);
// - into a clean installation the pack goes into its adopted «Страницы сайта» (id map `merge`): no new structure or
//   schema, the untouched starter Home page replaced, every page id, value, translation, tree edge and publication
//   (`keep`, a draft newer than the published revision) as on the source, owner refs from the slug (the pack's owner
//   ref, else a random one, when the slug's is taken), the site_node role `already_bound` and bound once, a list
//   block's storage mapped to the one the earlier import made of it (`list_target_unresolved` for an unknown one);
// - refused: a site with pages of its own (`storage_sitepack_site_pages_not_empty`, an edited Home included), site
//   pages without the content fields (`storage_sitepack_site_pages_fields_missing`), not adopted
//   (`storage_sitepack_site_pages_not_adopted`); a pack from before (no `adoption`) still imports as a new storage;
// - a cancelled merge removes only the records it wrote; the adopted structure stays.

const SITE_PAGES_PACK_ADMIN = 'user:admin_identity:1';
const SITE_PAGES_PACK_SCOPE = 'scope:main';
const SITE_PAGES_PACK_SITE = 'site:main';

/**
 * One installation on its own SQLite file: the SitePack runtime, the starter site and the site pages storage.
 *
 * @return array{connection: Connection, runtime: array<string, mixed>, sitePack: DatabaseStorageSitePack, starter: StarterSite, pages: SitePagesStorage, path: string}
 */
function sitePagesPackInstallation(bool $starter = true, bool $adopt = true): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-sitepack-pages-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_sitepack_pages_tempfile_failed');
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
    $admin = SITE_PAGES_PACK_ADMIN;
    $runtime = sitePackImportRuntime($connection, new SqliteStorageViewDdl($connection), [$admin => []], [$admin]);
    $roles = $runtime['roles'];
    $scopes = new DatabaseScopeRegistry($connection);
    $starterSite = new StarterSite($connection, $runtime['storage'], $roles, new StarterStructureRoles($roles),
        new ScopeBaselineInstaller($connection, $scopes, new DatabasePlaneRegistry($connection, $scopes)));
    $pages = new SitePagesStorage($connection, $runtime['workbench'], static fn (string $actor): string => SITE_PAGES_PACK_SCOPE);
    if ($starter) {
        $starterSite->apply($admin, 'Site', 'en');
        if ($adopt) {
            $pages->adopt($admin);
        }
    }

    return ['connection' => $connection, 'runtime' => $runtime, 'sitePack' => ($runtime['sitePack'])(), 'starter' => $starterSite, 'pages' => $pages, 'path' => $path];
}

/** @param array<string, mixed> $installation @param array<string, mixed> $values */
function sitePagesPackCreate(array $installation, array $values): StorageWorkbenchRecord
{
    return $installation['runtime']['workbench']->createRecord(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, $values, SITE_PAGES_PACK_ADMIN);
}

/** @param array<string, mixed> $installation @param array<string, mixed> $changes */
function sitePagesPackUpdate(array $installation, StorageWorkbenchRecord $record, array $changes): StorageWorkbenchRecord
{
    $values = array_filter($record->values, static fn (string|int $key): bool => !str_starts_with((string) $key, 'larena_'), ARRAY_FILTER_USE_KEY);

    return $installation['runtime']['workbench']->updateRecord(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, $record->recordId,
        $record->revision, $changes + $values, SITE_PAGES_PACK_ADMIN);
}

/** @param array<string, mixed> $installation */
function sitePagesPackPublish(array $installation, StorageWorkbenchRecord $record, string $locale = 'en'): void
{
    $installation['runtime']['publication']->publish(StarterSite::SCHEMA_ID, $record->recordId, SITE_PAGES_PACK_SITE, $locale, $record->revision, SITE_PAGES_PACK_ADMIN);
}

/** Exports one structure of the scope in this process and copies the archive to $target. @param array<string, mixed> $installation */
function sitePagesPackExport(array $installation, string $structureId, string $target): void
{
    $sitePack = $installation['sitePack'];
    $run = $sitePack->startExport(SITE_PAGES_PACK_SCOPE, $structureId, SITE_PAGES_PACK_ADMIN, null, false);
    $run = $sitePack->run($run->runRef, SITE_PAGES_PACK_ADMIN);
    storageViewExpect($run->isDone(), 'the export of ' . $structureId . ' did not end: ' . json_encode($run->toArray()));
    copy($sitePack->archive($run->runRef, SITE_PAGES_PACK_ADMIN)->path, $target);
}

/**
 * What a visitor and the admin read of the pages: by page id, its owner ref's end aside, the current values (reserved
 * fields aside), each language's published values, translations by revision order and the parent.
 *
 * @return array<string, array<string, mixed>>
 */
function sitePagesPackSnapshot(Connection $connection): array
{
    $pages = [];
    foreach ($connection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->orderBy('record_id')->get() as $head) {
        $id = (string) $head->record_id;
        $versions = [];
        foreach ($connection->table('larena_storage_record_versions')->where('schema_id', StarterSite::SCHEMA_ID)->where('record_id', $id)->orderBy('revision')->get() as $row) {
            $values = json_decode((string) $row->values_json, true);
            $versions[(int) $row->revision] = array_filter($values, static fn (string $key): bool => !str_starts_with($key, 'larena_'), ARRAY_FILTER_USE_KEY);
            ksort($versions[(int) $row->revision]);
        }
        $translations = [];
        foreach ($connection->table('larena_storage_localized_values')->where('schema_id', StarterSite::SCHEMA_ID)->where('record_id', $id)->get() as $row) {
            $translations[(string) $row->locale][(string) $row->field_key][(int) $row->revision] = json_decode((string) $row->value_json, true);
        }
        ksort($translations);
        foreach ($translations as $locale => $fields) {
            ksort($translations[$locale]);
        }
        $published = [];
        foreach ($connection->table('larena_storage_publication_states')->where('schema_id', StarterSite::SCHEMA_ID)->where('record_id', $id)->get() as $state) {
            $revision = $state->published_revision ?? null;
            $published[(string) $state->locale] = is_numeric($revision) ? [
                'values' => $versions[(int) $revision] ?? null,
                'translations' => array_map(static fn (array $fields): array => array_map(static fn (array $byRevision): mixed => $byRevision[(int) $revision] ?? null, $fields), $translations),
            ] : null;
        }
        ksort($published);
        $parent = $connection->table('larena_storage_record_relations')->where('relation_key', 'site_tree_parent')->where('from_record_id', $id)->value('to_record_id');
        $pages[$id] = [
            'current' => $versions[(int) $head->current_revision],
            'draft_after_published' => array_map(static fn (?array $state): bool => $state !== null && $state['values'] !== $versions[(int) $head->current_revision], $published),
            'published' => $published,
            'parent' => $parent === null ? null : (string) $parent,
        ];
    }

    return $pages;
}

/** @param array<string, mixed> $installation @return list<array<string, mixed>> the blocks of a page */
function sitePagesPackBlocks(array $installation, string $recordId): array
{
    $record = $installation['runtime']['workbench']->readRecord(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, $recordId, SITE_PAGES_PACK_ADMIN);

    return array_values($record->values['blocks'] ?? []);
}

$paths = [];
$scratch = sys_get_temp_dir() . '/larena-storage-sitepack-pages-' . bin2hex(random_bytes(6));
StorageSitePackExporter::directory($scratch);
$admin = SITE_PAGES_PACK_ADMIN;
$keep = new StorageSitePackImportOptions(null, null, StorageSitePackImportOptions::PUBLICATION_KEEP);
try {
    // ===== The source: Home with text, About → Team, a blocks page listing items and the pages, a translation, a draft newer than published. =====
    $source = sitePagesPackInstallation();
    $paths[] = $source['path'];
    $connection = $source['connection'];
    $workbench = $source['runtime']['workbench'];
    $items = $workbench->createStructure(SITE_PAGES_PACK_SCOPE, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
    ]], $admin);
    $item = $workbench->createRecord(SITE_PAGES_PACK_SCOPE, 'workbench.items', ['title' => 'First item'], $admin);
    $homeId = (string) $connection->table('larena_storage_records')->where('owner_ref', StarterSite::RECORD_OWNER_REF)->value('record_id');
    $home = $workbench->readRecord(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, $homeId, $admin);
    $home = sitePagesPackUpdate($source, $home, ['content_mode' => 'text', 'body' => '<p>Welcome home</p>']);
    sitePagesPackPublish($source, $home);
    $about = sitePagesPackCreate($source, ['slug' => 'about', 'title' => 'About', 'order_index' => 1, 'target' => '/about', 'content_mode' => 'text', 'body' => '<p>About us</p>']);
    $source['runtime']['localized']->write(StarterSite::SCHEMA_ID, $about->recordId, $about->revision, 'ru', ['title' => 'О нас', 'body' => '<p>О нас</p>'], ['title', 'description', 'body', 'blocks'], $admin);
    sitePagesPackPublish($source, $about);
    sitePagesPackPublish($source, $about, 'ru');
    // A draft newer than the published revision.
    sitePagesPackUpdate($source, $about, ['body' => '<p>About us, draft</p>']);
    $team = sitePagesPackCreate($source, ['slug' => 'team', 'title' => 'Team', 'order_index' => 1, 'target' => '/about/team', 'body' => '<p>Team</p>']);
    sitePagesPackPublish($source, $team);
    $connection->table('larena_storage_record_relations')->insert([
        'relation_id' => 'site_tree_parent|' . $team->recordId . '|' . $about->recordId, 'relation_key' => 'site_tree_parent', 'schema_id' => StarterSite::SCHEMA_ID,
        'from_record_id' => $team->recordId, 'to_record_id' => $about->recordId, 'kind' => 'tree_parent', 'tree_child_key' => $team->recordId,
        'path' => $about->recordId . '/' . $team->recordId, 'depth' => 1, 'order_index' => 0, 'delete_policy' => 'detach', 'status' => 'active',
        'created_by' => $admin, 'correlation_id' => null, 'created_at' => '2026-10-30 08:00:00', 'updated_at' => '2026-10-30 08:00:00',
    ]);
    $unknown = 'workbench.scoped.v1.' . str_repeat('0', 64);
    $news = sitePagesPackCreate($source, ['slug' => 'news', 'title' => 'News', 'order_index' => 2, 'target' => '/news', 'content_mode' => 'blocks', 'blocks' => [
        ['type' => 'larena.list', 'version' => 1, 'params' => ['target' => $items->schema->schemaId, 'query' => ['limit' => 5], 'title' => 'Items']],
        ['type' => 'larena.list', 'version' => 1, 'params' => ['target' => 'site_node@v1', 'query' => ['limit' => 5], 'title' => 'Pages']],
        ['type' => 'larena.list', 'version' => 1, 'params' => ['target' => $unknown, 'query' => ['limit' => 5]]],
    ]]);
    sitePagesPackPublish($source, $news);
    // Owner refs: a renamed page keeps its old one on the source; two drafts share a slug.
    $renamed = sitePagesPackUpdate($source, sitePagesPackCreate($source, ['slug' => 'old-name', 'title' => 'Renamed', 'order_index' => 3, 'target' => '/renamed']), ['slug' => 'renamed']);
    $twinOne = sitePagesPackCreate($source, ['slug' => 'twin', 'title' => 'Twin one', 'order_index' => 4, 'target' => '/twin']);
    $twinTwo = sitePagesPackUpdate($source, sitePagesPackCreate($source, ['slug' => 'twin-two', 'title' => 'Twin two', 'order_index' => 5, 'target' => '/twin-two']), ['slug' => 'twin']);
    $expected = sitePagesPackSnapshot($connection);
    storageViewExpect(count($expected) === 7 && $expected[$about->recordId]['draft_after_published'] === ['en' => true, 'ru' => true]
        && $expected[$team->recordId]['parent'] === $about->recordId, 'the source pages are off: ' . json_encode($expected));

    $pagesPack = $scratch . '/pages.zip';
    $itemsPack = $scratch . '/items.zip';
    sitePagesPackExport($source, SitePagesStorage::STRUCTURE_ID, $pagesPack);
    sitePagesPackExport($source, 'workbench.items', $itemsPack);
    $reader = Larena\Core\SitePack\SitePackArchiveReader::open($pagesPack);
    $annotations = $reader->envelope->annotations;
    $reader->close();
    storageViewExpect(($annotations['schema_minor'] ?? null) === StorageSitePackFormat::SCHEMA_MINOR, 'the pack does not say its format minor: ' . json_encode($annotations));
    $line = sitePackLines($pagesPack, StorageSitePackFormat::STRUCTURES)[0];
    storageViewExpect(($line['schema_id'] ?? null) === StarterSite::SCHEMA_ID
        && ($line['adoption'] ?? null) === ['schema_id' => StarterSite::SCHEMA_ID, 'owner_prefix' => 'site.pages:', 'owner_field' => 'slug'],
        'the structure line does not say it adopted the site pages: ' . json_encode($line));
    $ownerRefs = [];
    foreach (sitePackLines($pagesPack, StorageSitePackFormat::recordsPart(StorageSitePackFormat::structureKey(SitePagesStorage::STRUCTURE_ID), 1)) as $record) {
        $ownerRefs[$record['id']] = $record['owner_ref'] ?? null;
    }
    storageViewExpect($ownerRefs[$homeId] === 'site.pages:home' && $ownerRefs[$renamed->recordId] === 'site.pages:old-name' && count($ownerRefs) === 7,
        'the record lines do not carry their owner refs: ' . json_encode($ownerRefs));
    $itemsLine = sitePackLines($itemsPack, StorageSitePackFormat::STRUCTURES)[0];
    storageViewExpect(($itemsLine['schema_id'] ?? null) === $items->schema->schemaId && !array_key_exists('adoption', $itemsLine),
        'an ordinary structure line is off: ' . json_encode($itemsLine));

    // ===== A clean installation: its own items storage takes the ID, the items come as workbench.items_2, then the pages. =====
    $target = sitePagesPackInstallation();
    $paths[] = $target['path'];
    $targetConnection = $target['connection'];
    $targetWorkbench = $target['runtime']['workbench'];
    $starterHome = (string) $targetConnection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->value('record_id');
    $targetWorkbench->createStructure(SITE_PAGES_PACK_SCOPE, ['structure_id' => 'workbench.items', 'label' => 'Local items', 'fields' => [
        storageViewField('name', 10, 'string', ['max_length' => 200]),
    ]], $admin);
    $itemsRun = sitePackImportApply($target['sitePack'], sitePackImportPlan($target['sitePack'], SITE_PAGES_PACK_SCOPE, $itemsPack, $admin, $keep), $admin);
    storageViewExpect($itemsRun->isDone() && $itemsRun->structureId === 'workbench.items_2', 'the items did not come as workbench.items_2: ' . json_encode($itemsRun->toArray()));
    $itemsHere = sitePackImportStructures($targetConnection, SITE_PAGES_PACK_SCOPE)['workbench.items_2'];
    $structuresBefore = $targetConnection->table('larena_storage_workbench_structures')->count();
    $schemasBefore = $targetConnection->table('larena_storage_schemas')->count();

    $planned = sitePackImportPlan($target['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin, $keep);
    $plan = $planned->plan;
    storageViewExpect($plan !== null && $plan->ok(), 'the pages plan has errors: ' . json_encode($plan?->toArray()));
    storageViewExpect($plan->structure['merge'] === true && $plan->structure['target_id'] === SitePagesStorage::STRUCTURE_ID && $plan->structure['kept'] === false
        && $plan->records['total'] === 7 && $plan->records['keep'] === 7 && $plan->records['replaced'] === 1
        && $plan->roles === [['role_ref' => StarterSite::ROLE_REF, 'action' => 'already_bound']]
        && sitePackImportCodes($plan->warnings) === ['list_target_unresolved'],
        'the pages plan is off: ' . json_encode($plan->toArray()));
    $ids = $targetConnection->table('larena_storage_sitepack_ids')->where('run_ref', $planned->runRef)->whereIn('kind', ['structure', 'replaced'])
        ->orderBy('kind')->get(['kind', 'source_ref', 'target_ref', 'action'])->map(static fn ($row): array => (array) $row)->all();
    storageViewExpect($ids === [
        ['kind' => 'replaced', 'source_ref' => $starterHome, 'target_ref' => null, 'action' => 'replaced'],
        ['kind' => 'structure', 'source_ref' => SitePagesStorage::STRUCTURE_ID, 'target_ref' => SitePagesStorage::STRUCTURE_ID, 'action' => 'merge'],
    ], 'the id map of the merge is off: ' . json_encode($ids));
    $done = sitePackImportApply($target['sitePack'], $planned, $admin);
    storageViewExpect($done->isDone() && ($done->counts['records_replaced'] ?? null) === 1 && ($done->counts['records'] ?? null) === 7,
        'the pages import did not end: ' . json_encode($done->toArray()));
    storageViewExpect($targetConnection->table('larena_storage_workbench_structures')->count() === $structuresBefore
        && $targetConnection->table('larena_storage_schemas')->count() === $schemasBefore, 'the merge made a structure or a schema');
    storageViewExpect(!$targetConnection->table('larena_storage_records')->where('record_id', $starterHome)->exists(), 'the starter Home page stayed');
    $actual = sitePagesPackSnapshot($targetConnection);
    $strip = static function (array $pages) use ($news): array {
        // The blocks page's list target is the storage's here: compared below.
        foreach ($pages as $id => $page) {
            if ($id === $news->recordId) {
                unset($pages[$id]['current']['blocks']);
                foreach ($pages[$id]['published'] as $locale => $state) {
                    unset($pages[$id]['published'][$locale]['values']['blocks']);
                }
            }
        }

        return $pages;
    };
    storageViewExpect($strip($actual) === $strip($expected), 'the pages are not those of the source: ' . json_encode(['actual' => $actual, 'expected' => $expected]));
    $owners = $targetConnection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->pluck('owner_ref', 'record_id')->all();
    // One twin gets the slug's owner ref; the other its own from the pack (twin-two), or, when that is the first
    // twin's (site.pages:twin, taken), a random one.
    $twins = array_values(array_diff([$owners[$twinOne->recordId], $owners[$twinTwo->recordId]], ['site.pages:twin']));
    storageViewExpect($owners[$homeId] === 'site.pages:home' && $owners[$about->recordId] === 'site.pages:about' && $owners[$renamed->recordId] === 'site.pages:renamed'
        && count($twins) === 1 && ($twins[0] === 'site.pages:twin-two' || preg_match('/\Asite\.pages:[a-f0-9]{32}\z/D', $twins[0]) === 1),
        'the owner refs are off: ' . json_encode($owners));
    $bindings = $targetConnection->table('larena_storage_structure_role_bindings')->where('role_ref', StarterSite::ROLE_REF)->where('status', 'active')->get(['schema_id'])->all();
    storageViewExpect(count($bindings) === 1 && $bindings[0]->schema_id === StarterSite::SCHEMA_ID, 'site_node is bound more than once: ' . json_encode($bindings));
    $blocks = sitePagesPackBlocks($target, $news->recordId);
    storageViewExpect(array_map(static fn (array $block): string => $block['params']['target'], $blocks) === [$itemsHere, 'site_node@v1', $unknown],
        'the list targets are not mapped: ' . json_encode($blocks));

    // ===== Again: the site has pages now. =====
    $again = sitePackImportPlan($target['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin, new StorageSitePackImportOptions(null, null, 'keep', [], true));
    storageViewExpect($again->plan !== null && in_array('storage_sitepack_site_pages_not_empty', $again->plan->errorCodes(), true),
        'a second import into a used site was not refused: ' . json_encode($again->plan?->errors));

    // ===== Refused: an edited Home; site pages without content fields; not adopted. =====
    $edited = sitePagesPackInstallation();
    $paths[] = $edited['path'];
    $editedHome = (string) $edited['connection']->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->value('record_id');
    sitePagesPackUpdate($edited, $edited['runtime']['workbench']->readRecord(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, $editedHome, $admin), ['title' => 'Mine']);
    $refused = sitePackImportPlan($edited['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin, $keep);
    storageViewExpect($refused->plan?->errorCodes() === ['storage_sitepack_site_pages_not_empty'], 'an edited Home page was replaced: ' . json_encode($refused->plan?->errors));

    $bare = sitePagesPackInstallation(true, false);
    $paths[] = $bare['path'];
    $notAdopted = sitePackImportPlan($bare['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin, $keep);
    storageViewExpect($notAdopted->plan?->errorCodes() === ['storage_sitepack_site_pages_not_adopted'], 'site pages that are not a storage were not refused: ' . json_encode($notAdopted->plan?->errors));
    $bare['runtime']['workbench']->adoptSchema(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, StarterSite::SCHEMA_ID, 'Site pages',
        SitePagesStorage::OWNER_REF_PREFIX, $admin, SitePagesStorage::OWNER_REF_FIELD);
    $noFields = sitePackImportPlan($bare['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin, $keep);
    $missing = array_map(static fn (array $error): ?string => $error['subject'], $noFields->plan->errors ?? []);
    $content = [...SitePagesStorage::CONTENT_FIELDS, SitePagesStorage::RECORDS_FIELD, SitePagesStorage::MENU_FIELD, ...\Larena\Storage\SitePage\SitePageSeo::FIELDS];
    sort($missing);
    sort($content);
    storageViewExpect($noFields->plan?->errorCodes() === ['storage_sitepack_site_pages_fields_missing'] && $missing === $content,
        'site pages without the content fields were not refused: ' . json_encode($noFields->plan?->errors));

    // ===== Without site pages at all, the pack is an ordinary storage, as a pack from before is. =====
    $empty = sitePagesPackInstallation(false);
    $paths[] = $empty['path'];
    $ordinary = sitePackImportPlan($empty['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin);
    storageViewExpect($ordinary->plan !== null && $ordinary->plan->structure['merge'] === false && $ordinary->plan->structure['target_id'] === SitePagesStorage::STRUCTURE_ID
        && $ordinary->plan->ok(), 'a pack of site pages into an installation without them is off: ' . json_encode($ordinary->plan?->toArray()));

    // ===== A cancelled merge removes only its own records; the structure and the replaced Home's absence stay. =====
    $cancelling = sitePagesPackInstallation();
    $paths[] = $cancelling['path'];
    $cancelConnection = $cancelling['connection'];
    $pending = sitePackImportPlan($cancelling['sitePack'], SITE_PAGES_PACK_SCOPE, $pagesPack, $admin, $keep);
    storageViewExpect($pending->plan?->ok() === true, 'the cancelled merge did not plan: ' . json_encode($pending->plan?->errors));
    $cancelling['sitePack']->apply($pending->runRef, (string) $pending->planDigest, $admin, null, false);
    $ticks = 0;
    sitePackRejects(static function () use ($cancelling, $pending, $admin, &$ticks): void {
        $cancelling['sitePack']->process($pending->runRef, static function () use ($cancelling, $pending, $admin, &$ticks): void {
            if (++$ticks === 4) {
                $cancelling['sitePack']->cancel($pending->runRef, $admin);
            }
        });
    }, 'storage_sitepack_interrupted', 'the cancelled merge went on');
    $cancelled = $cancelling['sitePack']->read($pending->runRef, $admin);
    storageViewExpect($cancelled->state === StorageSitePackRun::STATE_CANCELLED && $cancelled->done > 0, 'the merge was not cancelled mid-import: ' . json_encode($cancelled->toArray()));
    storageViewExpect($cancelConnection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->count() === 0
        && $cancelConnection->table('larena_storage_record_versions')->where('schema_id', StarterSite::SCHEMA_ID)->count() === 0
        && $cancelConnection->table('larena_storage_localized_values')->where('schema_id', StarterSite::SCHEMA_ID)->count() === 0,
        'the cancelled merge left records behind: ' . json_encode([$cancelConnection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->get()->all(),
            $cancelConnection->table('larena_storage_record_versions')->where('schema_id', StarterSite::SCHEMA_ID)->get(['record_id', 'revision', 'correlation_id'])->all(),
            $cancelConnection->table('larena_storage_sitepack_runs')->where('run_ref', $pending->runRef)->value('correlation_id')]));
    storageViewExpect($cancelling['runtime']['workbench']->readStructure(SITE_PAGES_PACK_SCOPE, SitePagesStorage::STRUCTURE_ID, $admin)->adopted()
        && $cancelConnection->table('larena_storage_schemas')->where('schema_id', StarterSite::SCHEMA_ID)->exists()
        && $cancelConnection->table('larena_storage_structure_role_bindings')->where('schema_id', StarterSite::SCHEMA_ID)->count() === 1,
        'the cancelled merge removed the adopted structure');
    // Retried, it plans again into the now empty pages and ends.
    $cancelling['sitePack']->retry($pending->runRef, $admin, null, false);
    $replanned = $cancelling['sitePack']->run($pending->runRef, $admin);
    storageViewExpect($replanned->plan?->ok() === true && $replanned->plan->records['replaced'] === 0, 'the retried merge did not plan: ' . json_encode($replanned->plan?->toArray()));
    $resumed = sitePackImportApply($cancelling['sitePack'], $replanned, $admin);
    storageViewExpect($resumed->isDone() && $cancelConnection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->count() === 7,
        'the retried merge did not end: ' . json_encode($resumed->toArray()));
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
    StorageSitePackExporter::wipe($scratch);
}

echo "Storage SitePack site pages passed.\n";
