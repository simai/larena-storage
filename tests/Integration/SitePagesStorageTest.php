<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Translation\Translator;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\Console\Commands\StorageSitePagesAdoptCommand;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\FirstRun\SiteFirstRunContributor;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\SchemaEvolution\OptionalFieldCompatibilityAnalyzer;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewSync;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The site pages as an ordinary storage (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, step 1.4):
// - a new installation's first run writes the Home page and, once it has committed, adopts the pages as
//   «Страницы сайта» in the administrator's scope, listed with the other storages; the site step reads as initialized;
// - an installation from before: larena:storage:adopt-site-pages --dry-run writes nothing, the command adopts the
//   pages in place (the same record ids) and changes nothing when run again; without site pages it refuses.
// - the page content fields (step 1.5, owner decision q-4ede0beabe): content_mode (choice@1 text|blocks), body (html@1,
//   localized) and blocks (content@1, localized) come with the adoption on a new installation and with the next run of
//   the command on one adopted before; the Home page keeps its values; the витрина holds html as text and content as
//   its JSON (empty params `{}`); an optional html or content field is a safe schema addition.

const SITE_PAGES_ACTOR = 'user:admin_identity:1';
const SITE_PAGES_SCOPE = 'scope:main';

/** @return array{connection: Connection, workbench: DatabaseStorageWorkbench, views: StorageViewSync, starter: StarterSite, pages: SitePagesStorage, path: string} */
function sitePagesInstallation(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-site-pages-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_site_pages_tempfile_failed');
    }
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    // The first run's transaction runs its after-commit steps, as the application's connection does.
    $connection->setTransactionManager(new DatabaseTransactionsManager());
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    storageViewInstall($connection);
    $root = __DIR__ . '/../../database/migrations';
    $core = __DIR__ . '/../../vendor/larena/core/database/migrations/platform';
    foreach ([
        $core . '/2026_09_24_000001_create_larena_core_scopes_table.php',
        $core . '/2026_09_24_000002_create_larena_core_planes_table.php',
        $core . '/2026_09_24_000003_create_larena_core_plane_nodes_table.php',
        $core . '/2026_10_12_000004_add_legacy_ref_to_larena_core_scopes_table.php',
        $root . '/platform/2026_09_26_000001_create_larena_storage_structure_role_tables.php',
        $root . '/platform/2026_09_27_000001_create_larena_storage_record_relations_table.php',
        $root . '/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php',
        $root . '/platform/2026_09_29_000001_create_larena_storage_publication_tables.php',
        $root . '/platform/2026_10_22_000002_add_larena_storage_publication_scheduled_revision.php',
        $root . '/2026_10_23_000001_add_larena_storage_workbench_structure_import_run.php',
        $root . '/2026_10_29_000001_add_larena_storage_workbench_structure_adoption.php',
    ] as $file) {
        (require $file)->up();
    }
    $runtime = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), SITE_PAGES_ACTOR, SITE_PAGES_SCOPE);
    $roles = new DatabaseStructureRoleRegistry($connection);
    $scopes = new DatabaseScopeRegistry($connection);
    $starter = new StarterSite($connection, $runtime['storage'], $roles, new StarterStructureRoles($roles),
        new ScopeBaselineInstaller($connection, $scopes, new DatabasePlaneRegistry($connection, $scopes)));
    $pages = new SitePagesStorage($connection, $runtime['workbench'], static fn (string $actor): string => SITE_PAGES_SCOPE,
        new SitePagesWords());

    return ['connection' => $connection, 'workbench' => $runtime['workbench'], 'views' => $runtime['views'], 'starter' => $starter, 'pages' => $pages, 'path' => $path];
}

/** Storage's words from the files the application reads them from (larena-storage::admin.*), English by default. */
final class SitePagesWords implements Translator
{
    private string $locale = 'en';

    public function get($key, array $replace = [], $locale = null)
    {
        $prefix = 'larena-storage::admin.';
        if (!str_starts_with($key, $prefix)) {
            return $key;
        }
        $words = require __DIR__ . '/../../resources/lang/' . ($locale ?? $this->locale) . '/admin.php';
        foreach (explode('.', substr($key, strlen($prefix))) as $segment) {
            if (!is_array($words) || !array_key_exists($segment, $words)) {
                return $key;
            }
            $words = $words[$segment];
        }

        return $words;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->get($key, $replace, $locale);
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
        $this->locale = $locale;
    }
}

