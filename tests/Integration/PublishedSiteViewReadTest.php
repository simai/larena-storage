<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\PublishedKeyTable;
use Larena\Storage\Runtime\RequestedLocaleOnly;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\Site\PublishedSiteViewProjector;
use Larena\Storage\View\Site\PublishedSiteViewReader;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// The public reads answered from the site витрина on SQLite: after a random run of
// publications, translations and fallback chains, every page, record, key and count
// read through a ready slot is byte for byte what the exact path returns (and a slot
// that is silenced answers through the exact path, unchanged); a ready page costs at
// most three queries; a row that disagrees with the publication states silences its
// slot and the request is still answered exactly; a key beyond the scan limit is found
// through the slot; another locale chain and changed public fields take the exact path
// until the rebuild.

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
$read = $runtime['read'];
$siteRead = $runtime['siteRead'];
$site = $runtime['site'];
$registry = $site->registry();
$rebuilder = new PublishedSiteViewRebuilder($site);

$fields = [
    publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], true),
    publishedSiteViewField('slug', 20, 'string', 'public', ['max_length' => 120], true) + ['indexed' => true],
    publishedSiteViewField('body', 30, 'text', 'public', [], true),
    publishedSiteViewField('price', 40, 'number', 'public'),
    publishedSiteViewField('tags', 50, 'choices', 'public', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
    publishedSiteViewField('code', 60, 'integer', 'public'),
    publishedSiteViewField('secret', 70, 'string', 'admin', ['max_length' => 200], true),
];
$structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => $fields], $actor);
$schemaId = $structure->schema->schemaId;
$table = StorageViewNaming::siteTable($schemaId);
$records = [];
for ($index = 0; $index < 24; $index++) {
    $records[$index] = $workbench->createRecord($scope, 'workbench.pages', [
        'title' => 'Page ' . $index, 'slug' => 'page-' . ($index % 20), 'body' => 'Body ' . $index, 'price' => $index . '.50',
        'tags' => $index % 2 === 0 ? ['a'] : ['a', 'b'], 'code' => $index, 'secret' => 'SENTINEL-' . $index,
    ], $actor);
}

/**
 * Runs a read and returns its result (or the reason it was refused) and the queries it
 * ran, without the table lookups of the schema builder.
 *
 * @return array{0: mixed, 1: list<string>}
 */
function siteReadTrace(Connection $connection, Closure $call): array
{
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $result = $call();
    } catch (ReadContractRejected $rejected) {
        $result = 'rejected:' . $rejected->reasonCode;
    } finally {
        $log = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
    $queries = [];
    foreach ($log as $entry) {
        $sql = (string) $entry['query'];
        if (!str_contains($sql, 'sqlite_master') && !str_contains($sql, 'information_schema')) {
            $queries[] = $sql;
        }
    }

    return [$result, $queries];
}

/** @param list<string> $queries */
function siteReadAnswered(array $queries, string $table): bool
{
    foreach ($queries as $sql) {
        if (str_contains($sql, '"' . $table . '"') || str_contains($sql, '`' . $table . '`')) {
            return true;
        }
    }

    return false;
}

/**
 * Every public read of the storage in these scopes and locales returns the same
 * through both read contracts. Returns how many reads the site витрина answered.
 *
 * @param list<string> $scopes
 * @param list<string> $locales
 */
