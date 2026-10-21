<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Core\Contracts\OperationContext;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\ReadContractOperationHandlers;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\Site\PublishedSiteViewProjector;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewCounter;
use Larena\Storage\View\StorageViewNaming;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-list-fixture.php';

// The public list (PublishedListReads) on SQLite: every operator, the search and the
// sorts answer the same records in the same order from a ready slot and from the
// published records, as the oracle expects; the three count modes; a keyset walk
// under concurrent publications neither repeats nor skips a record; a cursor that is
// altered or replayed against another scope, locale or query is refused; a field that
// is not public is refused exactly like one that does not exist; the visibility
// filter path; the fallback over a silenced slot and its refusal above the limit;
// verify-on-read; the list sorted by record id is the projection; the operation and
// the metric.

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
publishedSiteViewInstall($connection);
$ddl = new SqliteStorageViewDdl($connection);
$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$queued = new PublishedSiteViewQueued();
$runtime = publishedSiteViewRuntime($connection, $ddl, $actor, $scope, static function (string $schemaId) use ($queued): void {
    $queued->add($schemaId);
});
$workbench = $runtime['workbench'];
$publication = $runtime['publication'];
$localized = $runtime['localized'];
$site = $runtime['site'];
$registry = $site->registry();
$properties = PropertyTypeRegistry::builtIns();
$cursorKey = 'published-list-test-cursor-key-of-at-least-32-bytes';
$chain = new PublishedSiteViewChain();
$readers = static fn (?Larena\Storage\View\Site\PublishedSiteViewReader $reader, ?StorageViewCounter $counter = null, int $fallbackLimit = DatabaseReadContracts::LIST_FALLBACK_LIMIT, ?StorageMetrics $metrics = null, string $key = '') => new DatabaseReadContracts(
    $connection,
    $localized,
    $chain,
    $metrics ?? new StorageMetrics(),
    null,
    $reader,
    DatabaseReadContracts::KEY_SCAN_LIMIT,
    $properties,
    $key === '' ? $cursorKey : $key,
    $counter,
    $fallbackLimit,
);
$slotRead = $readers($runtime['reader']);
$recordsRead = $readers(null);

$structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => publishedListFields()], $actor);
$schemaId = $structure->schema->schemaId;
$siteTable = StorageViewNaming::siteTable($schemaId);
$records = [];
for ($index = 0; $index < 30; $index++) {
    $records[$index] = $workbench->createRecord($scope, 'workbench.items', publishedListValues($index), $actor);
}
foreach ($records as $index => $record) {
    $publication->publish($schemaId, $record->recordId, 'site:main', 'en', 1, $actor);
    if ($index % 3 !== 2) {
        $publication->publish($schemaId, $record->recordId, 'site:main', 'kk', 1, $actor);
    }
    if ($index % 2 === 0) {
        $publication->publish($schemaId, $record->recordId, 'site:other', 'en', 1, $actor);
    }
}
// Translations along kk -> ru, and a translated admin field that must never be listed.
foreach ([1, 4, 7, 10] as $index) {
    $localized->write($schemaId, $records[$index]->recordId, 1, 'ru', ['title' => 'item 1' . $index . ' ru'], ['title', 'body', 'secret'], $actor);
}
$localized->write($schemaId, $records[3]->recordId, 1, 'kk', ['title' => 'item 1 kk', 'secret' => 'SENTINEL-KK'], ['title', 'body', 'secret'], $actor);
publishedSiteViewParity($site, $schemaId, 'seeded');

// --- Every case, through the slot and through the records, as the oracle expects. ---
$answered = publishedListMatrix($connection, $slotRead, $recordsRead, $schemaId, $siteTable, ['site:main', 'site:other'], ['en', 'kk'], 'seeded');
// site:other has no kk publication, so no kk slot: three slots answer every case.
storageViewExpect($answered === 3 * count(publishedListCases()), 'the slot answered ' . $answered . ' lists of ' . 3 * count(publishedListCases()));
echo 'Matrix: ' . $answered . " lists answered from the slot, each equal to the records and the oracle.\n";

