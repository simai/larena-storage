<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Setting\Persistence\DatabaseViewSettingsStore;
use Larena\Setting\Runtime\PersistentSettingRuntime;
use Larena\Setting\ViewConfig\ViewConfigDocument;
use Larena\Setting\ViewConfig\ViewConfigLevel;
use Larena\Setting\ViewConfig\ViewConfigStore;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\DataviewListPreferences;
use Larena\Storage\Admin\StorageReferenceLabelResolver;
use Larena\Storage\Admin\StorageViewConfigCodec;
use Larena\Storage\Admin\StorageWorkbenchDataviewPort;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The Storage list's data view port on the views store (simai.view-config/1, port 1.16.0), on SQLite:
// - saved views are view-config documents in larena_view_settings, named by their level;
// - a system view (shipped with a package) is never written through the port: saving or deleting it is refused;
// - limits: the 21st view of a level is refused with reason `limit` and the limit's name, in the pack's words;
// - the person's view state comes with the first answer (data.preferences) and round trips through preferences.save,
//   with a conflict on a stale revision; describe()'s limits come with every answer;
// - a query with a window answers a board: every lane's first records and totals, then one lane from its cursor.
$path = tempnam(sys_get_temp_dir(), 'larena-storage-view-config-port-');
if (!is_string($path)) {
    throw new RuntimeException('storage_view_config_port_tempfile_failed');
}
$container = new Container();
Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $connection->getSchemaBuilder());
$container->instance('router', new Router(new Dispatcher($container), $container));
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());
DB::swap($capsule->getDatabaseManager());
Route::swap($container->make('router'));