function siteReadParity(Connection $connection, DatabaseReadContracts $exact, DatabaseReadContracts $site, string $schemaId, string $table, array $scopes, array $locales, array $recordIds, string $message): int
{
    $odd = static fn (string $recordId): bool => crc32($recordId) % 2 === 0;
    $answered = 0;
    $same = static function (Closure $call) use ($connection, $exact, $site, $table, &$answered, $message): mixed {
        [$expected] = siteReadTrace($connection, static fn () => $call($exact));
        [$actual, $queries] = siteReadTrace($connection, static fn () => $call($site));
        storageViewExpect(serialize($actual) === serialize($expected), $message . ': the site read differs: ' . json_encode($actual) . ' instead of ' . json_encode($expected));
        if (siteReadAnswered($queries, $table)) {
            $answered++;
        }

        return $expected;
    };
    foreach ($scopes as $scopeRef) {
        foreach ($locales as $locale) {
            // Every page of every budget, walked by the cursor the unfiltered pages give.
            foreach ([1, 4, 500] as $budget) {
                $after = null;
                $cursors = [null];
                do {
                    $page = $same(static fn (DatabaseReadContracts $read) => $read->publishedProjection($schemaId, $scopeRef, $locale, $budget, null, $after));
                    $after = $page->records === [] ? null : $page->records[array_key_last($page->records)]['record_id'];
                    $cursors[] = $after;
                } while ($page->truncated && $after !== null);
                foreach (array_slice($cursors, 0, 6) as $cursor) {
                    $same(static fn (DatabaseReadContracts $read) => $read->publishedProjection($schemaId, $scopeRef, $locale, $budget, $odd, $cursor));
                }
            }
            $slugs = [];
            foreach ($exact->publishedProjection($schemaId, $scopeRef, $locale)->records as $entry) {
                if (is_string($entry['values']['slug'] ?? null)) {
                    $slugs[$entry['values']['slug']] = true;
                }
            }
            foreach (array_merge($recordIds, ['record-00000000000000000000000000000000']) as $recordId) {
                $same(static fn (DatabaseReadContracts $read) => $read->publishedRecord($schemaId, $scopeRef, $locale, $recordId));
                $same(static fn (DatabaseReadContracts $read) => $read->publishedRecord($schemaId, $scopeRef, $locale, $recordId, $odd));
            }
            foreach (array_merge(array_keys($slugs), ['page-missing', 'PAGE-1', '']) as $slug) {
                $same(static fn (DatabaseReadContracts $read) => $read->resolveKey($schemaId, $scopeRef, $locale, 'slug', (string) $slug));
                $same(static fn (DatabaseReadContracts $read) => $read->resolveKey($schemaId, $scopeRef, $locale, 'slug', (string) $slug, $odd));
                $same(static fn (DatabaseReadContracts $read) => $read->keyHolderIds($schemaId, $scopeRef, $locale, 'slug', (string) $slug));
            }
            // A key through a field without a string column (an integer) and through an admin field.
            $same(static fn (DatabaseReadContracts $read) => $read->resolveKey($schemaId, $scopeRef, $locale, 'code', '3'));
            $same(static fn (DatabaseReadContracts $read) => $read->resolveKey($schemaId, $scopeRef, $locale, 'secret', 'SENTINEL-3'));
            $same(static fn (DatabaseReadContracts $read) => $read->projectionExplain($schemaId, $scopeRef, $locale));
            $same(static fn (DatabaseReadContracts $read) => $read->projectionExplain($schemaId, $scopeRef, $locale, $odd));
        }
    }

    return $answered;
}

$scopes = ['site:main', 'site:other'];
$locales = ['en', 'kk', 'ru'];
$recordIds = array_map(static fn ($record): string => $record->recordId, $records);
$parity = static fn (string $message, DatabaseReadContracts $siteReader = null): int => siteReadParity($connection, $read, $siteReader ?? $siteRead, $schemaId, $table, $scopes, $locales, $recordIds, $message);

// --- A random run of publications, translations along the kk -> ru chain and new revisions. ---
mt_srand(20261022);
$current = $records;
$answered = 0;
for ($step = 0; $step < 240; $step++) {
    $index = mt_rand(0, count($current) - 1);
    $record = $current[$index];
    $scopeRef = $scopes[mt_rand(0, 1)];
    $locale = $locales[mt_rand(0, 2)];
    $revision = mt_rand(1, $record->revision);
    try {
        match (mt_rand(0, 9)) {
            0, 1, 2 => $publication->publish($schemaId, $record->recordId, $scopeRef, $locale, $revision, $actor),
            3 => $publication->unpublish($schemaId, $record->recordId, $scopeRef, $locale, $actor),
            4 => $publication->schedule($schemaId, $record->recordId, $scopeRef, $locale, $revision, '2020-01-01 00:00:00', $actor),
            5 => $publication->archive($schemaId, $record->recordId, $scopeRef, $locale, $actor),
            6 => $localized->write($schemaId, $record->recordId, $revision, $locale, ['title' => 'T' . $step . ' ' . $locale, 'body' => 'B' . $step], ['title', 'body', 'slug', 'secret'], $actor),
            7 => $localized->write($schemaId, $record->recordId, $revision, 'ru', ['slug' => 'ru-' . ($step % 15), 'secret' => 'SENTINEL-T' . $step], ['title', 'body', 'slug', 'secret'], $actor),
            8 => $current[$index] = $workbench->updateRecord($scope, 'workbench.pages', $record->recordId, $record->revision, [
                'title' => 'Page ' . $index . ' r' . $step, 'slug' => 'page-' . ($step % 20), 'body' => 'Body r' . $step, 'price' => $step . '.25',
                'tags' => $step % 2 === 0 ? ['b'] : ['a'], 'code' => $step, 'secret' => 'SENTINEL-' . $step,
            ], $actor),
            default => $publication->sweep('system:scheduler', '2026-10-21 12:00:00'),
        };
    } catch (Larena\Storage\Exceptions\PublicationRejected|Larena\Storage\Exceptions\LocalizedValueRejected) {
        // An illegal move, a key already taken, or a translation already written: nothing happened.
    }
    if ($step % 60 === 59) {
        publishedSiteViewParity($site, $schemaId, 'random run step ' . $step);
        $answered += $parity('random run step ' . $step);
    }
}
storageViewExpect($answered > 500, 'the site витрина answered too few reads: ' . $answered);
storageViewExpect($queued->take() === [], 'a read that agreed asked for a rebuild');
echo 'Random run: ' . $answered . " reads answered from the site витрина, each equal to the exact path.\n";