// --- The list sorted by record id is the projection, page after page. ---
foreach ([['site:main', 'en'], ['site:main', 'kk'], ['site:other', 'en']] as [$scopeRef, $locale]) {
    $walked = publishedListWalk($slotRead, $schemaId, $scopeRef, $locale, ['sort' => [['field' => '_record_id', 'direction' => 'asc']]], 7);
    storageViewExpect(serialize($walked['records']) === serialize($recordsRead->publishedProjection($schemaId, $scopeRef, $locale)->records), 'the list by record id is not the projection in ' . $scopeRef . '/' . $locale);
    $unsorted = publishedListWalk($slotRead, $schemaId, $scopeRef, $locale, [], 100);
    storageViewExpect(serialize($unsorted['records']) === serialize($walked['records']), 'the unsorted list is not in record id order');
}

// --- A cursor continues across the two paths: a page from the slot, the next from the records. ---
$case = ['filters' => ['tags' => ['operator' => 'in', 'values' => ['a']]], 'sort' => [['field' => 'price', 'direction' => 'desc'], ['field' => 'starts', 'direction' => 'asc']]];
$reference = publishedListWalk($slotRead, $schemaId, 'site:main', 'en', $case, 4)['records'];
$mixed = [];
$continuation = null;
$turn = 0;
do {
    $page = ($turn++ % 2 === 0 ? $slotRead : $recordsRead)->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 4, $continuation));
    array_push($mixed, ...$page->records);
    $continuation = $page->continuation;
} while ($continuation !== null);
storageViewExpect(serialize($mixed) === serialize($reference), 'a cursor did not continue across the slot and the records');

// --- Count modes: an estimate above the threshold is never below what was shown. ---
$estimating = $readers($runtime['reader'], new StorageViewCounter(2, 250, false));
$estimate = $estimating->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], 5, null, StorageWorkbenchCountMode::Auto));
storageViewExpect(!$estimate->countExact && $estimate->matchedCount !== null && $estimate->matchedCount >= 6, 'an estimated count is wrong: ' . json_encode($estimate->toArray()['matched_count']));
$exactCount = $estimating->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], 5, null, StorageWorkbenchCountMode::Exact));
storageViewExpect($exactCount->countExact && $exactCount->matchedCount === 30, 'the exact count is not exact under the estimating counter');
$second = $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], 20, $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], 20))->continuation, StorageWorkbenchCountMode::Auto));
storageViewExpect($second->matchedCount === 30 && $second->countExact && $second->continuation === null && count($second->records) === 10, 'the last page does not count every match');

// --- A keyset walk under concurrent publications: nothing repeated, nothing skipped. ---
$case = ['filters' => ['code' => ['operator' => 'gte', 'value' => 0]], 'sort' => [['field' => 'price', 'direction' => 'desc']]];
$before = array_column(publishedListWalk($slotRead, $schemaId, 'site:main', 'en', $case, 100)['records'], 'record_id');
$seen = [];
$keys = [];
$continuation = null;
$step = 0;
$added = [];
$removed = null;
do {
    $page = $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 2, $continuation));
    foreach ($page->records as $entry) {
        storageViewExpect(!isset($seen[$entry['record_id']]), 'a concurrent walk repeated ' . $entry['record_id']);
        $seen[$entry['record_id']] = true;
        $keys[] = (string) $entry['values']['price'];
    }
    $continuation = $page->continuation;
    $step++;
    if ($step === 3) {
        // One record ahead of the walk, one behind it; one not seen yet leaves; one seen is published again.
        foreach (['49.99', '0.01'] as $price) {
            $new = $workbench->createRecord($scope, 'workbench.items', ['price' => $price] + publishedListValues(40 + count($added)), $actor);
            $publication->publish($schemaId, $new->recordId, 'site:main', 'en', 1, $actor);
            $added[$price] = $new->recordId;
        }
        foreach (array_reverse($before) as $recordId) {
            if (!isset($seen[$recordId])) {
                $removed = $recordId;
                break;
            }
        }
        $publication->unpublish($schemaId, (string) $removed, 'site:main', 'en', $actor);
        $publication->publish($schemaId, (string) array_key_first($seen), 'site:main', 'en', 1, $actor);
    }
} while ($continuation !== null);
$sortedKeys = $keys;
usort($sortedKeys, static fn (string $left, string $right): int => (float) $right <=> (float) $left);
storageViewExpect($keys === $sortedKeys, 'a concurrent walk is out of order');
foreach ($before as $recordId) {
    storageViewExpect($recordId === $removed || isset($seen[$recordId]), 'a concurrent walk skipped ' . $recordId);
}
storageViewExpect(!isset($seen[(string) $removed]) && isset($seen[$added['0.01']]) && !isset($seen[$added['49.99']]), 'the records published and unpublished during the walk are wrong');
$publication->publish($schemaId, (string) $removed, 'site:main', 'en', 1, $actor);
foreach ($added as $recordId) {
    $publication->unpublish($schemaId, $recordId, 'site:main', 'en', $actor);
}
publishedSiteViewParity($site, $schemaId, 'after the concurrent walk');

