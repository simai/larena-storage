<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\DocumentPort\DatabaseDocumentPortStore;
use Larena\Storage\DocumentPort\DocumentPort;
use Larena\Storage\DocumentPort\DocumentPortBinding;
use Larena\Storage\DocumentPort\DocumentPortElement;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// The document port's history.list is read a page at a time, never every version of the record: a record with 500
// versions answers a page in a bounded number of queries, and the paged drafts are the drafts the whole history
// makes (a run of versions with the same document is one draft, named by its newest version), page edges included.

$actor = 'actor:admin:editor';
$scope = 'scope:site-main';
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
publishedSiteViewInstall($connection);
(require __DIR__ . '/../../database/migrations/platform/2026_11_20_000002_create_larena_storage_document_port_receipts_table.php')->up();
$runtime = publishedSiteViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope);
$workbench = $runtime['workbench'];
$workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
    publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], false, true),
    publishedSiteViewField('document', 20, 'composition', 'public'),
]], $actor);

$document = static fn (string $text): array => ['ru' => ['schema' => 'simai.composition.document', 'schema_version' => 1, 'id' => 'page',
    'profile' => 'ui-layout', 'locale' => 'ru', 'root' => ['id' => 'root', 'type' => 'layout.page', 'slots' => ['default' => [
        ['id' => 'main-1', 'type' => 'content.paragraph', 'data' => ['content' => [['text' => $text]]]],
    ]]]]];

// 500 versions: draft k is written once and then edited (k % 3) more times in the title only, so drafts of one, two
// and three versions fall on every page edge.
$record = null;
$versions = 0;
for ($k = 0; $versions < 500; $k++) {
    for ($edit = 0; $edit <= $k % 3 && $versions < 500; $edit++, $versions++) {
        $values = ['title' => 'Страница ' . $k . '.' . $edit, 'document' => $document('Черновик ' . $k)];
        $record = $record === null
            ? $workbench->createRecord($scope, 'workbench.pages', $values, $actor)
            : $workbench->updateRecord($scope, 'workbench.pages', $record->recordId, $record->revision, $values, $actor);
    }
}
$schemaId = $workbench->readStructure($scope, 'workbench.pages', $actor)->schema->schemaId;
$rows = $connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->where('record_id', $record->recordId)
    ->orderBy('id')->get(['id', 'values_json'])->all();
storageViewExpect(count($rows) === 500, 'the record has 500 versions, ' . count($rows));

// The drafts the whole history makes, worked out here from every version, newest first.
$expected = [];
$previous = null;
foreach ($rows as $row) {
    $text = json_decode((string) $row->values_json, true)['document']['ru']['root']['slots']['default'][0]['data']['content'][0]['text'];
    if ($text === $previous) {
        $expected[count($expected) - 1] = (string) $row->id;
    } else {
        $expected[] = (string) $row->id;
    }
    $previous = $text;
}
$expected = array_reverse($expected);
storageViewExpect(count($expected) === $k, 'one draft per document');

$binding = new DocumentPortBinding($scope, 'workbench.pages', $record->recordId, 'document', null, ['level' => 'page', 'ref' => 'page:/team/'], ['ru']);
$store = new DatabaseDocumentPortStore($connection, $workbench, $runtime['publication'], $binding, $actor);
$port = DocumentPort::bound(new DocumentPortElement(['level' => 'page', 'ref' => 'page:/team/'], ['ru'], ['history']), $store);
storageViewExpect($port->send('document.load')['data']['revision'] === $expected[0], 'the current draft is the newest');

// Paged with several limits, the pages joined are the whole history, each page within a bounded number of queries.
foreach ([20, 7, 1, 100] as $limit) {
    $seen = [];
    $before = null;
    $pages = 0;
    do {
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $answer = $port->send('history.list', ['limit' => $limit] + ($before === null ? [] : ['before' => $before]));
        $queries = count($connection->getQueryLog());
        $versionReads = count(array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'larena_storage_record_versions')));
        $connection->disableQueryLog();
        storageViewExpect($answer['answer'] === 'applied', 'history.list applies: ' . json_encode($answer['reason'] ?? null));
        $drafts = $answer['data']['drafts'];
        storageViewExpect(count($drafts) <= $limit && $drafts !== [], 'a page holds at most the limit');
        // A page reads its drafts and the one version that closes it: at most three versions a draft, in chunks of 32.
        $bound = 6 + (int) ceil((3 * $limit + 3) / 32);
        storageViewExpect($versionReads <= $bound && $queries <= $bound + 10, "limit {$limit}: {$versionReads} version reads, {$queries} queries");
        foreach ($drafts as $draft) {
            $seen[] = $draft['revision'];
            storageViewExpect($draft['languages'] === ['ru'], 'a draft names its languages');
        }
        $before = end($drafts)['revision'];
        $pages++;
    } while ($answer['data']['more']['drafts']);
    storageViewExpect($seen === $expected, "limit {$limit}: the pages are the whole history, grouped as one: " . json_encode(array_slice(array_diff_assoc($seen, $expected), 0, 3, true)));
    storageViewExpect($pages === (int) ceil(count($expected) / $limit), "limit {$limit}: {$pages} pages");
}

// A draft's time is its oldest version's; a page edge never splits a draft into two.
$first = $store->drafts(null, 3);
$next = $store->drafts($first['drafts'][2]['revision'], 3);
storageViewExpect(array_column([...$first['drafts'], ...$next['drafts']], 'revision') === array_slice($expected, 0, 6), 'two pages of three are the first six drafts');
storageViewExpect(count(array_unique(array_column([...$first['drafts'], ...$next['drafts']], 'revision'))) === 6, 'no draft on two pages');

// before must name a draft: an older version of a draft (a title edit inside it) or an unknown revision is not-found.
$inside = null;
foreach (array_reverse($rows) as $row) {
    if (!in_array((string) $row->id, $expected, true)) {
        $inside = (string) $row->id;
        break;
    }
}
storageViewExpect($inside !== null && $port->send('history.list', ['before' => $inside])['reason'] === 'not-found', 'a version inside a draft is not a draft');
storageViewExpect($port->send('history.list', ['before' => '999999'])['reason'] === 'not-found', 'an unknown revision is not-found');
storageViewExpect($port->send('history.list', ['before_publication' => 'p1'])['reason'] === 'not-found', 'no publications without publish');
storageViewExpect($store->drafts(null, 1000)['more'] === false && count($store->drafts(null, 1000)['drafts']) === count($expected), 'one page of all');

echo 'DocumentPortHistoryPagingTest passed: ' . count($expected) . " drafts of 500 versions\n";
