<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Console\Commands\PublishedKeyRebuildCommand;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;
use Larena\Storage\Contracts\PublishedKeyPolicy;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\ProjectionVersion;
use Larena\Storage\Runtime\PublishedKeyIndex;
use Larena\Storage\Runtime\PublishedKeyTable;
use Larena\Storage\Runtime\PublishedKeyUniqueness;
use Larena\Storage\Runtime\RequestedLocaleOnly;
use Larena\Storage\Runtime\StorageMetrics;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Site reads cost a constant number of queries per page, not one batch per record,
// and a published key is found through its indexed table, kept by publication in its
// own transaction, rebuilt by a resumable command and silent when the locale chain
// it was built along has changed.

require_once __DIR__ . '/../../vendor/autoload.php';

function publishedReadExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publishedReadRefused(callable $call, string $class, string $reason): void
{
    try {
        $call();
    } catch (PublicationRejected|ReadContractRejected $rejection) {
        publishedReadExpect($rejection instanceof $class && $rejection->reasonCode === $reason, $rejection->reasonCode . ' instead of ' . $reason);

        return;
    }
    throw new RuntimeException('expected ' . $reason);
}

/** @return int the queries the call ran */
function publishedReadQueries(Connection $connection, callable $call): int
{
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    try {
        $call();
    } finally {
        $connection->disableQueryLog();
    }

    return count($connection->getQueryLog());
}

/** @param array<string, mixed> $values */
function publishedReadVersion(Connection $connection, string $recordId, int $revision, array $values, string $schemaId = 'site.pages'): void
{
    $encoded = json_encode($values, JSON_THROW_ON_ERROR);
    $connection->table('larena_storage_record_versions')->insert([
        'schema_id' => $schemaId,
        'record_id' => $recordId,
        'revision' => $revision,
        'owner_ref' => 'site:main/' . $recordId,
        'schema_version' => 1,
        'values_json' => $encoded,
        'content_hash' => hash('sha256', $encoded),
        'operation' => 'create',
        'created_by' => 'actor:test',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());
foreach ([
    '/2026_07_13_000001_create_larena_storage_version_tables.php',
    '/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php',
    '/platform/2026_09_29_000001_create_larena_storage_publication_tables.php',
    '/platform/2026_10_20_000002_create_larena_storage_published_key_tables.php',
] as $migration) {
    (require __DIR__ . '/../../database/migrations' . $migration)->up();
}

$definition = json_encode(['fields' => [
    ['key' => 'slug', 'type' => 'string', 'type_version' => 1, 'visibility' => 'public', 'localized' => true],
    ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'visibility' => 'public', 'localized' => true],
    ['key' => 'note', 'type' => 'string', 'type_version' => 1, 'visibility' => 'admin'],
]], JSON_THROW_ON_ERROR);
$connection->table('larena_storage_schema_versions')->insert([
    'schema_id' => 'site.pages', 'version' => 1, 'definition' => $definition, 'definition_hash' => hash('sha256', $definition),
    'owner_package' => 'larena/storage', 'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s'),
]);

// Kazakh falls back to Russian; every other locale stands alone.
$chain = new class implements LocaleFallbackResolver {
    public function chainFor(string $requestedLocale): LocaleFallbackChain
    {
        return $requestedLocale === 'kk' ? LocaleFallbackChain::of('kk', 'ru') : LocaleFallbackChain::of($requestedLocale);
    }
};
$keys = new class implements PublishedKeyPolicy {
    public function keyFieldFor(string $schemaId, string $scopeRef): ?string
    {
        return $schemaId === 'site.pages' ? 'slug' : null;
    }
};
$timings = new class {
    /** @var list<array{string, array<string, string>}> */
    public array $seen = [];

    /** @param array<string, string> $tags */
    public function timing(string $id, float $ms, array $tags): void
    {
        $this->seen[] = [$id, $tags];
    }
};
$localized = new DatabaseLocalizedValues($connection);
$read = new DatabaseReadContracts($connection, $localized, $chain, new StorageMetrics(static fn (): object => $timings));
$index = new PublishedKeyIndex($connection, $read, $keys);
$localized = new DatabaseLocalizedValues($connection, null, null, null, static fn (): PublishedKeyIndex => $index);
$read = new DatabaseReadContracts($connection, $localized, $chain, new StorageMetrics(static fn (): object => $timings));
$index = new PublishedKeyIndex($connection, $read, $keys);
$plain = new DatabasePublicationLifecycle($connection);
$publication = new DatabasePublicationLifecycle($connection, null, null, null, new PublishedKeyUniqueness($read, $keys), $index);
$set = static fn (string $scope, string $locale): ?array => (new PublishedKeyTable($connection))->set('site.pages', $scope, $locale, 'slug');

