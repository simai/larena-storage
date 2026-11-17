<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Board\StorageBoardDescription;
use Larena\Storage\Board\StorageBoardMove;
use Larena\Storage\Contracts\StorageWorkbenchBoardQuery;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// A board of a storage (C304, board study BV-3…BV-5), on SQLite:
// - one call: the first records of every lane in list order and the totals of all lanes (count and sums, one grouped
//   query); the list's filter and search cut the lanes; lanes in the field's order, no value last;
// - «Показать ещё»: one lane further by its continuation, which only that lane takes;
// - a yes/no lane field and a user lane field; a lane field or a sum of the wrong type is refused;
// - the description: its options checked against the fields; lane order, hidden, collapsed, empty lanes, soft limits;
// - a move: the lane field changes; a move against a rule or without the ability is refused with its code and reason
//   and writes nothing; the storage's own refusals (a stale revision) come back the same way.

$path = tempnam(sys_get_temp_dir(), 'larena-storage-board-');
if (!is_string($path)) {
    throw new RuntimeException('storage_board_tempfile_failed');
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
    $statuses = ['new', 'wait', 'paid', 'sent', 'cancel'];
    $options = array_map(static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)], $statuses);
    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.orders', 'label' => 'Orders', 'fields' => [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('status', 20, 'choice', ['options' => $options]),
        storageViewField('amount', 30, 'number'),
        storageViewField('qty', 40, 'integer'),
        storageViewField('urgent', 50, 'boolean'),
        storageViewField('manager', 60, 'user'),
    ]], $actor);
    $id = $structure->structureId;
    // 30 new, 3 paid, 1 sent, 2 without a status; nothing waits or is cancelled.
    $made = [];
    $make = static function (string $title, ?string $status, string $amount, int $qty, bool $urgent, ?string $manager) use ($workbench, $scope, $id, $actor, &$made): string {
        $values = ['title' => $title, 'amount' => $amount, 'qty' => $qty, 'urgent' => $urgent]
            + ($status !== null ? ['status' => $status] : []) + ($manager !== null ? ['manager' => $manager] : []);
        $record = $workbench->createRecord($scope, $id, $values, $actor);
        $made[$title] = $record;

        return $record->recordId;
    };
    for ($n = 1; $n <= 30; $n++) {
        $make(sprintf('New %02d', $n), 'new', $n . '.50', $n, $n % 2 === 0, 'user:admin:' . ($n % 3 + 1));
    }
    $make('Paid A', 'paid', '100.25', 1, true, 'user:admin:1');
    $make('Paid B', 'paid', '200.25', 2, false, 'user:admin:1');
    $make('Paid C', 'paid', '300', 3, false, null);
    $make('Sent A', 'sent', '10', 4, true, 'user:admin:2');
    $make('Loose A', null, '1', 5, false, null);
    $make('Loose B', null, '2', 6, false, null);
    $list = static fn (array $filters = [], ?string $search = null, array $sort = [['field' => 'title', 'direction' => 'asc']], int $limit = 20, ?string $continuation = null): StorageWorkbenchRecordQuery
        => new StorageWorkbenchRecordQuery($scope, $id, $filters, $search, $sort, $limit, $continuation);

    // One call: every lane with records, their first 20 in list order, totals of all lanes.
    $board = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'status', ['amount', 'qty']), $actor);
    storageViewExpect(array_map(static fn ($lane): string => $lane->key, $board->lanes) === ['new', 'paid', 'sent', ''],
        'the lanes are not in the field order with no value last: ' . json_encode(array_map(static fn ($lane): string => $lane->key, $board->lanes)));
    storageViewExpect($board->total === 36, 'the board total is not 36: ' . $board->total);
    $new = $board->lane('new');
    storageViewExpect($new !== null && $new->total === 30 && count($new->records) === 20 && $new->continuation !== null, 'the new lane is not 20 of 30 with more');
    storageViewExpect($new->records[0]->values['title'] === 'New 01' && $new->records[19]->values['title'] === 'New 20', 'the new lane is not in list order');
    storageViewExpect($new->sums === ['amount' => (string) (array_sum(range(1, 30)) + 15), 'qty' => '465'], 'the new lane sums are wrong: ' . json_encode($new->sums));
    $paid = $board->lane('paid');
    storageViewExpect($paid !== null && $paid->total === 3 && count($paid->records) === 3 && $paid->continuation === null, 'the paid lane is not 3 without more');
    storageViewExpect($paid->sums === ['amount' => '600.5', 'qty' => '6'], 'the paid lane sums are wrong: ' . json_encode($paid->sums));
    $loose = $board->lane('');
    storageViewExpect($loose !== null && $loose->value === null && $loose->total === 2, 'the lane without a value is wrong');

    // «Показать ещё»: the new lane from its continuation, the last 10; the continuation is the new lane's only.
    $more = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(continuation: $new->continuation), 'status', [], 'new'), $actor);
    $rest = $more->lane('new');
    storageViewExpect(count($more->lanes) === 1 && $rest !== null && count($rest->records) === 10 && $rest->continuation === null && $rest->total === 30,
        'the more call did not give the last 10 of the new lane');
    storageViewExpect($rest->records[0]->values['title'] === 'New 21' && $rest->records[9]->values['title'] === 'New 30', 'the more call is not in list order');
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(continuation: $new->continuation), 'status', [], 'paid'), $actor),
        'storage_workbench_record_continuation_invalid');
    // A lane asked without a continuation starts at its first record.
    $first = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(limit: 2), 'status', [], 'paid'), $actor)->lane('paid');
    storageViewExpect($first !== null && count($first->records) === 2 && $first->records[0]->values['title'] === 'Paid A' && $first->continuation !== null,
        'a lane without a continuation does not start at its first record');

    // The list's filter and search cut the lanes; the sort orders each lane.
    $filtered = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(['urgent' => ['operator' => 'eq', 'value' => true]], null,
        [['field' => 'amount', 'direction' => 'desc']]), 'status', ['amount']), $actor);
    $filteredNew = $filtered->lane('new');
    storageViewExpect($filtered->total === 17 && $filteredNew !== null && $filteredNew->total === 15 && $filteredNew->records[0]->values['title'] === 'New 30',
        'the filter or the sort did not cut the lanes: ' . $filtered->total);
    storageViewExpect($filtered->lane('') === null, 'a lane without records under the filter is listed');
    $searched = $workbench->listBoard(new StorageWorkbenchBoardQuery($list([], 'paid'), 'status'), $actor);
    storageViewExpect(array_map(static fn ($lane): string => $lane->key, $searched->lanes) === ['paid'] && $searched->total === 3, 'the search did not cut the lanes');

    // A yes/no lane field (no, then yes) and a user lane field (the lane without a value last).
    $urgent = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'urgent'), $actor);
    $yes = $urgent->lane('true');
    storageViewExpect(array_map(static fn ($lane): string => $lane->key, $urgent->lanes) === ['false', 'true'] && $yes !== null && $yes->value === true
        && $yes->total === 17, 'the yes/no lanes are wrong');
    $urgentMore = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(limit: 5), 'urgent', [], 'true'), $actor)->lane('true');
    storageViewExpect($urgentMore !== null && count($urgentMore->records) === 5 && $urgentMore->records[0]->values['urgent'] === true, 'a yes/no lane is not read alone');
    $managers = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'manager'), $actor);
    storageViewExpect(array_map(static fn ($lane): string => $lane->key, $managers->lanes) === ['user:admin:1', 'user:admin:2', 'user:admin:3', ''],
        'the user lanes are wrong: ' . json_encode(array_map(static fn ($lane): string => $lane->key, $managers->lanes)));
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'title'), $actor), 'storage_workbench_board_lane_field_invalid');
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'missing'), $actor), 'storage_workbench_board_lane_field_invalid');
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'status', ['title']), $actor), 'storage_workbench_board_sum_field_invalid');
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'urgent', [], 'maybe'), $actor), 'storage_workbench_board_lane_invalid');
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(limit: 101), 'status'), $actor), 'storage_workbench_record_list_limit_invalid');
    storageViewRejects(static fn () => $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'status'), 'actor:admin:beta'), 'storage_workbench_scope_denied');

    // The description: options checked against the fields, and the board laid out by it.
    $fields = $workbench->readStructure($scope, $id, $actor)->fields;
    $description = StorageBoardDescription::fromArray([
        'lane_field' => 'status',
        'lanes' => ['order' => ['paid', 'new'], 'hidden' => ['cancel'], 'collapsed' => ['sent']],
        'card_fields' => ['manager', 'amount'],
        'totals' => ['count', ['sum' => 'amount']],
        'limits' => ['paid' => 2],
        'moves' => [
            'sent' => ['from' => ['paid'], 'reason' => 'Отправить можно только оплаченный заказ'],
            'cancel' => ['ability' => 'orders.cancel', 'reason' => 'Отменяет только руководитель'],
        ],
    ], $fields);
    $laid = $description->arrange($workbench->listBoard($description->query($list()), $actor));
    storageViewExpect(array_map(static fn (array $lane): string => $lane['lane']->key, $laid['lanes']) === ['paid', 'new', 'wait', 'sent', ''],
        'the lanes are not laid out by the description: ' . json_encode(array_map(static fn (array $lane): string => $lane['lane']->key, $laid['lanes'])));
    storageViewExpect($laid['hidden'] === ['cancel'] && $laid['lanes'][0]['over_limit'] === true && $laid['lanes'][0]['limit'] === 2
        && $laid['lanes'][3]['collapsed'] === true && $laid['lanes'][2]['lane']->total === 0, 'hidden, collapsed, empty or limited lanes are wrong');
    $hideEmpty = StorageBoardDescription::fromArray(['lane_field' => 'status', 'empty_lanes' => 'hide'], $fields);
    storageViewExpect(count($hideEmpty->arrange($board)['lanes']) === 4, 'empty lanes are shown when hidden');
    $described = $description->describe();
    storageViewExpect($described['board']['lane_field'] === 'status' && count($described['lane_values']) === 5 && $described['lane_values'][0]['label'] === 'New',
        'describe() does not give the board');
    $byKey = array_column($described['fields'], null, 'key');
    storageViewExpect($byKey['qty']['type'] === 'number' && $byKey['qty']['decimals'] === 0 && $byKey['status']['template'] === 'dropdown'
        && $byKey['urgent']['template'] === 'switch', 'describe() does not give the fields in the catalogue words');
    foreach ([
        [['lane_field' => 'title'], 'lane_field'],
        [['lane_field' => 'status', 'card_fields' => ['a', 'b', 'c', 'd', 'e']], 'card_fields'],
        [['lane_field' => 'status', 'totals' => [['sum' => 'title']]], 'totals'],
        [['lane_field' => 'status', 'order' => 'manual'], 'order'],
        [['lane_field' => 'status', 'order' => 'manual', 'order_field' => 'qty'], null],
        [['lane_field' => 'status', 'swimlane_field' => 'manager'], 'swimlane_field'],
        [['lane_field' => 'status', 'per_lane' => 500], 'per_lane'],
        [['lane_field' => 'status', 'moves' => ['sent' => ['from' => 'paid']]], 'moves'],
        [['lane_field' => 'status', 'colour' => 'red'], 'unknown_key'],
    ] as [$options, $refused]) {
        try {
            StorageBoardDescription::fromArray($options, $fields);
            storageViewExpect($refused === null, 'the description took ' . json_encode($options));
        } catch (StorageRejected $exception) {
            storageViewExpect($exception->reasonCode === 'storage_board_description_invalid:' . $refused, 'expected ' . $refused . ', got ' . $exception->reasonCode);
        }
    }

    // Moves: the targets of a card, a move, refusals with their reason, the storage's own refusal.
    // A person without the ability «orders.cancel».
    $mover = new StorageBoardMove($workbench, static fn (string $who, string $ability): bool => false);
    $newOne = $made['New 01'];
    $targets = $mover->targets($description, $newOne, ['new', 'wait', 'paid', 'sent', 'cancel', 'gone'], $actor);
    storageViewExpect($targets['new'] === null && $targets['wait'] === null && $targets['paid'] === null, 'allowed lanes are refused');
    storageViewExpect($targets['sent'] === ['code' => 'storage_board_move_not_allowed', 'reason' => 'Отправить можно только оплаченный заказ'],
        'a move against the rule is not refused with its reason');
    storageViewExpect($targets['cancel'] === ['code' => 'storage_board_move_denied', 'reason' => 'Отменяет только руководитель'],
        'a move without the ability is not refused with its reason');
    storageViewExpect($targets['gone'] === ['code' => 'storage_board_move_lane_unknown', 'reason' => null], 'an unknown lane is not refused');

    $refused = $mover->move($description, $scope, $id, $newOne->recordId, 'sent', $newOne->revision, $actor);
    storageViewExpect(($refused['error']['code'] ?? null) === 'storage_board_move_not_allowed' && $refused['error']['reason'] === 'Отправить можно только оплаченный заказ',
        'a move against the rule was not refused with its reason');
    storageViewExpect($workbench->readRecord($scope, $id, $newOne->recordId, $actor)->revision === $newOne->revision, 'a refused move wrote the record');

    $moved = $mover->move($description, $scope, $id, $newOne->recordId, 'paid', $newOne->revision, $actor);
    $record = $moved['record'] ?? null;
    storageViewExpect($record !== null && $record->values['status'] === 'paid' && $record->revision === $newOne->revision + 1
        && $record->values['title'] === 'New 01' && $record->values['amount'] === '1.5', 'the move did not change the lane field alone');
    $after = $workbench->listBoard(new StorageWorkbenchBoardQuery($list(), 'status'), $actor);
    storageViewExpect($after->lane('paid')?->total === 4 && $after->lane('new')?->total === 29, 'the board does not show the move');
    // Now paid, the card may be sent; the stale revision the board showed is the storage's refusal.
    $stale = $mover->move($description, $scope, $id, $newOne->recordId, 'sent', $newOne->revision, $actor);
    storageViewExpect(isset($stale['error']) && $stale['error']['code'] !== 'storage_board_move_not_allowed' && $stale['error']['reason'] === null,
        'a stale move was not refused by the storage: ' . json_encode($stale));
    $sent = $mover->move($description, $scope, $id, $newOne->recordId, 'sent', $record->revision, $actor);
    storageViewExpect(($sent['record'] ?? null)?->values['status'] === 'sent', 'an allowed move was refused: ' . json_encode($sent['error'] ?? null));
    // Into no value, and with the ability.
    $canCancel = new StorageBoardMove($workbench, static fn (string $who, string $ability): bool => $ability === 'orders.cancel');
    $paidA = $made['Paid A'];
    storageViewExpect(($canCancel->move($description, $scope, $id, $paidA->recordId, 'cancel', $paidA->revision, $actor)['record'] ?? null)?->values['status'] === 'cancel',
        'a move with the ability was refused');
    $paidB = $made['Paid B'];
    $cleared = $canCancel->move($description, $scope, $id, $paidB->recordId, '', $paidB->revision, $actor)['record'] ?? null;
    storageViewExpect($cleared !== null && !array_key_exists('status', $cleared->values), 'a move into the lane without a value kept the value');

    echo "StorageBoardTest passed\n";
} finally {
    @unlink($path);
}
