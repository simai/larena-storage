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
use Larena\Core\Site\ConfiguredSiteLanguages;
use Larena\Core\Site\SiteLanguageFolders;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\SitePage\SitePageAddressCache;
use Larena\Storage\SitePage\SitePageAddressChanges;
use Larena\Storage\SitePage\SitePageAddresses;
use Larena\Storage\SitePage\SitePagePlacement;
use Larena\Storage\SitePage\SitePageRecordValidator;
use Larena\Storage\SitePage\SitePageRules;
use Larena\Storage\SitePage\SitePageTree;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-sitepack-import-fixture.php';
require_once __DIR__ . '/../Support/storage-encryption-fixture.php';

// A page in several languages (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, steps 4.1 and 4.3):
// - an edit keeps the translations of the other languages on the next revision, in the same row bytes, so encrypted ones
//   still open; a draft's inherited row gives way to a translation written for it; a revision that went out stays as it is;
// - updateRecordLanguage() writes one language as one revision: the main language the shared values, another its rows;
// - slug and content_mode are localized on new storages and, with the command, on every storage bound to the role, with
//   no record rewritten; the shared slug keeps answering where a language has none of its own;
// - the slug's rules are per language: taken or free as the siblings show there; a language's code is reserved; Home is
//   `home` in every language; a translated slug is refused on a revision that is out.

const SITE_LANGUAGES_ADMIN = 'user:admin_identity:1';
const SITE_LANGUAGES_SCOPE = 'scope:minimal-cms';
const SITE_LANGUAGES_SITE = 'site:main';

/** @return array<string, mixed> */
function sitePageLanguagesInstallation(): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'larena-page-languages-');
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
    $capsule->setAsGlobal();
    /** @var Connection $connection */
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
    $holder = new stdClass();
    $runtime = sitePackImportRuntime($connection, new SqliteStorageViewDdl($connection), [SITE_LANGUAGES_ADMIN => []], [SITE_LANGUAGES_ADMIN], 'site:main',
        static fn (): array => [$holder->validator], true);
    $roles = $runtime['roles'];
    $scopes = new DatabaseScopeRegistry($connection);
    $starter = new StarterSite($connection, $runtime['storage'], $roles, new StarterStructureRoles($roles),
        new ScopeBaselineInstaller($connection, $scopes, new DatabasePlaneRegistry($connection, $scopes)));
    $read = new DatabaseReadContracts($connection, $runtime['localized']);
    $tree = new SitePageTree($connection, $read);
    $addresses = new SitePageAddresses($tree, $read);
    $rules = new SitePageRules($tree, $addresses, $read, static fn (): array => ['admin'], new ConfiguredSiteLanguages(['ru', 'en'], 'ru'));
    $holder->validator = new SitePageRecordValidator($rules, $tree, $addresses, new SitePagePlacement());

    return ['path' => $path, 'connection' => $connection, 'runtime' => $runtime, 'starter' => $starter, 'tree' => $tree, 'addresses' => $addresses, 'rules' => $rules, 'read' => $read,
        'pages' => new SitePagesStorage($connection, $runtime['workbench'], static fn (string $actor): string => SITE_LANGUAGES_SCOPE)];
}

/** @param array<string, mixed> $site */
function sitePageLanguagesRows(array $site, string $recordId, int $revision, string $locale): array
{
    $rows = [];
    foreach ($site['runtime']['localized']->read(StarterSite::SCHEMA_ID, $recordId, $revision, $locale) as $key => $value) {
        $rows[$key] = $value->value;
    }

    return $rows;
}

