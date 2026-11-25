<?php

declare(strict_types=1);

// The scenarios' demo pages (core's resources/scenarios/demo/<scenario>.zip): what each one holds, a disposable
// installation to build them on and import them into, and the build itself (scripts/build-scenario-demo-packs.php).
// Each pack is a SitePack of the site pages: a Home page, one section and one or two pages in it, the words in
// Russian (the shared values) with an English translation of the title, description and body.

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackExporter;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/storage-sitepack-import-fixture.php';

const SCENARIO_DEMO_ADMIN = 'user:admin_identity:1';
const SCENARIO_DEMO_SCOPE = 'scope:main';
const SCENARIO_DEMO_SITE = 'site:main';

/**
 * The demo pages of each scenario: the Home page, the section, the pages in it. Each page: slug, then [ru, en] for
 * the title, the description and the body.
 *
 * @return array<string, array{home: array<string, mixed>, section: array<string, mixed>, pages: list<array<string, mixed>>}>
 */
function scenarioDemoContent(): array
{
    $page = static fn (string $slug, array $title, array $description, array $body): array => ['slug' => $slug, 'title' => $title, 'description' => $description, 'body' => $body];

    return [
        'company_site' => [
            'home' => $page('home', ['Главная', 'Home'], ['Сайт компании: кто мы и чем помогаем.', 'Our company: who we are and how we help.'],
                ['<p>Мы помогаем бизнесу расти. Расскажите здесь о компании и её главном предложении.</p>', '<p>We help businesses grow. Tell visitors about the company and its main offer here.</p>']),
            'section' => $page('services', ['Услуги', 'Services'], ['Что мы делаем для клиентов.', 'What we do for our clients.'],
                ['<p>Все наши услуги на одной странице.</p>', '<p>All our services on one page.</p>']),
            'pages' => [
                $page('web-development', ['Разработка сайтов', 'Website development'], ['Сайты под ключ.', 'Websites from start to finish.'],
                    ['<p>Проектируем, делаем и запускаем сайты.</p>', '<p>We design, build and launch websites.</p>']),
                $page('support', ['Поддержка', 'Support'], ['Помогаем после запуска.', 'Help after the launch.'],
                    ['<p>Следим за сайтом и отвечаем на вопросы.</p>', '<p>We look after the site and answer questions.</p>']),
            ],
        ],
        'online_store' => [
            'home' => $page('home', ['Главная', 'Home'], ['Магазин: каталог и новинки.', 'The store: catalogue and new arrivals.'],
                ['<p>Добро пожаловать в магазин. Загляните в каталог.</p>', '<p>Welcome to the store. Have a look at the catalogue.</p>']),
            'section' => $page('catalog', ['Каталог', 'Catalogue'], ['Все товары магазина.', 'Everything the store sells.'],
                ['<p>Выберите товар из каталога.</p>', '<p>Choose a product from the catalogue.</p>']),
            'pages' => [
                $page('t-shirt', ['Футболка с логотипом', 'Logo T-shirt'], ['Хлопок, все размеры.', 'Cotton, every size.'],
                    ['<p>Мягкая хлопковая футболка с логотипом.</p>', '<p>A soft cotton T-shirt with the logo.</p>']),
                $page('mug', ['Кружка', 'Mug'], ['Керамика, 300 мл.', 'Ceramic, 300 ml.'],
                    ['<p>Кружка для чая и кофе.</p>', '<p>A mug for tea and coffee.</p>']),
            ],
        ],
        'blog' => [
            'home' => $page('home', ['Главная', 'Home'], ['Блог: заметки и новости.', 'The blog: notes and news.'],
                ['<p>Здесь мы пишем о том, что делаем и что узнали.</p>', '<p>Here we write about what we do and what we learn.</p>']),
            'section' => $page('blog', ['Блог', 'Blog'], ['Все записи блога.', 'Every post of the blog.'],
                ['<p>Свежие записи — сверху.</p>', '<p>The newest posts come first.</p>']),
            'pages' => [
                $page('first-post', ['Первая запись', 'The first post'], ['С чего всё началось.', 'How it all began.'],
                    ['<p>Это первая запись блога. Отредактируйте её или напишите новую.</p>', '<p>This is the blog\'s first post. Edit it or write a new one.</p>']),
                $page('how-we-work', ['Как мы работаем', 'How we work'], ['Наш подход к делу.', 'Our way of working.'],
                    ['<p>Рассказываем, как устроена наша работа.</p>', '<p>How our work is organised.</p>']),
            ],
        ],
        'landing' => [
            'home' => $page('home', ['Главная', 'Home'], ['Одна страница о главном предложении.', 'One page about the main offer.'],
                ['<p>Расскажите о предложении, его пользе и о том, как начать.</p>', '<p>Tell visitors about the offer, why it helps and how to start.</p>']),
            'section' => $page('pricing', ['Тарифы', 'Pricing'], ['Сколько это стоит.', 'What it costs.'],
                ['<p>Выберите подходящий тариф.</p>', '<p>Choose the plan that fits.</p>']),
            'pages' => [
                $page('basic', ['Базовый тариф', 'Basic plan'], ['Всё нужное для начала.', 'Everything needed to start.'],
                    ['<p>Базовый тариф подходит для старта.</p>', '<p>The basic plan is made for a start.</p>']),
            ],
        ],
        'staff_portal' => [
            'home' => $page('home', ['Главная', 'Home'], ['Портал для сотрудников компании.', 'The company\'s staff portal.'],
                ['<p>Новости, документы и всё нужное для работы — в одном месте.</p>', '<p>News, documents and everything needed for work in one place.</p>']),
            'section' => $page('news', ['Новости', 'News'], ['Новости компании.', 'Company news.'],
                ['<p>Здесь публикуются новости компании.</p>', '<p>Company news are posted here.</p>']),
            'pages' => [
                $page('welcome', ['Добро пожаловать на портал', 'Welcome to the portal'], ['Как пользоваться порталом.', 'How to use the portal.'],
                    ['<p>Здесь вы найдёте новости, документы и контакты коллег.</p>', '<p>Here you find news, documents and your colleagues\' contacts.</p>']),
                $page('holiday-hours', ['График работы на праздники', 'Holiday working hours'], ['Когда офис открыт в праздники.', 'When the office is open on holidays.'],
                    ['<p>График работы офиса в праздничные дни.</p>', '<p>The office hours on public holidays.</p>']),
            ],
        ],
    ];
}

