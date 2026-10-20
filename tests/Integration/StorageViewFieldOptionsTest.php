<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewChecker;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewLayout;
use Larena\Storage\View\StorageViewLayoutPlanner;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewQueryCompiler;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistration;
use Larena\Storage\View\StorageViewSearch;
use Larena\Storage\View\StorageViewSync;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The fields' витрина options (`searchable`, `indexed`), on SQLite with both DDL
// paths, and the SQL the compiler writes for MySQL:
// - validation: reason codes for a wrong type, a missing or long max_length, a
//   non-boolean, the 40-index budget;
// - an options change rewrites no record version; the layout changes online: a
//   string that becomes indexed gets a VARCHAR(max_length) column and index
//   (backfilled, the old column answering meanwhile, then dropped), an index that
//   is no longer wanted is dropped, the search text of new search fields is
//   refilled by the queued rebuild while the PHP list answers searches;
// - the search table is kept on create, update, archive and purge;
// - search through the search table, with the same results as the PHP list;
// - MySQL: MATCH ... AGAINST in boolean mode with `+word*`, the binary LIKE check,
//   the substring fallback for short terms, the index range of a prefix filter.

/** The storages a workbench asked to backfill. */
final class StorageOptionsBuildRequests
{
    /** @var list<string> */
    private array $ids = [];

    public function add(string $schemaId): void
    {
        $this->ids[] = $schemaId;
    }

    /** @return list<string> The requests since the last take. */
    public function take(): array
    {
        $ids = $this->ids;
        $this->ids = [];

        return $ids;
    }
}

/** @return array{connection: Connection, path: string} */
function storageOptionsOpen(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-view-options-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_view_options_tempfile_failed');
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

/**
 * @param array<string, mixed> $constraints
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function storageOptionsField(string $key, int $position, string $type, array $constraints = [], array $options = []): array
{
    return storageViewField($key, $position, $type, $constraints) + $options;
}

/** @return list<string> Record ids a list returns, in order. */
function storageOptionsIds(DatabaseStorageWorkbench $workbench, string $scope, string $structureId, string $actor, array $filters = [], ?string $search = null, array $sort = [], bool $archived = false): array
{
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, $structureId, $filters, $search, $sort, 100, null, $archived), $actor);

    return array_map(static fn ($record): string => $record->recordId, $page->items);
}

/** @param list<string> $ids @return list<string> */
function storageOptionsSorted(array $ids): array
{
    sort($ids, SORT_STRING);

    return $ids;
}

