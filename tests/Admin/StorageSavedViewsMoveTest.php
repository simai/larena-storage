<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Setting\Persistence\DatabaseViewSettingsStore;
use Larena\Setting\ViewConfig\ViewConfigDocument;
use Larena\Setting\ViewConfig\ViewConfigLevel;
use Larena\Setting\ViewConfig\ViewConfigStore;
use Larena\Storage\Admin\DataviewListPreferences;
use Larena\Storage\Admin\StorageSavedViewsMove;
use Larena\Storage\Admin\StorageViewConfigCodec;

require_once __DIR__ . '/bootstrap.php';

// The one-time move of the Storage lists' saved views into larena_view_settings (simai.view-config/1), on a copy:
// - up: both levels move (a person's views → personal, a scope's views for everyone → shared), from the key the
//   lists read first (list_views, then saved_views.v2, then the oldest saved_views with the person's columns);
//   each document holds to the contract and reads back as the view the lists had (the search text is never saved);
// - repeat: nothing is written twice; the old rows are never changed;
// - down: the rows the move wrote and nobody changed since go; a view saved after the move stays; the old rows
//   are as they were, so the lists before the move read them as before; up again moves them back.
$path = tempnam(sys_get_temp_dir(), 'larena-storage-views-move-');
if (!is_string($path)) {
    throw new RuntimeException('storage_views_move_tempfile_failed');
}

