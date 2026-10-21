<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\PublicationIndexChain;
use Larena\Storage\Runtime\PublishedKeyTable;
use Larena\Storage\Runtime\RequestedLocaleOnly;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\Site\PublishedSiteViewRegistry;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// The site витрина kept by publication on SQLite (shadow writes; reads still take the
// exact path): every transition (publish, a newer revision, unpublish, schedule from
// published, sweep, archive), translations (exact, fallback, of a revision that is not
// published), purge and history prune keep each slot equal to the read contracts'
// entries; every path around the indexes silences what it could change; only public
// fields of the published revision ever reach a site table.

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
}, 3);
$workbench = $runtime['workbench'];
$publication = $runtime['publication'];
$localized = $runtime['localized'];
$read = $runtime['read'];
$site = $runtime['site'];
$registry = $site->registry();
$rebuilder = new PublishedSiteViewRebuilder($site);

$fields = [
    publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], true),
    publishedSiteViewField('slug', 20, 'string', 'public', ['max_length' => 120]) + ['indexed' => true],
    publishedSiteViewField('body', 30, 'text', 'public', [], true),
    publishedSiteViewField('price', 40, 'number', 'public'),
    publishedSiteViewField('tags', 50, 'choices', 'public', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
    publishedSiteViewField('secret', 60, 'string', 'admin', ['max_length' => 200], true),
];
$structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => $fields], $actor);
$schemaId = $structure->schema->schemaId;
$table = StorageViewNaming::siteTable($schemaId);
$record = static fn (int $index, string $title, array $extra = []) => $workbench->createRecord($scope, 'workbench.pages', [
    'title' => $title, 'slug' => 'page-' . $index, 'body' => 'Body of ' . $title, 'price' => '1' . $index . '.50',
    'tags' => $index % 2 === 0 ? ['a'] : ['a', 'b'], 'secret' => 'SENTINEL-SECRET-' . $index,
] + $extra, $actor);
$r = [];
for ($index = 1; $index <= 4; $index++) {
    $r[$index] = $record($index, 'Page ' . $index);
}
storageViewExpect($registry->find($schemaId, true) === null && !$ddl->tableExists($table), 'a storage nobody published has a site витрина');
$entry = static fn (string $recordId, string $scopeRef = 'site:main', string $locale = 'en'): ?array => publishedSiteViewEntries($site, $schemaId, $scopeRef, $locale)[$recordId] ?? null;

// --- Publish: the first publication makes the table (before its transaction) and the slot (a row). ---
$publication->publish($schemaId, $r[1]->recordId, 'site:main', 'en', $r[1]->revision, $actor);
$registration = $registry->find($schemaId, true);
storageViewExpect($registration !== null && $registration->state === 'ready' && $ddl->tableExists($table), 'the first publication made no ready site витрина');
$siteKeys = array_map(static fn ($column): string => $column->fieldKey, $registration->layout->columns);
sort($siteKeys);
storageViewExpect($siteKeys === ['body', 'price', 'slug', 'tags', 'title'], 'the site columns are not the public fields: ' . json_encode($siteKeys));
storageViewExpect($registration->layout->column('c2')?->fieldKey === 'slug' && $registration->layout->column('c2')->indexed
    && in_array(Larena\Storage\View\StorageViewDdl::fieldIndexName($table, 'c2'), $ddl->indexes($table), true), 'the indexed public string has no (slot_id, c2, record_id) index');
storageViewExpect($registration->layout->searchFields === ['body', 'slug', 'title'], 'the site search fields are not the public searchable fields: ' . json_encode($registration->layout->searchFields));
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
storageViewExpect($slot !== null && $slot->state === 'ready' && $slot->localeChain === 'en', 'the first publication did not fill its slot');
storageViewExpect($entry($r[1]->recordId) === $read->publishedRecord($schemaId, 'site:main', 'en', $r[1]->recordId), 'the row is not the contract entry');
publishedSiteViewParity($site, $schemaId, 'first publish');
$publication->publish($schemaId, $r[2]->recordId, 'site:main', 'en', $r[2]->revision, $actor);
$publication->publish($schemaId, $r[3]->recordId, 'site:main', 'en', $r[3]->revision, $actor);
$publication->publish($schemaId, $r[4]->recordId, 'site:main', 'en', $r[4]->revision, $actor);
publishedSiteViewParity($site, $schemaId, 'four published');
storageViewExpect(count(publishedSiteViewEntries($site, $schemaId, 'site:main', 'en')) === 4, 'the slot does not hold the four published records');
$typed = $connection->table($table)->where('record_id', $r[2]->recordId)->first();
storageViewExpect($typed !== null && (string) $typed->title === 'Page 2' && $connection->table($table . '_m')->where('record_id', $r[2]->recordId)->count() === 1
    && str_contains((string) $connection->table($table . '_s')->where('record_id', $r[2]->recordId)->value('search_text'), 'page 2'), 'the typed, side or search rows are wrong');