// --- A page costs the same number of queries for 5 records as for 300. ---
$page = static function (string $scope, int $count) use ($connection, $plain, $localized): void {
    for ($i = 0; $i < $count; $i++) {
        $id = sprintf('%s-%04d', str_replace(':', '-', $scope), $i);
        publishedReadVersion($connection, $id, 1, ['slug' => 'page-' . $i, 'title' => 'Page ' . $i, 'note' => 'internal']);
        $localized->write('site.pages', $id, 1, 'ru', ['title' => 'Страница ' . $i], ['slug', 'title'], 'actor:test');
        $plain->publish('site.pages', $id, $scope, 'en', 1, 'actor:importer');
        $plain->publish('site.pages', $id, $scope, 'kk', 1, 'actor:importer');
    }
};
$page('site:small', 5);
$page('site:large', 300);
$queries = [];
foreach (['site:small' => 5, 'site:large' => 300] as $scope => $count) {
    $projection = null;
    $queries[$scope] = publishedReadQueries($connection, static function () use ($read, $scope, &$projection): void {
        $projection = $read->publishedProjection('site.pages', $scope, 'kk');
    });
    publishedReadExpect($projection !== null && count($projection->records) === $count, $scope . ': the page lost records');
    $first = $projection->records[0];
    publishedReadExpect($first['values'] === ['slug' => 'page-0', 'title' => 'Страница 0'], $scope . ': values or fallback wrong: ' . json_encode($first['values']));
    publishedReadExpect($first['value_sources'] === ['slug' => 'shared', 'title' => 'fallback:ru'], $scope . ': sources wrong');
    // The batched projection version is the one a publication observer reports.
    foreach ([$first, $projection->records[$count - 1]] as $entry) {
        publishedReadExpect(
            $entry['projection_version'] === ProjectionVersion::of($connection, 'site.pages', $entry['record_id'], $scope, 'kk'),
            $scope . ': the projection version differs from ProjectionVersion::of',
        );
    }
}
publishedReadExpect($queries['site:small'] === $queries['site:large'], 'a page of 300 costs more queries than a page of 5: ' . json_encode($queries));
publishedReadExpect($queries['site:large'] <= 10, 'a page costs too many queries: ' . $queries['site:large']);
$single = publishedReadQueries($connection, static fn () => $read->publishedRecord('site.pages', 'site:large', 'kk', 'site-large-0299'));
publishedReadExpect($single <= 10, 'one record costs too many queries: ' . $single);
publishedReadExpect($read->publishedRecord('site.pages', 'site:large', 'kk', 'site-large-0299')['values']['title'] === 'Страница 299', 'publishedRecord lost its value');
publishedReadExpect($read->publishedRecord('site.pages', 'site:large', 'kk', 'missing') === null, 'an unpublished record was found');
echo 'Page queries: ' . json_encode($queries) . ', one record: ' . $single . "\n";

// The reads are timed as storage.read with codes only.
publishedReadExpect($timings->seen !== [] && array_unique(array_map(static fn (array $seen): string => $seen[0], $timings->seen)) === ['storage.read'], 'the reads were not timed');
publishedReadExpect(array_values(array_unique(array_map(static fn (array $seen): string => json_encode($seen[1]), $timings->seen))) === ['{"outcome":"ok"}'], 'a read timing carried more than codes');

// --- A key in a scope with more than a page of published records: scanned in batches until the table is built. ---
publishedReadExpect($set('site:large', 'en') === null, 'a set appeared without a build');
$scan = publishedReadQueries($connection, static fn () => publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'en', 'slug', 'page-299')?->recordId === 'site-large-0299', 'the scan missed a key'));
$container = new class extends Container {
    public function runningUnitTests(): bool
    {
        return true;
    }
};
$container->instance(PublishedKeyIndex::class, $index);
$command = new PublishedKeyRebuildCommand();
$command->setLaravel($container);
$run = static function (array $arguments) use ($command): array {
    $output = new BufferedOutput();
    $input = new ArrayInput($arguments);
    $input->setInteractive(false);
    $status = $command->run($input, $output);

    return [$status, $output->fetch()];
};
[$status, $printed] = $run(['--chunk' => '120']);
publishedReadExpect($status === 0, 'the rebuild failed: ' . $printed);
publishedReadExpect(str_contains($printed, 'site.pages site:large/en: ready by slug, 300 records'), 'the rebuild did not report the large set: ' . $printed);
publishedReadExpect(str_contains($printed, 'site.pages site:small/kk: ready by slug, 5 records'), 'the rebuild did not report the small set: ' . $printed);
publishedReadExpect($connection->table('larena_storage_published_keys')->count() === 610, 'the table does not hold every published key');
[$status, $printed] = $run([]);
publishedReadExpect($status === 0 && trim($printed) === 'Nothing to build.', 'a second rebuild changed something: ' . $printed);
publishedReadExpect($run(['--chunk' => '0'])[0] === 2, 'an invalid chunk was accepted');