try {
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    storageViewInstall($connection);
    $connection->getSchemaBuilder()->create('larena_setting_values', static function (Blueprint $table): void {
        $table->id();
        $table->string('setting_namespace', 120);
        $table->string('setting_key', 160);
        $table->string('scope', 32);
        $table->string('scope_id', 120);
        $table->json('value');
        $table->unsignedBigInteger('revision')->default(1);
        $table->timestamps();
        $table->unique(['setting_namespace', 'setting_key', 'scope', 'scope_id']);
    });
    DatabaseViewSettingsStore::createSchema($connection->getSchemaBuilder());
    $runtime = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope);
    $workbench = $runtime['workbench'];
    $access = new StorageViewFixtureAccess($actor, $scope);
    $options = array_map(static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)], ['new', 'paid']);
    $id = $workbench->createStructure($scope, ['structure_id' => 'workbench.orders', 'label' => 'Orders', 'fields' => [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('status', 20, 'choice', ['options' => $options]),
        storageViewField('amount', 30, 'number'),
    ]], $actor)->structureId;
    for ($n = 1; $n <= 3; $n++) {
        $workbench->createRecord($scope, $id, ['title' => 'New ' . $n, 'status' => 'new', 'amount' => (string) $n], $actor);
    }
    $workbench->createRecord($scope, $id, ['title' => 'Paid', 'status' => 'paid', 'amount' => '10'], $actor);

    $values = new DatabaseSettingValueRepository($connection);
    $store = ViewConfigStore::on($connection);
    $lists = new DataviewListPreferences($access, $workbench, new PersistentSettingRuntime($values, $connection), $values, $store);
    $gateway = new class($scope, $id) implements StorageWorkbenchGateway {
        public function __construct(private string $scope, private string $structure)
        {
        }

        public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
        {
            return ['status' => 'ok', 'scope_ref' => $this->scope, 'selected_structure' => ['structure_id' => $this->structure, 'fields' => []],
                'permissions' => ['record.create' => true], 'records' => ['items' => [], 'matched_count' => 0, 'matched_count_exact' => true]];
        }

        public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
        {
            return ['status' => 'rejected'];
        }
    };
    $translator = new class implements Translator {
        /** @var array<string, string> */
        public array $words = [];

        public function get($key, array $replace = [], $locale = null)
        {
            $words = $this->words[$key] ?? $key;
            foreach ($replace as $name => $value) {
                $words = str_replace(':' . $name, (string) $value, $words);
            }

            return $words;
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
    };
    $pack = require dirname(__DIR__, 2) . '/vendor/larena/setting/resources/lang/ru/view-config.php';
    foreach ($pack['refused'] as $key => $words) {
        $translator->words['larena-setting::view-config.refused.' . $key] = $words;
    }
    $exceptions = new class implements ExceptionHandler {
        /** @var list<Throwable> */
        public array $reported = [];

        public function report(Throwable $e)
        {
            $this->reported[] = $e;
        }

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e)
        {
        }
    };
    $labels = (new ReflectionClass(StorageReferenceLabelResolver::class))->newInstanceWithoutConstructor();
    $port = new StorageWorkbenchDataviewPort($gateway, $workbench, $labels, $lists, $translator,
        new UrlGenerator(new RouteCollection(), Request::create('http://localhost/')), $exceptions);
    $raise = static fn (string $intent, array $payload): array => $port->raise($actor, $scope, $id, $intent, $payload);
    $query = static fn (array $asked = []): array => $raise('query.change', ['sequence' => 1, 'query' => ['page' => 1, 'per_page' => 20] + $asked]);

    // The first answer carries the person's view state and the limits; nothing is set yet.
    $first = $query();
    storageViewExpect($first['answer'] === 'applied' && $first['data']['preferences'] === ['revision' => 0], 'preferences in the first answer: '
        . json_encode($first['data']['preferences'] ?? null) . json_encode($exceptions->reported ? $exceptions->reported[0]->getMessage() : null));
    storageViewExpect($first['data']['limits']['views_per_level'] === 20 && $first['data']['limits']['view_bytes'] === 8192
        && $first['data']['limits']['views_per_list'] === 20 && $first['data']['limits']['settings_bytes'] === 8192, 'the limits');
    storageViewExpect($first['data']['capabilities']['personal-preferences'] === false, 'not announced without a pinned port 1.16.0');
    storageViewExpect($first['data']['saved_views'] === [], 'no saved views yet');

    // preferences.save: only the changed fields, against the revision; a stale one is a conflict; null removes a field.
    $saved = $raise('preferences.save', ['preferences' => ['page_size' => 50, 'view_zone' => 'open'], 'base_revision' => 0]);
    storageViewExpect($saved === ['answer' => 'applied', 'data' => ['preferences' => ['revision' => 1, 'page_size' => 50, 'view_zone' => 'open']]],
        'preferences.save: ' . json_encode($saved));
    storageViewExpect($raise('preferences.save', ['preferences' => ['page_size' => 100], 'base_revision' => 0])
        === ['answer' => 'conflict', 'current_revision' => 1], 'a stale save of the view state');
    $raise('preferences.save', ['preferences' => ['view_zone' => null, 'view' => 'unpaid'], 'base_revision' => '1']);
    storageViewExpect($query()['data']['preferences'] === ['revision' => 2, 'page_size' => 50, 'view' => 'unpaid'], 'the view state reads back');
    $bad = $raise('preferences.save', ['preferences' => ['page_size' => 25], 'base_revision' => 2]);
    storageViewExpect($bad['answer'] === 'refused' && $bad['reason'] === 'preferences_invalid' && $bad['code'] === 'view_config_preferences_invalid'
        && str_contains($bad['message'], 'Настройки списка не сохранены'), 'a page size off the menu: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
    storageViewExpect($raise('preferences.save', ['preferences' => ['page_size' => 20], 'base_revision' => 2, 'person' => 'actor:admin:beta'])['answer'] === 'refused',
        'the person is never named in the payload');

    // A saved view is a view-config document of the person's level.
    $mine = $raise('view.save', ['key' => 'filter_template_1', 'label' => 'Мои новые', 'query' => ['filters' => ['status' => ['operator' => 'eq', 'value' => 'new']],
        'search' => 'не сохраняется', 'sort' => [['key' => 'title', 'direction' => 'desc']], 'per_page' => 50],
        'layout' => ['columns' => [['key' => 'title', 'visible' => true, 'width' => '200px']]], 'settings' => ['filter_fields' => ['status']]]);
    storageViewExpect($mine['answer'] === 'applied' && preg_match(DataviewListPreferences::NEW_SAVED_VIEW_ID, $mine['key']) === 1, 'view.save: ' . json_encode($mine));
    $list = DataviewListPreferences::listOf($scope, $id);
    $stored = $store->view($list, ViewConfigLevel::Personal, $mine['key'], $actor);
    storageViewExpect($stored !== null && ViewConfigDocument::problems($stored['document']) === [] && $stored['document']['source'] === $id
        && $stored['document']['query'] === ['filter' => ['status' => ['operator' => 'eq', 'value' => 'new']], 'sort' => [['field' => 'title', 'dir' => 'desc']],
            'per_page' => 50] && $stored['document']['head'] === ['filter_fields' => ['status']], 'the stored document: ' . json_encode($stored, JSON_UNESCAPED_UNICODE));
    $views = $query()['data']['saved_views'];
    storageViewExpect(count($views) === 1 && $views[0]['level'] === 'personal' && $views[0]['revision'] === 1 && $views[0]['query']['search'] === ''
        && $views[0]['settings'] === ['filter_fields' => ['status']], 'the saved view as the list reads it: ' . json_encode($views, JSON_UNESCAPED_UNICODE));
    $renamed = $raise('view.save', ['key' => $mine['key'], 'label' => 'Новые', 'revision' => 1, 'query' => []]);
    storageViewExpect($renamed === ['answer' => 'applied', 'key' => $mine['key']], 'a rename keeps the key');
    storageViewExpect($raise('view.save', ['key' => $mine['key'], 'label' => 'Новые', 'revision' => 1, 'query' => []])
        === ['answer' => 'conflict', 'current_revision' => 2], 'a stale view save');

    // A system view ships with its package: listed first with its level, never written or removed through the port.
    $system = StorageViewConfigCodec::document(['saved_view_id' => 'all', 'structure_id' => $id, 'name' => 'Все заказы', 'revision' => 1, 'roles' => [],
        'layout' => ['columns' => []], 'settings' => [], 'query' => ['per_page' => 20]]);
    storageViewExpect($store->ship($list, $system + ['required' => true], 'larena/storage'), 'the package ships its view');
    $views = $query()['data']['saved_views'];
    // The pinned port is below 1.16.0: the system level goes out as `package`, the name those editions know (LVC-2).
    storageViewExpect(array_column($views, 'level') === ['package', 'personal'] && $views[0]['key'] === 'all', 'levels: ' . json_encode(array_column($views, 'level')));
    storageViewExpect(StorageWorkbenchDataviewPort::wireLevel('system') === 'package' && StorageWorkbenchDataviewPort::wireLevel('shared') === 'shared',
        'the level on the wire');
    $opened = $raise('view.open', ['key' => 'all']);
    storageViewExpect($opened['answer'] === 'applied' && $opened['data']['column_settings']['target']['key'] === 'all', 'a system view opens');
    foreach (['view.save' => ['key' => 'all', 'label' => 'Все заказы', 'revision' => 1, 'query' => ['filters' => ['status' => ['operator' => 'eq', 'value' => 'new']]]],
        'view.delete' => ['key' => 'all']] as $intent => $payload) {
        $answer = $raise($intent, $payload);
        // One cause, one reason (LVC-7): the same as a column change of the view.
        storageViewExpect($answer['answer'] === 'refused' && $answer['reason'] === 'system_readonly' && $answer['code'] === 'view_config_system_readonly'
            && str_contains($answer['message'], 'Системное представление'), $intent . ' of a system view: ' . json_encode($answer, JSON_UNESCAPED_UNICODE));
    }
    $layout = $raise('settings.save_personal', ['columnSettings' => ['title' => ['width' => '300px']], 'base_revision' => 1, 'saved_view_id' => 'all']);
    storageViewExpect($layout['answer'] === 'refused' && $layout['reason'] === 'system_readonly' && str_contains($layout['message'], 'Системное представление'),
        'a column change of a system view: ' . json_encode($layout, JSON_UNESCAPED_UNICODE));
    storageViewExpect($store->view($list, ViewConfigLevel::System, 'all')['revision'] === 1, 'the system view keeps its revision');
    // «Сохранить как моё»: the change is a new view of the person's.
    $own = $raise('view.save', ['key' => 'filter_template_2', 'label' => 'Все мои', 'query' => ['filters' => ['status' => ['operator' => 'eq', 'value' => 'new']]]]);
    storageViewExpect($own['answer'] === 'applied' && $own['key'] !== 'all', 'saved as a new view');

    // Limits: 20 views of a level; the 21st is refused with reason limit and the limit's name, in the pack's words.
    for ($n = 3; $n <= 20; $n++) {
        storageViewExpect($raise('view.save', ['key' => 'filter_template_' . $n, 'label' => 'Вид ' . $n, 'query' => []])['answer'] === 'applied', 'view ' . $n);
    }
    $over = $raise('view.save', ['key' => 'filter_template_21', 'label' => 'Лишний', 'query' => []]);
    storageViewExpect($over['answer'] === 'refused' && $over['reason'] === 'limit' && $over['limit'] === 'views_per_level' && $over['code'] === 'view_config_limit'
        && str_contains($over['message'], '20 представлений'), 'the 21st view: ' . json_encode($over, JSON_UNESCAPED_UNICODE));
    $big = $raise('view.save', ['key' => $mine['key'], 'label' => 'Новые', 'revision' => 2, 'query' => [], 'settings' => ['notes' => str_repeat('x', 8100)],
        'layout' => ['columns' => [['key' => 'title', 'width' => '200px']]]]);
    storageViewExpect($big['answer'] === 'refused' && $big['reason'] === 'limit' && $big['limit'] === 'view_bytes', 'a view over 8 KB: ' . json_encode($big));
    // The person's view state is no view: it does not count toward the limit.
    storageViewExpect($query()['data']['preferences']['revision'] === 2, 'the view state is kept');

    // A board: every lane's first records and totals in one answer; one lane further from its cursor.
    $board = $query(['sort' => [['key' => 'title', 'direction' => 'desc']], 'window' => ['lanes' => 'status', 'per_lane' => 2, 'totals' => ['count', 'amount']]]);
    $lanes = $board['data']['lanes'] ?? [];
    storageViewExpect($board['answer'] === 'applied' && array_column($lanes, 'value') === ['new', 'paid'] && array_column($lanes, 'total') === [3, 1]
        && $board['data']['total'] === 4 && $lanes[0]['sums'] === ['amount' => 6] && count($lanes[0]['records']) === 2 && isset($lanes[0]['cursor']),
        'the board: ' . json_encode($board));
    storageViewExpect($lanes[0]['records'][0]['display_values']['title'] === 'New 3' && $board['data']['records'] === [], 'the board follows the list\'s sort');
    $lane = $query(['sort' => [['key' => 'title', 'direction' => 'desc']], 'window' => ['lanes' => 'status', 'per_lane' => 2, 'lane' => 'new', 'cursor' => $lanes[0]['cursor']]]);
    storageViewExpect(count($lane['data']['lanes']) === 1 && count($lane['data']['lanes'][0]['records']) === 1 && !isset($lane['data']['lanes'][0]['cursor']),
        'one lane further: ' . json_encode($lane));
    $paged = $query(['window' => ['cursor' => null, 'limit' => 50]]);
    storageViewExpect($paged['answer'] === 'applied' && !isset($paged['data']['lanes']), 'a page window is no board: ' . json_encode($paged['reason'] ?? null));
    $tooWide = $query(['window' => ['lanes' => 'status', 'per_lane' => 101]]);
    storageViewExpect($tooWide['answer'] === 'refused' && $tooWide['reason'] === 'limit' && $tooWide['limit'] === 'per_lane_max', 'per_lane over 100');
    storageViewExpect(!isset($lanes[0]['over_limit']), 'no open board view, no limits');
    // An open board view's soft limits reach the page as `over_limit` (LVC-3); its options survive a save of the view.
    $boardView = StorageViewConfigCodec::document(['saved_view_id' => 'orders-board', 'structure_id' => $id, 'name' => 'Доска', 'revision' => 1, 'roles' => [],
        'layout' => ['columns' => []], 'settings' => [], 'query' => ['dataview_id' => $id . '.kanban', 'per_page' => 20],
        'board' => ['lane_field' => 'status', 'limits' => ['new' => 2, 'paid' => 5]]]);
    storageViewExpect($boardView['layout'] === 'board' && $boardView['layouts']['board']['limits'] === ['new' => 2, 'paid' => 5]
        && ViewConfigDocument::problems($boardView) === [], 'the board options sit in layouts.board: ' . json_encode($boardView));
    storageViewExpect($store->ship($list, $boardView, 'larena/storage'), 'the package ships its board');
    $limited = $query(['saved_view_id' => 'orders-board', 'window' => ['lanes' => 'status', 'per_lane' => 2]]);
    storageViewExpect(array_column($limited['data']['lanes'] ?? [], 'over_limit', 'value') === ['new' => true, 'paid' => false],
        'over_limit by the board view: ' . json_encode($limited));

    // views.reset (LVC-5): the person's own copy of a view everyone has goes; the view for everyone opens again.
    // An id may start with a digit (LVC-1).
    storageViewExpect($raise('view.delete', ['key' => $own['key']])['answer'] === 'applied', 'room for one more view');
    $quarter = StorageViewConfigCodec::document(['saved_view_id' => '2026-q4', 'structure_id' => $id, 'name' => 'IV квартал', 'revision' => 1, 'roles' => [],
        'layout' => ['columns' => []], 'settings' => [], 'query' => ['per_page' => 20]]);
    $store->save($list, ViewConfigLevel::Shared, $quarter, 0);
    $store->save($list, ViewConfigLevel::Personal, ['name' => 'IV квартал, мой'] + $quarter, 0, $actor);
    $keys = array_count_values(array_column($query()['data']['saved_views'], 'key'));
    storageViewExpect(($keys['2026-q4'] ?? 0) === 2, 'a digit-led view on two levels: ' . json_encode($keys));
    $opened = $raise('view.open', ['key' => '2026-q4']);
    storageViewExpect($opened['answer'] === 'applied', 'a digit-led view opens: ' . json_encode($opened['reason'] ?? null));
    storageViewExpect($raise('view.reset', ['key' => '2026-q4', 'level' => 'personal', 'revision' => 5]) === ['answer' => 'conflict', 'current_revision' => 1],
        'a reset of a copy changed since it was read');
    $reset = $raise('view.reset', ['key' => '2026-q4', 'level' => 'personal', 'revision' => 1]);
    storageViewExpect($reset['answer'] === 'applied' && $reset['view']['level'] === 'shared' && $reset['view']['label'] === 'IV квартал',
        'views.reset: ' . json_encode($reset, JSON_UNESCAPED_UNICODE));
    storageViewExpect($store->view($list, ViewConfigLevel::Personal, '2026-q4', $actor) === null
        && $store->view($list, ViewConfigLevel::Shared, '2026-q4')['revision'] === 1, 'only the person\'s copy went');
    $nothing = $raise('views.reset', ['key' => $mine['key'], 'level' => 'personal']);
    storageViewExpect($nothing['answer'] === 'refused' && $nothing['reason'] === 'nothing_above' && $nothing['message'] !== '',
        'a view kept on one level only: ' . json_encode($nothing, JSON_UNESCAPED_UNICODE));
    $systemReset = $raise('view.reset', ['key' => 'all', 'level' => 'package']);
    storageViewExpect($systemReset['answer'] === 'refused' && $systemReset['reason'] === 'system_readonly', 'a system view is reset by nobody');
    storageViewExpect($raise('view.reset', ['key' => '2026-q4'])['reason'] === 'view_fields_invalid', 'a reset names its level');

    // A name is 1–120 characters (LVC-6): 120 Cyrillic letters are kept whole, 121 are refused, never cut.
    $long = str_repeat('я', 120);
    $named = $raise('view.save', ['key' => 'filter_template_long', 'label' => $long, 'query' => []]);
    storageViewExpect($named['answer'] === 'applied', 'a name of 120 characters: ' . json_encode($named));
    storageViewExpect($store->view($list, ViewConfigLevel::Personal, $named['key'], $actor)['document']['name'] === $long, 'the name is kept whole');
    $tooLong = $raise('view.save', ['key' => 'filter_template_longer', 'label' => $long . 'я', 'query' => []]);
    storageViewExpect($tooLong === ['answer' => 'refused', 'reason' => 'view_name_too_long', 'limit' => 120], 'a name of 121 characters: ' . json_encode($tooLong));

    // Lane limits from the list page (view.save_board): written into layouts.board against the level's own revision.
    storageViewExpect($raise('view.delete', ['key' => $named['key']])['answer'] === 'applied'
        && $raise('view.delete', ['key' => $mine['key']])['answer'] === 'applied', 'room for the person\'s board copies');
    $team = StorageViewConfigCodec::document(['saved_view_id' => 'team-board', 'structure_id' => $id, 'name' => 'Доска команды', 'revision' => 1,
        'roles' => [], 'layout' => ['columns' => []], 'settings' => [], 'query' => ['dataview_id' => $id . '.kanban', 'per_page' => 20]]);
    $store->save($list, ViewConfigLevel::Shared, $team, 0);
    $lanesOf = static function (string $view) use ($query): array {
        $lanes = [];
        foreach ($query(['saved_view_id' => $view, 'window' => ['lanes' => 'status', 'per_lane' => 2]])['data']['lanes'] ?? [] as $lane) {
            $lanes[$lane['value']] = array_intersect_key($lane, ['total' => true, 'limit' => true, 'over_limit' => true]);
        }

        return $lanes;
    };
    storageViewExpect($lanesOf('team-board') === ['new' => ['total' => 3], 'paid' => ['total' => 1]], 'a board view without limits');
    // Everyone's level, with the right to change shared views: a form's digits are numbers, an empty lane has none.
    $teamSaved = $raise('view.save_board', ['key' => 'team-board', 'level' => 'shared', 'revision' => 1, 'lane_field' => 'status',
        'limits' => ['new' => '2', 'paid' => '']]);
    storageViewExpect($teamSaved['answer'] === 'applied' && $teamSaved['revision'] === 2 && $teamSaved['level'] === 'shared'
        && (array) $teamSaved['limits'] === ['new' => 2], 'view.save_board for everyone: ' . json_encode($teamSaved));
    storageViewExpect($store->view($list, ViewConfigLevel::Shared, 'team-board')['document']['layouts']['board']
        === ['columns' => [], 'roles' => [], 'settings' => [], 'lane_field' => 'status', 'limits' => ['new' => 2]],
        'the limits sit in layouts.board: ' . json_encode($store->view($list, ViewConfigLevel::Shared, 'team-board')));
    // The page answer carries the limits after the save: «N из M» and the over-limit mark.
    storageViewExpect($lanesOf('team-board') === ['new' => ['total' => 3, 'limit' => 2, 'over_limit' => true], 'paid' => ['total' => 1]],
        'the board after the save: ' . json_encode($lanesOf('team-board')));
    // A save against the revision read before is a conflict and changes nothing.
    storageViewExpect($raise('view.save_board', ['key' => 'team-board', 'level' => 'shared', 'revision' => 1, 'limits' => ['new' => 9]])
        === ['answer' => 'conflict', 'current_revision' => 2], 'a stale board save');
    storageViewExpect($lanesOf('team-board')['new']['limit'] === 2, 'the conflict changed nothing');
    // The person's own limits: a copy of their own over the view everyone has, the view for everyone untouched.
    $own = $raise('view.save_board', ['key' => 'team-board', 'revision' => 0, 'limits' => ['new' => 5, 'paid' => 1]]);
    storageViewExpect($own['answer'] === 'applied' && $own['level'] === 'personal' && $own['revision'] === 1, 'the person\'s own limits: ' . json_encode($own));
    storageViewExpect($lanesOf('team-board') === ['new' => ['total' => 3, 'limit' => 5, 'over_limit' => false], 'paid' => ['total' => 1, 'limit' => 1, 'over_limit' => false]],
        'the person\'s limits are the ones the board reads: ' . json_encode($lanesOf('team-board')));
    storageViewExpect($store->view($list, ViewConfigLevel::Shared, 'team-board')['revision'] === 2, 'the view for everyone is untouched');
    $again = $raise('view.save_board', ['key' => 'team-board', 'revision' => 1, 'limits' => ['new' => 5, 'paid' => 1]]);
    storageViewExpect($again['answer'] === 'applied' && $again['level'] === 'personal' && $again['revision'] === 2, 'the person\'s copy again: ' . json_encode($again));
    // A save of the view itself keeps its board options.
    storageViewExpect($raise('view.save', ['key' => 'team-board', 'label' => 'Доска команды', 'revision' => 2, 'query' => ['filters' => (object) []]])['answer'] === 'applied',
        'the view is saved again');
    storageViewExpect($lanesOf('team-board')['new']['limit'] === 5, 'the limits survive a save of the view');
    // views.reset to the level above drops the person's limits: the board reads everyone's again.
    $back = $raise('views.reset', ['key' => 'team-board', 'level' => 'personal']);
    storageViewExpect($back['answer'] === 'applied' && $back['view']['level'] === 'shared', 'the reset: ' . json_encode($back));
    storageViewExpect($lanesOf('team-board') === ['new' => ['total' => 3, 'limit' => 2, 'over_limit' => true], 'paid' => ['total' => 1]],
        'after the reset: ' . json_encode($lanesOf('team-board')));
    // A system view is read-only on every level.
    foreach ([['revision' => 0], ['revision' => 1, 'level' => 'shared']] as $asked) {
        $refused = $raise('view.save_board', ['key' => 'orders-board', 'limits' => ['new' => 3]] + $asked);
        storageViewExpect($refused['answer'] === 'refused' && $refused['reason'] === 'system_readonly' && $refused['code'] === 'view_config_system_readonly',
            'board limits of a system view: ' . json_encode($refused, JSON_UNESCAPED_UNICODE));
    }
    storageViewExpect($lanesOf('orders-board')['new']['limit'] === 2, 'the system view keeps its limits');
    // Without the right to change shared views, everyone's level is refused.
    $stranger = $port->raise('actor:admin:beta', $scope, $id, 'view.save_board', ['key' => 'team-board', 'level' => 'shared', 'revision' => 2, 'limits' => []]);
    storageViewExpect($stranger['answer'] === 'refused', 'everyone\'s limits without the right: ' . json_encode($stranger));
    // Limits a description refuses, a view that is no board, a payload of other keys.
    storageViewExpect($raise('view.save_board', ['key' => 'team-board', 'level' => 'shared', 'revision' => 2, 'limits' => ['new' => 0]])['reason']
        === 'storage_board_description_invalid:limits', 'a limit below 1');
    storageViewExpect($raise('view.save_board', ['key' => 'team-board', 'level' => 'shared', 'revision' => 2, 'limits' => ['new' => '2.5']])['reason']
        === 'storage_board_description_invalid:limits', 'a limit that is no whole number');
    storageViewExpect($raise('view.save_board', ['key' => '2026-q4', 'level' => 'shared', 'revision' => 1, 'limits' => ['new' => 2]])['reason']
        === 'dataview_list_saved_view_not_board', 'a table view has no lanes');
    storageViewExpect($raise('view.save_board', ['key' => 'team-board', 'revision' => 0, 'limits' => [], 'hard' => true])['reason']
        === 'view_fields_invalid', 'no hard limits in the contract');

    echo "StorageViewConfigPortTest passed.\n";
} finally {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
    @unlink($path);
}