// --- The same reads with every slot silenced: the exact path, unchanged. ---
$registry->silenceSchema($schemaId);
storageViewExpect($parity('silenced') === 0, 'a silenced slot answered');
$rebuilder->rebuild($schemaId, 7);
publishedSiteViewParity($site, $schemaId, 'rebuilt');
storageViewExpect($parity('rebuilt') > 0, 'the rebuilt slots do not answer');

// --- A ready page costs at most three queries; so does a record. ---
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
storageViewExpect($slot !== null && $slot->state === 'ready', 'site:main/en is not ready');
$siteRead->publishedProjection($schemaId, 'site:main', 'en', 2);
[$page, $queries] = siteReadTrace($connection, static fn () => $siteRead->publishedProjection($schemaId, 'site:main', 'en', 2));
storageViewExpect(siteReadAnswered($queries, $table) && count($queries) <= 3, 'a ready page took ' . count($queries) . ' queries: ' . json_encode($queries));
$pageQueries = count($queries);
[$deep, $queries] = siteReadTrace($connection, static fn () => $siteRead->publishedProjection($schemaId, 'site:main', 'en', 2, null, $page->records[0]['record_id']));
storageViewExpect(siteReadAnswered($queries, $table) && count($queries) <= 3, 'a deeper page took ' . count($queries) . ' queries');
[$entry, $queries] = siteReadTrace($connection, static fn () => $siteRead->publishedRecord($schemaId, 'site:main', 'en', $page->records[0]['record_id']));
storageViewExpect(is_array($entry) && siteReadAnswered($queries, $table) && count($queries) <= 3, 'a ready record took ' . count($queries) . ' queries');
[, $legacyQueries] = siteReadTrace($connection, static fn () => $read->publishedProjection($schemaId, 'site:main', 'en', 2));
echo 'Queries: a ready page ' . $pageQueries . ', a record ' . count($queries) . ', the exact page ' . count($legacyQueries) . ".\n";