$indexed = publishedReadQueries($connection, static fn () => publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'en', 'slug', 'page-299')?->recordId === 'site-large-0299', 'the index missed a key'));
$small = publishedReadQueries($connection, static fn () => $read->resolveKey('site.pages', 'site:small', 'en', 'slug', 'page-4'));
publishedReadExpect($indexed === $small && $indexed <= 12, 'an indexed lookup is not constant: ' . $indexed . ' vs ' . $small);
publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'en', 'slug', 'page-missing') === null, 'a missing key resolved');
publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'en', 'note', 'internal') === null, 'a key on an admin field resolved');
echo 'Key lookup queries: scan of 300 ' . $scan . ', indexed ' . $indexed . "\n";

// The fallback is the one a read uses: the Kazakh key is the shared one until a
// Kazakh or Russian slug is written, and a translation of the published revision
// moves the key at once.
publishedReadExpect($read->resolveKey('site.pages', 'site:small', 'kk', 'slug', 'page-1')?->recordId === 'site-small-0001', 'kk key wrong');
$localized->write('site.pages', 'site-small-0001', 1, 'ru', ['slug' => 'stranitsa-1'], ['slug', 'title'], 'actor:test');
publishedReadExpect($read->resolveKey('site.pages', 'site:small', 'kk', 'slug', 'page-1') === null, 'the kk key did not follow the ru translation');
$moved = $read->resolveKey('site.pages', 'site:small', 'kk', 'slug', 'stranitsa-1');
publishedReadExpect($moved?->recordId === 'site-small-0001' && $moved->valueSources['slug'] === 'fallback:ru', 'the kk key is not the ru fallback');
publishedReadExpect($set('site:small', 'kk')['state'] === PublishedKeyTable::STATE_READY, 'the translation broke the set');
publishedReadExpect($read->resolveKey('site.pages', 'site:small', 'en', 'slug', 'page-1')?->recordId === 'site-small-0001', 'the ru translation moved the en key');

// A changed locale chain silences the sets built along the old one: reads scan and
// stay right, and the rebuild rebuilds exactly those.
$requestedOnly = new DatabaseReadContracts($connection, $localized, new RequestedLocaleOnly());
publishedReadExpect($requestedOnly->resolveKey('site.pages', 'site:small', 'kk', 'slug', 'page-1')?->recordId === 'site-small-0001', 'the scan ignored the new chain');
publishedReadExpect($requestedOnly->resolveKey('site.pages', 'site:small', 'kk', 'slug', 'stranitsa-1') === null, 'a set built along the old chain answered');
$requestedOnlyIndex = new PublishedKeyIndex($connection, $requestedOnly, $keys);
$result = $requestedOnlyIndex->build('site.pages', 'site:small', 'kk');
publishedReadExpect($result['state'] === 'ready' && !$result['skipped'] && $set('site:small', 'kk')['locale_chain'] === 'kk', 'the rebuild kept the old chain');
publishedReadExpect($requestedOnlyIndex->build('site.pages', 'site:small', 'en')['skipped'], 'a set along an unchanged chain was rebuilt');
$requestedOnlyIndex->build('site.pages', 'site:small', 'kk', 500, true);
$index->build('site.pages', 'site:small', 'kk');