$paths = [];
try {
    $site = sitePageLanguagesInstallation();
    $paths[] = $site['path'];
    $connection = $site['connection'];
    $site['starter']->apply(SITE_LANGUAGES_ADMIN, 'Main', 'ru');
    $site['pages']->adopt(SITE_LANGUAGES_ADMIN, SITE_LANGUAGES_SCOPE);
    $workbench = $site['runtime']['workbench'];
    $localized = $site['runtime']['localized'];
    $publication = $site['runtime']['publication'];
    $structure = SitePagesStorage::STRUCTURE_ID;
    $schema = StarterSite::SCHEMA_ID;

    // ===== A new storage has the fields per language. =====
    $fields = array_column($workbench->readStructure(SITE_LANGUAGES_SCOPE, $structure, SITE_LANGUAGES_ADMIN)->fields, null, 'key');
    storageViewExpect($fields['slug']['localized'] === true && $fields['content_mode']['localized'] === true && $fields['target']['localized'] !== true, 'slug and content_mode are per language');

    // ===== 4.1: an edit keeps the translations. =====
    $about = $workbench->createRecord(SITE_LANGUAGES_SCOPE, $structure, ['slug' => 'o-nas', 'title' => 'О нас', 'order_index' => 1, 'target' => '/o-nas'], SITE_LANGUAGES_ADMIN);
    $localized->write($schema, $about->recordId, $about->revision, 'en', ['title' => 'About'], [], SITE_LANGUAGES_ADMIN);
    $edited = $workbench->updateRecord(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $about->revision,
        ['slug' => 'o-nas', 'title' => 'О компании', 'order_index' => 1, 'target' => '/o-nas'], SITE_LANGUAGES_ADMIN);
    storageViewExpect($edited->revision === $about->revision + 1 && sitePageLanguagesRows($site, $about->recordId, $edited->revision, 'en') === ['title' => 'About'], 'an edit lost the translation');
    storageViewExpect(sitePageLanguagesRows($site, $about->recordId, $about->revision, 'en') === ['title' => 'About'], 'the old revision lost its translation');
    $publication->publish($schema, $about->recordId, SITE_LANGUAGES_SITE, 'en', $edited->revision, SITE_LANGUAGES_ADMIN);
    $publication->publish($schema, $about->recordId, SITE_LANGUAGES_SITE, 'ru', $edited->revision, SITE_LANGUAGES_ADMIN);
    $next = $workbench->updateRecord(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $edited->revision,
        ['slug' => 'o-nas', 'title' => 'О нашей компании', 'order_index' => 1, 'target' => '/o-nas'], SITE_LANGUAGES_ADMIN);
    $publication->publish($schema, $about->recordId, SITE_LANGUAGES_SITE, 'ru', $next->revision, SITE_LANGUAGES_ADMIN);
    $publication->publish($schema, $about->recordId, SITE_LANGUAGES_SITE, 'en', $next->revision, SITE_LANGUAGES_ADMIN);
    storageViewExpect($site['read']->publishedRecord(StarterSite::ROLE_REF, SITE_LANGUAGES_SITE, 'en', $about->recordId)['values']['title'] === 'About', 'the next publication dropped the translation');
    storageViewExpect($site['read']->publishedRecord(StarterSite::ROLE_REF, SITE_LANGUAGES_SITE, 'ru', $about->recordId)['values']['title'] === 'О нашей компании', 'the shared value is the main language\'s');
    // A draft's inherited row gives way; a revision that went out does not.
    $draft = $workbench->updateRecord(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $next->revision,
        ['slug' => 'o-nas', 'title' => 'О нашей компании', 'order_index' => 2, 'target' => '/o-nas'], SITE_LANGUAGES_ADMIN);
    $localized->write($schema, $about->recordId, $draft->revision, 'en', ['title' => 'About us'], [], SITE_LANGUAGES_ADMIN);
    storageViewExpect(sitePageLanguagesRows($site, $about->recordId, $draft->revision, 'en') === ['title' => 'About us'], 'a draft\'s inherited row did not give way');
    storageViewExpect(sitePageLanguagesRows($site, $about->recordId, $next->revision, 'en') === ['title' => 'About'], 'a published revision changed');
    try {
        $localized->write($schema, $about->recordId, $next->revision, 'en', ['title' => 'Changed'], [], SITE_LANGUAGES_ADMIN);
        storageViewExpect(false, 'a published revision took a new translation of the same field');
    } catch (LocalizedValueRejected $rejected) {
        storageViewExpect($rejected->reasonCode === 'revision_locale_field_immutable', $rejected->reasonCode);
    }
    // Archive and restore carry them too.
    $kept = $workbench->createRecord(SITE_LANGUAGES_SCOPE, $structure, ['slug' => 'staroe', 'title' => 'Старое', 'order_index' => 9, 'target' => '/staroe'], SITE_LANGUAGES_ADMIN);
    $localized->write($schema, $kept->recordId, $kept->revision, 'en', ['title' => 'Old'], [], SITE_LANGUAGES_ADMIN);
    $archived = $workbench->archiveRecord(SITE_LANGUAGES_SCOPE, $structure, $kept->recordId, $kept->revision, SITE_LANGUAGES_ADMIN);
    $back = $workbench->restoreRecord(SITE_LANGUAGES_SCOPE, $structure, $kept->recordId, $archived->revision, SITE_LANGUAGES_ADMIN);
    storageViewExpect(sitePageLanguagesRows($site, $kept->recordId, $back->revision, 'en') === ['title' => 'Old'], 'archive and restore lost the translation');

    // ===== 4.1: one language, one revision. =====
    $saved = $workbench->updateRecordLanguage(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $draft->revision, 'en', false, ['slug' => 'about', 'body' => '<p>En</p>'], SITE_LANGUAGES_ADMIN);
    storageViewExpect($saved->revision === $draft->revision + 1 && $saved->values['title'] === 'О нашей компании' && $saved->values['slug'] === 'o-nas', 'another language changed the shared values');
    storageViewExpect(sitePageLanguagesRows($site, $about->recordId, $saved->revision, 'en') === ['body' => '<p>En</p>', 'slug' => 'about', 'title' => 'About us'], 'one language did not keep the rest');
    try {
        $workbench->updateRecordLanguage(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $saved->revision, 'en', false, ['order_index' => 3], SITE_LANGUAGES_ADMIN);
        storageViewExpect(false, 'a field that is not translated was written in a language');
    } catch (StorageRejected $rejected) {
        storageViewExpect($rejected->reasonCode === 'storage_workbench_record_field_not_localized', $rejected->reasonCode);
    }
    try {
        $workbench->updateRecordLanguage(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $saved->revision, 'en', false, [], SITE_LANGUAGES_ADMIN);
        storageViewExpect(false, 'nothing to write was written');
    } catch (StorageRejected $rejected) {
        storageViewExpect($rejected->reasonCode === 'storage_workbench_record_language_empty', $rejected->reasonCode);
    }
    // The main language writes the shared values and drops its own rows of those fields, so the edit is seen.
    $localized->write($schema, $about->recordId, $saved->revision, 'ru', ['title' => 'Строка основного языка'], [], SITE_LANGUAGES_ADMIN);
    $main = $workbench->updateRecordLanguage(SITE_LANGUAGES_SCOPE, $structure, $about->recordId, $saved->revision, 'ru', true, ['title' => 'Главный текст'], SITE_LANGUAGES_ADMIN);
    storageViewExpect($main->values['title'] === 'Главный текст' && sitePageLanguagesRows($site, $about->recordId, $main->revision, 'ru') === []
        && sitePageLanguagesRows($site, $about->recordId, $main->revision, 'en')['slug'] === 'about', 'the main language');

    // ===== 4.3: the rules per language. =====
    $rules = $site['rules'];
    $shop = $workbench->createRecord(SITE_LANGUAGES_SCOPE, $structure, ['slug' => 'magazin', 'title' => 'Магазин', 'order_index' => 2, 'target' => '/magazin'], SITE_LANGUAGES_ADMIN);
    $why = static fn (string $slug, ?string $locale, ?string $record = null) => $rules->whyNotWrite($schema, $record ?? $shop->recordId, null, $slug, $locale);
    storageViewExpect($why('about', 'en') === SitePageRules::ADDRESS_TAKEN, 'taken in the language by a sibling\'s translation');
    storageViewExpect($why('about', 'ru') === null, 'free in the other language');
    storageViewExpect($why('o-nas', 'de') === SitePageRules::ADDRESS_TAKEN, 'a sibling\'s shared slug shows in a language with no translation');
    storageViewExpect($why('o-nas', 'en') === null, 'a sibling\'s shared slug is not what it shows in its language');
    storageViewExpect($why('about', null) === SitePageRules::ADDRESS_TAKEN, 'a shared slug would show in the language where a sibling holds it');
    storageViewExpect($why('magazin', 'en') === null, 'a write that leaves the slug as it shows passes');
    storageViewExpect($why('en', 'en') === SitePageRules::SLUG_RESERVED && $why('ru', null) === SitePageRules::SLUG_RESERVED, 'a language\'s code is its folder');
    storageViewExpect($why('admin', 'en') === SitePageRules::SLUG_RESERVED, 'what the application answers');
    storageViewExpect($why('Кириллица', 'en') === SitePageRules::SLUG_INVALID, 'not a latin slug');
    storageViewExpect($why('home', 'en') === SitePageRules::HOME_FIXED, 'no other page becomes Home in a language');
    $homeId = (string) $connection->table('larena_storage_records')->where('owner_ref', StarterSite::RECORD_OWNER_REF)->value('record_id');
    storageViewExpect($why('glavnaya', 'en', $homeId) === SitePageRules::HOME_FIXED && $why('home', 'en', $homeId) === null, 'Home keeps home in every language');
    // Only the shared slug makes the tree's Home, whatever the languages show.
    storageViewExpect($site['tree']->homeId($schema) === $homeId && $site['tree']->currentSlug($schema, $about->recordId) === 'o-nas' && $site['tree']->currentSlug($schema, $about->recordId, 'en') === 'about', 'what the tree reads');

    // The address per language, with the shared slug where a language has none.
    $current = $main->revision;
    foreach (['en', 'ru', 'de'] as $language) {
        $publication->publish($schema, $about->recordId, SITE_LANGUAGES_SITE, $language, $current, SITE_LANGUAGES_ADMIN);
    }
    storageViewExpect($site['addresses']->addressOf($schema, SITE_LANGUAGES_SITE, 'en', $about->recordId) === '/about', 'a translated slug is the address');
    storageViewExpect($site['addresses']->addressOf($schema, SITE_LANGUAGES_SITE, 'ru', $about->recordId) === '/o-nas', 'the shared slug is the main language\'s address');
    storageViewExpect($site['addresses']->addressOf($schema, SITE_LANGUAGES_SITE, 'de', $about->recordId) === '/o-nas', 'the shared slug answers a language with none');

    // A translated slug on a revision that is out is refused; on a draft it is not.
    $changes = new SitePageAddressChanges($site['tree'], $site['addresses'], $rules,
        static fn (): SitePageAddressCache => new SitePageAddressCache($site['tree'], $site['addresses']), static fn () => throw new RuntimeException('no redirects here'), static fn () => null);
    $guarded = new DatabaseLocalizedValues($connection, null, null, null, static fn (): SitePageAddressChanges => $changes);
    try {
        $guarded->write($schema, $about->recordId, $current, 'de', ['slug' => 'ueber-uns'], [], SITE_LANGUAGES_ADMIN);
        storageViewExpect(false, 'a translated slug moved a published address');
    } catch (LocalizedValueRejected $rejected) {
        storageViewExpect($rejected->reasonCode === SitePageRules::SLUG_PUBLISHED, $rejected->reasonCode);
    }
    $guarded->write($schema, $shop->recordId, $shop->revision, 'en', ['slug' => 'shop'], [], SITE_LANGUAGES_ADMIN);
    storageViewExpect($site['tree']->currentSlug($schema, $shop->recordId, 'en') === 'shop', 'a draft takes a translated slug');
    try {
        $guarded->write($schema, $shop->recordId, $shop->revision, 'de', ['slug' => 'Не слаг'], [], SITE_LANGUAGES_ADMIN);
        storageViewExpect(false, 'not a slug');
    } catch (LocalizedValueRejected $rejected) {
        storageViewExpect($rejected->reasonCode === SitePageRules::SLUG_INVALID, $rejected->reasonCode);
    }
    // A move checks each language's slug in the new section.
    storageViewExpect($rules->whyNotMove($schema, $shop->recordId, null) === null, 'a move to where the translations are free');

    // Where a language's addresses stand.
    $folders = new SiteLanguageFolders(new ConfiguredSiteLanguages(['ru', 'en'], 'ru'));
    storageViewExpect($folders->address(SITE_LANGUAGES_SITE, 'en', '/about') === '/en/about' && $folders->address(SITE_LANGUAGES_SITE, 'en', '/') === '/en'
        && $folders->address(SITE_LANGUAGES_SITE, 'ru', '/o-nas') === '/o-nas', 'the language\'s folder');

    // ===== 4.3: an installed storage is upgraded by the command, with no record rewritten. =====
    $one = sitePageLanguagesInstallation();
    $paths[] = $one['path'];
    $one['starter']->apply(SITE_LANGUAGES_ADMIN, 'Main', 'ru');
    $one['pages']->adopt(SITE_LANGUAGES_ADMIN, SITE_LANGUAGES_SCOPE);
    $oneWorkbench = $one['runtime']['workbench'];
    $old = $oneWorkbench->readStructure(SITE_LANGUAGES_SCOPE, $structure, SITE_LANGUAGES_ADMIN);
    $oldFields = array_map(static function (array $field): array {
        if (in_array($field['key'], SitePagesStorage::LOCALIZED_FIELDS, true)) {
            $field['localized'] = false;
        }

        return $field;
    }, $old->fields);
    $oneWorkbench->updateStructure(SITE_LANGUAGES_SCOPE, $structure, $old->version, ['structure_id' => $structure, 'label' => $old->label, 'fields' => $oldFields], SITE_LANGUAGES_ADMIN);
    $versions = (int) $one['connection']->table('larena_storage_record_versions')->count();
    $first = $one['pages']->adopt(SITE_LANGUAGES_ADMIN, SITE_LANGUAGES_SCOPE);
    storageViewExpect($first['localized_fields'] === [['schema_id' => $schema, 'state' => 'localized']], 'the command made them localized');
    $upgraded = array_column($oneWorkbench->readStructure(SITE_LANGUAGES_SCOPE, $structure, SITE_LANGUAGES_ADMIN)->fields, null, 'key');
    storageViewExpect($upgraded['slug']['localized'] === true && $upgraded['content_mode']['localized'] === true, 'the fields are localized');
    storageViewExpect((int) $one['connection']->table('larena_storage_record_versions')->count() === $versions, 'the upgrade rewrote records');
    $second = $one['pages']->adopt(SITE_LANGUAGES_ADMIN, SITE_LANGUAGES_SCOPE);
    storageViewExpect($second['localized_fields'] === [['schema_id' => $schema, 'state' => 'already']], 'running it again changes nothing');
    $homeOne = (string) $one['connection']->table('larena_storage_records')->where('owner_ref', StarterSite::RECORD_OWNER_REF)->value('record_id');
    $revisionOne = (int) $one['connection']->table('larena_storage_records')->where('record_id', $homeOne)->value('current_revision');
    $one['runtime']['publication']->publish($schema, $homeOne, SITE_LANGUAGES_SITE, 'ru', $revisionOne, SITE_LANGUAGES_ADMIN);
    $one['runtime']['localized']->write($schema, $homeOne, $revisionOne, 'en', ['title' => 'Home'], [], SITE_LANGUAGES_ADMIN);
    storageViewExpect($one['tree']->currentSlug($schema, $homeOne, 'en') === 'home', 'a title-only translation keeps the shared slug answering');

    // ===== 4.1: an encrypted translation still opens on the next revision. =====
    $encrypted = sitePageLanguagesInstallationForEncryption();
    $paths[] = $encrypted['path'];
    echo "Site page languages passed.\n";
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
}