// --- A cursor is bound to its storage, scope, locale and query, and to the key. ---
$case = ['filters' => ['active' => ['operator' => 'eq', 'value' => true]], 'sort' => [['field' => 'code', 'direction' => 'desc']]];
$first = $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3));
$cursor = (string) $first->continuation;
storageViewExpect($cursor !== '' && !str_contains((string) base64_decode(strtr($cursor, '-_', '+/'), false), 'record-'), 'a cursor carries a readable record id');
$refused = static function (Closure $call, string $label): void {
    try {
        $call();
    } catch (ReadContractRejected $rejected) {
        storageViewExpect($rejected->reasonCode === 'invalid_query', $label . ' was refused as ' . $rejected->reasonCode);

        return;
    }
    throw new RuntimeException($label . ' was accepted');
};
$tampered = substr($cursor, 0, 20) . ($cursor[20] === 'A' ? 'B' : 'A') . substr($cursor, 21);
foreach ([
    'an altered cursor' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3, $tampered)),
    'a truncated cursor' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3, substr($cursor, 0, -4))),
    'a cursor of another scope' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:other', 'en', $case, 3, $cursor)),
    'a cursor of another locale' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'kk', $case, 3, $cursor)),
    'a cursor of another filter' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', ['filters' => ['active' => ['operator' => 'eq', 'value' => false]]] + $case, 3, $cursor)),
    'a cursor of another sort' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', ['sort' => [['field' => 'code', 'direction' => 'asc']]] + $case, 3, $cursor)),
    'a cursor of another search' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', ['search' => 'oak'] + $case, 3, $cursor)),
    'a cursor under another key' => fn () => $readers($runtime['reader'], null, DatabaseReadContracts::LIST_FALLBACK_LIMIT, null, 'another-published-list-cursor-key-of-32-bytes')->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3, $cursor)),
    'a cursor of the records path replayed in another scope' => fn () => $recordsRead->publishedList(publishedListQuery($schemaId, 'site:other', 'en', $case, 3, $cursor)),
    'a garbage cursor' => fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3, 'not+a/cursor')),
] as $label => $call) {
    $refused($call, $label);
}
storageViewExpect(count($slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3, $cursor))->records) === 3, 'the cursor itself no longer works');
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'ready', 'a refused cursor silenced the slot');