// --- Publication keeps the table. A key another published record holds is refused, and nothing is written. ---
publishedReadVersion($connection, 'about', 1, ['slug' => 'about', 'title' => 'About']);
publishedReadVersion($connection, 'copy', 1, ['slug' => 'about', 'title' => 'Copy']);
publishedReadVersion($connection, 'copy', 2, ['slug' => 'copy', 'title' => 'Copy']);
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');
publishedReadExpect($set('site:main', 'en')['state'] === PublishedKeyTable::STATE_READY, 'the first publish did not start a ready set');
publishedReadRefused(static fn () => $publication->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor'), PublicationRejected::class, 'key_conflict');
publishedReadExpect($publication->head('site.pages', 'copy', 'site:main', 'en') === null, 'a refused publish wrote a head');
publishedReadExpect($connection->table('larena_storage_published_keys')->where('scope_ref', 'site:main')->count() === 1, 'a refused publish wrote a key');

// The unique index refuses what slips past the guard, as a concurrent publish would.
$unguarded = new DatabasePublicationLifecycle($connection, null, null, null, null, $index);
publishedReadRefused(static fn () => $unguarded->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor'), PublicationRejected::class, 'key_conflict');
publishedReadExpect($publication->head('site.pages', 'copy', 'site:main', 'en') === null, 'the index refusal left a head');

// A record takes its new key with a new revision; unpublishing, archiving and
// scheduling free it.
$publication->publish('site.pages', 'copy', 'site:main', 'en', 2, 'actor:editor');
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');
$publication->unpublish('site.pages', 'about', 'site:main', 'en', 'actor:editor');
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about') === null, 'an unpublished key still resolved');
$publication->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor');
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about')?->recordId === 'copy', 'the freed key was not taken');
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'copy') === null, 'the old key of a republished record still resolved');
$publication->archive('site.pages', 'copy', 'site:main', 'en', 'actor:editor');
publishedReadExpect($connection->table('larena_storage_published_keys')->where('scope_ref', 'site:main')->count() === 0, 'an archived record kept its key');
$publication->schedule('site.pages', 'about', 'site:main', 'en', 1, '2020-01-01 00:00:00', 'actor:editor');
publishedReadExpect($publication->sweep('system:scheduler', '2026-10-01 12:00:00')['published_count'] === 1, 'the sweep did not publish');
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about')?->recordId === 'about', 'the swept key is not indexed');

// --- Duplicates published around the index (a lifecycle built without it): the
// rebuild finds them, the set stops answering and the read fails closed. ---
$plain->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:importer');
// At once, before any rebuild: the set the index could not follow stops answering.
publishedReadExpect($set('site:main', 'en')['state'] === PublishedKeyTable::STATE_CONFLICTED, 'a publish around the index left the set answering');
publishedReadRefused(static fn () => $read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about'), ReadContractRejected::class, 'ambiguous_key');
[$status, $printed] = $run(['--schema' => 'site.pages', '--scope' => 'site:main', '--fresh' => true]);
publishedReadExpect($status === 0 && str_contains($printed, 'site.pages site:main/en: conflicted by slug, 2 records, 1 conflicting keys'), 'the rebuild hid a conflict: ' . $printed);
publishedReadRefused(static fn () => $read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about'), ReadContractRejected::class, 'ambiguous_key');
$plain->unpublish('site.pages', 'copy', 'site:main', 'en', 'actor:importer');
[$status, $printed] = $run(['--scope' => 'site:main']);
publishedReadExpect($status === 0 && str_contains($printed, 'site:main/en: ready by slug, 1 records'), 'the rebuild did not clear the conflict: ' . $printed);
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about')?->recordId === 'about', 'the rebuilt set lost its key');

// A stale row left by a publication that bypassed the index gives way to the
// next publish, and its lookup falls back to the scan instead of naming it.
$plain->unpublish('site.pages', 'about', 'site:main', 'en', 'actor:importer');
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about') === null, 'a stale row was trusted');
$publication->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor');
publishedReadExpect($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about')?->recordId === 'copy', 'the stale row did not give way');

// --- An interrupted build resumes after its cursor. ---
// The first run wrote the first 200 keys and stopped.
$table = new PublishedKeyTable($connection);
$table->startSet('site.pages', 'site:large', 'kk', 'slug', $read->localeChainKey('kk'));
$index->connection()->transaction(static function () use ($table): void {
    for ($i = 0; $i < 200; $i++) {
        $table->insert('site.pages', 'site:large', 'kk', 'slug', 'page-' . $i, sprintf('site-large-%04d', $i), 1);
    }
    $table->advanceSet('site.pages', 'site:large', 'kk', 'slug', 'site-large-0199', 0);
});
publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'kk', 'slug', 'page-250')?->recordId === 'site-large-0250', 'a set being built answered or the scan failed');
[$status, $printed] = $run(['--locale' => 'kk', '--scope' => 'site:large']);
publishedReadExpect($status === 0 && str_contains($printed, 'site:large/kk: ready by slug, 100 records (resumed)'), 'the build did not resume: ' . $printed);
publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'kk', 'slug', 'page-250')?->recordId === 'site-large-0250', 'the resumed set lost a key');
publishedReadExpect($read->resolveKey('site.pages', 'site:large', 'kk', 'slug', 'page-5')?->recordId === 'site-large-0005', 'the resumed set lost a key of the first run');
publishedReadExpect($set('site:large', 'kk')['state'] === PublishedKeyTable::STATE_READY, 'the resumed set is not ready');

// Without the tables the reads scan, as before the migration.
$bare = new Capsule();
$bare->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
publishedReadExpect(!(new PublishedKeyTable($bare->getConnection()))->available(), 'missing tables were reported available');

echo "Published read batching and the published-key table passed.\n";
