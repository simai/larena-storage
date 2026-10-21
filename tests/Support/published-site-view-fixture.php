<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;
use Larena\Storage\Contracts\LocalizedValueObserver;
use Larena\Storage\Contracts\PublishedKeyPolicy;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\LocalizedValueObserverChain;
use Larena\Storage\Runtime\PublicationIndexChain;
use Larena\Storage\Runtime\PublishedKeyIndex;
use Larena\Storage\View\Site\PublishedSiteViewChecker;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewProjector;
use Larena\Storage\View\Site\PublishedSiteViewRegistry;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewNaming;

require_once __DIR__ . '/storage-view-fixture.php';

/**
 * The site витрина wired as the application wires it, on any connection: the
 * workbench and its admin витрина, localized values, read contracts with a fallback
 * chain (kk falls back to ru), the published-key index (keyed by slug) and the site
 * index, a lifecycle with both indexes, and lifecycles without them.
 */

/** Kazakh falls back to Russian; every other locale stands alone. */
final class PublishedSiteViewChain implements LocaleFallbackResolver
{
    public function chainFor(string $requestedLocale): LocaleFallbackChain
    {
        return $requestedLocale === 'kk' ? LocaleFallbackChain::of('kk', 'ru') : LocaleFallbackChain::of($requestedLocale);
    }
}

final class PublishedSiteViewSlugKeys implements PublishedKeyPolicy
{
    public function keyFieldFor(string $schemaId, string $scopeRef): string
    {
        return 'slug';
    }
}

/** The indexes the localized values tell of each write; set once they are made (they read through those values). */
final class PublishedSiteViewIndexes
{
    public ?PublishedKeyIndex $keys = null;
    public ?PublishedSiteViewIndex $site = null;

    public function observer(): LocalizedValueObserver
    {
        if ($this->keys === null || $this->site === null) {
            throw new RuntimeException('published_site_view_fixture_incomplete');
        }

        return new LocalizedValueObserverChain([$this->keys, $this->site]);
    }
}

/** The storages a structure change asked to rebuild the site витрина of. */
final class PublishedSiteViewQueued
{
    /** @var list<string> */
    private array $ids = [];

    public function add(string $schemaId): void
    {
        $this->ids[] = $schemaId;
    }

    /** @return list<string> Those asked for since the last call. */
    public function take(): array
    {
        $ids = $this->ids;
        $this->ids = [];

        return $ids;
    }
}

/** The storage, workbench, localized, publication, published-key and site migrations. */
function publishedSiteViewInstall(Connection $connection): void
{
    storageViewInstall($connection);
    $root = __DIR__ . '/../../database/migrations';
    foreach ([
        '/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php',
        '/platform/2026_09_29_000001_create_larena_storage_publication_tables.php',
        '/platform/2026_10_20_000002_create_larena_storage_published_key_tables.php',
        '/platform/2026_10_22_000001_create_larena_storage_site_view_tables.php',
    ] as $file) {
        (require $root . $file)->up();
    }
}

/**
 * @param (Closure(string): void)|null $siteRebuilds Hears the storages whose site витрина a structure change silenced.
 * @return array{workbench: Larena\Storage\Runtime\DatabaseStorageWorkbench, storage: Larena\Storage\Runtime\VersionedStorage, views: Larena\Storage\View\StorageViewSync, evolution: Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution, localized: DatabaseLocalizedValues, bare: DatabaseLocalizedValues, read: DatabaseReadContracts, keys: PublishedKeyIndex, site: PublishedSiteViewIndex, publication: DatabasePublicationLifecycle, plain: DatabasePublicationLifecycle, keysOnly: DatabasePublicationLifecycle, checker: PublishedSiteViewChecker}
 */
