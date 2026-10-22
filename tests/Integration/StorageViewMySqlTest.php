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
require_once __DIR__ . '/../Support/published-site-view-fixture.php';
require_once __DIR__ . '/../Support/published-list-fixture.php';
require_once __DIR__ . '/StorageViewDdlTest.php';

// The витрина on a throwaway MySQL database (opt-in): the DDL (INSTANT/INPLACE
// column changes, INPLACE indexes), the workbench write path with field changes
// whose DDL runs outside the transaction, the pending path inside an outer
// transaction, two writers to one storage that no longer wait for each other, and
// the list by creation time with its (state, created_at, record_id) index added
// in place to a table built without it.

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
    storageViewCreatedAtChecks($connection, $ddl, $workbench, $views, $scope, $actor);
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
    // No record version is written: the rows keep their schema version and read through the converter.
    $versionRows = $connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count();
    $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => array_merge($fields, [storageViewField('day', 70, 'date')])], $actor);
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration?->state === 'ready' && $registration->layout->schemaVersion === 2 && !$registration->backfillPending(), 'an added field left the MySQL витрина out of sync');
    storageViewExpect($connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count() === $versionRows, 'adding a field on MySQL wrote record versions');
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
    storageViewExpect($connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count() === $versionRows, 'a field change on MySQL wrote record versions');
    // The removed note was searched: the search text is refilled by the backfill, and lists do not
    // search it meanwhile; the retyped qty's new column was filled before the switch.
    storageViewExpect($registration->layout->searchState === 'backfilling' && $registration->backfillPending(), 'the removed searched field did not ask for the search refill');
    (new StorageViewRebuilder($views))->rebuild($schemaId, 2);
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

    // A field change in the background on MySQL: the new column is added online (INSTANT) before
    // it, filled in chunks while the list answers from the old one, writes made meanwhile are checked
    // again, and the switch drops the old column. No record version is written.
    $queued = [];
    $background = storageViewRuntime($connection, $ddl, $actor, $scope, null, null, static function (string $changeRef) use (&$queued): bool {
        $queued[] = $changeRef;

        return true;
    }, 2)['workbench'];
    $counts = $background->createStructure($scope, ['structure_id' => 'workbench.counts', 'label' => 'Counts', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200]), storageViewField('n', 20, 'integer'),
    ]], $actor);
    $countsSchema = $counts->schema->schemaId;
    $countIds = [];
    for ($index = 1; $index <= 6; $index++) {
        $countIds[] = $background->createRecord($scope, 'workbench.counts', ['title' => 'C' . $index, 'n' => $index * 10], $actor)->recordId;
    }
    $countRows = $connection->table('larena_storage_record_versions')->where('schema_id', $countsSchema)->count();
    $running = $background->migrateStructure($scope, 'workbench.counts', $counts->version, ['structure_id' => 'workbench.counts', 'label' => 'Counts', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200]), storageViewField('n', 20, 'number'),
    ]], $actor);
    storageViewExpect($running->changing() && count($queued) === 1, 'the MySQL retype did not go to the background');
    $stopped = false;
    try {
        $background->runFieldChange($queued[0], 2, static function () use (&$stopped): void {
            $stopped = true;
            throw new RuntimeException('stopped after a chunk');
        });
    } catch (RuntimeException $exception) {
        storageViewExpect($stopped && $exception->getMessage() === 'stopped after a chunk', 'the MySQL field change stopped for another reason: ' . $exception->getMessage());
    }
    sort($countIds, SORT_STRING);
    $early = $background->readRecord($scope, 'workbench.counts', $countIds[0], $actor);
    $background->updateRecord($scope, 'workbench.counts', $early->recordId, $early->revision, ['title' => 'C early', 'n' => 7], $actor);
    $result = $background->runFieldChange($queued[0], 2);
    storageViewExpect($result['state'] === 'done' && $result['resumed'], 'the MySQL field change did not resume and finish');
    $counts = $background->readStructure($scope, 'workbench.counts', $actor);
    storageViewExpect($counts->schema->version === 2 && !$counts->changing(), 'the MySQL field change did not switch');
    storageViewExpect($connection->table('larena_storage_record_versions')->where('schema_id', $countsSchema)->count() === $countRows + 1, 'the MySQL field change wrote record versions');
    storageViewExpect($background->readRecord($scope, 'workbench.counts', $countIds[0], $actor)->values['n'] === '7'
        && $background->readRecord($scope, 'workbench.counts', $countIds[5], $actor)->values['n'] === (string) ((int) substr((string) $background->readRecord($scope, 'workbench.counts', $countIds[5], $actor)->values['title'], 1) * 10),
        'the MySQL retyped values do not read converted');
    storageViewExpect($connection->table('larena_storage_field_change_records')->count() === 0, 'the checked revisions were not emptied');
    storageViewParity($connection, $views, $countsSchema, 'mysql background field change');
    $capsuleB->getDatabaseManager()->disconnect();

    // The site витрина on MySQL: its DDL, the table made before the first publication's
    // transaction, the rows kept by publication and translation, nothing inside an outer
    // transaction (no DDL there), the rebuild, purge, and the tables dropped after a structure purge.
    foreach ([
        '/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php',
        '/platform/2026_09_29_000001_create_larena_storage_publication_tables.php',
        '/platform/2026_10_20_000002_create_larena_storage_published_key_tables.php',
        '/platform/2026_10_22_000001_create_larena_storage_site_view_tables.php',
        '/platform/2026_10_22_000002_add_larena_storage_publication_scheduled_revision.php',
    ] as $file) {
        (require __DIR__ . '/../../database/migrations' . $file)->up();
    }
    storageViewExpect(Illuminate\Support\Facades\Schema::hasIndex('larena_storage_publication_states', 'storage_pub_schema_scope_locale_state_record_idx'), 'the publication states index was not built on MySQL');
    storageViewSiteDdlChecks($connection, $ddl);
    $siteRuntime = publishedSiteViewRuntime($connection, $ddl, $actor, $scope);
    $siteWorkbench = $siteRuntime['workbench'];
    $site = $siteRuntime['site'];
    $publication = $siteRuntime['publication'];
    $pages = $siteWorkbench->createStructure($scope, ['structure_id' => 'workbench.site_pages', 'label' => 'Pages', 'fields' => [
        publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], true),
        publishedSiteViewField('slug', 20, 'string', 'public', ['max_length' => 120]) + ['indexed' => true],
        publishedSiteViewField('body', 30, 'text', 'public'),
        publishedSiteViewField('tags', 40, 'choices', 'public', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
        publishedSiteViewField('secret', 50, 'string', 'admin', ['max_length' => 200], true),
    ]], $actor);
    $pagesSchema = $pages->schema->schemaId;
    $siteTable = StorageViewNaming::siteTable($pagesSchema);
    $pageRecords = [];
    for ($index = 0; $index < 5; $index++) {
        $pageRecords[] = $siteWorkbench->createRecord($scope, 'workbench.site_pages', ['title' => 'Page ' . $index, 'slug' => 'page-' . $index, 'body' => 'Long body ' . $index, 'tags' => $index % 2 === 0 ? ['a'] : ['b'], 'secret' => 'SENTINEL-' . $index], $actor);
    }
    // Inside an outer transaction MySQL runs no DDL: the publication writes no site row and does not fail.
    $connection->transaction(static fn () => $publication->publish($pagesSchema, $pageRecords[0]->recordId, 'site:main', 'en', 1, $actor));
    storageViewExpect($site->registry()->find($pagesSchema, true) === null && !$ddl->tableExists($siteTable), 'a publication inside a transaction made a site table on MySQL');
    foreach ($pageRecords as $index => $page) {
        $publication->publish($pagesSchema, $page->recordId, 'site:main', 'en', 1, $actor);
        $publication->publish($pagesSchema, $page->recordId, 'site:main', 'kk', 1, $actor);
    }
    storageViewExpect($site->registry()->find($pagesSchema, true)?->state === 'ready' && $ddl->tableExists($siteTable), 'the first publication made no site table on MySQL');
    storageViewExpect(in_array(StorageViewDdl::searchIndexName($siteTable), $ddl->indexes($siteTable . '_s'), true), 'the site search table has no FULLTEXT index');
    $siteRuntime['localized']->write($pagesSchema, $pageRecords[1]->recordId, 1, 'ru', ['title' => 'Страница 1'], ['title', 'body', 'secret'], $actor);
    $siteRuntime['localized']->write($pagesSchema, $pageRecords[1]->recordId, 1, 'kk', ['secret' => 'SENTINEL-TRANSLATED'], ['title', 'body', 'secret'], $actor);
    $publication->unpublish($pagesSchema, $pageRecords[2]->recordId, 'site:main', 'en', $actor);
    // The record left its slot; the first one, published again above, is in both.
    publishedSiteViewParity($site, $pagesSchema, 'mysql site writes');
    // A newer revision scheduled on a published page: the old row stays until the sweep promotes it.
    $pageRecords[3] = $siteWorkbench->updateRecord($scope, 'workbench.site_pages', $pageRecords[3]->recordId, $pageRecords[3]->revision, ['title' => 'Page 3 next', 'slug' => 'page-3', 'body' => 'Long body 3', 'tags' => ['b'], 'secret' => 'SENTINEL-3b'], $actor);
    $publication->schedule($pagesSchema, $pageRecords[3]->recordId, 'site:main', 'en', 2, '2020-01-01 00:00:00', $actor);
    $pendingRow = publishedSiteViewEntries($site, $pagesSchema, 'site:main', 'en')[$pageRecords[3]->recordId] ?? null;
    storageViewExpect(($pendingRow['revision'] ?? null) === 1 && $pendingRow['values']['title'] === 'Page 3', 'a published page with a schedule lost its row on MySQL');
    publishedSiteViewParity($site, $pagesSchema, 'mysql pending revision');
    storageViewExpect($publication->sweep('system:scheduler', '2026-10-22 12:00:00')['published_count'] === 1, 'the pending revision was not swept on MySQL');
    storageViewExpect((publishedSiteViewEntries($site, $pagesSchema, 'site:main', 'en')[$pageRecords[3]->recordId]['values']['title'] ?? null) === 'Page 3 next', 'the promoted revision has no row on MySQL');
    publishedSiteViewParity($site, $pagesSchema, 'mysql promoted revision');
    storageViewExpect(publishedSiteViewEntries($site, $pagesSchema, 'site:main', 'kk')[$pageRecords[1]->recordId]['values']['title'] === 'Страница 1', 'the ru fallback did not reach the kk row on MySQL');
    publishedSiteViewListChecks($connection, $site, $pagesSchema, 'site:main', 'en');
    publishedSiteViewListChecks($connection, $site, $pagesSchema, 'site:main', 'kk');
    // The public reads from the MySQL slots equal the exact path; the key column's
    // case-insensitive collation only narrows, so PAGE-1 is no key; a forged row
    // silences its slot and the request is still answered exactly.
    $siteRead = $siteRuntime['siteRead'];
    $exactRead = $siteRuntime['read'];
    $siteAnswered = static function (Closure $call) use ($connection, $siteTable): array {
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $result = $call();
        } finally {
            $log = $connection->getQueryLog();
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
        $answered = false;
        foreach ($log as $entry) {
            $answered = $answered || str_contains((string) $entry['query'], '`' . $siteTable . '`');
        }

        return [$result, $answered];
    };
    foreach (['en', 'kk'] as $siteLocale) {
        foreach ([[2, null], [2, $pageRecords[1]->recordId], [500, null]] as [$budget, $after]) {
            [$sitePage, $answered] = $siteAnswered(static fn () => $siteRead->publishedProjection($pagesSchema, 'site:main', $siteLocale, $budget, null, $after));
            storageViewExpect($answered && serialize($sitePage) === serialize($exactRead->publishedProjection($pagesSchema, 'site:main', $siteLocale, $budget, null, $after)), 'a MySQL site page differs from the exact path');
        }
        foreach ($pageRecords as $page) {
            storageViewExpect(serialize($siteRead->publishedRecord($pagesSchema, 'site:main', $siteLocale, $page->recordId)) === serialize($exactRead->publishedRecord($pagesSchema, 'site:main', $siteLocale, $page->recordId)), 'a MySQL site record differs');
        }
        $connection->transaction(static fn () => (new Larena\Storage\Runtime\PublishedKeyTable($connection))->silenceScope($pagesSchema, 'site:main'));
        foreach (['page-1', 'PAGE-1', 'page-1 ', 'page-missing'] as $slug) {
            [$resolved, $answered] = $siteAnswered(static fn () => $siteRead->resolveKey($pagesSchema, 'site:main', $siteLocale, 'slug', $slug));
            storageViewExpect($answered && serialize($resolved) === serialize($exactRead->resolveKey($pagesSchema, 'site:main', $siteLocale, 'slug', $slug)), 'a MySQL site key differs for ' . $slug);
            storageViewExpect(($resolved !== null) === ($slug === 'page-1'), 'the MySQL key column collation answered ' . $slug);
        }
        storageViewExpect($siteRead->projectionExplain($pagesSchema, 'site:main', $siteLocale) === $exactRead->projectionExplain($pagesSchema, 'site:main', $siteLocale), 'a MySQL site explain differs');
    }
    $enSlot = $site->registry()->slot($pagesSchema, 'site:main', 'en', null, true);
    $expectedPage = $exactRead->publishedProjection($pagesSchema, 'site:main', 'en');
    $forged = $connection->table($siteTable)->where('slot_id', $enSlot->id)->where('record_id', $pageRecords[1]->recordId)->first();
    $forgedEntry = Larena\Storage\View\Site\PublishedSiteViewProjector::decodeEntry((string) $forged->entry_json);
    $forgedEntry['revision'] = 9;
    $forgedJson = Larena\Storage\View\Site\PublishedSiteViewProjector::encodeEntry($forgedEntry);
    $connection->table($siteTable)->where('slot_id', $enSlot->id)->where('record_id', $pageRecords[1]->recordId)->update(['revision' => 9, 'entry_json' => $forgedJson, 'entry_hash' => hash('sha256', $forgedJson)]);
    storageViewExpect(serialize($siteRead->publishedProjection($pagesSchema, 'site:main', 'en')) === serialize($expectedPage), 'a forged MySQL row reached a reader');
    storageViewExpect($site->registry()->slot($pagesSchema, 'site:main', 'en', null, true)?->state === 'pending', 'a forged MySQL row did not silence its slot');
    (new Larena\Storage\View\Site\PublishedSiteViewRebuilder($site))->rebuild($pagesSchema);
    publishedSiteViewParity($site, $pagesSchema, 'mysql site rebuilt after a forged row');
    $reference = publishedSiteViewSnapshot($site, $pagesSchema);
    $siteRebuilder = new Larena\Storage\View\Site\PublishedSiteViewRebuilder($site);
    $siteRebuilder->rebuild($pagesSchema, 2, true);
    publishedSiteViewParity($site, $pagesSchema, 'mysql site rebuild');
    storageViewExpect(publishedSiteViewSnapshot($site, $pagesSchema) === $reference, 'the MySQL site rebuild differs from the rows kept by publication');
    // The public list on MySQL: every case equal through the slot (FULLTEXT search, binary
    // equality, the capped count) and through the published records, as the oracle expects.
    $listProperties = Larena\Property\Runtime\PropertyTypeRegistry::builtIns();
    $listKey = 'published-list-mysql-cursor-key-of-at-least-32-bytes';
    $listReader = static fn (?Larena\Storage\View\Site\PublishedSiteViewReader $reader): Larena\Storage\Runtime\DatabaseReadContracts => new Larena\Storage\Runtime\DatabaseReadContracts(
        $connection, $siteRuntime['localized'], new PublishedSiteViewChain(), new Larena\Storage\Runtime\StorageMetrics(), null, $reader,
        Larena\Storage\Runtime\DatabaseReadContracts::KEY_SCAN_LIMIT, $listProperties, $listKey,
    );
    $items = $siteWorkbench->createStructure($scope, ['structure_id' => 'workbench.site_items', 'label' => 'Items', 'fields' => publishedListFields()], $actor);
    $itemsSchema = $items->schema->schemaId;
    for ($index = 0; $index < 30; $index++) {
        $item = $siteWorkbench->createRecord($scope, 'workbench.site_items', publishedListValues($index), $actor);
        $publication->publish($itemsSchema, $item->recordId, 'site:main', 'en', 1, $actor);
        if ($index % 3 !== 2) {
            $publication->publish($itemsSchema, $item->recordId, 'site:main', 'kk', 1, $actor);
        }
    }
    publishedSiteViewParity($site, $itemsSchema, 'mysql list seeded');
    $listAnswered = publishedListMatrix($connection, $listReader($siteRuntime['reader']), $listReader(null), $itemsSchema, StorageViewNaming::siteTable($itemsSchema), ['site:main'], ['en', 'kk'], 'mysql');
    storageViewExpect($listAnswered === 2 * count(publishedListCases()), 'the MySQL slots answered ' . $listAnswered . ' lists');
    $capped = new Larena\Storage\Runtime\DatabaseReadContracts(
        $connection, $siteRuntime['localized'], new PublishedSiteViewChain(), new Larena\Storage\Runtime\StorageMetrics(), null, $siteRuntime['reader'],
        Larena\Storage\Runtime\DatabaseReadContracts::KEY_SCAN_LIMIT, $listProperties, $listKey, new Larena\Storage\View\StorageViewCounter(5),
    );
    $estimated = $capped->publishedList(new Larena\Storage\Contracts\PublishedListQuery($itemsSchema, 'site:main', 'en', limit: 3, countMode: Larena\Storage\Enums\StorageWorkbenchCountMode::Auto));
    storageViewExpect(!$estimated->countExact && $estimated->matchedCount !== null && $estimated->matchedCount >= 6, 'the MySQL list count above the threshold is not an estimate');
    $archivedPage = $siteWorkbench->archiveRecord($scope, 'workbench.site_pages', $pageRecords[3]->recordId, $pageRecords[3]->revision, $actor);
    $siteWorkbench->purgeRecords($scope, 'workbench.site_pages', [$pageRecords[3]->recordId => $archivedPage->revision], $actor);
    storageViewExpect($connection->table($siteTable)->where('record_id', $pageRecords[3]->recordId)->count() === 0, 'a purged record kept its MySQL site rows');
    publishedSiteViewParity($site, $pagesSchema, 'mysql site purge');
    storageViewExpect(!str_contains(publishedSiteViewDump($connection, $pagesSchema), 'SENTINEL'), 'a MySQL site table holds an admin value');
    $pages = $siteWorkbench->readStructure($scope, 'workbench.site_pages', $actor);
    $pages = $siteWorkbench->archiveStructure($scope, 'workbench.site_pages', $pages->version, $actor);
    $siteWorkbench->purgeStructure($scope, 'workbench.site_pages', $pages->version, $actor);
    storageViewExpect($site->registry()->find($pagesSchema, true) === null && !$ddl->tableExists($siteTable) && !$ddl->tableExists($siteTable . '_s'), 'the MySQL site tables were not dropped after the purge');
} finally {
    Facade::clearResolvedInstances();
    ($database['drop'])();
}

echo "StorageViewMySqlTest passed.\n";