/** @return array{0: int, 1: array<string, mixed>} The command's exit status and its receipt. */
function sitePagesCommand(SitePagesStorage $pages, array $options): array
{
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(SitePagesStorage::class, $pages);
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
    $command = new StorageSitePagesAdoptCommand();
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

/**
 * The content fields of the site pages' structure, by key: type, localized, label and the options' labels.
 *
 * @return array<string, array{type: string, localized: bool, visibility: string, required: bool, label: string, options: list<string>}>
 */
function sitePagesContentFields(DatabaseStorageWorkbench $workbench): array
{
    $fields = [];
    foreach ($workbench->readStructure(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, SITE_PAGES_ACTOR)->fields as $field) {
        if (in_array($field['key'], SitePagesStorage::CONTENT_FIELDS, true)) {
            $fields[$field['key']] = [
                'type' => $field['type'], 'localized' => $field['localized'] === true, 'visibility' => $field['visibility'],
                'required' => $field['required'], 'label' => $field['label'],
                'options' => array_map(static fn (array $option): string => $option['value'] . ':' . $option['label'], $field['constraints']['options'] ?? []),
            ];
        }
    }

    return $fields;
}

/** @return array<string, int> */
function sitePagesCounts(Connection $connection): array
{
    return [
        'structures' => $connection->table('larena_storage_workbench_structures')->count(),
        'schema_versions' => $connection->table('larena_storage_schema_versions')->where('schema_id', StarterSite::SCHEMA_ID)->count(),
        'record_versions' => $connection->table('larena_storage_record_versions')->where('schema_id', StarterSite::SCHEMA_ID)->count(),
    ];
}

$paths = [];
try {
    // 1. A new installation: the site step writes the Home page; the adoption waits for the first run's commit.
    $fresh = sitePagesInstallation();
    $paths[] = $fresh['path'];
    $connection = $fresh['connection'];
    $workbench = $fresh['workbench'];
    $pages = $fresh['pages'];
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => SITE_PAGES_ACTOR]);
    storageViewExpect($status === 1 && $receipt['reason_code'] === 'storage_site_pages_missing', 'the command did not refuse an installation without site pages');
    $contributor = new SiteFirstRunContributor($fresh['starter'], $connection, null, null, null,
        static fn (string $actor, string $locale): array => $pages->adopt($actor, null, $locale));
    storageViewExpect($contributor->state() === FirstRunContributor::STATE_EMPTY, 'a new installation does not read as empty');
    $payload = new FirstRunPayload('Administrator', 'admin@example.test', 'Password!2026', 'Larena', 'ru', 'UTC');
    $context = $connection->transaction(static function () use ($contributor, $payload, $connection): FirstRunContext {
        $context = $contributor->apply($payload, new FirstRunContext(['auth.subject_ref' => SITE_PAGES_ACTOR]));
        storageViewExpect($connection->table('larena_storage_workbench_structures')->count() === 0, 'the pages were adopted inside the first run');

        return $context;
    });
    $home = $context->string('site.homepage_record_id');
    storageViewExpect($contributor->state() === FirstRunContributor::STATE_INITIALIZED, 'the adopted site pages do not read as initialized');
    $structure = $workbench->readStructure(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, SITE_PAGES_ACTOR);
    storageViewExpect($structure->adopted() && $structure->schema->schemaId === StarterSite::SCHEMA_ID && $structure->label === 'Страницы сайта'
        && $structure->recordOwnerPrefix === SitePagesStorage::OWNER_REF_PREFIX && $structure->recordOwnerField === 'slug',
        'the first run did not adopt the site pages as «Страницы сайта»');
    $listed = array_map(static fn ($item): string => $item->structureId, $workbench->listStructures(SITE_PAGES_SCOPE, SITE_PAGES_ACTOR));
    storageViewExpect(in_array(SitePagesStorage::STRUCTURE_ID, $listed, true), 'the site pages are not listed with the storages');
    $records = $workbench->listRecords(new StorageWorkbenchRecordQuery(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, limit: 10), SITE_PAGES_ACTOR);
    storageViewExpect(count($records->items) === 1 && $records->items[0]->recordId === $home && $records->items[0]->values['slug'] === 'home'
        && $records->items[0]->state === 'active', 'the Home page is not the storage\'s one active record');
    storageViewExpect($connection->getSchemaBuilder()->hasTable(StorageViewNaming::table(StarterSite::SCHEMA_ID)), 'the site pages have no витрина');
    storageViewExpect($connection->table('larena_storage_records')->where('record_id', $home)->value('owner_ref') === StarterSite::RECORD_OWNER_REF,
        'the Home page lost its owner ref');
    // The content fields came with the adoption, in the installer's language; the Home page kept its values.
    storageViewExpect(sitePagesContentFields($workbench) === [
        'content_mode' => ['type' => 'choice', 'localized' => true, 'visibility' => 'public', 'required' => false, 'label' => 'Форма содержимого',
            'options' => ['text:Текст', 'blocks:Блоки']],
        'body' => ['type' => 'html', 'localized' => true, 'visibility' => 'public', 'required' => false, 'label' => 'Текст', 'options' => []],
        'blocks' => ['type' => 'content', 'localized' => true, 'visibility' => 'public', 'required' => false, 'label' => 'Блоки', 'options' => []],
    ], 'a new installation\'s site pages lack the content fields');
    storageViewExpect($connection->table('larena_storage_record_versions')->where('record_id', $home)->count() === 1,
        'the content fields rewrote the Home page');
    $homeValues = $workbench->readRecord(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, $home, SITE_PAGES_ACTOR)->values;
    storageViewExpect(!array_key_exists('body', $homeValues) && SitePagesStorage::contentMode($homeValues) === SitePagesStorage::CONTENT_MODE_TEXT,
        'the Home page does not read as text without a body');
    // A page with content: html as written, blocks with an empty params kept an object in the витрина.
    $html = '<h2>О нас</h2><p>Текст <a href="/contacts">страницы</a>.</p>';
    $blocks = [['type' => 'larena.list', 'version' => 1, 'params' => ['target' => 'site_node@v1']], ['type' => 'larena.content', 'version' => 1, 'params' => []]];
    $about = $workbench->createRecord(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, ['slug' => 'about', 'title' => 'О нас', 'order_index' => 10,
        'target' => '/about', 'content_mode' => 'blocks', 'body' => $html, 'blocks' => $blocks], SITE_PAGES_ACTOR);
    storageViewExpect($about->values['body'] === $html && $about->values['blocks'] === $blocks && SitePagesStorage::contentMode($about->values) === 'blocks',
        'a page does not keep its content as written');
    storageViewRejects(static fn () => $workbench->createRecord(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, ['slug' => 'bad', 'title' => 'Bad',
        'order_index' => 20, 'target' => '/bad', 'content_mode' => 'video'], SITE_PAGES_ACTOR), 'storage_record_field_invalid');
    $registration = $fresh['views']->registry()->find(StarterSite::SCHEMA_ID, true);
    $cells = [];
    foreach ($registration->layout->written() as $column) {
        if (in_array($column->fieldKey, ['body', 'blocks'], true)) {
            storageViewExpect($column->sqlType === StorageViewColumn::SQL_TEXT && !$column->indexed, 'a content field is not unindexed text in the витрина');
            $cells[$column->fieldKey] = $connection->table($registration->tableName)->where('record_id', $about->recordId)->value((string) $column->column);
        }
    }
    storageViewExpect(($cells['body'] ?? null) === $html && ($cells['blocks'] ?? null) === PropertyTypeRegistry::contentJson($blocks)
        && str_contains((string) $cells['blocks'], '"params":{}'), 'the витрина does not hold the page content');
    storageViewParity($connection, $fresh['views'], StarterSite::SCHEMA_ID, 'site pages with content');
    storageViewRejects(static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID,
        filters: ['blocks' => ['operator' => 'eq', 'value' => '[]']], limit: 10), SITE_PAGES_ACTOR), 'storage_query_filter_operator_unsupported');
    storageViewExpect(SitePagesStorage::contentMode(['blocks' => $blocks]) === 'blocks' && SitePagesStorage::contentMode(['body' => '<p>x</p>', 'blocks' => $blocks]) === 'text'
        && SitePagesStorage::contentMode(['body' => ' ', 'blocks' => []]) === 'text' && SitePagesStorage::contentMode([]) === 'text', 'contentMode() does not follow the contract');

    // The command on a new installation changes nothing.
    $before = sitePagesCounts($connection);
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => SITE_PAGES_ACTOR]);
    storageViewExpect($status === 0 && $receipt['state'] === DatabaseStorageWorkbench::ALREADY_ADOPTED && $receipt['scope_ref'] === SITE_PAGES_SCOPE
        && $receipt['content_fields'] === [] && sitePagesCounts($connection) === $before, 'the command wrote on an adopted installation');

    // 2. An installation from before: the site pages written, never adopted. It reads as initialized all the same.
    $upgraded = sitePagesInstallation();
    $paths[] = $upgraded['path'];
    $connection = $upgraded['connection'];
    $workbench = $upgraded['workbench'];
    $pages = $upgraded['pages'];
    $home = $upgraded['starter']->apply(SITE_PAGES_ACTOR, 'Larena', 'en');
    $contributor = new SiteFirstRunContributor($upgraded['starter']);
    storageViewExpect($contributor->state() === FirstRunContributor::STATE_INITIALIZED, 'site pages not yet adopted read as partial');
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => 'system:storage']);
    storageViewExpect($status === 1 && $receipt['reason_code'] === 'storage_site_pages_input_invalid', 'the command ran without an administrator');

    // The dry run: the plan, and no write.
    $before = sitePagesCounts($connection);
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => SITE_PAGES_ACTOR, '--dry-run' => true]);
    storageViewExpect($status === 0 && $receipt['status'] === 'planned' && $receipt['state'] === 'ready' && $receipt['records'] === 1
        && $receipt['structure_id'] === SitePagesStorage::STRUCTURE_ID && $receipt['label'] === 'Site pages'
        && $receipt['writes'] === ['schema_versions' => 2, 'structures' => 1, 'record_versions' => 0]
        && $receipt['content_fields'] === SitePagesStorage::CONTENT_FIELDS, 'the dry run does not plan the adoption and the content fields');
    storageViewExpect(sitePagesCounts($connection) === $before && $before['structures'] === 0, 'the dry run wrote');

    // The adoption: in place, three schema versions more (the adoption, the content fields, what a form no longer asks), no record version; then idempotent.
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => SITE_PAGES_ACTOR, '--locale' => 'ru']);
    storageViewExpect($status === 0 && $receipt['status'] === 'ready' && $receipt['state'] === DatabaseStorageWorkbench::ADOPTED, 'the command did not adopt the pages');
    $after = sitePagesCounts($connection);
    storageViewExpect($after === ['structures' => 1, 'schema_versions' => $before['schema_versions'] + 3, 'record_versions' => $before['record_versions']]
        && $receipt['content_fields'] === SitePagesStorage::CONTENT_FIELDS, 'the adoption wrote more than the structure and three schema versions');
    storageViewExpect(array_keys(sitePagesContentFields($workbench)) === SitePagesStorage::CONTENT_FIELDS, 'the adopted pages lack the content fields');
    $read = $workbench->readRecord(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, $home, SITE_PAGES_ACTOR);
    storageViewExpect($read->values['title'] === 'Home' && $read->state === 'active', 'the adopted Home page does not read with its id');
    storageViewExpect($workbench->readStructure(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, SITE_PAGES_ACTOR)->label === 'Страницы сайта',
        'the storage is not named in the asked language');
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => SITE_PAGES_ACTOR]);
    storageViewExpect($status === 0 && $receipt['state'] === DatabaseStorageWorkbench::ALREADY_ADOPTED && sitePagesCounts($connection) === $after,
        'a second run of the command wrote');
    [$status, $receipt] = sitePagesCommand($pages, ['--actor' => SITE_PAGES_ACTOR, '--dry-run' => true]);
    storageViewExpect($status === 0 && $receipt['state'] === DatabaseStorageWorkbench::ALREADY_ADOPTED, 'the dry run of adopted pages is not already_adopted');
    storageViewExpect($contributor->state() === FirstRunContributor::STATE_INITIALIZED, 'the adopted pages do not read as initialized');

    // 3. An installation adopted before the content fields: the next run of the command adds them, once.
    $earlier = sitePagesInstallation();
    $paths[] = $earlier['path'];
    $connection = $earlier['connection'];
    $workbench = $earlier['workbench'];
    $home = $earlier['starter']->apply(SITE_PAGES_ACTOR, 'Larena', 'en');
    $workbench->adoptSchema(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, StarterSite::SCHEMA_ID, 'Site pages',
        SitePagesStorage::OWNER_REF_PREFIX, SITE_PAGES_ACTOR, SitePagesStorage::OWNER_REF_FIELD);
    storageViewExpect(sitePagesContentFields($workbench) === [], 'the earlier adoption already has the content fields');
    $before = sitePagesCounts($connection);
    [$status, $receipt] = sitePagesCommand($earlier['pages'], ['--actor' => SITE_PAGES_ACTOR, '--dry-run' => true]);
    storageViewExpect($status === 0 && $receipt['state'] === DatabaseStorageWorkbench::ALREADY_ADOPTED && $receipt['content_fields'] === SitePagesStorage::CONTENT_FIELDS
        && $receipt['writes']['schema_versions'] === 1 && sitePagesCounts($connection) === $before, 'the dry run does not plan the content fields');
    [$status, $receipt] = sitePagesCommand($earlier['pages'], ['--actor' => SITE_PAGES_ACTOR]);
    storageViewExpect($status === 0 && $receipt['state'] === DatabaseStorageWorkbench::ALREADY_ADOPTED && $receipt['content_fields'] === SitePagesStorage::CONTENT_FIELDS
        && sitePagesCounts($connection) === ['structures' => 1, 'schema_versions' => $before['schema_versions'] + 2, 'record_versions' => $before['record_versions']],
        'the command did not add the content fields to adopted pages');
    $fields = sitePagesContentFields($workbench);
    storageViewExpect(array_keys($fields) === SitePagesStorage::CONTENT_FIELDS && $fields['content_mode']['options'] === ['text:Text', 'blocks:Blocks']
        && $fields['body']['label'] === 'Text', 'the added content fields are not the contract\'s');
    storageViewExpect($workbench->readRecord(SITE_PAGES_SCOPE, SitePagesStorage::STRUCTURE_ID, $home, SITE_PAGES_ACTOR)->values['title'] === 'Home',
        'the Home page changed');
    $after = sitePagesCounts($connection);
    [$status, $receipt] = sitePagesCommand($earlier['pages'], ['--actor' => SITE_PAGES_ACTOR]);
    storageViewExpect($status === 0 && $receipt['content_fields'] === [] && sitePagesCounts($connection) === $after, 'the content fields were added twice');
    storageViewParity($connection, $earlier['views'], StarterSite::SCHEMA_ID, 'site pages given the content fields');

    // 4. A schema migration accepts an optional html field (with its max_length) and an optional content field.
    $analyzer = new OptionalFieldCompatibilityAnalyzer(new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns()));
    $source = ['schema_id' => 'probe.pages', 'owner_package' => 'larena/storage', 'fields' => [
        ['key' => 'title', 'type' => 'string', 'type_version' => 2, 'required' => true, 'visibility' => 'public', 'constraints' => ['max_length' => 100]],
    ]];
    $added = static fn (array ...$fields): array => ['fields' => [...$source['fields'], ...$fields]] + $source;
    $html = static fn (array $constraints, bool $required = false): array => ['key' => 'body', 'type' => 'html', 'type_version' => 1, 'required' => $required,
        'visibility' => 'public', 'constraints' => $constraints, 'localized' => true];
    $content = ['key' => 'blocks', 'type' => 'content', 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => [], 'localized' => true];
    $result = $analyzer->analyze($source, $added($html(['max_length' => 5000]), $content));
    storageViewExpect($result['compatible'] && $result['added_optional_count'] === 2, 'optional html and content fields are not a safe addition');
    storageViewExpect($analyzer->analyze($source, $added($html([])))['compatible'], 'an optional html field without settings is not a safe addition');
    storageViewExpect($analyzer->analyze($source, $added($html(['max_length' => 5000], true)))['reason_codes'] === ['storage_schema_migration_required_field_added'],
        'a required html field is a safe addition');
    storageViewExpect(in_array('storage_schema_migration_added_field_constraints_unsupported',
        $analyzer->analyze($source, $added(['constraints' => ['max_items' => 3]] + $content))['reason_codes'], true), 'a content field with settings is a safe addition');

    fwrite(STDOUT, "SitePagesStorageTest passed\n");
} finally {
    foreach ($paths as $path) {
        @unlink($path);
    }
}