function publishedSiteViewRuntime(Connection $connection, StorageViewDdl $ddl, string $actor, string $scopeRef, ?Closure $siteRebuilds = null, int $inlineBuildLimit = PublishedSiteViewIndex::INLINE_BUILD_LIMIT, ?LocaleFallbackResolver $chain = null): array
{
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scopeRef);
    $chain ??= new PublishedSiteViewChain();
    // The indexes read through the localized values that tell them of each write: they are made after them.
    $indexes = new PublishedSiteViewIndexes();
    $localized = new DatabaseLocalizedValues($connection, null, null, null, static fn (): LocalizedValueObserver => $indexes->observer());
    $read = new DatabaseReadContracts($connection, $localized, $chain);
    $keys = new PublishedKeyIndex($connection, $read, new PublishedSiteViewSlugKeys());
    $site = new PublishedSiteViewIndex($connection, $read, new PublishedSiteViewRegistry($connection, 0.0), $ddl, true, $inlineBuildLimit);
    $indexes->keys = $keys;
    $indexes->site = $site;
    $runtime['views']->useSiteViews($site->registry(), static fn (): PublishedSiteViewIndex => $site, $siteRebuilds);
    $exists = static fn (string $schemaId, string $recordId, int $revision): bool => $connection->table('larena_storage_record_versions')
        ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists();

    return $runtime + [
        'localized' => $localized,
        // Localized values without an observer: a write the indexes never hear of.
        'bare' => new DatabaseLocalizedValues($connection),
        'read' => $read,
        'keys' => $keys,
        'site' => $site,
        'publication' => new DatabasePublicationLifecycle($connection, $exists, null, null, null, new PublicationIndexChain([$keys, $site])),
        'plain' => new DatabasePublicationLifecycle($connection, $exists),
        'keysOnly' => new DatabasePublicationLifecycle($connection, $exists, null, null, null, $keys),
        'checker' => new PublishedSiteViewChecker($site),
    ];
}

/** @param array<string, mixed> $constraints @return array<string, mixed> */
function publishedSiteViewField(string $key, int $position, string $type, string $visibility, array $constraints = [], bool $localized = false, bool $required = false): array
{
    return array_merge(storageViewField($key, $position, $type, $constraints, $required), ['visibility' => $visibility], $localized ? ['localized' => true] : []);
}

/** The site витрина of the storage agrees with the read contracts: every slot ready, every row exact. */
function publishedSiteViewParity(PublishedSiteViewIndex $site, string $schemaId, string $message): void
{
    $report = (new PublishedSiteViewChecker($site))->check($schemaId);
    storageViewExpect($report['problems'] === [], $message . ': ' . json_encode($report));
    foreach ($site->registry()->slots($schemaId) as $slot) {
        storageViewExpect($slot->state === 'ready', $message . ': slot ' . $slot->scopeRef . '/' . $slot->locale . ' is ' . $slot->state);
    }
}

/**
 * The entries one slot holds, by record id, decoded.
 *
 * @return array<string, array<string, mixed>>
 */
function publishedSiteViewEntries(PublishedSiteViewIndex $site, string $schemaId, string $scopeRef, string $locale): array
{
    $slot = $site->registry()->slot($schemaId, $scopeRef, $locale, null, true);
    if ($slot === null) {
        return [];
    }
    $entries = [];
    foreach ($site->connection()->table(StorageViewNaming::siteTable($schemaId))->where('slot_id', $slot->id)->orderBy('record_id')->get() as $row) {
        $entries[(string) $row->record_id] = PublishedSiteViewProjector::decodeEntry((string) $row->entry_json);
    }

    return $entries;
}