// --- A field that is not public is refused exactly like one that does not exist. ---
$messages = [];
foreach ([
    'an admin field filter' => ['filters' => ['secret' => ['operator' => 'eq', 'value' => 'SENTINEL-1']]],
    'an unknown field filter' => ['filters' => ['nothing' => ['operator' => 'eq', 'value' => 'SENTINEL-1']]],
    'a system field filter' => ['filters' => ['larena_scope_ref' => ['operator' => 'eq', 'value' => $scope]]],
    'an admin field sort' => ['sort' => [['field' => 'secret', 'direction' => 'asc']]],
    'an unknown field sort' => ['sort' => [['field' => 'nothing', 'direction' => 'asc']]],
    'a multi-value sort' => ['sort' => [['field' => 'tags', 'direction' => 'asc']]],
    'an operator the type does not take' => ['filters' => ['active' => ['operator' => 'gt', 'value' => true]]],
    'a value the type does not take' => ['filters' => ['code' => ['operator' => 'eq', 'value' => 'three']]],
    'a list of filters' => ['filters' => [['operator' => 'eq', 'value' => 1]]],
    'too many filters' => ['filters' => array_fill_keys(['title', 'slug', 'body', 'price', 'code', 'day', 'starts', 'active', 'status'], ['operator' => 'eq', 'value' => 'x'])],
    'too many sorts' => ['sort' => [['field' => 'code', 'direction' => 'asc'], ['field' => 'day', 'direction' => 'asc'], ['field' => 'price', 'direction' => 'asc'], ['field' => 'title', 'direction' => 'asc']]],
    'too many values' => ['filters' => ['code' => ['operator' => 'in', 'values' => range(1, 101)]]],
    'a fragment too long' => ['filters' => ['title' => ['operator' => 'contains', 'value' => str_repeat('a', 1001)]]],
    'a search too long' => ['search' => str_repeat('a', 101)],
    'a control character' => ['search' => "oak\x01"],
] as $label => $case) {
    foreach ([$slotRead, $recordsRead] as $reader) {
        try {
            $reader->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 3));
            throw new RuntimeException($label . ' was accepted');
        } catch (ReadContractRejected $rejected) {
            storageViewExpect($rejected->reasonCode === 'invalid_query', $label . ' was refused as ' . $rejected->reasonCode);
            $messages[$rejected->getMessage()] = true;
        }
    }
}
storageViewExpect(array_keys($messages) === ['The list query is invalid.'], 'a refusal says more than that the query is invalid: ' . json_encode(array_keys($messages)));
foreach ([0, 101] as $limit) {
    $refused(fn () => $slotRead->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], $limit)), 'limit ' . $limit);
}
foreach ([['limit' => '101'], ['limit' => '0'], ['count' => 'all'], ['filters' => '[1]'], ['filters' => '{"a":'], ['sort' => 'code:up'], ['sort' => 'a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z,aa,bb,cc,dd,ee,ff,gg,hh,ii,jj,kk,ll,mm,nn,oo,pp,qq,rr,ss,tt,uu,vv,ww,xx,yy,zz,aaa,bbb,ccc,ddd,eee,fff,ggg,hhh,iii,jjj,kkk,lll,mmm,nnn,ooo,ppp,qqq']] as $bad) {
    $refused(fn () => PublishedListQuery::fromInput($bad + ['target' => $schemaId, 'scope_ref' => 'site:main', 'locale' => 'en']), 'input ' . json_encode($bad));
}
$parsed = PublishedListQuery::fromInput(['target' => $schemaId, 'scope_ref' => 'site:main', 'locale' => 'en', 'filters' => '{"code":{"operator":"gt","value":3}}', 'sort' => 'price:desc,_published_at', 'limit' => '5', 'count' => 'exact']);
storageViewExpect($parsed->sort === [['field' => 'price', 'direction' => 'desc'], ['field' => '_published_at', 'direction' => 'asc']] && $parsed->limit === 5 && $parsed->countMode === StorageWorkbenchCountMode::Exact && $parsed->filters === ['code' => ['operator' => 'gt', 'value' => 3]], 'the REST input was parsed wrong');
storageViewExpect(!str_contains(json_encode(publishedListWalk($slotRead, $schemaId, 'site:main', 'kk', [], 100)['records']) ?: '', 'SENTINEL'), 'a list carries an admin value');

