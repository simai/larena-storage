<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\View\MySqlStorageViewDdl;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewCounter;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';
require_once __DIR__ . '/../Support/mysql-disposable-database.php';
require_once __DIR__ . '/../Support/storage-reference-list.php';

// Lists read from the витрина in SQL, on SQLite:
// - parity with the reference list (every current record read and matched in PHP)
//   on the same data: every filter operator of every field type, search, sorts
//   (NULLs, three keys, record id, revision, creation time), archived;
// - keyset continuation across pages, page numbers, the earlier offset
//   continuation, refused tampered or foreign continuations;
// - counts: exact, an estimate above the threshold, Exact on request, Skip;
// - incoming references answered from the витрина;
// - a storage of 6000 records lists, filters, sorts and pages;
// - the list by creation time with ties, and its index added to an older table.

/** @return array{connection: Connection, path: string} */
function storageViewListOpen(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-view-list-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_view_list_tempfile_failed');
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

/** A workbench on the same tables and витрина, with a given counter. */
function storageViewListWorkbench(Connection $connection, array $runtime, string $actor, string $scope, ?StorageViewSync $views, ?StorageViewCounter $counter = null): DatabaseStorageWorkbench
{
    $access = new StorageViewFixtureAccess($actor, $scope);
    $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $policies->seal();

    return new DatabaseStorageWorkbench($connection, PropertyTypeRegistry::builtIns(), $access, $access, $runtime['storage'],
        $policies, 'storage-view-fixture-cursor-key-minimum-32-bytes', new NullStorageSecurityEventSink(), $views, $counter);
}

/** True when the call read a витрина table. */
function storageViewListUsedView(Connection $connection, callable $call): bool
{
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    try {
        $call();
    } finally {
        $log = $connection->getQueryLog();
        $connection->disableQueryLog();
    }
    foreach ($log as $entry) {
        if (str_contains((string) $entry['query'], StorageViewNaming::TABLE_PREFIX)) {
            return true;
        }
    }

    return false;
}

/**
 * The whole scenario on one connection. On MySQL, strings sort by the column's
 * collation rather than byte order, so lists sorted by a string are compared as
 * sets there; everything else is compared in order.
 */
function storageViewListScenario(Connection $connection, StorageViewDdl $ddl, bool $mysql): void
{
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    storageViewInstall($connection);
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope);
    $sql = $runtime['workbench'];
    $views = $runtime['views'];
    // The expected answers, read record by record and never from the витрина.
    $php = new StorageReferenceList($connection, $sql, new StorageViewFixtureAccess($actor, $scope), 'storage-view-fixture-cursor-key-minimum-32-bytes');

    $sections = $sql->createStructure($scope, ['structure_id' => 'workbench.sections', 'label' => 'Sections',
        'fields' => [storageViewField('title', 10, 'string', [], true)]], $actor);
    $sectionIds = [];
    foreach (['Books', 'Games', 'Music'] as $title) {
        $sectionIds[] = $sql->createRecord($scope, $sections->structureId, ['title' => $title], $actor)->recordId;
    }
    $fields = [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('label', 15, 'string'),
        storageViewField('body', 20, 'text'),
        storageViewField('qty', 30, 'integer'),
        storageViewField('price', 40, 'number'),
        storageViewField('day', 50, 'date'),
        storageViewField('starts_at', 60, 'datetime'),
        storageViewField('active', 70, 'boolean'),
        storageViewField('status', 80, 'choice', ['options' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'review', 'label' => 'Review'], ['value' => 'done', 'label' => 'Done']]]),
        storageViewField('tags', 90, 'choices', ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'green', 'label' => 'Green'], ['value' => 'blue', 'label' => 'Blue']]]),
        storageViewField('owner', 100, 'user'),
        storageViewField('document', 110, 'file'),
        storageViewField('section', 120, 'record', ['target_structure_id' => 'workbench.sections']),
    ];
    $items = $sql->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $fields], $actor);

    // Deterministic records with gaps (NULLs), mixed case, non-ASCII letters and LIKE wildcards.
    mt_srand(20261021);
    $words = ['Alpha', 'alpha', 'ÄPFEL', 'äpfel', 'Ёлка', 'ёжик', 'Straße', 'STRASSE', '50%_off', '50% off', 'under_score', 'Zeta', 'omega'];
    $created = [];
    for ($index = 0; $index < 80; $index++) {
        $values = ['title' => $words[$index % count($words)] . ' ' . sprintf('%03d', ($index * 37) % 101)];
        $maybe = static fn (int $percent): bool => mt_rand(1, 100) <= $percent;
        if ($maybe(70)) {
            $values['label'] = $words[mt_rand(0, count($words) - 1)];
        }
        if ($maybe(60)) {
            $values['body'] = "Line one\nline " . $words[mt_rand(0, count($words) - 1)];
        }
        if ($maybe(80)) {
            $values['qty'] = mt_rand(-5, 20);
        }
        if ($maybe(80)) {
            $values['price'] = sprintf('%d.%02d', mt_rand(0, 300), mt_rand(0, 99));
        }
        if ($maybe(80)) {
            $values['day'] = sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28));
        }
        if ($maybe(80)) {
            $values['starts_at'] = sprintf('2026-%02d-%02dT%02d:%02d:00', mt_rand(1, 12), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59));
        }
        if ($maybe(80)) {
            $values['active'] = $maybe(50);
        }
        if ($maybe(80)) {
            $values['status'] = ['draft', 'review', 'done'][mt_rand(0, 2)];
        }
        if ($maybe(70)) {
            $values['tags'] = array_values(array_unique([['red', 'green', 'blue'][mt_rand(0, 2)], ['red', 'green', 'blue'][mt_rand(0, 2)]]));
        }
        if ($maybe(70)) {
            $values['owner'] = 'user:admin:' . mt_rand(1, 4);
        }
        if ($maybe(50)) {
            $values['document'] = sprintf('018f4f4c-706e-7b1f-9a3e-%012x', mt_rand(1, 5));
        }
        if ($maybe(70)) {
            $values['section'] = $sectionIds[mt_rand(0, 2)];
        }
        $record = $sql->createRecord($scope, $items->structureId, $values, $actor);
        if ($index % 9 === 4) {
            $record = $sql->updateRecord($scope, $items->structureId, $record->recordId, $record->revision, $values + ['qty' => 7], $actor);
        }
        if ($index % 7 === 3) {
            $record = $sql->archiveRecord($scope, $items->structureId, $record->recordId, $record->revision, $actor);
        }
        $created[] = $record;
    }
    storageViewParity($connection, $views, $items->schema->schemaId, 'seeded items');

    $ids = static fn ($page): array => array_map(static fn ($record): string => $record->recordId, $page->items);
    $stringKeys = ['title', 'label', 'body'];
    $both = static function (array $filters = [], ?string $search = null, array $sort = [], bool $archived = false) use ($sql, $php, $scope, $items, $actor, $ids, $connection, $mysql, $stringKeys): void {
        $query = new StorageWorkbenchRecordQuery($scope, $items->structureId, $filters, $search, $sort, 100, null, $archived);
        $expected = $php->listRecords($query, $actor);
        $actual = null;
        $used = storageViewListUsedView($connection, static function () use (&$actual, $sql, $query, $actor): void {
            $actual = $sql->listRecords($query, $actor);
        });
        $label = json_encode(['filters' => $filters, 'search' => $search, 'sort' => $sort, 'archived' => $archived], JSON_UNESCAPED_UNICODE);
        storageViewExpect($used, 'the list did not read the витрина: ' . $label);
        $actualIds = $ids($actual);
        $expectedIds = $ids($expected);
        if ($mysql && array_intersect(array_column($sort, 'field'), $stringKeys) !== []) {
            sort($actualIds);
            sort($expectedIds);
        }
        storageViewExpect($actualIds === $expectedIds, 'list mismatch ' . $label . ': ' . json_encode($actualIds) . ' vs ' . json_encode($expectedIds));
        storageViewExpect($actual->matchedCount === $expected->matchedCount && $actual->countExact, 'count mismatch ' . $label);
        $byId = [];
        foreach ($expected->items as $record) {
            $byId[$record->recordId] = $record;
        }
        foreach ($actual->items as $record) {
            storageViewExpect($record->contentHash === $byId[$record->recordId]->contentHash && $record->values === $byId[$record->recordId]->values,
                'hydrated record differs ' . $label);
        }
    };

    // Every operator of every field type, against values the records hold and values they do not.
    $pool = [];
    foreach ($created as $record) {
        foreach ($record->values as $key => $value) {
            if ($value !== null) {
                $pool[$key][] = $value;
            }
        }
    }
    $pick = static function (string $key, int $nth) use ($pool) {
        $values = $pool[$key];

        return $values[($nth * 13) % count($values)];
    };
    $operators = $sql->filterOperators();
    $checked = 0;
    foreach ($fields as $field) {
        $key = $field['key'];
        $type = $field['type'];
        foreach ($operators[$type] as $operator) {
            for ($nth = 0; $nth < 3; $nth++) {
                $value = $pick($key, $nth);
                $other = $pick($key, $nth + 1);
                $filter = match ($operator) {
                    'eq' => ['operator' => 'eq', 'value' => $type === 'choices' ? $value[0] : $value],
                    'in' => ['operator' => 'in', 'values' => $type === 'choices' ? array_values(array_unique([$value[0], $other[count($other) - 1]])) : array_values(array_unique([$value, $other], SORT_REGULAR))],
                    'contains' => ['operator' => 'contains', 'value' => mb_strtoupper(mb_substr((string) $value, 1, 3))],
                    'starts_with' => ['operator' => 'starts_with', 'value' => mb_strtoupper(mb_substr((string) $value, 0, 2))],
                    'between' => ['operator' => 'between', 'values' => $value <= $other ? [$value, $other] : [$other, $value]],
                    default => ['operator' => $operator, 'value' => $value],
                };
                $both([$key => $filter]);
                $both([$key => $filter], null, [], true);
                $checked++;
            }
        }
    }
    storageViewExpect($checked >= 3 * 40, 'not every operator was checked');
    // Wildcards in a text fragment match literally; a value nobody holds matches nothing.
    $both(['title' => ['operator' => 'contains', 'value' => '%_']]);
    $both(['title' => ['operator' => 'contains', 'value' => '_']]);
    $both(['title' => ['operator' => 'starts_with', 'value' => '50%']]);
    $both(['title' => ['operator' => 'eq', 'value' => 'alpha 000']]);
    $both(['qty' => ['operator' => 'gt', 'value' => 1000]]);
    $both(['day' => ['operator' => 'between', 'values' => ['2026-02-01', '2026-02-28']], 'status' => ['operator' => 'in', 'values' => ['draft', 'done']],
        'tags' => ['operator' => 'eq', 'value' => 'red']]);
    $both(['active' => ['operator' => 'eq', 'value' => false]]);
    $both(['starts_at' => ['operator' => 'gte', 'value' => '2026-06-01T00:00']]);

    // Search (every string and text field by default): word prefixes, Unicode letters, literal wildcards.
    foreach (['alpha', 'ÄPF', 'ёл', 'strasse', 'straße', '%', '_', 'line', 'nothing-like-this', '0'] as $needle) {
        $both([], $needle);
        $both([], $needle, [], true);
    }
    $both(['qty' => ['operator' => 'gte', 'value' => 0]], 'a', [['field' => 'price', 'direction' => 'desc']]);

    // Sorting: every sortable field both ways (NULL first ascending), three keys, record id and revision.
    foreach (['title', 'label', 'body', 'qty', 'price', 'day', 'starts_at', 'active', 'status', 'owner', 'document', 'section', '_record_id', '_revision', '_created_at'] as $key) {
        foreach (['asc', 'desc'] as $direction) {
            $both([], null, [['field' => $key, 'direction' => $direction]]);
            $both([], null, [['field' => $key, 'direction' => $direction]], true);
        }
    }
    $both([], null, [['field' => 'status', 'direction' => 'asc'], ['field' => 'active', 'direction' => 'desc'], ['field' => 'qty', 'direction' => 'asc']], true);
    $both([], null, [['field' => 'label', 'direction' => 'desc'], ['field' => '_revision', 'direction' => 'desc']]);
    $both([], null, [['field' => '_created_at', 'direction' => 'desc'], ['field' => 'qty', 'direction' => 'asc']], true);

    // Keyset continuation: page after page equals the whole list, also through NULL keys.
    foreach ([
        [],
        [['field' => 'label', 'direction' => 'asc']],
        [['field' => 'label', 'direction' => 'desc'], ['field' => 'qty', 'direction' => 'asc']],
        [['field' => 'status', 'direction' => 'desc'], ['field' => 'price', 'direction' => 'desc'], ['field' => 'day', 'direction' => 'asc']],
        [['field' => '_created_at', 'direction' => 'desc']],
    ] as $sort) {
        $whole = $ids($sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $sort, limit: 100, includeArchived: true), $actor));
        $walked = [];
        $continuation = null;
        $pages = 0;
        do {
            $page = $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $sort, limit: 7, continuation: $continuation, includeArchived: true), $actor);
            $walked = [...$walked, ...$ids($page)];
            $continuation = $page->continuation;
            storageViewExpect($page->matchedCount === count($whole) && $page->countExact, 'a continued page lost its count');
        } while ($continuation !== null && ++$pages < 50);
        storageViewExpect($walked === $whole, 'keyset pages differ from the whole list for ' . json_encode($sort));
        // Page numbers give the same slices.
        $third = $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $sort, limit: 7, includeArchived: true, page: 3), $actor);
        storageViewExpect($ids($third) === array_slice($whole, 14, 7), 'page 3 differs from the slice');
    }
    $first = $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 5), $actor);
    $payload = json_decode((string) base64_decode(strtr((string) $first->continuation, '-_', '+/'), false), true);
    storageViewExpect(is_array($payload) && $payload['version'] === 2 && $payload['offset'] === 5, 'the continuation is not a keyset one');
    storageViewRejects(static fn () => $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 5,
        continuation: substr((string) $first->continuation, 0, -2) . 'AA'), $actor), 'storage_workbench_record_continuation_invalid');
    storageViewRejects(static fn () => $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 5,
        filters: ['qty' => ['operator' => 'gt', 'value' => 0]], continuation: $first->continuation), $actor), 'storage_workbench_record_continuation_invalid');
    // The earlier offset continuation (version 1) is still read for one release.
    $old = $php->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 5), $actor);
    $oldNext = $php->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 5, continuation: $old->continuation), $actor);
    $newNext = $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 5, continuation: $old->continuation), $actor);
    storageViewExpect($ids($newNext) === $ids($oldNext), 'an offset continuation was not continued');
    // A page past the end is empty; a page past the offset limit is refused.
    storageViewExpect($sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 50, page: 9), $actor)->items === [], 'a page past the end is not empty');
    storageViewRejects(static fn () => $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 10, page: 1002), $actor),
        'storage_workbench_record_page_invalid');

    // A sort key too long to carry (long text) continues by offset and still walks the whole list.
    $long = $sql->createStructure($scope, ['structure_id' => 'workbench.long', 'label' => 'Long',
        'fields' => [storageViewField('body', 10, 'text', [], true)]], $actor);
    for ($index = 0; $index < 12; $index++) {
        $sql->createRecord($scope, $long->structureId, ['body' => str_repeat('x', 300) . sprintf('%02d', ($index * 5) % 12)], $actor);
    }
    $longSort = [['field' => 'body', 'direction' => 'desc']];
    $longWhole = $ids($php->listRecords(new StorageWorkbenchRecordQuery($scope, $long->structureId, sort: $longSort, limit: 100), $actor));
    $longWalked = [];
    $continuation = null;
    do {
        $page = $sql->listRecords(new StorageWorkbenchRecordQuery($scope, $long->structureId, sort: $longSort, limit: 5, continuation: $continuation), $actor);
        $longWalked = [...$longWalked, ...$ids($page)];
        $continuation = $page->continuation;
        if ($continuation !== null) {
            $payload = json_decode((string) base64_decode(strtr($continuation, '-_', '+/'), false), true);
            storageViewExpect(is_array($payload) && $payload['version'] === 1, 'a long sort key was carried in the continuation');
        }
    } while ($continuation !== null && count($longWalked) < 100);
    storageViewExpect($longWalked === $longWhole, 'a long-key list did not walk by offset');

    // Counts: an estimate above the threshold, Exact on request, Skip, exact under the threshold.
    $estimating = storageViewListWorkbench($connection, $runtime, $actor, $scope, $views, new StorageViewCounter(5, 250, false));
    $active = $php->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 1), $actor)->matchedCount;
    $auto = $estimating->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 10), $actor);
    // At the cap the count is the optimizer's estimate on MySQL, at least the cap, and never below
    // the rows the page has shown (10 and one more).
    storageViewExpect(!$auto->countExact && ($mysql ? $auto->matchedCount >= 11 : $auto->matchedCount === 11), 'a large count was not an estimate');
    $exact = $estimating->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 10, countMode: StorageWorkbenchCountMode::Exact), $actor);
    storageViewExpect($exact->countExact && $exact->matchedCount === $active, 'Exact did not count exactly');
    $skip = $estimating->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 10, countMode: StorageWorkbenchCountMode::Skip), $actor);
    storageViewExpect(!$skip->countExact && $skip->matchedCount === 11 && count($skip->items) === 10, 'Skip counted');
    $skipLast = $estimating->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 100, countMode: StorageWorkbenchCountMode::Skip), $actor);
    storageViewExpect($skipLast->countExact && $skipLast->matchedCount === $active, 'Skip on the last page is not exact');
    $small = $estimating->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId,
        filters: ['title' => ['operator' => 'starts_with', 'value' => 'zeta']], limit: 1), $actor);
    $smallExpected = $php->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId,
        filters: ['title' => ['operator' => 'starts_with', 'value' => 'zeta']], limit: 1), $actor)->matchedCount;
    storageViewExpect($small->countExact && $small->matchedCount === $smallExpected && $smallExpected <= 5, 'a small count was not exact');
    $lastPage = $estimating->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 10, page: (int) ceil($active / 10)), $actor);
    storageViewExpect($lastPage->countExact && $lastPage->matchedCount === $active, 'the last page did not know the total');

    // A sort without an index of its own: the page names the field, and says whether the list is large
    // (here from 20 records, by the counter's count). An indexed sort or no sort says nothing.
    $sizing = storageViewListWorkbench($connection, $runtime, $actor, $scope, $views, new StorageViewCounter(StorageViewCounter::THRESHOLD, 250, true, 20));
    $byTitle = [['field' => 'title', 'direction' => 'asc']];
    $unindexedPage = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $byTitle, limit: 10), $actor);
    storageViewExpect($unindexedPage->sortUnindexed === ['title'] && $unindexedPage->large, 'a large list sorted by a string without an index did not say so');
    $twoKeys = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId,
        sort: [['field' => 'qty', 'direction' => 'desc'], ['field' => 'label', 'direction' => 'asc']], limit: 10), $actor);
    storageViewExpect($twoKeys->sortUnindexed === ['label'] && $twoKeys->large, 'only the unindexed key of a sort is named');
    $indexedPage = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: [['field' => 'qty', 'direction' => 'asc']], limit: 10), $actor);
    storageViewExpect($indexedPage->sortUnindexed === [] && !$indexedPage->large, 'an indexed sort was reported as slow');
    $unsorted = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, limit: 10), $actor);
    storageViewExpect($unsorted->sortUnindexed === [] && !$unsorted->large, 'a list without a sort was reported as slow');
    $narrow = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $byTitle,
        filters: ['title' => ['operator' => 'starts_with', 'value' => 'zeta']], limit: 10), $actor);
    storageViewExpect($narrow->sortUnindexed === ['title'] && !$narrow->large, 'a small filtered list was called large');
    $skipFirst = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $byTitle, limit: 10,
        countMode: StorageWorkbenchCountMode::Skip), $actor);
    storageViewExpect($skipFirst->sortUnindexed === ['title'] && $skipFirst->large, 'an uncounted first page was not sized');
    $skipNext = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $byTitle, limit: 10,
        countMode: StorageWorkbenchCountMode::Skip, continuation: $skipFirst->continuation), $actor);
    storageViewExpect($skipNext->sortUnindexed === ['title'] && !$skipNext->large, 'a reader paging on was counted again');
    $exactLarge = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $byTitle, limit: 10,
        countMode: StorageWorkbenchCountMode::Exact), $actor);
    storageViewExpect($exactLarge->large, 'an exactly counted large list was not large');
    storageViewExpect(!$sql->listRecords(new StorageWorkbenchRecordQuery($scope, $items->structureId, sort: $byTitle, limit: 10), $actor)->large,
        'a list under 50 000 records was called large');
    // A string indexed on request sorts by its own index.
    $codes = $sql->createStructure($scope, ['structure_id' => 'workbench.codes', 'label' => 'Codes',
        'fields' => [storageViewField('code', 10, 'string', ['max_length' => 40], true) + ['indexed' => true]]], $actor);
    foreach (['b', 'a', 'c'] as $code) {
        $sql->createRecord($scope, $codes->structureId, ['code' => $code], $actor);
    }
    $codePage = $sizing->listRecords(new StorageWorkbenchRecordQuery($scope, $codes->structureId, sort: [['field' => 'code', 'direction' => 'asc']]), $actor);
    storageViewExpect($codePage->sortUnindexed === [] && array_map(static fn ($record) => $record->values['code'], $codePage->items) === ['a', 'b', 'c'],
        'a string indexed on request was reported as unindexed');

    // Incoming references come from the витрина of the structure that holds them.
    $referenced = null;
    foreach ($created as $record) {
        if (($record->values['section'] ?? null) !== null) {
            $referenced = $record->values['section'];
            break;
        }
    }
    storageViewExpect(is_string($referenced), 'no record references a section');
    $section = $sql->readRecord($scope, $sections->structureId, $referenced, $actor);
    $archivedSection = $sql->archiveRecord($scope, $sections->structureId, $section->recordId, $section->revision, $actor);
    $used = storageViewListUsedView($connection, static function () use ($sql, $scope, $sections, $archivedSection, $actor): void {
        storageViewRejects(static fn () => $sql->purgeRecords($scope, $sections->structureId,
            [$archivedSection->recordId => $archivedSection->revision], $actor), 'storage_record_purge_referenced');
    });
    storageViewExpect($used, 'incoming references were not read from the витрина');
    $sectionsNow = $sql->readStructure($scope, $sections->structureId, $actor);
    $sectionsArchived = $sql->archiveStructure($scope, $sections->structureId, $sectionsNow->version, $actor);
    storageViewRejects(static fn () => $sql->purgeStructure($scope, $sections->structureId, $sectionsArchived->version, $actor), 'storage_record_purge_referenced');
    $sql->restoreStructure($scope, $sections->structureId, $sectionsArchived->version, $actor);

    // A field change keeps the list in SQL once the витрина follows the new schema.
    $itemsNow = $sql->readStructure($scope, $items->structureId, $actor);
    $sql->updateStructure($scope, $items->structureId, $itemsNow->version, ['structure_id' => $items->structureId, 'label' => 'Items',
        'fields' => [...$fields, storageViewField('extra', 130, 'integer')]], $actor);
    $both(['qty' => ['operator' => 'lte', 'value' => 3]], null, [['field' => 'title', 'direction' => 'desc']]);

    // 6000 records, built by the rebuild. Lists, filters, deep pages and keyset all work.
    $bulk = $sql->createStructure($scope, ['structure_id' => 'workbench.bulk', 'label' => 'Bulk',
        'fields' => [storageViewField('name', 10, 'string', [], true)]], $actor);
    $normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
    $connection->transaction(static function () use ($connection, $normalizer, $bulk, $scope, $actor): void {
        $heads = [];
        $versions = [];
        for ($index = 0; $index < 6000; $index++) {
            $recordId = 'record-' . sprintf('%032x', 0xb000000 + $index);
            $ownerRef = 'workbench.record:' . sprintf('%032x', 0xb000000 + $index);
            $json = $normalizer->canonicalJson(['larena_record_state' => 'active', 'larena_scope_ref' => $scope, 'name' => 'Scan ' . $index]);
            $hash = hash('sha256', $json);
            $heads[] = ['record_id' => $recordId, 'schema_id' => $bulk->schema->schemaId, 'owner_ref' => $ownerRef, 'current_revision' => 1,
                'current_schema_version' => 1, 'current_hash' => $hash, 'created_at' => '2026-10-21 00:00:00', 'updated_at' => '2026-10-21 00:00:00'];
            $versions[] = ['schema_id' => $bulk->schema->schemaId, 'record_id' => $recordId, 'revision' => 1, 'owner_ref' => $ownerRef,
                'schema_version' => 1, 'values_json' => $json, 'content_hash' => $hash, 'operation' => 'create', 'created_by' => $actor,
                'correlation_id' => null, 'created_at' => '2026-10-21 00:00:00'];
            if (count($heads) === 200) {
                $connection->table('larena_storage_records')->insert($heads);
                $connection->table('larena_storage_record_versions')->insert($versions);
                $heads = [];
                $versions = [];
            }
        }
    });
    (new StorageViewRebuilder(new StorageViewSync($connection, new StorageViewRegistry($connection, 0.0), $ddl)))->rebuild($bulk->schema->schemaId, 1000, true);
    $views->registry()->forget();
    $bulkQuery = static fn (array $filters = [], ?int $page = null, ?string $continuation = null, int $limit = 60, array $sort = []): StorageWorkbenchRecordQuery
        => new StorageWorkbenchRecordQuery($scope, $bulk->structureId, $filters, null, $sort, $limit, $continuation, false, $page);
    $firstBulk = $sql->listRecords($bulkQuery(), $actor);
    storageViewExpect($firstBulk->matchedCount === 6000 && $firstBulk->countExact && count($firstBulk->items) === 60, 'a list of 6000 records did not open');
    $deep = $sql->listRecords($bulkQuery(page: 100), $actor);
    storageViewExpect(count($deep->items) === 60 && $deep->continuation === null && $deep->items[59]->values['name'] === 'Scan 5999', 'the deep page is wrong');
    storageViewRejects(static fn () => $sql->listRecords($bulkQuery(page: 168), $actor), 'storage_workbench_record_page_invalid');
    $filteredBulk = $sql->listRecords($bulkQuery(['name' => ['operator' => 'starts_with', 'value' => 'scan 59']]), $actor);
    storageViewExpect($filteredBulk->matchedCount === 111, 'a filter over 6000 records counted ' . $filteredBulk->matchedCount);
    $sortedBulk = $sql->listRecords($bulkQuery(limit: 3, sort: [['field' => 'name', 'direction' => 'desc']]), $actor);
    storageViewExpect(array_map(static fn ($record) => $record->values['name'], $sortedBulk->items) === ['Scan 999', 'Scan 998', 'Scan 997'], 'sorting 6000 records is wrong');
    $seen = [];
    $continuation = null;
    $pages = 0;
    do {
        $page = $sql->listRecords($bulkQuery(continuation: $continuation, limit: 100), $actor);
        foreach ($page->items as $record) {
            $seen[$record->recordId] = true;
        }
        $continuation = $page->continuation;
    } while ($continuation !== null && ++$pages < 100);
    storageViewExpect(count($seen) === 6000 && $continuation === null, 'keyset pages did not walk 6000 records');

    storageViewCreatedAtChecks($connection, $ddl, $sql, $views, $scope, $actor);
}

$opened = storageViewListOpen();
try {
    storageViewListScenario($opened['connection'], new SqliteStorageViewDdl($opened['connection']), false);
} finally {
    Facade::clearResolvedInstances();
    foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal'] as $file) {
        @unlink($file);
    }
}

// The same on a throwaway MySQL database (opt-in, as the other витрина MySQL tests).
$optIn = getenv('LARENA_STORAGE_VIEW_MYSQL_TEST');
if (is_string($optIn) && filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    $database = larena_storage_mysql_disposable_database('larena_storage_view_list_test_');
    try {
        $connection = larena_storage_mysql_connect($database['config']);
        $ddl = StorageViewDdl::for($connection);
        storageViewExpect($ddl instanceof MySqlStorageViewDdl, 'MySQL did not get its DDL');
        storageViewListScenario($connection, $ddl, true);
        echo "StorageViewListTest passed on MySQL.\n";
    } finally {
        Facade::clearResolvedInstances();
        ($database['drop'])();
    }
}

echo "StorageViewListTest passed.\n";
