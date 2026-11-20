<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Board\StorageBoardWindow;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The board window of simai.view-config/1 (contract §3, Larena 2026-11-19), on SQLite:
// - one request: every lane's first per_lane records (20 by default) and every lane's total and sums, in the answer's
//   shape {lanes: [{value, total, sums?, over_limit?, records[], cursor?}]};
// - one lane further: {lanes, lane, cursor} answers that lane alone;
// - per_lane at most 100, at most 200 lanes: more is refused with the limit's name, never cut silently; all 200 lanes
//   carry their first records.
$path = tempnam(sys_get_temp_dir(), 'larena-storage-board-window-');
if (!is_string($path)) {
    throw new RuntimeException('storage_board_window_tempfile_failed');
}
$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());

try {
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    storageViewInstall($connection);
    $workbench = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope)['workbench'];
    $options = array_map(static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)], ['new', 'paid', 'sent']);
    $id = $workbench->createStructure($scope, ['structure_id' => 'workbench.orders', 'label' => 'Orders', 'fields' => [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('status', 20, 'choice', ['options' => $options]),
        storageViewField('amount', 30, 'number'),
        storageViewField('manager', 40, 'user'),
    ]], $actor)->structureId;
    for ($n = 1; $n <= 25; $n++) {
        $workbench->createRecord($scope, $id, ['title' => sprintf('New %02d', $n), 'status' => 'new', 'amount' => '10'], $actor);
    }
    $workbench->createRecord($scope, $id, ['title' => 'Paid A', 'status' => 'paid', 'amount' => '100.5'], $actor);
    $workbench->createRecord($scope, $id, ['title' => 'Loose A', 'amount' => '1'], $actor);
    $window = new StorageBoardWindow($workbench);
    $list = new StorageWorkbenchRecordQuery($scope, $id, [], null, [['field' => 'title', 'direction' => 'asc']]);
    $refused = static function (callable $call, string $code): void {
        storageViewRejects($call, $code);
    };

    // One request: every lane, its first 20 and its totals; the sums are numbers; a lane over its soft limit says so.
    $answer = $window->answer(['lanes' => 'status', 'totals' => ['count', 'amount']], $list, $actor,
        static fn (StorageWorkbenchRecord $record): array => ['title' => $record->values['title']], ['new' => 20, 'paid' => 5]);
    $lanes = $answer['lanes'];
    storageViewExpect(array_column($lanes, 'value') === ['new', 'paid', null], 'the lanes: ' . json_encode(array_column($lanes, 'value')));
    storageViewExpect(array_column($lanes, 'total') === [25, 1, 1], 'the totals');
    storageViewExpect(count($lanes[0]['records']) === 20 && isset($lanes[0]['cursor']) && !isset($lanes[1]['cursor']), 'the first lane is 20 of 25 with a cursor');
    storageViewExpect($lanes[0]['sums'] === ['amount' => 250] && $lanes[1]['sums'] === ['amount' => 100.5], 'the sums: ' . json_encode(array_column($lanes, 'sums')));
    storageViewExpect($lanes[0]['over_limit'] === true && $lanes[1]['over_limit'] === false && !isset($lanes[2]['over_limit']), 'over_limit');
    storageViewExpect(array_keys($lanes[0]['records'][0]) === ['id', 'revision', 'title'] && $lanes[0]['records'][0]['title'] === 'New 01'
        && $lanes[0]['records'][0]['revision'] === '1', 'a record is its id, revision and what the host adds');
    storageViewExpect(array_keys($lanes[1]) === ['value', 'total', 'sums', 'over_limit', 'records'], 'the lane shape: ' . json_encode(array_keys($lanes[1])));

    // One lane further, from its cursor: that lane alone, the rest of it.
    $more = $window->answer(['lanes' => 'status', 'lane' => 'new', 'cursor' => $lanes[0]['cursor']], $list, $actor)['lanes'];
    storageViewExpect(count($more) === 1 && $more[0]['value'] === 'new' && $more[0]['total'] === 25 && count($more[0]['records']) === 5
        && !isset($more[0]['cursor']), 'the lane page');
    // per_lane: default 20, at most 100.
    $two = $window->answer(['lanes' => 'status', 'per_lane' => 2], $list, $actor)['lanes'];
    storageViewExpect(count($two[0]['records']) === 2 && isset($two[0]['cursor']) && !isset($two[0]['sums']), 'per_lane 2, count only');
    $refused(static fn () => $window->answer(['lanes' => 'status', 'per_lane' => 101], $list, $actor), 'storage_board_window_limit:per_lane_max');
    $refused(static fn () => $window->answer(['lanes' => 'status', 'cursor' => 'x'], $list, $actor), 'storage_board_window_invalid');
    $refused(static fn () => $window->answer(['lanes' => 'status', 'page' => 2], $list, $actor), 'storage_board_window_invalid');
    $refused(static fn () => $window->answer(['lanes' => 'title'], $list, $actor), 'storage_workbench_board_lane_field_invalid');

    // 200 lanes: every one carries its first records. One more is refused, not cut.
    for ($n = 1; $n <= 197; $n++) {
        $workbench->createRecord($scope, $id, ['title' => sprintf('M %03d', $n), 'manager' => 'user:admin:' . $n], $actor);
    }
    $managers = $window->answer(['lanes' => 'manager'], $list, $actor)['lanes'];
    // 197 managers and the lane without one (the first 27 records).
    storageViewExpect(count($managers) === 198, 'lanes: ' . count($managers));
    storageViewExpect(array_filter($managers, static fn (array $lane): bool => count($lane['records']) === 0) === [], 'a lane past 50 has no records');
    $workbench->createRecord($scope, $id, ['title' => 'M 198', 'manager' => 'user:admin:198'], $actor);
    $workbench->createRecord($scope, $id, ['title' => 'M 199', 'manager' => 'user:admin:199'], $actor);
    storageViewExpect(count($window->answer(['lanes' => 'manager'], $list, $actor)['lanes']) === 200, 'two hundred lanes');
    $workbench->createRecord($scope, $id, ['title' => 'M 200', 'manager' => 'user:admin:200'], $actor);
    $refused(static fn () => $window->answer(['lanes' => 'manager'], $list, $actor), 'storage_board_window_limit:lanes_max');

    echo "StorageBoardWindowTest passed.\n";
} finally {
    @unlink($path);
}