// --- A newer revision: a draft stays out, its publication replaces the row. ---
$draft = $workbench->updateRecord($scope, 'workbench.pages', $r[4]->recordId, $r[4]->revision, ['title' => 'DRAFT-SENTINEL', 'slug' => 'page-4', 'secret' => 'SENTINEL-SECRET-4b'], $actor);
storageViewExpect($entry($r[4]->recordId)['revision'] === 1 && !str_contains(publishedSiteViewDump($connection, $schemaId), 'DRAFT-SENTINEL'), 'a draft revision reached the site витрина');
$r[1] = $workbench->updateRecord($scope, 'workbench.pages', $r[1]->recordId, $r[1]->revision, ['title' => 'Page 1 b', 'slug' => 'page-1', 'body' => 'Second body', 'price' => '2.00', 'tags' => ['b'], 'secret' => 'SENTINEL-SECRET-1b'], $actor);
$publication->publish($schemaId, $r[1]->recordId, 'site:main', 'en', $r[1]->revision, $actor);
storageViewExpect($entry($r[1]->recordId)['revision'] === 2 && $entry($r[1]->recordId)['values']['title'] === 'Page 1 b', 'the newer revision did not replace the row');
publishedSiteViewParity($site, $schemaId, 'republish');

// --- Unpublish, schedule from published (the page goes until the sweep), sweep, archive. ---
$publication->unpublish($schemaId, $r[3]->recordId, 'site:main', 'en', $actor);
storageViewExpect($entry($r[3]->recordId) === null, 'an unpublished record kept its row');
publishedSiteViewParity($site, $schemaId, 'unpublish');
$r[2] = $workbench->updateRecord($scope, 'workbench.pages', $r[2]->recordId, $r[2]->revision, ['title' => 'Page 2 scheduled', 'slug' => 'page-2', 'secret' => 'SENTINEL-SECRET-2b'], $actor);
$publication->schedule($schemaId, $r[2]->recordId, 'site:main', 'en', $r[2]->revision, '2020-01-01 00:00:00', $actor);
storageViewExpect($entry($r[2]->recordId) === null, 'a scheduled record kept its row');
publishedSiteViewParity($site, $schemaId, 'schedule from published');
storageViewExpect($publication->sweep('system:scheduler', '2026-10-21 12:00:00')['published_count'] === 1, 'the sweep did not publish');
storageViewExpect($entry($r[2]->recordId)['values']['title'] === 'Page 2 scheduled', 'the swept revision has no row');
publishedSiteViewParity($site, $schemaId, 'sweep');
$publication->archive($schemaId, $r[3]->recordId, 'site:main', 'en', $actor);
$publication->publish($schemaId, $r[3]->recordId, 'site:main', 'en', $r[3]->revision, $actor);
$publication->archive($schemaId, $r[3]->recordId, 'site:main', 'en', $actor);
storageViewExpect($entry($r[3]->recordId) === null, 'an archived record kept its row');
publishedSiteViewParity($site, $schemaId, 'archive');

// --- Translations: exact, fallback along kk → ru, and of a revision that is not published. ---
foreach (['kk', 'ru'] as $locale) {
    $publication->publish($schemaId, $r[1]->recordId, 'site:main', $locale, $r[1]->revision, $actor);
    $publication->publish($schemaId, $r[2]->recordId, 'site:main', $locale, $r[2]->revision, $actor);
}
publishedSiteViewParity($site, $schemaId, 'three locales');
$localized->write($schemaId, $r[1]->recordId, $r[1]->revision, 'kk', ['title' => 'Бет 1'], ['title', 'body', 'secret'], $actor);
storageViewExpect($entry($r[1]->recordId, 'site:main', 'kk')['values']['title'] === 'Бет 1' && $entry($r[1]->recordId, 'site:main', 'kk')['value_sources']['title'] === 'exact', 'the exact translation did not reach the kk row');
$localized->write($schemaId, $r[1]->recordId, $r[1]->revision, 'ru', ['body' => 'Текст'], ['title', 'body', 'secret'], $actor);
storageViewExpect($entry($r[1]->recordId, 'site:main', 'kk')['value_sources']['body'] === 'fallback:ru' && $entry($r[1]->recordId, 'site:main', 'ru')['values']['body'] === 'Текст', 'the ru translation did not reach the kk fallback and the ru row');
storageViewExpect($entry($r[1]->recordId, 'site:main', 'en')['values']['body'] === 'Second body', 'a ru translation moved the en row');
publishedSiteViewParity($site, $schemaId, 'translations');
$before = $entry($r[1]->recordId, 'site:main', 'kk')['projection_version'];
// A translation of the unpublished first revision changes nothing shown, but the projection version.
$localized->write($schemaId, $r[1]->recordId, 1, 'kk', ['title' => 'Ескі'], ['title', 'body', 'secret'], $actor);
storageViewExpect($entry($r[1]->recordId, 'site:main', 'kk')['projection_version'] !== $before && $entry($r[1]->recordId, 'site:main', 'kk')['values']['title'] === 'Бет 1', 'a translation of another revision did not move the projection version only');
publishedSiteViewParity($site, $schemaId, 'translation of a revision that is not published');
// A translation of the protected field never reaches a site table.
$localized->write($schemaId, $r[2]->recordId, $r[2]->revision, 'kk', ['secret' => 'SENTINEL-TRANSLATED'], ['title', 'body', 'secret'], $actor);
publishedSiteViewParity($site, $schemaId, 'protected translation');