// --- Through a visibility filter: rows skipped and counted, the cursor after the last row scanned. ---
$visible = static fn (string $recordId): bool => crc32($recordId) % 3 !== 0;
foreach ([$slotRead, $recordsRead] as $reader) {
    foreach ([[], ['filters' => ['tags' => ['operator' => 'eq', 'value' => 'a']], 'sort' => [['field' => 'day', 'direction' => 'desc']]]] as $case) {
        $all = publishedListWalk($reader, $schemaId, 'site:main', 'en', $case, 100)['records'];
        $walk = publishedListWalk($reader, $schemaId, 'site:main', 'en', $case, 2, $visible, StorageWorkbenchCountMode::Exact);
        $expected = array_values(array_filter($all, static fn (array $entry): bool => $visible($entry['record_id'])));
        storageViewExpect(serialize($walk['records']) === serialize($expected), 'the visible walk differs from the visible records');
        $hidden = 0;
        foreach ($walk['pages'] as $page) {
            storageViewExpect($page->matchedCount === null && !$page->countExact, 'a filtered page reported a total');
            $hidden += $page->filteredCount;
        }
        storageViewExpect($hidden === count($all) - count($expected), 'the hidden records were not all counted: ' . $hidden);
        foreach ($walk['cursors'] as $cursor) {
            storageViewExpect(!str_contains((string) base64_decode(strtr($cursor, '-_', '+/'), false), 'record-'), 'a filtered cursor carries a readable record id');
        }
    }
    $none = $reader->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], 5), static fn (string $recordId): bool => false);
    storageViewExpect($none->records === [] && $none->continuation === null && $none->filteredCount === 30, 'a filter that hides everything answered wrong');
}

// --- The records path over a silenced slot, and its refusal above the limit. ---
$case = ['filters' => ['status' => ['operator' => 'in', 'values' => ['live', 'gone']]], 'sort' => [['field' => 'title', 'direction' => 'desc']]];
$fromSlot = publishedListWalk($slotRead, $schemaId, 'site:main', 'en', $case, 4);
$small = $readers($runtime['reader'], null, 10);
[$ready, $answered] = publishedListTrace($connection, $siteTable, static fn () => $small->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 4)));
storageViewExpect($answered && $ready instanceof Larena\Storage\Contracts\PublishedListPage, 'a ready slot did not answer above the records limit');
$registry->silenceSchema($schemaId);
[$silenced, $answered] = publishedListTrace($connection, $siteTable, static fn () => publishedListWalk($slotRead, $schemaId, 'site:main', 'en', $case, 4));
storageViewExpect(!$answered && serialize($silenced['records']) === serialize($fromSlot['records']), 'a silenced slot answered, or the records answered differently');
[$refusal] = publishedListTrace($connection, $siteTable, static fn () => $small->publishedList(publishedListQuery($schemaId, 'site:main', 'en', $case, 4)));
storageViewExpect($refusal === 'rejected:site_view_not_ready', 'a list above the records limit without a slot was not refused: ' . json_encode($refusal));
[$smallOther] = publishedListTrace($connection, $siteTable, static fn () => $readers($runtime['reader'], null, 15)->publishedList(publishedListQuery($schemaId, 'site:other', 'en', $case, 4)));
storageViewExpect($smallOther instanceof Larena\Storage\Contracts\PublishedListPage, 'a list at the records limit was refused');
$rebuilder = new PublishedSiteViewRebuilder($site);
$rebuilder->rebuild($schemaId);
publishedSiteViewParity($site, $schemaId, 'rebuilt');

