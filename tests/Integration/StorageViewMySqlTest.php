<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Exceptions\StorageConflict;
use Illuminate\Support\Facades\Facade;
use Larena\Storage\View\MySqlStorageViewDdl;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewShapeVerifier;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/mysql-disposable-database.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';
require_once __DIR__ . '/StorageViewDdlTest.php';

// The витрина on a throwaway MySQL database (opt-in): the DDL (INSTANT/INPLACE
// column changes, INPLACE indexes), the workbench write path with field changes
// whose DDL runs outside the transaction, the pending path inside an outer
// transaction, and two writers to one storage that no longer wait for each other.

$optIn = getenv('LARENA_STORAGE_VIEW_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "StorageViewMySqlTest skipped (explicit opt-in required).\n";
    exit(0);
}

$database = larena_storage_mysql_disposable_database('larena_storage_view_test_');
try {
    $connection = larena_storage_mysql_connect($database['config']);
    $ddl = StorageViewDdl::for($connection);
    storageViewExpect($ddl instanceof MySqlStorageViewDdl && !$ddl->transactional(), 'MySQL did not get its non-transactional DDL');
    storageViewDdlChecks($connection, $ddl);

    storageViewInstall($connection);
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope);
    $workbench = $runtime['workbench'];
    $views = $runtime['views'];
    $verifier = new StorageViewShapeVerifier($ddl);
    $fields = [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'integer'),
        storageViewField('price', 30, 'number'),
        storageViewField('at', 40, 'datetime'),
        storageViewField('tags', 50, 'choices', ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]),
        storageViewField('note', 60, 'text'),
    ];
    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $fields], $actor);
    $schemaId = $structure->schema->schemaId;
    $table = StorageViewNaming::table($schemaId);
    storageViewExpect($views->registry()->find($schemaId, true)?->state === 'ready', 'the structure has no ready витрина on MySQL');
    $first = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Chair', 'qty' => 2, 'price' => '19.99', 'at' => '2026-10-20T08:30', 'tags' => ['red']], $actor);
    $second = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Table', 'note' => str_repeat('n', 70000)], $actor);
    $first = $workbench->updateRecord($scope, 'workbench.items', $first->recordId, $first->revision, ['title' => 'Armchair', 'qty' => 3, 'price' => '20', 'tags' => ['blue', 'red']], $actor);
    $second = $workbench->archiveRecord($scope, 'workbench.items', $second->recordId, $second->revision, $actor);
    storageViewParity($connection, $views, $schemaId, 'mysql writes');
    storageViewRejects(
        static fn () => $workbench->createRecord($scope, 'workbench.items', ['title' => 'Too big', 'price' => '1' . str_repeat('0', 36)], $actor),
        'storage_view_number_out_of_range',
    );

    // A field change: the column is added before the transaction, the old one dropped after it.
    $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => array_merge($fields, [storageViewField('day', 70, 'date')])], $actor);
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration?->state === 'ready' && $registration->layout->schemaVersion === 2, 'an added field left the MySQL витрина out of sync');
    storageViewParity($connection, $views, $schemaId, 'mysql field added');
    $structure = $workbench->migrateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'number'),
        storageViewField('price', 30, 'number'),
        storageViewField('day', 70, 'date'),
    ]], $actor);
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration?->state === 'ready' && $registration->layout->inState('dropping') === [], 'the migration did not settle on MySQL');
    storageViewExpect($verifier->matches($table, $registration->layout), 'the migrated MySQL table does not match: ' . implode(', ', $verifier->problems($table, $registration->layout)));
    storageViewParity($connection, $views, $schemaId, 'mysql migrate');

    // Two writers to one storage: the second neither waits for nor fails on the first.
    $capsuleB = new Capsule();
    $capsuleB->addConnection($database['config']);
    $connectionB = $capsuleB->getConnection();
    $connectionB->statement('SET SESSION innodb_lock_wait_timeout = 2');
    $runtimeB = storageViewRuntime($connectionB, new MySqlStorageViewDdl($connectionB), $actor, $scope);
    $first = $workbench->readRecord($scope, 'workbench.items', $first->recordId, $actor);
    $third = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Shelf'], $actor);
    $connection->beginTransaction();
    try {
        $workbench->createRecord($scope, 'workbench.items', ['title' => 'Held by A'], $actor);
        $workbench->updateRecord($scope, 'workbench.items', $first->recordId, $first->revision, ['title' => 'Edited by A'], $actor);
        $started = microtime(true);
        try {
            $runtimeB['workbench']->createRecord($scope, 'workbench.items', ['title' => 'Created by B'], $actor);
            $runtimeB['workbench']->updateRecord($scope, 'workbench.items', $third->recordId, $third->revision, ['title' => 'Edited by B'], $actor);
        } catch (Throwable $exception) {
            throw new RuntimeException('a second writer to the storage was blocked: ' . $exception->getMessage(), 0, $exception);
        }
        storageViewExpect(microtime(true) - $started < 1.5, 'a second writer to the storage waited for the first');
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollBack();
        throw $exception;
    }
    storageViewParity($connection, $views, $schemaId, 'mysql two writers');
    // A structure change still waits for a record update: B's change times out while A writes.
    $structure = $workbench->readStructure($scope, 'workbench.items', $actor);
    $connection->beginTransaction();
    try {
        $held = $workbench->readRecord($scope, 'workbench.items', $third->recordId, $actor);
        $workbench->updateRecord($scope, 'workbench.items', $held->recordId, $held->revision, ['title' => 'Holding the structure'], $actor);
        $blocked = false;
        try {
            $runtimeB['workbench']->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Renamed', 'fields' => $structure->fields], $actor);
        } catch (Throwable $exception) {
            // The lock wait times out and the workbench reports a conflict.
            $blocked = $exception instanceof StorageConflict;
        }
        storageViewExpect($blocked, 'a structure change did not wait for a record writer');
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollBack();
        throw $exception;
    }

    // Inside an outer transaction the витрина is pending; the DDL waits for the commit.
    $connection->beginTransaction();
    $nested = $workbench->createStructure($scope, ['structure_id' => 'workbench.nested', 'label' => 'Nested', 'fields' => [storageViewField('title', 10, 'string')]], $actor);
    $workbench->createRecord($scope, 'workbench.nested', ['title' => 'In the outer transaction'], $actor);
    $connection->commit();
    $nestedSchema = $nested->schema->schemaId;
    storageViewExpect($views->registry()->find($nestedSchema, true)?->state === 'pending', 'a structure created in an outer MySQL transaction is not pending');
    $views->settle();
    storageViewExpect($ddl->tableExists(StorageViewNaming::table($nestedSchema)), 'the deferred DDL did not create the table');
    (new StorageViewRebuilder($views))->rebuild($nestedSchema);
    storageViewParity($connection, $views, $nestedSchema, 'mysql pending then rebuilt');

    // Purging the structure drops its tables after the commit.
    $archived = $workbench->archiveStructure($scope, 'workbench.nested', $workbench->readStructure($scope, 'workbench.nested', $actor)->version, $actor);
    $workbench->purgeStructure($scope, 'workbench.nested', $archived->version, $actor);
    storageViewExpect(!$ddl->tableExists(StorageViewNaming::table($nestedSchema)), 'the purged structure kept its MySQL tables');
    $capsuleB->getDatabaseManager()->disconnect();
} finally {
    Facade::clearResolvedInstances();
    ($database['drop'])();
}

echo "StorageViewMySqlTest passed.\n";