/** Where core ships the demo packs, by scenario id. */
function scenarioDemoPackPath(string $scenarioId): string
{
    return __DIR__ . '/../../vendor/larena/core/resources/scenarios/demo/' . $scenarioId . '.zip';
}

/**
 * One fresh installation on its own SQLite file, as a new site is: the starter site (one untouched Home page, as the
 * first run leaves it) with its pages adopted as the storage «Страницы сайта». $locales: what the site declares.
 *
 * @param list<string> $locales
 * @return array{connection: Connection, runtime: array<string, mixed>, sitePack: DatabaseStorageSitePack, path: string}
 */
function scenarioDemoInstallation(array $locales = ['ru', 'en']): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-scenario-demo-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_scenario_demo_tempfile_failed');
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
    $admin = SCENARIO_DEMO_ADMIN;
    $runtime = sitePackImportRuntime($connection, new SqliteStorageViewDdl($connection), [$admin => []], [$admin]);
    $roles = $runtime['roles'];
    $scopes = new DatabaseScopeRegistry($connection);
    (new StarterSite($connection, $runtime['storage'], $roles, new StarterStructureRoles($roles),
        new ScopeBaselineInstaller($connection, $scopes, new DatabasePlaneRegistry($connection, $scopes))))->apply($admin, 'Сайт', 'ru');
    (new SitePagesStorage($connection, $runtime['workbench'], static fn (string $actor): string => SCENARIO_DEMO_SCOPE))->adopt($admin);

    return [
        'connection' => $connection,
        'runtime' => $runtime,
        'sitePack' => ($runtime['sitePack'])(['siteLocales' => static fn (string $siteRef): array => $locales]),
        'path' => $path,
    ];
}

/** @param array<string, mixed> $installation @param array<string, mixed> $values */
function scenarioDemoCreatePage(array $installation, array $values, ?string $parent = null): StorageWorkbenchRecord
{
    $record = $installation['runtime']['workbench']->createRecord(SCENARIO_DEMO_SCOPE, SitePagesStorage::STRUCTURE_ID, $values, SCENARIO_DEMO_ADMIN);
    if ($parent !== null) {
        scenarioDemoPlaceUnder($installation['connection'], $record->recordId, $parent);
    }

    return $record;
}