// --- Verify on read: a row that disagrees silences its slot, the list is still answered exactly. ---
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
$victim = $fromSlot['records'][1]['record_id'];
$row = (array) $connection->table($siteTable)->where('slot_id', $slot->id)->where('record_id', $victim)->first();
$forged = PublishedSiteViewProjector::decodeEntry((string) $row['entry_json']);
$forged['revision'] = 7;
$forged['values']['title'] = 'Forged';
$json = PublishedSiteViewProjector::encodeEntry($forged);
$connection->table($siteTable)->where('slot_id', $slot->id)->where('record_id', $victim)->update(['revision' => 7, 'entry_json' => $json, 'entry_hash' => hash('sha256', $json)]);
$answer = publishedListWalk($slotRead, $schemaId, 'site:main', 'en', $case, 4);
storageViewExpect(serialize($answer['records']) === serialize($fromSlot['records']), 'a forged row reached a list');
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'pending' && in_array($schemaId, $queued->take(), true), 'a forged row did not silence its slot');
$rebuilder->rebuild($schemaId);
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
// A publication time that disagrees is caught the same way.
$connection->table($siteTable)->where('slot_id', $slot->id)->where('record_id', $victim)->update(['published_at' => '2001-01-01 00:00:00']);
$byTime = ['sort' => [['field' => '_published_at', 'direction' => 'asc']]];
$answer = publishedListWalk($slotRead, $schemaId, 'site:main', 'en', $byTime, 50);
storageViewExpect(serialize($answer['records']) === serialize(publishedListWalk($recordsRead, $schemaId, 'site:main', 'en', $byTime, 50)['records']), 'a forged publication time reached a list');
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'pending', 'a forged publication time did not silence its slot');
$rebuilder->rebuild($schemaId);
publishedSiteViewParity($site, $schemaId, 'rebuilt after the forgeries');
$queued->take();

// --- The operation, as the REST site boundary calls it; and the metric. ---
$recorder = new class () {
    /** @var list<array{id: string, tags: array<string, string>}> */
    public array $records = [];

    /** @param array<string, string> $tags */
    public function timing(string $id, float $ms, array $tags = []): void
    {
        $this->records[] = ['id' => $id, 'tags' => $tags];
    }
};
$metered = $readers($runtime['reader'], null, DatabaseReadContracts::LIST_FALLBACK_LIMIT, new StorageMetrics(static fn (): object => $recorder));
$handlers = new ReadContractOperationHandlers($metered);
$descriptor = ReadContractOperationHandlers::descriptors()['storage.read.published_list'];
$output = $handlers->handle($descriptor, new OperationContext('actor:visitor', 'corr-list', metadata: [
    'target' => $schemaId, 'scope_ref' => 'site:main', 'locale' => 'en',
    'filters' => '{"code":{"operator":"gt","value":3}}', 'sort' => 'price:desc', 'limit' => '2', 'count' => 'exact',
]));
storageViewExpect(array_keys($output) === ['records', 'returned', 'continuation', 'matched_count', 'count_exact', 'filtered_count'] && $output['returned'] === 2 && is_string($output['continuation']) && $output['count_exact'] === true, 'the operation answered wrong: ' . json_encode(array_keys($output)));
$next = $handlers->handle($descriptor, new OperationContext('actor:visitor', 'corr-list', metadata: [
    'target' => $schemaId, 'scope_ref' => 'site:main', 'locale' => 'en',
    'filters' => ['code' => ['operator' => 'gt', 'value' => 3]], 'sort' => [['field' => 'price', 'direction' => 'desc']], 'limit' => 2, 'cursor' => $output['continuation'],
]));
storageViewExpect($next['returned'] === 2 && $next['records'][0]['record_id'] !== $output['records'][0]['record_id'], 'the operation did not continue');
try {
    $handlers->handle($descriptor, new OperationContext('actor:visitor', 'corr-list', metadata: ['target' => $schemaId, 'scope_ref' => 'site:main', 'locale' => 'en', 'filters' => '{"secret":{"operator":"eq","value":"x"}}']));
    throw new RuntimeException('the operation accepted an admin field');
} catch (ReadContractRejected $rejected) {
    storageViewExpect($rejected->reasonCode === 'invalid_query', 'the operation refused an admin field as ' . $rejected->reasonCode);
}
$registry->silenceSchema($schemaId);
$metered->publishedList(publishedListQuery($schemaId, 'site:main', 'en', [], 3, null, StorageWorkbenchCountMode::Auto));
$lines = array_map(static fn (array $record): string => $record['id'] . ' ' . json_encode($record['tags']), $recorder->records);
storageViewExpect($lines === [
    'storage.read.published_list {"path":"slot","outcome":"ok","count":"exact"}',
    'storage.read.published_list {"path":"slot","outcome":"ok","count":"skip"}',
    'storage.read.published_list {"path":"none","outcome":"rejected"}',
    'storage.read.published_list {"path":"fallback","outcome":"ok","count":"exact"}',
], 'the list metric is wrong: ' . json_encode($lines));
$rebuilder->rebuild($schemaId);

