<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Console\Commands\StorageViewRebuildCommand;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewShapeVerifier;
use Larena\Storage\View\StorageViewSync;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The витрина on the write path, on SQLite: a structure gets its table, every
// record write keeps its row equal to the current version in the same
// transaction (create, update, archive, restore, purge, schema apply), a rolled
// back write leaves no row, field changes add and drop columns, purging the
// structure drops its tables, and the rebuild fills an existing storage and
// resumes after a stop. Both DDL paths run: SQLite's, inside the transaction,
// and MySQL's, before the transaction and after the commit.

/** @return array{connection: Connection, path: string} */
function storageViewOpen(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-view-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_view_tempfile_failed');
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

    return ['connection' => $connection, 'path' => $path];
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$paths = [];

try {
    foreach ([true, false] as $transactionalDdl) {
        $mode = $transactionalDdl ? 'sqlite ddl' : 'mysql-like ddl';
        $opened = storageViewOpen();
        $paths[] = $opened['path'];
        $connection = $opened['connection'];
        storageViewInstall($connection);
        $ddl = new SqliteStorageViewDdl($connection, $transactionalDdl);
        $runtime = storageViewRuntime($connection, $ddl, $actor, $scope);
        $workbench = $runtime['workbench'];
        $views = $runtime['views'];
        $verifier = new StorageViewShapeVerifier($ddl);

        $fields = [
            storageViewField('title', 10, 'string', ['max_length' => 200], true),
            storageViewField('qty', 20, 'integer'),
            storageViewField('price', 30, 'number'),
            storageViewField('day', 40, 'date'),
            storageViewField('status', 50, 'choice', ['options' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'live', 'label' => 'Live']]]),
            storageViewField('tags', 60, 'choices', ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]),
            storageViewField('note', 70, 'text'),
        ];
        $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $fields], $actor);
        $schemaId = $structure->schema->schemaId;
        $table = StorageViewNaming::table($schemaId);
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration !== null && $registration->state === 'ready' && $registration->tableName === $table, $mode . ': a new structure has no ready витрина');
        storageViewExpect($registration->builtSchemaVersion === 1 && $registration->layout->schemaVersion === 1, $mode . ': the built version is wrong');
        storageViewExpect($verifier->matches($table, $registration->layout), $mode . ': the table does not match its layout: ' . implode(', ', $verifier->problems($table, $registration->layout)));
        storageViewParity($connection, $views, $schemaId, $mode . ' empty');

        // Create, update, archive, restore.
        $first = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Red chair', 'qty' => 3, 'price' => '12.50', 'day' => '2026-10-20', 'status' => 'draft', 'tags' => ['red'], 'note' => "Long\nnote"], $actor);
        $second = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Blue table', 'tags' => ['blue', 'red']], $actor);
        $third = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Lamp'], $actor);
        storageViewParity($connection, $views, $schemaId, $mode . ' create');
        $row = $connection->table($table)->where('record_id', $first->recordId)->first();
        storageViewExpect($row !== null && $row->state === 'active' && $row->scope_ref === $scope && $row->title === 'Red chair' && (int) $row->revision === 1, $mode . ': the created row is wrong');

        $first = $workbench->updateRecord($scope, 'workbench.items', $first->recordId, $first->revision, ['title' => 'Red armchair', 'qty' => 4, 'tags' => ['blue']], $actor);
        $second = $workbench->archiveRecord($scope, 'workbench.items', $second->recordId, $second->revision, $actor);
        storageViewParity($connection, $views, $schemaId, $mode . ' update and archive');
        storageViewExpect($connection->table($table)->where('record_id', $second->recordId)->value('state') === 'archived', $mode . ': archive did not set the state');
        $second = $workbench->restoreRecord($scope, 'workbench.items', $second->recordId, $second->revision, $actor);
        storageViewExpect($connection->table($table)->where('record_id', $second->recordId)->value('state') === 'active', $mode . ': restore did not set the state');
        $archivedThird = $workbench->bulkArchive($scope, 'workbench.items', [$third->recordId => $third->revision], $actor)[0];
        storageViewParity($connection, $views, $schemaId, $mode . ' restore and bulk archive');

        // A refused write and a rolled-back transaction leave the витрина as it was.
        $before = $connection->table($table)->count();
        storageViewRejects(
            static fn () => $workbench->createRecord($scope, 'workbench.items', ['title' => 'Too precise', 'price' => '1' . str_repeat('0', 40)], $actor),
            'storage_view_number_out_of_range',
        );
        storageViewExpect($connection->table($table)->count() === $before && $connection->table('larena_storage_records')->count() === $before, $mode . ': a refused write left a row');
        try {
            $connection->transaction(static function () use ($workbench, $scope, $actor, $first): void {
                $workbench->updateRecord($scope, 'workbench.items', $first->recordId, $first->revision, ['title' => 'Rolled back'], $actor);
                $workbench->createRecord($scope, 'workbench.items', ['title' => 'Rolled back too'], $actor);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException $exception) {
            storageViewExpect($exception->getMessage() === 'roll back', $mode . ': the rollback failed differently');
        }
        storageViewExpect($connection->table($table)->count() === $before, $mode . ': a rolled-back create left a row');
        storageViewExpect($connection->table($table)->where('record_id', $first->recordId)->value('title') === 'Red armchair', $mode . ': a rolled-back update changed the row');
        storageViewParity($connection, $views, $schemaId, $mode . ' rollback');

        // Purging an archived record removes its row and side rows.
        $workbench->purgeRecords($scope, 'workbench.items', [$archivedThird->recordId => $archivedThird->revision], $actor);
        storageViewExpect($connection->table($table)->where('record_id', $third->recordId)->doesntExist(), $mode . ': a purged record kept its row');
        storageViewParity($connection, $views, $schemaId, $mode . ' purge');

        // Adding a field adds a column and rewrites every row at the new schema version.
        $withColor = array_merge($fields, [storageViewField('color', 80, 'choice', ['options' => [['value' => 'red', 'label' => 'Red']]])]);
        $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $withColor], $actor);
        storageViewExpect($structure->schema->version === 2, $mode . ': the field was not added through a schema apply');
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration !== null && $registration->layout->schemaVersion === 2 && $registration->builtSchemaVersion === 2, $mode . ': the layout did not follow the schema');
        storageViewParity($connection, $views, $schemaId, $mode . ' field added');
        storageViewExpect((int) $connection->table($table)->max('schema_version') === 2 && (int) $connection->table($table)->min('schema_version') === 2, $mode . ': rows are not at the new schema version');
        $colored = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Red cup', 'color' => 'red'], $actor);
        storageViewParity($connection, $views, $schemaId, $mode . ' write after the field was added');

        // Removing and retyping fields: new column for the retyped one, old columns dropped after the commit.
        $columnsBefore = $ddl->columns($table);
        $migratedFields = [
            storageViewField('title', 10, 'string', ['max_length' => 200], true),
            storageViewField('qty', 20, 'number'),
            storageViewField('price', 30, 'number'),
            storageViewField('day', 40, 'date'),
            storageViewField('status', 50, 'choice', ['options' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'live', 'label' => 'Live']]]),
            storageViewField('color', 80, 'choice', ['options' => [['value' => 'red', 'label' => 'Red']]]),
        ];
        $structure = $workbench->migrateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $migratedFields], $actor);
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration !== null && $registration->layout->schemaVersion === 3, $mode . ': the migration did not reach the layout');
        storageViewExpect($registration->layout->inState('dropping') === [] && $registration->layout->inState('adding') === [], $mode . ': dropped columns were not settled after the commit');
        $columnsAfter = $ddl->columns($table);
        storageViewExpect(count(array_diff($columnsBefore, $columnsAfter)) === 2 && count(array_diff($columnsAfter, $columnsBefore)) === 1, $mode . ': the note and old qty columns were not dropped, or the new qty column not added');
        storageViewExpect($connection->table($table . '_m')->count() === 0, $mode . ': the removed multi-value field kept side rows');
        storageViewExpect($verifier->matches($table, $registration->layout), $mode . ': the migrated table does not match: ' . implode(', ', $verifier->problems($table, $registration->layout)));
        storageViewParity($connection, $views, $schemaId, $mode . ' migrate');

        // Lists still use the current path and see the same records.
        $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.items', limit: 50, includeArchived: true), $actor);
        storageViewExpect($page->matchedCount === $connection->table($table)->count(), $mode . ': the list and the витрина disagree on the record count');

        // Purging the structure drops its tables and its registration.
        $colored = $workbench->readRecord($scope, 'workbench.items', $colored->recordId, $actor);
        $workbench->updateRecord($scope, 'workbench.items', $colored->recordId, $colored->revision, ['title' => 'Red mug', 'color' => 'red'], $actor);
        storageViewParity($connection, $views, $schemaId, $mode . ' write after the migration');
        $archived = $workbench->archiveStructure($scope, 'workbench.items', $structure->version, $actor);
        $workbench->purgeStructure($scope, 'workbench.items', $archived->version, $actor);
        storageViewExpect($views->registry()->find($schemaId, true) === null, $mode . ': the purged structure kept its registration');
        storageViewExpect(!$ddl->tableExists($table) && !$ddl->tableExists($table . '_m'), $mode . ': the purged structure kept its tables');

        // The rebuild fills an existing storage, in chunks, and resumes after a stop.
        $legacy = $workbench->createStructure($scope, ['structure_id' => 'workbench.legacy', 'label' => 'Legacy', 'fields' => [
            storageViewField('title', 10, 'string', ['max_length' => 200], true),
            storageViewField('qty', 20, 'integer'),
            storageViewField('tags', 30, 'choices', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
        ]], $actor);
        $legacyIds = [];
        for ($index = 0; $index < 7; $index++) {
            $legacyIds[] = $workbench->createRecord($scope, 'workbench.legacy', ['title' => 'Item ' . $index, 'qty' => $index, 'tags' => $index % 2 === 0 ? ['a'] : ['a', 'b']], $actor);
        }
        $legacySchema = $legacy->schema->schemaId;
        $legacyTable = StorageViewNaming::table($legacySchema);
        // As if the storage existed before the витрина: no registration, no tables.
        $views->registry()->delete($legacySchema);
        $ddl->dropTables($legacyTable);
        $rebuilder = new StorageViewRebuilder($views);
        $chunks = 0;
        try {
            $rebuilder->rebuild($legacySchema, 2, false, static function () use (&$chunks): void {
                if (++$chunks === 2) {
                    throw new RuntimeException('stopped');
                }
            });
        } catch (RuntimeException $exception) {
            storageViewExpect($exception->getMessage() === 'stopped', $mode . ': the rebuild stopped for another reason: ' . $exception->getMessage());
        }
        $building = $views->registry()->find($legacySchema, true);
        storageViewExpect($building !== null && $building->state === 'building' && $building->buildCursor !== null, $mode . ': a stopped rebuild did not keep its position');
        storageViewExpect($connection->table($legacyTable)->count() === 4, $mode . ': the first two chunks were not written');
        // Writers keep a building витрина current, before and after the position.
        usort($legacyIds, static fn ($left, $right): int => strcmp($left->recordId, $right->recordId));
        $early = $legacyIds[0];
        $late = $legacyIds[6];
        $workbench->updateRecord($scope, 'workbench.legacy', $early->recordId, $early->revision, ['title' => 'Early edit', 'tags' => ['b']], $actor);
        $workbench->updateRecord($scope, 'workbench.legacy', $late->recordId, $late->revision, ['title' => 'Late edit'], $actor);
        storageViewExpect($connection->table($legacyTable)->where('record_id', $early->recordId)->value('title') === 'Early edit', $mode . ': a write during the build did not reach the row');
        $result = $rebuilder->rebuild($legacySchema, 2);
        storageViewExpect($result['resumed'] && $result['records'] === 3, $mode . ': the rebuild did not resume where it stopped');
        storageViewParity($connection, $views, $legacySchema, $mode . ' rebuild resumed');
        storageViewExpect($views->registry()->find($legacySchema, true)?->builtSchemaVersion === 1, $mode . ': the rebuild did not record the built version');
        // A rebuild from scratch gives the same витрина.
        $connection->table($legacyTable)->where('record_id', $late->recordId)->update(['title' => 'Drifted']);
        $result = $rebuilder->rebuild($legacySchema, 3, true);
        storageViewExpect(!$result['resumed'] && $result['records'] === 7, $mode . ': the rebuild from scratch did not walk every record');
        storageViewParity($connection, $views, $legacySchema, $mode . ' rebuild from scratch');

        // The command builds what is missing and leaves what is ready.
        $views->registry()->delete($legacySchema);
        $ddl->dropTables($legacyTable);
        $container = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->instance(StorageViewSync::class, $views);
        $command = new StorageViewRebuildCommand();
        $command->setLaravel($container);
        $output = new BufferedOutput();
        $input = new ArrayInput(['--missing' => true, '--chunk' => '3']);
        $input->setInteractive(false);
        $status = $command->run($input, $output);
        $printed = $output->fetch();
        storageViewExpect($status === 0, $mode . ': the rebuild command failed: ' . $printed);
        storageViewExpect(str_contains($printed, $legacySchema . ': ready, 7 records'), $mode . ': the command did not report the build: ' . $printed);
        storageViewParity($connection, $views, $legacySchema, $mode . ' rebuild command');
        $output = new BufferedOutput();
        $input = new ArrayInput(['--missing' => true]);
        $input->setInteractive(false);
        storageViewExpect($command->run($input, $output) === 0 && str_contains($output->fetch(), 'Nothing to build.'), $mode . ': the command rebuilt a ready витрина');
        $output = new BufferedOutput();
        $input = new ArrayInput(['--chunk' => '0', '--all' => true]);
        $input->setInteractive(false);
        storageViewExpect($command->run($input, $output) === 2, $mode . ': an invalid chunk was accepted');

        if (!$transactionalDdl) {
            // Inside an outer transaction MySQL cannot run DDL: the витрина is pending,
            // its table is built after the commit, and the rebuild makes it ready.
            $nestedFields = [storageViewField('title', 10, 'string', ['max_length' => 200], true)];
            $nested = $connection->transaction(static fn () => $workbench->createStructure($scope, ['structure_id' => 'workbench.nested', 'label' => 'Nested', 'fields' => $nestedFields], $actor));
            $nestedSchema = $nested->schema->schemaId;
            $nestedTable = StorageViewNaming::table($nestedSchema);
            storageViewExpect($views->registry()->find($nestedSchema, true)?->state === 'pending' && !$ddl->tableExists($nestedTable), $mode . ': DDL ran inside an outer transaction');
            $workbench->createRecord($scope, 'workbench.nested', ['title' => 'Written while pending'], $actor);
            storageViewExpect(!$ddl->tableExists($nestedTable), $mode . ': a pending витрина was written');
            $views->settle();
            storageViewExpect($ddl->tableExists($nestedTable) && $views->registry()->find($nestedSchema, true)?->state === 'pending', $mode . ': the deferred DDL did not build the table, or marked it ready with records missing');
            $rebuilder->rebuild($nestedSchema);
            storageViewParity($connection, $views, $nestedSchema, $mode . ' pending then rebuilt');
            $nested = $connection->transaction(static fn () => $workbench->updateStructure($scope, 'workbench.nested', $nested->version, ['structure_id' => 'workbench.nested', 'label' => 'Nested', 'fields' => array_merge($nestedFields, [storageViewField('qty', 20, 'integer')])], $actor));
            $pending = $views->registry()->find($nestedSchema, true);
            storageViewExpect($pending?->state === 'pending' && $pending->layout->schemaVersion === 2, $mode . ': a field change in an outer transaction did not leave the витрина pending');
            $views->settle();
            $rebuilder->rebuild($nestedSchema);
            storageViewParity($connection, $views, $nestedSchema, $mode . ' field change in an outer transaction, rebuilt');
            storageViewExpect(count($ddl->columns($nestedTable)) === 12, $mode . ': the nested table does not have its two field columns');
        }

        // Rolling the migration back drops every витрина table, registered or not.
        $orphan = StorageViewNaming::table('workbench.orphan');
        $ddl->ensureTable($orphan, $views->planner()->plan([storageViewField('title', 10, 'string')], 1)->promoted());
        storageViewExpect(count($ddl->viewTables()) >= 4, $mode . ': the витрина tables to roll back are missing');
        (require __DIR__ . '/../../database/migrations/platform/2026_10_20_000001_create_larena_storage_views_table.php')->down();
        storageViewExpect($ddl->viewTables() === [] && !$connection->getSchemaBuilder()->hasTable('larena_storage_views'), $mode . ': the rollback left витрина tables');

        // Without the registry table nothing is written and nothing fails.
        $plain = storageViewRuntime($connection, $ddl, $actor, $scope);
        $plain['workbench']->createRecord($scope, 'workbench.legacy', ['title' => 'No registry'], $actor);
        $plain['workbench']->createStructure($scope, ['structure_id' => 'workbench.unviewed', 'label' => 'Unviewed', 'fields' => [storageViewField('title', 10, 'string')]], $actor);
        storageViewExpect($ddl->viewTables() === [], $mode . ': a write without the registry touched the витрина');
        storageViewExpect(!(new StorageViewSync($connection, $plain['views']->registry(), null))->enabled(), $mode . ': a driver without DDL is enabled');
        storageViewExpect(StorageViewDdl::for($connection) instanceof SqliteStorageViewDdl, $mode . ': SQLite does not get its DDL');
    }
} finally {
    Facade::clearResolvedInstances();
    foreach ($paths as $path) {
        @unlink($path);
    }
}

echo "StorageViewWritePathTest passed.\n";