// --- Verify on read: a row that disagrees silences its slot; the request is still answered exactly. ---
$tamper = static function (string $recordId, int $slotId, Closure $change) use ($connection, $table): void {
    $row = $connection->table($table)->where('slot_id', $slotId)->where('record_id', $recordId)->first();
    storageViewExpect($row !== null, 'nothing to tamper with');
    $connection->table($table)->where('slot_id', $slotId)->where('record_id', $recordId)->update($change((array) $row));
};
$victim = $page->records[0]['record_id'];
$expected = $read->publishedProjection($schemaId, 'site:main', 'en', 2);
// A revision written into the row and its entry alike (the entry still vouches for itself).
$tamper($victim, $slot->id, static function (array $row): array {
    $entry = PublishedSiteViewProjector::decodeEntry((string) $row['entry_json']);
    $entry['revision'] = (int) $row['revision'] + 7;
    $entry['values']['title'] = 'Forged';
    $json = PublishedSiteViewProjector::encodeEntry($entry);

    return ['revision' => $entry['revision'], 'entry_json' => $json, 'entry_hash' => hash('sha256', $json)];
});
[$answer] = siteReadTrace($connection, static fn () => $siteRead->publishedProjection($schemaId, 'site:main', 'en', 2));
storageViewExpect(serialize($answer) === serialize($expected), 'a tampered row reached a reader');
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'pending', 'a mismatch did not silence the slot');
storageViewExpect($queued->take() === [$schemaId], 'a mismatch did not queue the rebuild');
[, $queries] = siteReadTrace($connection, static fn () => $siteRead->publishedProjection($schemaId, 'site:main', 'en', 2));
storageViewExpect(!siteReadAnswered($queries, $table), 'a silenced slot still answered');
$rebuilder->rebuild($schemaId);
publishedSiteViewParity($site, $schemaId, 'rebuilt after a mismatch');
// A missing row, a record read alone, and an entry that no longer matches its hash.
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
$connection->table($table)->where('slot_id', $slot->id)->where('record_id', $victim)->delete();
storageViewExpect(serialize($siteRead->publishedProjection($schemaId, 'site:main', 'en', 2)) === serialize($expected), 'a missing row shortened a page');
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'pending', 'a missing row did not silence the slot');
$rebuilder->rebuild($schemaId);
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
$expectedRecord = $read->publishedRecord($schemaId, 'site:main', 'en', $victim);
$tamper($victim, $slot->id, static fn (array $row): array => ['entry_json' => str_replace('"values":{', '"values":{"forged":1,', (string) $row['entry_json'])]);
storageViewExpect(serialize($siteRead->publishedRecord($schemaId, 'site:main', 'en', $victim)) === serialize($expectedRecord), 'a corrupt entry reached a reader');
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'pending', 'a corrupt entry did not silence the slot');
$rebuilder->rebuild($schemaId);
// Inside a transaction a mismatch only takes the exact path: what the transaction wrote may not have reached the slot.
$slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
$connection->table($table)->where('slot_id', $slot->id)->where('record_id', $victim)->delete();
$connection->transaction(static function () use ($siteRead, $schemaId, $expected): void {
    storageViewExpect(serialize($siteRead->publishedProjection($schemaId, 'site:main', 'en', 2)) === serialize($expected), 'a mismatch in a transaction was answered wrong');
});
storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'ready', 'a mismatch inside a transaction silenced the slot');
$rebuilder->rebuild($schemaId, 500, true);
publishedSiteViewParity($site, $schemaId, 'rebuilt after the tampering');
$queued->take();

// --- A key beyond the scan limit: the exact path refuses, the slot finds it (or says it is not there). ---
$keyTable = new PublishedKeyTable($connection);
$connection->transaction(static fn () => $keyTable->silenceScope($schemaId, 'site:main'));
$limitedRead = new DatabaseReadContracts($connection, $localized, new PublishedSiteViewChain(), new StorageMetrics(), null, null, 3);
$limitedSite = new DatabaseReadContracts($connection, $localized, new PublishedSiteViewChain(), new StorageMetrics(), null, $runtime['reader'], 3);
foreach ($current as $record) {
    try {
        $publication->publish($schemaId, $record->recordId, 'site:main', 'en', $record->revision, $actor);
    } catch (Larena\Storage\Exceptions\PublicationRejected) {
        // Its key is taken, or it is published already.
    }
}
$connection->transaction(static fn () => $keyTable->silenceScope($schemaId, 'site:main'));
$published = $read->publishedProjection($schemaId, 'site:main', 'en');
storageViewExpect(count($published->records) > 4, 'too few published records for the scan limit');
$last = $published->records[array_key_last($published->records)];
$key = $last['values']['slug'];
$holders = $read->keyHolderIds($schemaId, 'site:main', 'en', 'slug', $key);
[$refused] = siteReadTrace($connection, static fn () => $limitedRead->resolveKey($schemaId, 'site:main', 'en', 'slug', $key));
storageViewExpect($refused === 'rejected:key_scan_limit_exceeded', 'the exact path did not refuse past its scan limit: ' . json_encode($refused));
[$found, $queries] = siteReadTrace($connection, static fn () => count($holders) === 1
    ? $limitedSite->resolveKey($schemaId, 'site:main', 'en', 'slug', $key)
    : $limitedSite->keyHolderIds($schemaId, 'site:main', 'en', 'slug', $key));
$expectedKey = count($holders) === 1 ? $read->resolveKey($schemaId, 'site:main', 'en', 'slug', $key) : $holders;
storageViewExpect(serialize($found) === serialize($expectedKey) && siteReadAnswered($queries, $table), 'the slot did not find a key past the scan limit: ' . json_encode($found));
[$missing] = siteReadTrace($connection, static fn () => $limitedSite->resolveKey($schemaId, 'site:main', 'en', 'slug', 'page-missing'));
storageViewExpect($missing === null, 'a missing key past the scan limit was not a miss through the slot: ' . json_encode($missing));
echo 'Key past the scan limit: ' . count($queries) . " queries through the slot.\n";