try {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
    $connection = $capsule->getConnection();
    $schema = $connection->getSchemaBuilder();
    $schema->create('larena_setting_values', static function (Blueprint $table): void {
        $table->id();
        $table->string('setting_namespace', 120);
        $table->string('setting_key', 160);
        $table->string('scope', 32);
        $table->string('scope_id', 120);
        $table->json('value');
        $table->unsignedBigInteger('revision')->default(1);
        $table->timestamps();
    });
    $schema->create('larena_storage_workbench_structures', static function (Blueprint $table): void {
        $table->string('scope_ref', 191);
        $table->string('structure_id', 120);
    });
    DatabaseViewSettingsStore::createSchema($schema);
    $connection->table('larena_storage_workbench_structures')->insert([
        ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.orders'],
        ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.clients'],
        ['scope_ref' => 'scope:beta', 'structure_id' => 'workbench.clients'],
    ]);
    $query = static fn (array $more = []): array => $more + ['dataview_id' => null, 'search' => null, 'filters' => [], 'filter_field' => null,
        'filter_value' => null, 'sort_field' => null, 'sort_direction' => null, 'per_page' => 20, 'include_deleted' => false];
    $view = static fn (string $id, string $structure, string $name, array $query, array $more = []): array => $more + ['saved_view_id' => $id,
        'structure_id' => $structure, 'name' => $name, 'query' => $query, 'revision' => 1, 'roles' => [], 'layout' => ['columns' => []], 'settings' => []];
    $id = static fn (int $n): string => 'saved-view-' . str_pad((string) $n, 24, '0', STR_PAD_LEFT);
    $anna = 'user:admin_identity:1';
    $boris = 'user:admin_identity:2';
    $vera = 'user:admin_identity:3';
    $annaViews = [
        $view($id(1), 'workbench.orders', 'Срочные', $query(['dataview_id' => 'workbench.orders.kanban', 'search' => 'Ромашка',
            'filters' => ['status' => ['operator' => 'in', 'values' => ['new', 'work']], 'due' => ['operator' => 'week']],
            'sort_field' => 'due', 'sort_direction' => 'asc', 'per_page' => 50, 'include_deleted' => true]), [
            'revision' => 4, 'roles' => ['lane' => 'status'], 'layout' => ['columns' => ['title' => ['visible' => true, 'width' => '240px'], 'note' => ['visible' => false]]],
            'settings' => ['filter_fields' => ['status', 'due'], 'density' => 'compact']]),
        $view($id(2), 'workbench.clients', 'Казань', $query(['filter_field' => 'city', 'filter_value' => 'Казань'])),
        $view($id(3), 'workbench.orders', 'План', $query(['dataview_id' => 'workbench.orders.gantt'])),
    ];
    $sharedViews = [$view($id(4), 'workbench.orders', 'Для всех', $query(['per_page' => 10]), ['revision' => 2])];
    $rows = [
        ['list_views', 'user', $anna, $annaViews, 6],
        ['list_views', 'system', 'scope:alpha', $sharedViews, 3],
        // Boris's list was never written under its new key: it is read from the one before.
        ['saved_views.v2', 'user', $boris, [$view($id(5), 'workbench.orders', 'Старый ключ', $query())], 2],
        // Vera's are the oldest views: no layout; they take her columns of that storage.
        ['saved_views', 'user', $vera, ['kind' => 'array', 'value' => [['saved_view_id' => $id(6), 'structure_id' => 'workbench.orders',
            'name' => 'Самый старый', 'query' => $query(['per_page' => 100])]]], 1],
        ['columns.' . hash('sha256', "scope:alpha\0workbench.orders"), 'user', $vera, ['title' => ['order' => 0]], 1],
        // A view of a storage nobody has any more stays where it is.
        ['list_views', 'user', 'user:admin_identity:4', [$view($id(7), 'workbench.gone', 'Нет хранилища', $query())], 1],
        // Another namespace is not touched.
        ['list_views', 'user', $anna, [], 1, 'other_namespace'],
    ];
    foreach ($rows as $row) {
        $connection->table('larena_setting_values')->insert(['setting_namespace' => $row[5] ?? 'larena_dataview', 'setting_key' => $row[0],
            'scope' => $row[1], 'scope_id' => $row[2], 'value' => json_encode($row[3], JSON_UNESCAPED_UNICODE), 'revision' => $row[4],
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']);
    }
    $old = static fn (): array => array_map(static fn (object $row): array => (array) $row,
        $connection->table('larena_setting_values')->orderBy('id')->get()->all());
    $before = $old();

    // up, through the migration file, on the migration's connection.
    $container = new Container();
    Container::setInstance($container);
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $schema);
    Schema::swap($schema);
    $migration = require __DIR__ . '/../../database/migrations/2026_11_20_000001_move_larena_storage_saved_views_to_view_settings.php';
    $migration->up();
    $store = ViewConfigStore::on($connection);
    $moved = static fn (): int => $connection->table(DatabaseViewSettingsStore::TABLE)->where('component', ViewConfigStore::COMPONENT)->count();
    // Anna's three (one storage in one scope each, clients in two scopes: 4 rows), the shared one, Boris's, Vera's.
    $count = $moved();
    assert($count === 7, (string) $count);
    assert($old() === $before, 'the old rows are not changed');
    $orders = DataviewListPreferences::listOf('scope:alpha', 'workbench.orders');
    $annaOrders = $store->views($orders, $anna);
    assert(array_map(static fn (array $entry): string => $entry['level']->value . ':' . $entry['document']['id'], $annaOrders)
        === ['shared:' . $id(4), 'personal:' . $id(1), 'personal:' . $id(3)]);
    foreach ($annaOrders as $entry) {
        assert(ViewConfigDocument::problems($entry['document']) === [], implode(',', ViewConfigDocument::problems($entry['document'])));
    }
    $urgent = $annaOrders[1];
    assert($urgent['revision'] === 4 && $urgent['document']['layout'] === 'board' && $urgent['document']['query']['per_page'] === 50);
    assert($urgent['document']['query']['filter'] === ['_archived' => ['operator' => 'eq', 'value' => true],
        'due' => ['operator' => 'week'], 'status' => ['operator' => 'in', 'value' => ['new', 'work']]]);
    assert($urgent['document']['query']['sort'] === [['field' => 'due', 'dir' => 'asc']] && $urgent['document']['head'] === ['filter_fields' => ['status', 'due']]);
    assert(!isset($urgent['document']['query']['search']), 'the search text is never saved');
    // It reads back as the view the lists had, without the search text.
    $back = StorageViewConfigCodec::view($urgent['document'], 'workbench.orders');
    $expected = $annaViews[0];
    $expected['query']['search'] = null;
    ksort($expected['query']['filters']);
    $expected['settings'] = ['density' => 'compact', 'filter_fields' => ['status', 'due']];
    assert($back == $expected, json_encode($back, JSON_UNESCAPED_UNICODE));
    assert(StorageViewConfigCodec::view($annaOrders[2]['document'], 'workbench.orders')['query']['dataview_id'] === 'workbench.orders.gantt',
        'a gantt view keeps its type beside the table layout');
    assert($annaOrders[2]['document']['layout'] === 'table' && $annaOrders[2]['document']['layouts']['table']['view_type'] === 'gantt');
    // The oldest single filter is an eq condition; the person's views went to every scope that has the storage.
    foreach (['scope:alpha', 'scope:beta'] as $scope) {
        $kazan = $store->view(DataviewListPreferences::listOf($scope, 'workbench.clients'), ViewConfigLevel::Personal, $id(2), $anna);
        assert($kazan !== null && $kazan['document']['query']['filter'] === ['city' => ['operator' => 'eq', 'value' => 'Казань']]);
    }
    assert($store->view($orders, ViewConfigLevel::Personal, $id(5), $boris)['document']['name'] === 'Старый ключ');
    $oldest = $store->view($orders, ViewConfigLevel::Personal, $id(6), $vera);
    assert($oldest !== null && $oldest['document']['query']['per_page'] === 100 && $oldest['document']['layouts']['table']['columns'] === ['title' => ['order' => 0]]);
    assert($store->views($orders, $boris)[0]['document']['id'] === $id(4), 'the shared view is everyone\'s');

    // Repeat: nothing written twice.
    $report = (new StorageSavedViewsMove($connection))->up();
    assert($report['moved'] === 0 && $report['present'] === 7 && $report['skipped'] === ['user:' . $id(7) . ':storage_gone'], json_encode($report));
    $count = $moved();
    assert($count === 7 && $old() === $before);

    // A person saves a moved view after the move: down leaves it, removes the rest, and the old rows read as before.
    $store->save($orders, ViewConfigLevel::Personal, ['name' => 'Срочные мои'] + $urgent['document'], 4, $anna, $anna);
    $migration->down();
    $count = $moved();
    assert($count === 1 && $store->view($orders, ViewConfigLevel::Personal, $id(1), $anna)['document']['name'] === 'Срочные мои');
    assert($old() === $before, 'down never touches the old rows');
    // Up again: the others come back; the one saved since keeps what the person saved.
    $report = (new StorageSavedViewsMove($connection))->up();
    assert($report['moved'] === 6 && $report['present'] === 1, json_encode($report));
    assert($store->view($orders, ViewConfigLevel::Personal, $id(1), $anna)['revision'] === 5);

    // Without the old table or the store there is nothing to move.
    $schema->drop('larena_setting_values');
    assert((new StorageSavedViewsMove($connection))->up() === ['moved' => 0, 'present' => 0, 'oversized' => 0, 'skipped' => []]);

    echo "StorageSavedViewsMoveTest passed.\n";
} finally {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
    @unlink($path);
}