/** Every value in the storage's site tables (main, side and search rows), as text, to scan for what must never be there. */
function publishedSiteViewDump(Connection $connection, string $schemaId): string
{
    $table = StorageViewNaming::siteTable($schemaId);
    $dump = '';
    foreach ([$table, $table . '_m', $table . '_s'] as $name) {
        if (!$connection->getSchemaBuilder()->hasTable($name)) {
            continue;
        }
        foreach ($connection->table($name)->get() as $row) {
            $dump .= json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }

    return $dump;
}

/**
 * One slot's rows without their write time, side rows and search rows, to compare two builds.
 *
 * @return array{rows: array<string, array<string, mixed>>, multi: list<string>, search: array<string, ?string>}
 */
function publishedSiteViewSnapshot(PublishedSiteViewIndex $site, string $schemaId): array
{
    $table = StorageViewNaming::siteTable($schemaId);
    $slots = [];
    foreach ($site->registry()->slots($schemaId) as $slot) {
        $slots[$slot->id] = $slot->scopeRef . '/' . $slot->locale;
    }
    $rows = [];
    foreach ($site->connection()->table($table)->get() as $row) {
        $values = (array) $row;
        unset($values['updated_at']);
        $values['slot_id'] = $slots[(int) $values['slot_id']] ?? 'unknown';
        $rows[$values['slot_id'] . '|' . $values['record_id']] = $values;
    }
    ksort($rows, SORT_STRING);
    $multi = [];
    foreach ($site->connection()->table($table . '_m')->get() as $row) {
        $multi[] = ($slots[(int) $row->slot_id] ?? 'unknown') . '|' . $row->record_id . '|' . $row->field_key . '|' . $row->value;
    }
    sort($multi);
    $search = [];
    foreach ($site->connection()->table($table . '_s')->get() as $row) {
        $search[($slots[(int) $row->slot_id] ?? 'unknown') . '|' . $row->record_id] = $row->search_text === null ? null : (string) $row->search_text;
    }
    ksort($search, SORT_STRING);

    return ['rows' => $rows, 'multi' => $multi, 'search' => $search];
}

/**
 * A list compiled onto one slot of the site table (a choices filter, a search, a sort)
 * finds exactly the slot's entries that match, in order, and counts them; no row of
 * another slot comes in.
 */
function publishedSiteViewListChecks(Connection $connection, PublishedSiteViewIndex $site, string $schemaId, string $scopeRef, string $locale): void
{
    $registration = $site->registry()->find($schemaId, true);
    $slot = $site->registry()->slot($schemaId, $scopeRef, $locale, null, true);
    $schema = $site->currentSchema($schemaId);
    storageViewExpect($registration !== null && $slot !== null && $schema !== null, 'no slot to list');
    $fields = [];
    foreach (PublishedSiteViewProjector::publicFields($schema['fields']) as $field) {
        $fields[(string) $field['key']] = $field;
    }
    $compiler = new Larena\Storage\View\StorageViewQueryCompiler();
    $entries = publishedSiteViewEntries($site, $schemaId, $scopeRef, $locale);
    foreach ([
        ['filters' => [], 'search' => null, 'sort' => [['field' => 'slug', 'direction' => 'desc']]],
        ['filters' => ['tags' => ['operator' => 'eq', 'value' => 'b']], 'search' => null, 'sort' => []],
        ['filters' => [], 'search' => 'page', 'sort' => [['field' => 'title', 'direction' => 'asc']]],
    ] as $case) {
        $query = $compiler->compileLayout($connection, $registration->tableName, $registration->layout, $fields, $case + ['include_archived' => false], $slot->id);
        storageViewExpect($query !== null, 'a site list did not compile: ' . json_encode($case));
        $expected = [];
        foreach ($entries as $recordId => $entry) {
            $values = $entry['values'];
            if ($case['filters'] !== [] && !in_array('b', is_array($values['tags'] ?? null) ? $values['tags'] : [], true)) {
                continue;
            }
            if ($case['search'] !== null) {
                $texts = [];
                foreach (['body', 'slug', 'title'] as $key) {
                    if (is_string($values[$key] ?? null)) {
                        $texts[] = $values[$key];
                    }
                }
                if (!Larena\Storage\View\StorageViewSearch::matches(Larena\Storage\View\StorageViewSearch::text($texts), Larena\Storage\View\StorageViewSearch::terms($case['search']))) {
                    continue;
                }
            }
            $expected[$recordId] = $case['sort'] === [] ? '' : (string) ($values[$case['sort'][0]['field']] ?? '');
        }
        $ids = array_keys($expected);
        usort($ids, static fn (string $left, string $right): int => [$expected[$left], $left] <=> [$expected[$right], $right]);
        if (($case['sort'][0]['direction'] ?? 'asc') === 'desc') {
            $ids = array_reverse($ids);
        }
        $page = array_map(static fn (array $row): string => $row['record_id'], $query->page(100));
        storageViewExpect($page === $ids, 'the site list of ' . $scopeRef . '/' . $locale . ' is wrong for ' . json_encode($case) . ': ' . json_encode($page) . ' instead of ' . json_encode($ids));
        $count = (new Larena\Storage\View\StorageViewCounter())->count($query, Larena\Storage\Enums\StorageWorkbenchCountMode::Exact);
        storageViewExpect($count['count'] === count($ids) && $count['exact'], 'the site list count is wrong');
    }
}