// --- History prune: localized values of pruned revisions go, the entries' projection versions with them. ---
// A record whose first revision, never published, has the newest kk translation of it.
$pruned = $record(9, 'Page 9');
$localized->write($schemaId, $pruned->recordId, 1, 'kk', ['title' => 'Тоғыз'], ['title', 'body', 'secret'], $actor);
$pruned = $workbench->updateRecord($scope, 'workbench.pages', $pruned->recordId, $pruned->revision, ['title' => 'Page 9 b', 'slug' => 'page-9'], $actor);
$pruned = $workbench->updateRecord($scope, 'workbench.pages', $pruned->recordId, $pruned->revision, ['title' => 'Page 9 c', 'slug' => 'page-9'], $actor);
$publication->publish($schemaId, $pruned->recordId, 'site:main', 'kk', $pruned->revision, $actor);
$before = $entry($pruned->recordId, 'site:main', 'kk')['projection_version'];
$workbench->setHistoryKeep($scope, 'workbench.pages', 1, $actor);
storageViewExpect($connection->table('larena_storage_localized_values')->where('record_id', $pruned->recordId)->where('revision', 1)->count() === 0, 'the prune kept the old revision\'s translation');
storageViewExpect($entry($pruned->recordId, 'site:main', 'kk')['projection_version'] !== $before, 'the prune did not move the projection version of the row');
publishedSiteViewParity($site, $schemaId, 'history prune');
$publication->unpublish($schemaId, $pruned->recordId, 'site:main', 'kk', $actor);

// --- Purge: the record leaves every slot. ---
$publication->publish($schemaId, $r[3]->recordId, 'site:other', 'en', $r[3]->revision, $actor);
$publication->publish($schemaId, $r[3]->recordId, 'site:main', 'ru', $r[3]->revision, $actor);
storageViewExpect($connection->table($table)->where('record_id', $r[3]->recordId)->count() === 2, 'the record is not in two slots');
$archived = $workbench->archiveRecord($scope, 'workbench.pages', $r[3]->recordId, $workbench->readRecord($scope, 'workbench.pages', $r[3]->recordId, $actor)->revision, $actor);
$workbench->purgeRecords($scope, 'workbench.pages', [$r[3]->recordId => $archived->revision], $actor);
storageViewExpect($connection->table($table)->where('record_id', $r[3]->recordId)->count() === 0
    && $connection->table($table . '_m')->where('record_id', $r[3]->recordId)->count() === 0
    && $connection->table($table . '_s')->where('record_id', $r[3]->recordId)->count() === 0, 'a purged record kept site rows');
publishedSiteViewParity($site, $schemaId, 'purge');

// --- Security: nothing that is not public, and no draft, is in any site table. ---
$dump = publishedSiteViewDump($connection, $schemaId);
foreach (['SENTINEL-SECRET', 'SENTINEL-TRANSLATED', 'DRAFT-SENTINEL', 'larena_scope_ref', 'larena_record_state', $scope] as $forbidden) {
    storageViewExpect(!str_contains($dump, $forbidden), 'the site витрина holds ' . $forbidden);
}
storageViewExpect(str_contains($dump, 'Бет 1') && str_contains($dump, 'page-1'), 'the dump does not hold the public values (the scan is blind)');

// --- The list compiler reads one slot: its rows only, the side and search rows of that slot. ---
publishedSiteViewListChecks($connection, $site, $schemaId, 'site:main', 'kk');
publishedSiteViewListChecks($connection, $site, $schemaId, 'site:main', 'en');

