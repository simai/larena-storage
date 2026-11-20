<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Contracts\StorageWorkbenchHistoryKeepPlan;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// «Хранить изменений» on SQLite: lowering the number removes old versions only on a confirmed plan that says how many
// versions of how many records go (a published revision is never counted nor removed); a plan that moved meanwhile is
// refused; raising the number, keeping it or keeping all needs no plan; later writes keep trimming to the number.

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
publishedSiteViewInstall($connection);
$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$runtime = publishedSiteViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope);
$workbench = $runtime['workbench'];
$publication = $runtime['publication'];

$structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.notes', 'label' => 'Notes',
    'fields' => [publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200])]], $actor);
$schemaId = $structure->schema->schemaId;
$versions = static fn (string $recordId): array => array_map('intval', $connection->table('larena_storage_record_versions')
    ->where('schema_id', $schemaId)->where('record_id', $recordId)->orderBy('revision')->pluck('revision')->all());
$write = static function (string $recordId, int $times) use ($workbench, $scope, $actor): void {
    for ($i = 0; $i < $times; $i++) {
        $current = $workbench->readRecord($scope, 'workbench.notes', $recordId, $actor);
        $workbench->updateRecord($scope, 'workbench.notes', $recordId, $current->revision, ['title' => 'Note ' . $recordId . ' ' . $i], $actor);
    }
};

// A at revision 6 with revision 2 published, B at revision 3, C at revision 1.
$a = $workbench->createRecord($scope, 'workbench.notes', ['title' => 'A'], $actor)->recordId;
$b = $workbench->createRecord($scope, 'workbench.notes', ['title' => 'B'], $actor)->recordId;
$c = $workbench->createRecord($scope, 'workbench.notes', ['title' => 'C'], $actor)->recordId;
$write($a, 1);
$publication->publish($schemaId, $a, 'site:main', 'en', 2, $actor);
$write($a, 4);
$write($b, 2);
storageViewExpect($versions($a) === [1, 2, 3, 4, 5, 6] && $versions($b) === [1, 2, 3] && $versions($c) === [1], 'the records are not as set up');

// Keeping all → 3 lowers: A loses 1 and 3 (2 is published), B and C lose nothing.
$plan = $workbench->planHistoryKeep($scope, 'workbench.notes', 3, $actor);
storageViewExpect($plan['lowers'] === true && $plan['from'] === null && $plan['to'] === 3 && $plan['label'] === 'Notes'
    && $plan['versions'] === 2 && $plan['records'] === 1 && preg_match('/\A[a-f0-9]{64}\z/', $plan['digest']) === 1, 'the plan is wrong: ' . json_encode($plan));
storageViewExpect($versions($a) === [1, 2, 3, 4, 5, 6], 'the plan removed versions');

// Without the plan, or on a wrong one, nothing changes.
storageViewRejects(static fn () => $workbench->setHistoryKeep($scope, 'workbench.notes', 3, $actor), StorageWorkbenchHistoryKeepPlan::PLAN_REQUIRED);
storageViewRejects(static fn () => $workbench->applyHistoryKeep($scope, 'workbench.notes', 3, str_repeat('0', 64), $actor), StorageWorkbenchHistoryKeepPlan::PLAN_STALE);
storageViewRejects(static fn () => $workbench->applyHistoryKeep($scope, 'workbench.notes', 4, $plan['digest'], $actor), StorageWorkbenchHistoryKeepPlan::PLAN_STALE);
storageViewExpect($workbench->readStructure($scope, 'workbench.notes', $actor)->historyKeep === null && $versions($a) === [1, 2, 3, 4, 5, 6], 'a refused lowering changed something');

// A write meanwhile moves the count: the plan seen is refused, a new one says 3 and applies.
$write($a, 1);
storageViewRejects(static fn () => $workbench->applyHistoryKeep($scope, 'workbench.notes', 3, $plan['digest'], $actor), StorageWorkbenchHistoryKeepPlan::PLAN_STALE);
$plan = $workbench->planHistoryKeep($scope, 'workbench.notes', 3, $actor);
storageViewExpect($plan['versions'] === 3 && $plan['records'] === 1, 'the new plan is wrong: ' . json_encode($plan));
$applied = $workbench->applyHistoryKeep($scope, 'workbench.notes', 3, $plan['digest'], $actor);
storageViewExpect($applied->historyKeep === 3, 'the number was not set');
storageViewExpect($versions($a) === [2, 5, 6, 7] && $versions($b) === [1, 2, 3] && $versions($c) === [1], 'the trim is not what the plan said: ' . json_encode($versions($a)));
$removed = 6 + 1 + 3 + 1 - count($versions($a)) - count($versions($b)) - count($versions($c));
storageViewExpect($removed === $plan['versions'], 'the plan count differs from what was removed');

// Raising, the same number and keeping all need no plan, and remove nothing.
$raise = $workbench->planHistoryKeep($scope, 'workbench.notes', 10, $actor);
storageViewExpect($raise['lowers'] === false && $raise['versions'] === 0 && $raise['from'] === 3, 'a raise is planned as a lowering');
storageViewExpect($workbench->setHistoryKeep($scope, 'workbench.notes', 10, $actor)->historyKeep === 10, 'a raise needed a plan');
storageViewExpect($workbench->setHistoryKeep($scope, 'workbench.notes', 10, $actor)->historyKeep === 10, 'the same number needed a plan');
storageViewExpect($workbench->setHistoryKeep($scope, 'workbench.notes', null, $actor)->historyKeep === null, 'keeping all needed a plan');
storageViewExpect($versions($a) === [2, 5, 6, 7], 'a raise removed versions');

// A lowering that removes nothing is planned with zero versions; its plan still applies.
$empty = $workbench->planHistoryKeep($scope, 'workbench.notes', 4, $actor);
storageViewExpect($empty['lowers'] === true && $empty['versions'] === 0, 'a lowering with nothing to remove is planned wrong: ' . json_encode($empty));
$workbench->applyHistoryKeep($scope, 'workbench.notes', 4, $empty['digest'], $actor);

// Later writes keep each record trimmed to the confirmed number, as before.
$write($b, 3);
storageViewExpect($versions($b) === [3, 4, 5, 6], 'a later write did not trim to the number: ' . json_encode($versions($b)));
$write($a, 1);
storageViewExpect($versions($a) === [2, 5, 6, 7, 8], 'a later write removed the published revision or too much: ' . json_encode($versions($a)));

// A lowering that removes nothing (a new or imported storage) applies at once: nothing can be lost.
$workbench->createStructure($scope, ['structure_id' => 'workbench.fresh', 'label' => 'Fresh',
    'fields' => [publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200])]], $actor);
$workbench->createRecord($scope, 'workbench.fresh', ['title' => 'Only'], $actor);
storageViewExpect($workbench->planHistoryKeep($scope, 'workbench.fresh', 3, $actor)['versions'] === 0, 'a fresh storage planned versions to go');
storageViewExpect($workbench->setHistoryKeep($scope, 'workbench.fresh', 3, $actor)->historyKeep === 3, 'a lowering that removes nothing needed a plan');

echo "StorageHistoryKeepPlanTest passed.\n";