/** @return array{path: string} */
function sitePageLanguagesInstallationForEncryption(): array
{
    $opened = storageViewQueueOpen();
    $connection = $opened['connection'];
    storageEncryptionInstall($connection);
    $admin = 'actor:admin:alpha';
    $runtime = storageEncryptionRuntime($connection, new SqliteStorageViewDdl($connection), [$admin => []], storageEncryptionWrapper());
    $scope = 'scope:tenant-alpha';
    $structure = $runtime['workbench']->createStructure($scope, ['structure_id' => 'workbench.notes', 'label' => 'Notes', 'fields' => [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200]),
        storageEncryptionField('notes', 20, 'text', [], false, ['encrypted' => true, 'localized' => true]),
    ]], $admin);
    $schemaId = $structure->schema->schemaId;
    $record = $runtime['workbench']->createRecord($scope, 'workbench.notes', ['title' => 'One'], $admin);
    $runtime['localized']->write($schemaId, $record->recordId, $record->revision, 'ru', ['notes' => 'секрет'], [], $admin);
    $next = $runtime['workbench']->updateRecord($scope, 'workbench.notes', $record->recordId, $record->revision, ['title' => 'Two'], $admin);
    storageViewExpect($runtime['localized']->carryForward($schemaId, $record->recordId, $record->revision, $next->revision, $admin) === ['ru'], 'the translation was not carried');
    $row = static fn (int $revision): string => (string) $connection->table('larena_storage_localized_values')
        ->where('schema_id', $schemaId)->where('record_id', $record->recordId)->where('revision', $revision)->where('locale', 'ru')->value('value_json');
    storageViewExpect($row($record->revision) === $row($next->revision) && !str_contains($row($next->revision), 'секрет'), 'the carried row is not the stored bytes');
    $opened2 = $runtime['reveal']->revealValues($admin, $schemaId, $record->recordId, ['notes' => json_decode($row($next->revision), true)], 'ru');
    storageViewExpect($opened2 === ['notes' => 'секрет'], 'a carried envelope does not open');

    return ['path' => $opened['path']];
}