// --- Silencing: every path around an index stops what it could change. ---
$rebuildAll = static function (string $message) use ($rebuilder, $site, $schemaId, $runtime): void {
    $rebuilder->rebuild($schemaId, 2);
    foreach ($runtime['keys']->targets($schemaId) as $target) {
        $runtime['keys']->build($target['schema_id'], $target['scope_ref'], $target['locale'], 500, true);
    }
    publishedSiteViewParity($site, $schemaId, $message);
};
$slotState = static fn (string $scopeRef, string $locale): ?string => $registry->slot($schemaId, $scopeRef, $locale, null, true)?->state;
$keySet = static fn (string $scopeRef, string $locale): ?array => (new PublishedKeyTable($connection))->set($schemaId, $scopeRef, $locale, 'slug');
storageViewExpect($keySet('site:main', 'en')['state'] === 'ready', 'the key set of site:main/en is not ready');
// A lifecycle without any index: its scope stops answering (site and keys), other scopes do not.
$runtime['plain']->unpublish($schemaId, $r[4]->recordId, 'site:main', 'en', $actor);
storageViewExpect($slotState('site:main', 'en') === 'pending' && $slotState('site:main', 'kk') === 'pending' && $slotState('site:other', 'en') === 'ready', 'a lifecycle without the site index did not silence its scope only');
storageViewExpect($keySet('site:main', 'en')['state'] === 'conflicted', 'a lifecycle without the key index did not silence the keys');
$rebuildAll('rebuilt after a plain lifecycle');
storageViewExpect($entry($r[4]->recordId) === null, 'the rebuild kept a row the plain lifecycle removed');
// A bare published-key index keeps the keys and silences the site.
$runtime['keysOnly']->publish($schemaId, $r[4]->recordId, 'site:main', 'en', 1, $actor);
storageViewExpect($slotState('site:main', 'en') === 'pending' && $keySet('site:main', 'en')['state'] !== 'conflicted', 'a keys-only lifecycle did not silence the site only');
$rebuildAll('rebuilt after a keys-only lifecycle');
// Localized values without an observer: the whole storage (site slots and key sets).
$runtime['bare']->write($schemaId, $r[2]->recordId, $r[2]->revision, 'ru', ['title' => 'Страница'], ['title', 'body', 'secret'], $actor);
foreach ($registry->slots($schemaId) as $silenced) {
    storageViewExpect($silenced->state === 'pending', 'a translation without an observer left ' . $silenced->scopeRef . '/' . $silenced->locale . ' answering');
}
storageViewExpect($keySet('site:other', 'en')['state'] === 'conflicted', 'a translation without an observer left a key set answering');
$rebuildAll('rebuilt after a bare translation');
storageViewExpect($entry($r[2]->recordId, 'site:main', 'ru')['values']['title'] === 'Страница', 'the rebuild missed the bare translation');
// A changed locale chain: a write along the new chain silences the slot built along the old one.
$requestedOnly = new DatabaseReadContracts($connection, $localized, new RequestedLocaleOnly());
$otherChain = new PublishedSiteViewIndex($connection, $requestedOnly, new PublishedSiteViewRegistry($connection, 0.0), $ddl);
$chainLifecycle = new DatabasePublicationLifecycle($connection, null, null, null, null, new PublicationIndexChain([$runtime['keys'], $otherChain]));
$chainLifecycle->unpublish($schemaId, $r[2]->recordId, 'site:main', 'kk', $actor);
storageViewExpect($slotState('site:main', 'kk') === 'pending' && $slotState('site:main', 'ru') === 'ready', 'a write along another locale chain did not silence the kk slot only');
$chainLifecycle->publish($schemaId, $r[2]->recordId, 'site:main', 'kk', $r[2]->revision, $actor);
$rebuildAll('rebuilt after a chain change');
// Public fields changed under the registration (a schema change no hook saw): the next write silences it.
$connection->table('larena_storage_site_views')->where('schema_id', $schemaId)->update(['public_fields_hash' => str_repeat('0', 64)]);
$publication->unpublish($schemaId, $r[2]->recordId, 'site:main', 'ru', $actor);
storageViewExpect($registry->find($schemaId, true)?->state === 'pending' && $slotState('site:main', 'en') === 'pending', 'a write under changed public fields did not silence the storage');
$rebuildAll('rebuilt after a public-field mismatch');