// --- Records whose ids are their storage's own (a starter site's `alpha`, not `record-<hex>`), in a
// schema written without type versions: the cursor still continues, a filter still normalizes. ---
$plainSchema = 'site.list_plain';
$plainDefinition = json_encode(['fields' => [
    ['key' => 'slug', 'type' => 'string', 'visibility' => 'public'],
    ['key' => 'order_index', 'type' => 'integer', 'visibility' => 'public'],
    ['key' => 'internal_note', 'type' => 'string', 'visibility' => 'admin'],
]], JSON_THROW_ON_ERROR);
$connection->table('larena_storage_schema_versions')->insert(['schema_id' => $plainSchema, 'version' => 1, 'definition' => $plainDefinition,
    'definition_hash' => hash('sha256', $plainDefinition), 'owner_package' => 'larena/storage', 'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s')]);
foreach (['alpha' => 3, 'beta' => 1, 'gamma' => 2, 'delta' => 5] as $slug => $order) {
    $encoded = json_encode(['slug' => $slug, 'order_index' => $order, 'internal_note' => 'secret ' . $slug], JSON_THROW_ON_ERROR);
    $connection->table('larena_storage_record_versions')->insert(['schema_id' => $plainSchema, 'record_id' => $slug, 'revision' => 1, 'owner_ref' => 'site:main/' . $slug,
        'schema_version' => 1, 'values_json' => $encoded, 'content_hash' => hash('sha256', $encoded), 'operation' => 'create', 'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $site->prepare($plainSchema);
    $publication->publish($plainSchema, $slug, 'site:main', 'en', 1, $actor);
}
$plainSiteTable = StorageViewNaming::siteTable($plainSchema);
foreach (['slot' => $slotRead, 'records' => $recordsRead] as $path => $reader) {
    $slugsOf = static fn (Larena\Storage\Contracts\PublishedListPage $page): array => array_map(static fn (array $entry): string => (string) $entry['values']['slug'], $page->records);
    $input = ['target' => $plainSchema, 'scope_ref' => 'site:main', 'locale' => 'en', 'sort' => 'order_index', 'limit' => '2', 'count' => 'exact'];
    [$pageOne, $fromSlot] = publishedListTrace($connection, $plainSiteTable, static fn () => $reader->publishedList(PublishedListQuery::fromInput($input)));
    storageViewExpect($pageOne instanceof Larena\Storage\Contracts\PublishedListPage && $slugsOf($pageOne) === ['beta', 'gamma'] && $pageOne->matchedCount === 4 && is_string($pageOne->continuation), $path . ': the first page of own ids is wrong: ' . json_encode($pageOne));
    storageViewExpect($fromSlot === ($path === 'slot'), $path . ': the list of own ids took the wrong path');
    $pageTwo = $reader->publishedList(PublishedListQuery::fromInput($input + ['cursor' => $pageOne->continuation]));
    storageViewExpect($slugsOf($pageTwo) === ['alpha', 'delta'] && $pageTwo->continuation === null, $path . ': the cursor over own ids did not continue');
    $filtered = $reader->publishedList(PublishedListQuery::fromInput(['target' => $plainSchema, 'scope_ref' => 'site:main', 'locale' => 'en', 'filters' => '{"order_index":{"operator":"gte","value":3}}', 'sort' => 'order_index:desc']));
    storageViewExpect($slugsOf($filtered) === ['delta', 'alpha'], $path . ': a filter in a schema without type versions is wrong');
    $refused(fn () => $reader->publishedList(PublishedListQuery::fromInput(['sort' => 'slug', 'cursor' => $pageOne->continuation] + $input)), $path . ': a cursor of another sort over own ids');
}

echo "PublishedListTest passed.\n";