// --- Another locale chain: the exact-locale reader never matches a chain with fallbacks. ---
$exactLocale = new DatabaseReadContracts($connection, $localized, new RequestedLocaleOnly());
$exactLocaleSite = new DatabaseReadContracts($connection, $localized, new RequestedLocaleOnly(), new StorageMetrics(), null, $runtime['reader']);
[$kkExact] = siteReadTrace($connection, static fn () => $exactLocale->publishedProjection($schemaId, 'site:main', 'kk'));
[$kkSite, $queries] = siteReadTrace($connection, static fn () => $exactLocaleSite->publishedProjection($schemaId, 'site:main', 'kk'));
storageViewExpect(serialize($kkSite) === serialize($kkExact) && !siteReadAnswered($queries, $table), 'a reader along another chain read the kk slot');
storageViewExpect($registry->slot($schemaId, 'site:main', 'kk', null, true)?->state === 'ready', 'a reader along another chain silenced the slot');
[$enExact] = siteReadTrace($connection, static fn () => $exactLocale->publishedProjection($schemaId, 'site:main', 'en'));
[$enSite, $queries] = siteReadTrace($connection, static fn () => $exactLocaleSite->publishedProjection($schemaId, 'site:main', 'en'));
storageViewExpect(serialize($enSite) === serialize($enExact) && siteReadAnswered($queries, $table), 'the en slot (the same chain) did not answer');
storageViewExpect($queued->take() === [], 'another chain asked for a rebuild');

// --- Public fields changed: the exact path until the rebuild. ---
$changed = array_map(static fn (array $field): array => $field['key'] === 'body' ? ['visibility' => 'admin'] + $field : $field, $fields);
$structure = $workbench->readStructure($scope, 'workbench.pages', $actor);
$workbench->migrateStructure($scope, 'workbench.pages', $structure->version, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => $changed], $actor);
storageViewExpect($queued->take() === [$schemaId], 'the public field change did not queue the rebuild');
[$answer, $queries] = siteReadTrace($connection, static fn () => $siteRead->publishedProjection($schemaId, 'site:main', 'en'));
storageViewExpect(!siteReadAnswered($queries, $table) && serialize($answer) === serialize($read->publishedProjection($schemaId, 'site:main', 'en')), 'changed public fields were read from the slot');
storageViewExpect(!str_contains(json_encode($answer) ?: '', '"body"'), 'a field made admin is still read');
$rebuilder->rebuild($schemaId);
publishedSiteViewParity($site, $schemaId, 'rebuilt after the public field change');
storageViewExpect($parity('after the public field change') > 0, 'the rebuilt slots do not answer after the public field change');
// A hash changed under a ready registration (a change no hook saw): the exact path, and the rebuild is asked for once.
// (A reader of its own: this one asked for this storage's rebuild less than a minute ago, after the mismatch.)
$freshRead = new DatabaseReadContracts($connection, $localized, new PublishedSiteViewChain(), new StorageMetrics(), null, new PublishedSiteViewReader($connection, static function (string $schemaId) use ($queued): void {
    $queued->add($schemaId);
}));
$connection->table('larena_storage_site_views')->where('schema_id', $schemaId)->update(['public_fields_hash' => str_repeat('a', 64)]);
[, $queries] = siteReadTrace($connection, static fn () => $freshRead->publishedProjection($schemaId, 'site:main', 'en'));
storageViewExpect(!siteReadAnswered($queries, $table), 'a slot answered under changed public fields');
$freshRead->publishedProjection($schemaId, 'site:main', 'ru');
storageViewExpect($queued->take() === [$schemaId], 'changed public fields did not ask for the rebuild exactly once');
$rebuilder->rebuild($schemaId);
publishedSiteViewParity($site, $schemaId, 'rebuilt after the hash change');

// --- A reader on tables that are not migrated keeps to the exact path. ---
$bare = new Capsule();
$bare->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bareConnection = $bare->getConnection();
storageViewInstall($bareConnection);
(require __DIR__ . '/../../database/migrations/platform/2026_09_29_000001_create_larena_storage_publication_tables.php')->up();
$bareReader = new PublishedSiteViewReader($bareConnection);
storageViewExpect($bareReader->source($schemaId, 'site:main', 'en', 'en') === null, 'a reader without the site tables found a slot');
$bareRead = new DatabaseReadContracts($bareConnection, null, new RequestedLocaleOnly(), new StorageMetrics(), null, $bareReader);
storageViewExpect($bareRead->publishedProjection($schemaId, 'site:main', 'en')->records === [], 'a read without the site tables failed');

echo "PublishedSiteViewReadTest passed.\n";