// --- Structure changes: public fields or their options silence it in the change's transaction and queue a rebuild. ---
$current = $workbench->readStructure($scope, 'workbench.pages', $actor);
$queued->take();
$current = $workbench->migrateStructure($scope, 'workbench.pages', $current->version, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => array_merge($fields, [
    publishedSiteViewField('internal', 70, 'string', 'admin', ['max_length' => 50]),
])], $actor);
storageViewExpect($registry->find($schemaId, true)?->state === 'ready' && $queued->take() === [], 'an admin field silenced the site витрина');
publishedSiteViewParity($site, $schemaId, 'admin field added');
$withSummary = array_merge($fields, [
    publishedSiteViewField('internal', 70, 'string', 'admin', ['max_length' => 50]),
    publishedSiteViewField('summary', 80, 'string', 'public', ['max_length' => 100]),
]);
$current = $workbench->migrateStructure($scope, 'workbench.pages', $current->version, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => $withSummary], $actor);
storageViewExpect($registry->find($schemaId, true)?->state === 'pending' && $slotState('site:other', 'en') === 'pending', 'a public field added left the site витрина answering');
$asked = $queued->take();
storageViewExpect($asked === [$schemaId], 'the rebuild was not queued after the change: ' . json_encode($asked));
$rebuildAll('rebuilt after a public field');
storageViewExpect(in_array('summary', array_map(static fn ($column) => $column->fieldKey, $registry->find($schemaId, true)->layout->columns), true), 'the rebuilt layout lacks the new public field');
$queued->take();
$withSummary[2]['searchable'] = false;
$current = $workbench->updateStructure($scope, 'workbench.pages', $current->version, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => $withSummary], $actor);
storageViewExpect($registry->find($schemaId, true)?->state === 'pending' && $queued->take() === [$schemaId], 'a public search option change left the site витрина answering');
$rebuildAll('rebuilt after a search option');
storageViewExpect($registry->find($schemaId, true)->layout->searchFields === ['slug', 'summary', 'title'], 'the rebuilt search fields are wrong');

// --- A new slot beyond the inline limit stays pending until the rebuild. ---
for ($index = 5; $index <= 8; $index++) {
    $r[$index] = $record($index, 'Page ' . $index);
    $runtime['plain']->publish($schemaId, $r[$index]->recordId, 'site:wide', 'en', $r[$index]->revision, $actor);
}
storageViewExpect($registry->slot($schemaId, 'site:wide', 'en', null, true) === null, 'a lifecycle without the index made a slot');
$publication->publish($schemaId, $r[1]->recordId, 'site:wide', 'en', $r[1]->revision, $actor);
storageViewExpect($slotState('site:wide', 'en') === 'pending', 'a slot over the inline limit was filled inline');
$rebuildAll('rebuilt a wide slot');
storageViewExpect(count(publishedSiteViewEntries($site, $schemaId, 'site:wide', 'en')) === 5, 'the wide slot does not hold its five records');

// --- A storage without a public field has no site витрина. ---
$hidden = $workbench->createStructure($scope, ['structure_id' => 'workbench.hidden', 'label' => 'Hidden', 'fields' => [
    publishedSiteViewField('title', 10, 'string', 'admin', ['max_length' => 50]),
]], $actor);
$hiddenRecord = $workbench->createRecord($scope, 'workbench.hidden', ['title' => 'SENTINEL-HIDDEN'], $actor);
$publication->publish($hidden->schema->schemaId, $hiddenRecord->recordId, 'site:main', 'en', $hiddenRecord->revision, $actor);
storageViewExpect($registry->find($hidden->schema->schemaId, true) === null && !$ddl->tableExists(StorageViewNaming::siteTable($hidden->schema->schemaId)), 'a storage without a public field got a site витрина');

// --- A purged structure loses its site витрина. ---
$current = $workbench->readStructure($scope, 'workbench.pages', $actor);
$current = $workbench->archiveStructure($scope, 'workbench.pages', $current->version, $actor);
$workbench->purgeStructure($scope, 'workbench.pages', $current->version, $actor);
storageViewExpect($registry->find($schemaId, true) === null && $registry->slots($schemaId) === [] && !$ddl->tableExists($table) && !$ddl->tableExists($table . '_m'), 'a purged structure kept its site витрина');
storageViewExpect($ddl->viewTables(\Larena\Storage\View\StorageViewTableShape::Site) === [], 'site tables are left: ' . json_encode($ddl->viewTables(\Larena\Storage\View\StorageViewTableShape::Site)));

// --- Without the site registry migrated nothing is written and nothing fails. ---
$bare = new Capsule();
$bare->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
storageViewExpect(!(new PublishedSiteViewRegistry($bare->getConnection()))->available(), 'missing site registry tables were reported available');

echo "PublishedSiteViewMaintenanceTest passed.\n";
