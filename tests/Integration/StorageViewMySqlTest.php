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
    storageViewExpect(!$ddl->tableExists(StorageViewNaming::searchTable(StorageViewNaming::table($nestedSchema))), 'the purged structure kept its MySQL search table');

    // Search through the FULLTEXT index: words that begin with each term, stopwords
    // included, a short term as a substring; and an options change online.
    $catalog = $workbench->createStructure($scope, ['structure_id' => 'workbench.catalog', 'label' => 'Catalog', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('code', 20, 'string', ['max_length' => 40]),
        storageViewField('body', 30, 'text'),
    ]], $actor);
    $catalogSchema = $catalog->schema->schemaId;
    $catalogTable = StorageViewNaming::table($catalogSchema);
    storageViewExpect(in_array(StorageViewDdl::searchIndexName($catalogTable), $ddl->indexes(StorageViewNaming::searchTable($catalogTable)), true)
        && !in_array(StorageViewDdl::searchIndexName($catalogTable), $ddl->indexes($catalogTable), true), 'the FULLTEXT index is not on the search table alone');
    $items = [];
    foreach ([
        ['title' => 'Oak Chair', 'code' => 'A-1', 'body' => 'The best chair'],
        ['title' => 'Armchair', 'code' => 'B-2', 'body' => 'About leather'],
        ['title' => 'Chairs, set of four', 'code' => 'C-3'],
        ['title' => 'Ёлка искусственная', 'code' => 'D-4'],
        ['title' => 'Café table', 'code' => 'E-5'],
    ] as $values) {
        $items[] = $workbench->createRecord($scope, 'workbench.catalog', $values, $actor)->recordId;
    }
    $find = static function (string $term) use ($workbench, $scope, $actor): array {
        $ids = array_map(static fn ($record): string => $record->recordId, $workbench->listRecords(
            new \Larena\Storage\Contracts\StorageWorkbenchRecordQuery($scope, 'workbench.catalog', [], $term, [], 100),
            $actor,
        )->items);
        sort($ids, SORT_STRING);

        return $ids;
    };
    $sorted = static function (array $ids): array {
        sort($ids, SORT_STRING);

        return $ids;
    };
    storageViewExpect($find('chair') === $sorted([$items[0], $items[2]]), 'a word prefix on MySQL does not find Chair and Chairs only: ' . json_encode($find('chair')));
    storageViewExpect($find('CHAI') === $sorted([$items[0], $items[2]]), 'a prefix is not case-insensitive on MySQL');
    storageViewExpect($find('ёлк') === [$items[3]] && $find('елк') === [], 'Cyrillic is not matched exactly (ё is not е)');
    storageViewExpect($find('cafe') === [] && $find('café') === [$items[4]], 'an accent is folded by the collation instead of matched exactly');
    storageViewExpect($find('ch') === $sorted([$items[0], $items[2]]), 'a two-letter term is not a word prefix');
    storageViewExpect($find('hair') === [] && $find('armch') === [$items[1]], 'a term inside a word matched, or a word prefix did not');
    storageViewExpect($find('set four') === [$items[2]], 'two words in another order do not match');
    // Options change on MySQL: body searchable (stopwords included), code indexed; the main table stays INSTANT.
    $catalog = $workbench->updateStructure($scope, 'workbench.catalog', $catalog->version, ['structure_id' => 'workbench.catalog', 'label' => 'Catalog', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('code', 20, 'string', ['max_length' => 40]) + ['indexed' => true],
        storageViewField('body', 30, 'text') + ['searchable' => true],
    ]], $actor);
    $registration = $views->registry()->find($catalogSchema, true);
    storageViewExpect($registration?->state === 'ready' && $registration->layout->needsBackfill(), 'the MySQL options change left no backfill');
    (new StorageViewRebuilder($views))->rebuild($catalogSchema, 2);
    storageViewParity($connection, $views, $catalogSchema, 'mysql options backfilled');
    storageViewExpect($find('the best') === [$items[0]] && $find('about') === [$items[1]], 'stopwords are not searchable');
    $code = array_values(array_filter($views->registry()->find($catalogSchema, true)?->layout->columns ?? [], static fn ($c): bool => $c->fieldKey === 'code'));
    storageViewExpect(count($code) === 1 && $code[0]->sqlType === 'varchar40' && $code[0]->indexed
        && in_array(StorageViewDdl::fieldIndexName($catalogTable, (string) $code[0]->column), $ddl->indexes($catalogTable), true), 'the MySQL indexed string has no VARCHAR(40) column and index');
    $explain = $connection->select('EXPLAIN SELECT record_id FROM `' . $catalogTable . '` WHERE state = ? ORDER BY `' . $code[0]->column . '`, record_id LIMIT 10', ['active']);
    storageViewExpect(($explain[0]->key ?? null) === StorageViewDdl::fieldIndexName($catalogTable, (string) $code[0]->column), 'MySQL does not sort the indexed string by its index: ' . json_encode($explain));
    // A витрина built before the search table: a structure change in the transaction leaves it ready
    // (its search table is made before the transaction) and the rebuild backfills it in place.
    $current = $views->registry()->find($catalogSchema, true);
    storageViewExpect($current !== null, 'the catalog витрина is gone');
    $views->registry()->put($current->withLayout(new \Larena\Storage\View\StorageViewLayout($current->layout->schemaVersion, $current->layout->nextOrdinal, $current->layout->columns)));
    $connection->statement('DROP TABLE `' . StorageViewNaming::searchTable($catalogTable) . '`');
    $catalog = $workbench->readStructure($scope, 'workbench.catalog', $actor);
    $catalog = $workbench->updateStructure($scope, 'workbench.catalog', $catalog->version, ['structure_id' => 'workbench.catalog', 'label' => 'Catalog renamed', 'fields' => $catalog->fields], $actor);
    $upgraded = $views->registry()->find($catalogSchema, true);
    storageViewExpect($upgraded?->state === 'ready' && $upgraded->backfillPending() && $ddl->tableExists(StorageViewNaming::searchTable($catalogTable)),
        'a legacy MySQL витрина did not stay ready with its search table made and a backfill pending');
    (new StorageViewRebuilder($views))->rebuild($catalogSchema);
    storageViewParity($connection, $views, $catalogSchema, 'mysql legacy upgraded');
    storageViewExpect($find('the best') === [$items[0]], 'the upgraded MySQL витрина does not search');
    $capsuleB->getDatabaseManager()->disconnect();
} finally {
    Facade::clearResolvedInstances();
    ($database['drop'])();
}

echo "StorageViewMySqlTest passed.\n";