/** @return array<string, array<string, mixed>> */
function storageOptionsFieldsByKey(array $fields): array
{
    $byKey = [];
    foreach ($fields as $field) {
        $byKey[(string) $field['key']] = $field;
    }

    return $byKey;
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$paths = [];

try {
    // Unit: the options of fields, defaults and checks.
    $defaults = StorageViewFieldOptions::resolve([
        storageViewField('code', 5, 'string'),
        storageViewField('title', 10, 'string'),
        storageViewField('body', 20, 'text'),
    ]);
    storageViewExpect($defaults->searchable === ['body', 'code', 'title'] && $defaults->indexed === [], 'by default every string and text field is searchable and nothing is indexed');
    storageViewExpect(StorageViewFieldOptions::resolve([storageOptionsField('title', 10, 'string', [], ['searchable' => false]), storageOptionsField('body', 20, 'text', [], ['searchable' => true])])->searchable === ['body'],
        'explicit searchable flags are not followed');
    $rejections = [
        [[storageOptionsField('qty', 10, 'integer', [], ['searchable' => true])], StorageViewFieldOptions::REASON_SEARCHABLE_TYPE],
        [[storageOptionsField('body', 10, 'text', [], ['indexed' => true])], StorageViewFieldOptions::REASON_INDEXED_TYPE],
        [[storageOptionsField('status', 10, 'choice', ['options' => [['value' => 'a', 'label' => 'A']]], ['indexed' => false])], StorageViewFieldOptions::REASON_INDEXED_TYPE],
        [[storageOptionsField('code', 10, 'string', [], ['indexed' => true])], StorageViewFieldOptions::REASON_INDEXED_MAX_LENGTH],
        [[storageOptionsField('code', 10, 'string', ['max_length' => 256], ['indexed' => true])], StorageViewFieldOptions::REASON_INDEXED_MAX_LENGTH],
        [[storageOptionsField('code', 10, 'string', ['max_length' => 20], ['indexed' => 'yes'])], StorageViewFieldOptions::REASON_OPTION_INVALID],
    ];
    foreach ($rejections as [$fields, $reason]) {
        storageViewRejects(static fn () => StorageViewFieldOptions::validate($fields), $reason);
    }
    $budget = [];
    for ($i = 1; $i <= 35; $i++) {
        $budget[] = storageViewField('n' . $i, 100 + $i, 'integer');
    }
    for ($i = 1; $i <= 5; $i++) {
        $budget[] = storageOptionsField('s' . $i, 200 + $i, 'string', ['max_length' => 50], ['indexed' => true]);
    }
    storageViewExpect(count(StorageViewFieldOptions::validate($budget)->indexed) === 5, '35 automatic and 5 requested indexes are refused');
    $budget[] = storageOptionsField('s6', 300, 'string', ['max_length' => 50], ['indexed' => true]);
    storageViewRejects(static fn () => StorageViewFieldOptions::validate($budget), StorageViewFieldOptions::REASON_INDEX_LIMIT);
    storageViewExpect(StorageViewFieldOptions::validate([storageOptionsField('code', 10, 'string', [], ['indexed' => false])])->indexed === [], 'indexed: false on a string without max_length is refused');

    // Unit: the search rule.
    $text = StorageViewSearch::text(["  Красный\t\n СТОЛ, (oak) ", '', 'SKU-0001 o_neil']);
    storageViewExpect($text === " красный стол oak \x1F sku 0001 o_neil", 'the search text is not the lowercased words of each field: ' . json_encode($text));
    storageViewExpect(StorageViewSearch::text(['', ' ,. ']) === null, 'a text without words is not empty');
    storageViewExpect(StorageViewSearch::terms('Chair  chair СТОЛ sku-0001 ,,') === ['chair', 'стол', 'sku 0001'], 'terms are not their words, lowercased and distinct');
    storageViewExpect(StorageViewSearch::likePattern('50% off_') === '% 50!% off!_%', 'the term pattern does not escape LIKE wildcards');
    storageViewExpect(StorageViewSearch::booleanQuery(['sku 0001', 'ab', 'стол']) === '+0001*', 'the boolean query is not +word* for the first longest word');
    storageViewExpect(StorageViewSearch::booleanQuery(['ab', 'quartzite cedar']) === '+quartzite*', 'the boolean query does not take the longest word');
    storageViewExpect(StorageViewSearch::booleanQuery(['ab', 'x']) === null, 'short terms produced a FULLTEXT query');
    storageViewExpect(StorageViewSearch::booleanQuery(['ab'], 2) === '+ab*', 'the server token size is not followed');
    storageViewExpect(StorageViewSearch::matches($text, ['стол', 'oa', 'sku 00', 'o_n']), 'terms at the start of words do not match');
    foreach (['тол', 'ak', 'oak sku', 'neil', 'sku 1'] as $term) {
        storageViewExpect(!StorageViewSearch::matches($text, [$term]), 'a term inside a word, across fields or out of order matched: ' . $term);
    }
    storageViewExpect(!StorageViewSearch::matches($text, []) && !StorageViewSearch::matches(null, ['oak']), 'no terms, or no text, matched');

    // Unit: the layout planner with options.
    $planner = new StorageViewLayoutPlanner();
    $schemaFields = [
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('code', 20, 'string', ['max_length' => 40]),
        storageViewField('body', 30, 'text'),
    ];
    $plain = $planner->plan($schemaFields, 1, null, StorageViewFieldOptions::resolve($schemaFields))->promoted();
    storageViewExpect($plain->searchFields === ['body', 'code', 'title'] && $plain->searchState === StorageViewLayout::SEARCH_READY, 'a new layout does not search every string and text field');
    storageViewExpect($plain->columns[1]->sqlType === StorageViewColumn::SQL_TEXT && !$plain->columns[1]->indexed, 'an unindexed string is not long text');
    $indexed = $planner->plan($schemaFields, 1, $plain, new StorageViewFieldOptions(['title', 'body'], ['code']));
    $byState = [];
    foreach ($indexed->columns as $column) {
        $byState[$column->fieldKey . ':' . $column->state] = $column;
    }
    $oldCode = $byState['code:ready'] ?? null;
    $newCode = $byState['code:adding'] ?? null;
    storageViewExpect($oldCode instanceof StorageViewColumn && $newCode instanceof StorageViewColumn && $oldCode->column === 'c2'
        && $newCode->sqlType === 'varchar40' && $newCode->indexed && $newCode->column === 'c4',
        'a string that becomes indexed does not get a new VARCHAR(40) column beside its ready one');
    storageViewExpect($indexed->searchFields === ['body', 'title'] && $indexed->searchState === StorageViewLayout::SEARCH_BACKFILLING, 'new search fields are not marked for refilling');
    $filled = $indexed->backfilling()->promoted();
    $codeColumns = array_values(array_filter($filled->columns, static fn (StorageViewColumn $c): bool => $c->fieldKey === 'code'));
    storageViewExpect(count($codeColumns) === 2 && $codeColumns[0]->state === 'dropping' && $codeColumns[1]->state === 'ready' && $filled->searchState === 'ready',
        'a filled backfill does not retire the old column');
    $settled = $filled->withoutDropped();
    $unindexed = $planner->plan($schemaFields, 1, $settled, new StorageViewFieldOptions(['title', 'body'], []));
    $code = array_values(array_filter($unindexed->columns, static fn (StorageViewColumn $c): bool => $c->fieldKey === 'code'));
    storageViewExpect(count($code) === 1 && $code[0]->column === 'c4' && $code[0]->sqlType === 'varchar40' && !$code[0]->indexed,
        'an index no longer wanted does not keep its VARCHAR column without the index');
    $ops = array_map(static fn (array $op): string => $op['op'] . ':' . $op['column']->column, $planner->diff($settled, $unindexed));
    storageViewExpect($ops === ['drop_index:c4'], 'the diff of an unindexed string is not one dropped index: ' . implode(', ', $ops));
    storageViewExpect(StorageViewLayout::fromJson($indexed->toJson())->hash === $indexed->hash, 'the search part of the layout does not round trip');
    $legacyJson = json_encode(['schema_version' => 1, 'next_ordinal' => 2, 'columns' => [['field_key' => 'title', 'column' => 'c1', 'kind' => 'string', 'sql_type' => 'text', 'indexed' => false, 'state' => 'ready']]], JSON_THROW_ON_ERROR);
    $legacy = StorageViewLayout::fromJson($legacyJson);
    storageViewExpect($legacy->searchFields === null && !$legacy->searchTable() && $legacy->toJson() === $legacyJson, 'a layout stored before the search fields does not keep its shape and hash');
    storageViewExpect($planner->plan([storageViewField('title', 10, 'string')], 1, $legacy)->searchFields === ['title'], 'a legacy layout does not take the default search fields');

    foreach ([true, false] as $transactionalDdl) {
        $mode = $transactionalDdl ? 'sqlite ddl' : 'mysql-like ddl';
        $opened = storageOptionsOpen();
        $paths[] = $opened['path'];
        $connection = $opened['connection'];
        storageViewInstall($connection);
        $ddl = new SqliteStorageViewDdl($connection, $transactionalDdl);
        // The storages the workbench asked to backfill.
        $requested = new StorageOptionsBuildRequests();
        $runtime = storageViewRuntime($connection, $ddl, $actor, $scope, null, static function (string $schemaId) use ($requested): void {
            $requested->add($schemaId);
        });
        $workbench = $runtime['workbench'];
        $views = $runtime['views'];
        $php = new DatabaseStorageWorkbench($connection, Larena\Property\Runtime\PropertyTypeRegistry::builtIns(), new StorageViewFixtureAccess($actor, $scope),
            new StorageViewFixtureAccess($actor, $scope), $runtime['storage'], $runtime['evolution'], (static function () {
                $policies = new Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry();
                $policies->seal();

                return $policies;
            })(), 'storage-view-fixture-cursor-key-minimum-32-bytes');
        $rebuilder = new StorageViewRebuilder($views);
        $compiler = new StorageViewQueryCompiler();

        // Validation through the workbench.
        storageViewRejects(static fn () => $workbench->createStructure($scope, ['structure_id' => 'workbench.bad', 'label' => 'Bad', 'fields' => [
            storageOptionsField('title', 10, 'string', [], ['indexed' => true]),
        ]], $actor), StorageViewFieldOptions::REASON_INDEXED_MAX_LENGTH);
        storageViewRejects(static fn () => $workbench->createStructure($scope, ['structure_id' => 'workbench.bad', 'label' => 'Bad', 'fields' => [
            storageOptionsField('title', 10, 'string', [], ['searchable' => 1]),
        ]], $actor), StorageViewFieldOptions::REASON_OPTION_INVALID);

        $fields = [
            storageOptionsField('title', 10, 'string', ['max_length' => 200]),
            storageOptionsField('code', 20, 'string', ['max_length' => 40]),
            // Opted out: every other string and text field is searched by default.
            storageOptionsField('body', 30, 'text', [], ['searchable' => false]),
            storageOptionsField('qty', 40, 'integer'),
        ];
        $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $fields], $actor);
        $schemaId = $structure->schema->schemaId;
        $table = StorageViewNaming::table($schemaId);
        storageViewExpect($ddl->tableExists(StorageViewNaming::searchTable($table)), $mode . ': a new structure has no search table');
        $rows = [
            ['title' => 'Oak Chair', 'code' => 'A-1', 'body' => 'Solid wood, waxed', 'qty' => 3],
            ['title' => 'Armchair', 'code' => 'B-2', 'body' => 'Leather and   OAK frame', 'qty' => 1],
            ['title' => 'Стол дубовый', 'code' => 'C-3', 'body' => 'Массив дуба', 'qty' => 7],
            ['title' => 'Pine shelf', 'code' => 'a-10', 'qty' => 2],
        ];
        $records = [];
        foreach ($rows as $values) {
            $records[] = $workbench->createRecord($scope, 'workbench.items', $values, $actor);
        }
        storageViewParity($connection, $views, $schemaId, $mode . ' created');
        $search = static fn (string $term, bool $archived = false): array => storageOptionsSorted(storageOptionsIds($workbench, $scope, 'workbench.items', $actor, [], $term, [], $archived));
        $searchPhp = static fn (string $term, bool $archived = false): array => storageOptionsSorted(storageOptionsIds($php, $scope, 'workbench.items', $actor, [], $term, [], $archived));
        // Every string and text field but the opted-out body; every term at the start of a word.
        storageViewExpect($search('chair') === [$records[0]->recordId] && $search('CHAI') === [$records[0]->recordId], $mode . ': a word prefix of the title is not found, or armchair is');
        storageViewExpect($search('armch') === [$records[1]->recordId], $mode . ': a title word prefix is not found');
        storageViewExpect($search('chair oak') === [$records[0]->recordId], $mode . ': two terms in any order do not narrow the search');
        storageViewExpect($search('СТОЛ') === [$records[2]->recordId], $mode . ': a Cyrillic term is not case-folded');
        storageViewExpect($search('wood') === [], $mode . ': the body is searched without being searchable');
        foreach (['chair', 'chair oak', 'oak chair', 'СТОЛ', 'wood', 'a', '1', 'hair', ',,'] as $term) {
            storageViewExpect($search($term) === $searchPhp($term), $mode . ': the витрина and the PHP list disagree on ' . $term);
        }

        // An options change: code indexed, body searchable. No record is rewritten.
        $versions = $connection->table('larena_storage_record_versions')->count();
        $requested->take();
        $optioned = [
            storageOptionsField('title', 10, 'string', ['max_length' => 200]),
            storageOptionsField('code', 20, 'string', ['max_length' => 40], ['indexed' => true]),
            storageOptionsField('body', 30, 'text', [], ['searchable' => true]),
            storageOptionsField('qty', 40, 'integer'),
        ];
        $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $optioned], $actor);
        storageViewExpect($connection->table('larena_storage_record_versions')->count() === $versions && $structure->schema->version === 1,
            $mode . ': an options change rewrote record versions or the schema');
        storageViewExpect($requested->take() === [$schemaId], $mode . ': the options change did not ask for the backfill');
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration?->state === StorageViewRegistration::STATE_READY && $registration->layout->needsBackfill()
            && $registration->layout->searchState === StorageViewLayout::SEARCH_BACKFILLING, $mode . ': the витрина is not ready with a backfill pending');
        $columns = $ddl->columns($table);
        $newCode = null;
        foreach ($registration->layout->columns as $column) {
            if ($column->fieldKey === 'code' && $column->state === StorageViewColumn::STATE_BACKFILLING) {
                $newCode = $column;
            }
        }
        storageViewExpect($newCode !== null && $newCode->sqlType === 'varchar40' && in_array((string) $newCode->column, $columns, true)
            && in_array(StorageViewDdl::fieldIndexName($table, (string) $newCode->column), $ddl->indexes($table), true),
            $mode . ': the indexed string did not get its column and index online');
        $byKey = storageOptionsFieldsByKey($structure->fields);
        $query = static fn (array $filters = [], ?string $term = null, array $sort = []): array => ['filters' => $filters, 'search' => $term, 'sort' => $sort, 'include_archived' => false];
        storageViewExpect($compiler->compile($connection, $registration, $byKey, $query([], 'wood')) === null, $mode . ': a search was answered from search text still being refilled');
        storageViewExpect($compiler->compile($connection, $registration, $byKey, $query(['code' => ['operator' => 'eq', 'value' => 'B-2']])) !== null, $mode . ': the old code column stopped answering during the backfill');
        storageViewExpect($search('wood') === [$records[0]->recordId], $mode . ': the PHP list does not search the newly searchable body meanwhile');
        // A write during the backfill fills its own row in full.
        $records[3] = $workbench->updateRecord($scope, 'workbench.items', $records[3]->recordId, $records[3]->revision, ['title' => 'Pine shelf', 'code' => 'a-10', 'body' => 'Knotty pine', 'qty' => 2], $actor);
        $result = $rebuilder->rebuild($schemaId, 2);
        storageViewExpect($result['records'] === 4, $mode . ': the backfill did not walk every record');
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration !== null && !$registration->layout->needsBackfill() && $registration->layout->searchFields === ['body', 'code', 'title'], $mode . ': the backfill did not finish');
        storageViewExpect(!in_array('c2', $ddl->columns($table), true), $mode . ': the replaced code column was not dropped');
        storageViewParity($connection, $views, $schemaId, $mode . ' backfilled');
        storageViewExpect((new StorageViewChecker($views))->check($schemaId)['problems'] === [], $mode . ': the checker finds problems after the backfill');
        storageViewExpect($search('oak') === storageOptionsSorted([$records[0]->recordId, $records[1]->recordId]), $mode . ': the body is not searched after the backfill');
        storageViewExpect($search('pine knotty') === [$records[3]->recordId], $mode . ': a write during the backfill lost its search text');
        foreach (['oak', 'wood', 'дуб', 'oak frame', 'a-1', 'leather oak'] as $term) {
            storageViewExpect($search($term) === $searchPhp($term), $mode . ': the витрина and the PHP list disagree on ' . $term . ' after the backfill');
        }
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration !== null && $compiler->compile($connection, $registration, storageOptionsFieldsByKey($structure->fields), $query([], 'oak')) !== null,
            $mode . ': the filled search text does not answer');
        $codeIds = storageOptionsIds($workbench, $scope, 'workbench.items', $actor, [], null, [['field' => 'code', 'direction' => 'asc']]);
        storageViewExpect($codeIds === storageOptionsIds($php, $scope, 'workbench.items', $actor, [], null, [['field' => 'code', 'direction' => 'asc']]), $mode . ': the indexed string sorts differently');
        storageViewExpect(storageOptionsIds($workbench, $scope, 'workbench.items', $actor, ['code' => ['operator' => 'starts_with', 'value' => 'a-1']]) === storageOptionsIds($php, $scope, 'workbench.items', $actor, ['code' => ['operator' => 'starts_with', 'value' => 'a-1']]),
            $mode . ': a prefix filter on the indexed string differs');

        // The index is no longer wanted: dropped, the VARCHAR column kept, nothing to backfill.
        $requested->take();
        $optioned[1] = storageOptionsField('code', 20, 'string', ['max_length' => 40], ['indexed' => false]);
        $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $optioned], $actor);
        $registration = $views->registry()->find($schemaId, true);
        $code = array_values(array_filter($registration?->layout->columns ?? [], static fn (StorageViewColumn $c): bool => $c->fieldKey === 'code'));
        storageViewExpect($requested->take() === [] && count($code) === 1 && !$code[0]->indexed && $code[0]->sqlType === 'varchar40' && $code[0]->state === 'ready', $mode . ': an unindexed string did not keep its column');
        storageViewExpect(!in_array(StorageViewDdl::fieldIndexName($table, (string) $code[0]->column), $ddl->indexes($table), true), $mode . ': the index no longer wanted was not dropped');
        storageViewParity($connection, $views, $schemaId, $mode . ' unindexed');

        // The search table follows archive and purge.
        $archived = $workbench->archiveRecord($scope, 'workbench.items', $records[1]->recordId, $records[1]->revision, $actor);
        storageViewParity($connection, $views, $schemaId, $mode . ' archived');
        storageViewExpect($search('armchair') === [] && $search('armchair', true) === [$records[1]->recordId], $mode . ': an archived record is searched by default, or not at all');
        $workbench->purgeRecords($scope, 'workbench.items', [$archived->recordId => $archived->revision], $actor);
        storageViewExpect($connection->table(StorageViewNaming::searchTable($table))->where('record_id', $records[1]->recordId)->doesntExist(), $mode . ': a purged record kept its search row');
        storageViewParity($connection, $views, $schemaId, $mode . ' purged');

        // An index alone (the search fields stay): the new column is written from now on and backfilled.
        $requested->take();
        $optioned[0] = storageOptionsField('title', 10, 'string', ['max_length' => 200], ['indexed' => true]);
        $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $optioned], $actor);
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration !== null && $registration->backfillPending() && $registration->layout->searchState === 'ready'
            && $requested->take() === [$schemaId], $mode . ': an index alone left no backfill');
        $rebuilder->rebuild($schemaId);
        storageViewParity($connection, $views, $schemaId, $mode . ' title indexed');

        // A field added with its options in one migration: the schema apply rewrites every
        // record and fills everything, nothing is left to backfill.
        $optioned[] = storageOptionsField('sku', 50, 'string', ['max_length' => 16], ['indexed' => true, 'searchable' => true]);
        $requested->take();
        $structure = $workbench->migrateStructure($scope, 'workbench.items', $structure->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $optioned], $actor);
        $registration = $views->registry()->find($schemaId, true);
        storageViewExpect($registration?->state === 'ready' && !$registration->layout->needsBackfill() && $registration->layout->searchFields === ['body', 'code', 'sku', 'title'] && $requested->take() === [],
            $mode . ': a field added with options left a backfill');
        $sku = array_values(array_filter($registration->layout->columns, static fn (StorageViewColumn $c): bool => $c->fieldKey === 'sku'));
        storageViewExpect(count($sku) === 1 && $sku[0]->sqlType === 'varchar16' && $sku[0]->indexed, $mode . ': the added field is not an indexed VARCHAR(16)');
        storageViewParity($connection, $views, $schemaId, $mode . ' field added with options');

        // A layout stored before the search fields: it never answers a search, and the
        // rebuild gives it its search table and fills it in place.
        $current = $views->registry()->find($schemaId, true);
        storageViewExpect($current !== null, $mode . ': the registration is gone');
        $views->registry()->put($current->withLayout(new StorageViewLayout($current->layout->schemaVersion, $current->layout->nextOrdinal, $current->layout->columns)));
        $connection->statement('DROP TABLE "' . StorageViewNaming::searchTable($table) . '"');
        $legacy = $views->registry()->find($schemaId, true);
        storageViewExpect($legacy !== null && $compiler->compile($connection, $legacy, storageOptionsFieldsByKey($structure->fields), $query([], 'oak')) === null,
            $mode . ': a legacy layout answered a search');
        storageViewExpect(in_array('backfill_pending', (new StorageViewChecker($views))->check($schemaId)['problems'], true), $mode . ': the checker does not report the legacy layout');
        $rebuilder->rebuild($schemaId);
        $upgraded = $views->registry()->find($schemaId, true);
        storageViewExpect($upgraded?->state === 'ready' && $upgraded->layout->searchFields === ['body', 'code', 'sku', 'title'] && !$upgraded->layout->needsBackfill()
            && $ddl->tableExists(StorageViewNaming::searchTable($table)), $mode . ': the legacy layout was not backfilled');
        storageViewParity($connection, $views, $schemaId, $mode . ' legacy backfilled');
        storageViewExpect($search('oak') === $searchPhp('oak') && $search('oak') !== [], $mode . ': the backfilled legacy layout does not search');

        // A search refresh empties the search table and refills it in place; lists keep the витрина.
        storageViewExpect($views->refreshSearch($schemaId), $mode . ': a search refresh was refused');
        $refreshing = $views->registry()->find($schemaId, true);
        storageViewExpect($refreshing?->state === 'ready' && $refreshing->backfillPending()
            && $compiler->compile($connection, $refreshing, storageOptionsFieldsByKey($structure->fields), $query([], 'oak')) === null
            && $compiler->compile($connection, $refreshing, storageOptionsFieldsByKey($structure->fields), $query(['qty' => ['operator' => 'gte', 'value' => 1]])) !== null,
            $mode . ': a refreshing search was answered, or the rest of the list stopped');
        storageViewExpect(!$views->refreshSearch($schemaId), $mode . ': a refresh under way was started again');
        $rebuilder->rebuild($schemaId);
        storageViewParity($connection, $views, $schemaId, $mode . ' search refreshed');
        storageViewExpect($search('oak') === $searchPhp('oak') && !$views->registry()->find($schemaId, true)?->backfillPending(), $mode . ': the refreshed search does not answer');
    }

    // The two forms of a search give the same pages: the window of the first rows in list
    // order answers a common term, the indexed form a rare one (here a second check that
    // stands for the FULLTEXT form on SQLite).
    $opened = storageOptionsOpen();
    $paths[] = $opened['path'];
    $connection = $opened['connection'];
    $ddl = new SqliteStorageViewDdl($connection);
    $probeTable = StorageViewNaming::table('workbench.scope.probe');
    $probeLayout = (new StorageViewLayoutPlanner())->plan([storageViewField('title', 10, 'string'), storageViewField('qty', 20, 'integer')], 1)->promoted();
    $ddl->ensureTable($probeTable, $probeLayout);
    $projector = new Larena\Storage\View\StorageViewProjector();
    $writer = new Larena\Storage\View\StorageViewWriter($connection);
    $projections = [];
    for ($i = 0; $i < 3000; $i++) {
        // Every record is "common", one in 500 is "rare"; qty is a sort key in reverse.
        $projections[] = $projector->project($probeLayout, sprintf('record-%032d', $i), 1, 1, str_repeat('f', 64), [
            'larena_record_state' => 'active',
            'title' => 'Common item ' . ($i % 500 === 7 ? 'rare' : 'plain') . ' ' . $i,
            'qty' => 3000 - $i,
        ], '2026-10-21 10:00:00', '2026-10-21 10:00:00');
    }
    $connection->transaction(static fn () => $writer->upsert($probeTable, $projections));
    $probeSearch = StorageViewNaming::searchTable($probeTable);
    $forms = static function (string $term) use ($probeSearch): array {
        $pattern = StorageViewSearch::likePattern($term);
        $check = static function ($where) use ($probeSearch, $pattern): void {
            $where->whereExists(static fn ($exists) => $exists->selectRaw('1')->from($probeSearch . ' as s')
                ->whereColumn('s.record_id', '=', 'v.record_id')->whereRaw('s.search_text LIKE ? ESCAPE \'!\'', [$pattern]));
        };
        $indexed = static function ($where) use ($probeSearch, $pattern): void {
            $where->whereIn('v.record_id', static fn ($matched) => $matched->select('s.record_id')->from($probeSearch . ' as s')
                ->whereRaw('s.search_text LIKE ? ESCAPE \'!\'', [$pattern]));
        };

        return [$check, $indexed];
    };
    $state = static fn ($where) => $where->where('v.state', '=', 'active');
    foreach (['common', 'rare', 'nothing'] as $term) {
        foreach ([[], [['column' => (string) $probeLayout->columns[1]->column, 'direction' => 'asc']]] as $sort) {
            [$check, $indexed] = $forms($term);
            $both = new Larena\Storage\View\StorageViewQuery($connection, $probeTable, $state, $sort, $check, $indexed);
            $only = new Larena\Storage\View\StorageViewQuery($connection, $probeTable, $state, $sort, $check);
            $first = $both->page(5);
            storageViewExpect($first === $only->page(5), 'the two forms disagree on the first page of ' . $term);
            storageViewExpect($term !== 'nothing' || $first === [], 'a term nothing has matched');
            // "nothing" has 3000 candidates and no match: the window is not the whole table, so the indexed form answers.
            storageViewExpect($both->searchCommon() === ($term === 'common'), 'the page did not pick the right form for ' . $term);
            // The indexed form counts its matches with the page; past the last match there is nothing to count with.
            storageViewExpect($both->knownTotal() === ($term === 'rare' ? 6 : null), 'the indexed form did not count its matches for ' . $term);
            $last = $first[count($first) - 1] ?? null;
            if ($last !== null) {
                $after = ['values' => $last['keys'], 'record_id' => $last['record_id']];
                storageViewExpect($both->page(5, 0, $after) === $only->page(5, 0, $after), 'the two forms disagree after a keyset for ' . $term);
            }
            storageViewExpect($both->page(5, 3) === $only->page(5, 3), 'the two forms disagree at an offset for ' . $term);
            storageViewExpect($both->filtered()->count() === $only->filtered()->count(), 'the two forms count differently for ' . $term);
        }
    }

    // The SQL the compiler writes for MySQL, without a server.
    $mysql = new MySqlConnection(static fn () => throw new RuntimeException('no server in this test'), 'larena', '', ['driver' => 'mysql', 'name' => 'compile-only']);
    $layout = (new StorageViewLayoutPlanner())->plan([
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('body', 20, 'text'),
    ], 1, null, new StorageViewFieldOptions(['body', 'title'], ['title']))->promoted();
    $view = StorageViewRegistration::create('workbench.scope.compile', $layout, StorageViewRegistration::STATE_READY);
    $compileFields = storageOptionsFieldsByKey([
        storageOptionsField('title', 10, 'string', ['max_length' => 200], ['indexed' => true]),
        storageOptionsField('body', 20, 'text', [], ['searchable' => true]),
    ]);
    $compiled = (new StorageViewQueryCompiler([3, 84]))->compile($mysql, $view, $compileFields, [
        'filters' => ['title' => ['operator' => 'starts_with', 'value' => 'oak_']],
        'search' => 'Chair sku-0001 ab',
        'sort' => [['field' => 'title', 'direction' => 'asc']],
        'include_archived' => false,
    ]);
    storageViewExpect($compiled !== null && $compiled->sort === [['column' => 'c1', 'direction' => 'asc']], 'the MySQL list does not sort by the indexed title column');
    $builder = $compiled->filtered();
    $sql = $builder->toSql();
    $bindings = $builder->getBindings();
    $searchTable = StorageViewNaming::searchTable($view->tableName);
    storageViewExpect(str_contains($sql, '`v`.`record_id` in (select `s`.`record_id` from `' . $searchTable . '` as `s` where MATCH(`s`.`search_text`) AGAINST (? IN BOOLEAN MODE)'),
        'the MySQL search is not a MATCH on the search table: ' . $sql);
    storageViewExpect(!$compiled->searchCommon(), 'a search counted as common before a page was read');
    storageViewExpect(substr_count($sql, '`s`.`search_text` COLLATE utf8mb4_bin LIKE ? ESCAPE \'!\'') === 3, 'every term is not checked by a binary LIKE: ' . $sql);
    storageViewExpect(str_contains($sql, 'LOWER(`v`.`c1`) COLLATE utf8mb4_bin LIKE ? ESCAPE \'!\'') && str_contains($sql, ' and `v`.`c1` LIKE ? ESCAPE \'!\''),
        'the prefix filter on an indexed string has no index range: ' . $sql);
    storageViewExpect($bindings === ['active', 'oak!_%', 'oak!_%', '+chair*', '% chair%', '% sku 0001%', '% ab%'], 'the MySQL bindings are wrong: ' . json_encode($bindings));
    $short = (new StorageViewQueryCompiler([3, 84]))->compile($mysql, $view, $compileFields, ['filters' => [], 'search' => 'ab', 'sort' => [], 'include_archived' => true]);
    storageViewExpect($short !== null, 'a short term search was not compiled');
    $shortSql = $short->filtered()->toSql();
    storageViewExpect(!str_contains($shortSql, 'MATCH(') && str_contains($shortSql, 'exists (select 1 from `' . $searchTable . '` as `s` where `s`.`record_id` = `v`.`record_id` and `s`.`search_text` COLLATE utf8mb4_bin LIKE ?')
        && $short->filtered()->getBindings() === ['% ab%'], 'a short term is not checked row by row: ' . $shortSql);
    storageViewExpect((new StorageViewQueryCompiler([3, 84]))->compile($mysql, $view, storageOptionsFieldsByKey([
        storageOptionsField('title', 10, 'string', ['max_length' => 200], ['indexed' => true]),
        storageOptionsField('body', 20, 'text', [], ['searchable' => false]),
    ]), ['filters' => [], 'search' => 'chair', 'sort' => [], 'include_archived' => false]) === null, 'a search over other fields than the search text holds was answered');
} finally {
    Facade::clearResolvedInstances();
    foreach ($paths as $path) {
        @unlink($path);
    }
}

echo "StorageViewFieldOptionsTest passed.\n";