/** Puts a page under another, as the page tree does. */
function scenarioDemoPlaceUnder(Connection $connection, string $child, string $parent): void
{
    $now = gmdate('Y-m-d H:i:s');
    $connection->table('larena_storage_record_relations')->insert([
        'relation_id' => 'site_tree_parent|' . $child . '|' . $parent, 'relation_key' => 'site_tree_parent', 'schema_id' => StarterSite::SCHEMA_ID,
        'from_record_id' => $child, 'to_record_id' => $parent, 'kind' => 'tree_parent', 'tree_child_key' => $child,
        'path' => $parent . '/' . $child, 'depth' => 1, 'order_index' => 0, 'delete_policy' => 'detach', 'status' => 'active',
        'created_by' => SCENARIO_DEMO_ADMIN, 'correlation_id' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

/** @param array<string, mixed> $installation @param array<string, mixed> $changes */
function scenarioDemoUpdatePage(array $installation, StorageWorkbenchRecord $record, array $changes): StorageWorkbenchRecord
{
    $values = array_filter($record->values, static fn (string|int $key): bool => !str_starts_with((string) $key, 'larena_'), ARRAY_FILTER_USE_KEY);

    return $installation['runtime']['workbench']->updateRecord(SCENARIO_DEMO_SCOPE, SitePagesStorage::STRUCTURE_ID, $record->recordId,
        $record->revision, $changes + $values, SCENARIO_DEMO_ADMIN);
}

/**
 * Builds one scenario's demo pack on a fresh installation and writes it to $target: the starter Home page rewritten
 * as the scenario's, the section and its pages added under it, each with its English translation.
 *
 * @param array{home: array<string, mixed>, section: array<string, mixed>, pages: list<array<string, mixed>>} $content
 */
function scenarioDemoBuild(array $content, string $target): void
{
    $installation = scenarioDemoInstallation();
    try {
        $connection = $installation['connection'];
        $workbench = $installation['runtime']['workbench'];
        $localized = $installation['runtime']['localized'];
        $localizedKeys = [];
        foreach ($workbench->readStructure(SCENARIO_DEMO_SCOPE, SitePagesStorage::STRUCTURE_ID, SCENARIO_DEMO_ADMIN)->fields as $field) {
            if (($field['localized'] ?? false) === true) {
                $localizedKeys[] = (string) $field['key'];
            }
        }
        $values = static fn (array $page, int $order, string $target): array => [
            'slug' => $page['slug'], 'title' => $page['title'][0], 'order_index' => $order, 'target' => $target,
            'description' => $page['description'][0], 'content_mode' => SitePagesStorage::CONTENT_MODE_TEXT, 'body' => $page['body'][0],
        ];
        $english = static function (StorageWorkbenchRecord $record, array $page) use ($localized, $localizedKeys): void {
            $localized->write(StarterSite::SCHEMA_ID, $record->recordId, $record->revision, 'en',
                ['title' => $page['title'][1], 'description' => $page['description'][1], 'body' => $page['body'][1]], $localizedKeys, SCENARIO_DEMO_ADMIN);
        };
        $homeId = (string) $connection->table('larena_storage_records')->where('owner_ref', StarterSite::RECORD_OWNER_REF)->value('record_id');
        $home = scenarioDemoUpdatePage($installation, $workbench->readRecord(SCENARIO_DEMO_SCOPE, SitePagesStorage::STRUCTURE_ID, $homeId, SCENARIO_DEMO_ADMIN),
            $values($content['home'], 0, '/'));
        $english($home, $content['home']);
        $sectionPath = '/' . $content['section']['slug'];
        $section = scenarioDemoCreatePage($installation, $values($content['section'], 1, $sectionPath));
        $english($section, $content['section']);
        foreach ($content['pages'] as $index => $page) {
            $record = scenarioDemoCreatePage($installation, $values($page, $index, $sectionPath . '/' . $page['slug']), $section->recordId);
            $english($record, $page);
        }
        $sitePack = $installation['sitePack'];
        $run = $sitePack->startExport(SCENARIO_DEMO_SCOPE, SitePagesStorage::STRUCTURE_ID, SCENARIO_DEMO_ADMIN, null, false);
        $run = $sitePack->run($run->runRef, SCENARIO_DEMO_ADMIN);
        if (!$run->isDone()) {
            throw new RuntimeException('storage_scenario_demo_export_failed: ' . json_encode($run->toArray()));
        }
        if (!copy($sitePack->archive($run->runRef, SCENARIO_DEMO_ADMIN)->path, $target)) {
            throw new RuntimeException('storage_scenario_demo_copy_failed');
        }
    } finally {
        scenarioDemoDrop($installation);
    }
}

/** Removes an installation's database file and its SitePack directory. @param array<string, mixed> $installation */
function scenarioDemoDrop(array $installation): void
{
    @unlink($installation['path']);
    StorageSitePackExporter::wipe($installation['runtime']['directory']);
}
