<?php

declare(strict_types=1);

namespace Larena\Storage\Benchmark;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageWorkbenchBoardQuery;
use Larena\Storage\Contracts\StorageWorkbenchCalendarQuery;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\RequestedLocaleOnly;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewReader;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\Site\PublishedSiteViewRegistry;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;
use Throwable;

/**
 * Measures today's Storage engine on a disposable workbench structure of about
 * twenty fields: list pages, filters, sorting, search, the matched count, and
 * single-record read, update and create. Every operation reports p50/p95/max
 * milliseconds, peak memory and the SQL query count of one call. An operation
 * the engine refuses (for example a list of a large storage whose витрина is not
 * ready yet) is recorded as failed with its reason; the run goes on.
 *
 * Records are seeded by writing exactly the rows the engine's create writes:
 * values normalized against the stored schema version, canonical JSON, a
 * sha256 content hash shared by the head and the first version. Seeding
 * through the engine one record at a time would take hours at 100k.
 *
 * Writes keep each structure's витрина, as in the application; the seeded
 * records get theirs from one rebuild after seeding (timed: `view_build_ms`).
 *
 * The public reads are measured on a second storage of public fields, every record
 * published in one scope and locale: a projection page, a deep page, a record and a
 * key whose published-key set is not built, each through the exact path (`legacy`)
 * and through the site витрина (`site`), built by one rebuild (timed) and checked to
 * return the same.
 *
 * The benchmark runs its own runtime with an access policy that admits only the
 * benchmark actor in a fresh benchmark scope, so it never reads other data. Run
 * it against a disposable database only.
 */
final class StorageBenchmark
{
    public const ACTOR = 'system:storage-benchmark';
    private const SCOPE_FIELD = 'larena_scope_ref';
    private const STATE_FIELD = 'larena_record_state';
    private const SEED_CHUNK = 500;
    private const SECTION_COUNT = 20;
    private const SEARCH_TOKEN = 'quartzite';
    private const STATUS = ['draft', 'review', 'approved', 'published', 'retired'];
    private const PRIORITY = ['low', 'normal', 'high'];
    private const TAGS = ['red', 'green', 'blue', 'black', 'white', 'gold'];
    private const WORDS = ['alpha', 'river', 'stone', 'cedar', 'harbor', 'meadow', 'signal', 'copper', 'lantern', 'orbit', 'violet', 'summit'];

    private string $scopeRef;
    private string $runId;
    private DatabaseStorageWorkbench $workbench;
    private VersionedStorage $storage;
    private SchemaDefinitionNormalizer $normalizer;
    private StorageViewSync $views;
    private string $cursorKey;

    public function __construct(private Connection $connection, private PropertyTypeRegistry $propertyTypes)
    {
        $this->runId = bin2hex(random_bytes(4));
        $this->scopeRef = 'scope:storage-benchmark-' . $this->runId;
        $access = new StorageBenchmarkAccess(self::ACTOR, $this->scopeRef);
        $events = new NullStorageSecurityEventSink();
        $cursorKey = bin2hex(random_bytes(32));
        $this->cursorKey = $cursorKey;
        $this->normalizer = new SchemaDefinitionNormalizer($propertyTypes);
        $this->views = StorageViewSync::for($connection);
        $this->storage = new VersionedStorage($connection, $propertyTypes, $access, $events, $access, $cursorKey, null, $this->views);
        $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
        $policies->protect(
            'larena/storage',
            static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {
                if (!$capability instanceof DatabaseStorageWorkbench || !str_starts_with($context->source->schemaId, 'workbench.')) {
                    throw new \InvalidArgumentException('storage_workbench_schema_evolution_capability_invalid');
                }
            },
            'workbench.',
        );
        $policies->seal();
        $this->workbench = new DatabaseStorageWorkbench(
            $connection,
            $propertyTypes,
            $access,
            $access,
            $this->storage,
            $policies,
            $cursorKey,
            $events,
            $this->views,
        );
    }

    /** Creates the Storage tables on an empty disposable database through the package migrations. */
    public static function installSchema(Connection $connection): void
    {
        $root = dirname(__DIR__, 2) . '/database/migrations';
        $files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/platform/*.php') ?: []);
        sort($files, SORT_STRING);
        Schema::swap($connection->getSchemaBuilder());
        try {
            foreach ($files as $file) {
                $migration = require $file;
                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up();
                }
            }
        } finally {
            Facade::clearResolvedInstance('db.schema');
        }
    }

    /**
     * @param list<int> $sizes
     * @param Closure(string): void|null $progress
     * @return array<string, mixed>
     */
    public function run(array $sizes, int $repeat, bool $keep = false, ?Closure $progress = null, bool $siteOnly = false, bool $boardOnly = false, bool $calendarOnly = false): array
    {
        $progress ??= static function (string $line): void {
        };
        $runs = [];
        foreach ($sizes as $size) {
            $progress(sprintf('Seeding %d records...', $size));
            $runs[] = $siteOnly
                ? ['records' => $size, 'site' => $this->runSite($this->runId . '_' . $size, $size, $repeat, $keep, $progress)]
                : $this->runSize($size, $repeat, $keep, $progress, $boardOnly, $calendarOnly);
        }

        return [
            'schema' => 'larena.storage.benchmark',
            'format' => 1,
            'started_by' => self::ACTOR,
            'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => $this->environment(),
            'repeat' => $repeat,
            'seeding' => 'direct rows identical to the engine create (normalized values, canonical JSON, sha256 head and version hash)',
            'runs' => $runs,
        ];
    }

    /**
     * @param Closure(string): void $progress
     * @return array<string, mixed>
     */
    private function runSize(int $size, int $repeat, bool $keep, Closure $progress, bool $boardOnly = false, bool $calendarOnly = false): array
    {
        $suffix = $this->runId . '_' . $size;
        $sections = $this->workbench->createStructure($this->scopeRef, [
            'structure_id' => 'workbench.bench_sections_' . $suffix,
            'label' => 'Benchmark sections',
            'fields' => [self::field('title', 10, 'string', true, ['max_length' => 200])],
        ], self::ACTOR);
        $sectionIds = [];
        for ($index = 1; $index <= self::SECTION_COUNT; $index++) {
            $sectionIds[] = $this->workbench->createRecord($this->scopeRef, $sections->structureId, ['title' => 'Section ' . $index], self::ACTOR)->recordId;
        }
        $structure = $this->workbench->createStructure($this->scopeRef, self::descriptor('workbench.bench_items_' . $suffix, $sections->structureId), self::ACTOR);

        $seedStarted = hrtime(true);
        $recordIds = $this->seed($structure, $size, fn (int $index): array => $this->values($index, $sectionIds));
        $seedMs = (hrtime(true) - $seedStarted) / 1e6;
        $optionsMs = null;
        $buildMs = null;
        if ($this->views->enabled()) {
            // Nothing else writes the benchmark storage, so the build does not wait for writers.
            // Timed: the build a list runs inline for a storage up to INLINE_VIEW_BUILD_LIMIT records
            // (the inline build also waits twice for the registry memo, about 2 s with the default 1 s).
            $builder = new StorageViewSync($this->connection, new StorageViewRegistry($this->connection, 0.0), $this->views->ddl());
            $buildStarted = hrtime(true);
            (new StorageViewRebuilder($builder))->rebuild($structure->schema->schemaId, StorageViewRebuilder::DEFAULT_CHUNK, true);
            $buildMs = (hrtime(true) - $buildStarted) / 1e6;
            $this->views->registry()->forget($structure->schema->schemaId);
        }
        $viewsOnly = $boardOnly || $calendarOnly;
        if ($this->views->enabled() && !$viewsOnly) {
            // The field options are switched on the way an owner does it on a filled storage:
            // a structure change (no record rewritten) and the backfill of the витрина, timed.
            $builder = new StorageViewSync($this->connection, new StorageViewRegistry($this->connection, 0.0), $this->views->ddl());
            $optionsStarted = hrtime(true);
            $structure = $this->workbench->updateStructure($this->scopeRef, $structure->structureId, $structure->version, self::descriptor(
                $structure->structureId,
                $sections->structureId,
                true,
            ), self::ACTOR);
            (new StorageViewRebuilder($builder))->rebuild($structure->schema->schemaId, StorageViewRebuilder::DEFAULT_CHUNK);
            $this->views->registry()->forget($structure->schema->schemaId);
            $optionsMs = (hrtime(true) - $optionsStarted) / 1e6;
        }
        $progress(sprintf('Seeded %d records in %.1f s%s; measuring...', $size, $seedMs / 1000, $optionsMs === null ? '' : sprintf(', options backfilled in %.1f s', $optionsMs / 1000)));

        $list = fn (array $filters = [], ?string $search = null, array $sort = [], int $limit = 50, ?int $page = null,
            StorageWorkbenchCountMode $count = StorageWorkbenchCountMode::Auto): int => $this->workbench->listRecords(
                new StorageWorkbenchRecordQuery($this->scopeRef, $structure->structureId, $filters, $search, $sort, $limit, null, false, $page, $count),
                self::ACTOR,
            )->matchedCount;
        $pick = static fn (int $iteration): string => $recordIds[($iteration * 7919) % count($recordIds)];
        $deepPage = min(50, intdiv($size - 1, 50) + 1);
        // Each operation prepares outside the timer and returns the timed call.
        $listing = static fn (array $filters = [], ?string $search = null, array $sort = [], int $limit = 50, ?int $page = null,
            StorageWorkbenchCountMode $count = StorageWorkbenchCountMode::Auto): Closure => static fn (): int => $list($filters, $search, $sort, $limit, $page, $count);
        $operations = [
            'list_page_1' => static fn (): Closure => $listing(),
            // Page 50 of 50 records, or the last page of a smaller structure.
            'list_deep_page' => static fn (): Closure => $listing(page: $deepPage),
            'filter_choice' => static fn (): Closure => $listing(['status' => ['operator' => 'eq', 'value' => 'review']]),
            'filter_number_range' => static fn (): Closure => $listing(['price' => ['operator' => 'between', 'values' => ['100', '250']]]),
            'filter_date_range' => static fn (): Closure => $listing(['day' => ['operator' => 'between', 'values' => ['2026-03-01', '2026-03-31']]]),
            'sort_number' => static fn (): Closure => $listing(sort: [['field' => 'price', 'direction' => 'desc']]),
            // title is a string indexed on request (VARCHAR(200) with its index); subtitle is unindexed long text.
            'sort_title' => static fn (): Closure => $listing(sort: [['field' => 'title', 'direction' => 'asc']]),
            'sort_title_desc_deep' => static fn (): Closure => $listing(sort: [['field' => 'title', 'direction' => 'desc']], page: $deepPage),
            'sort_subtitle_unindexed' => static fn (): Closure => $listing(sort: [['field' => 'subtitle', 'direction' => 'asc']]),
            'filter_title_prefix' => static fn (): Closure => $listing(['title' => ['operator' => 'starts_with', 'value' => 'river']]),
            'filter_sku_eq' => static fn (): Closure => $listing(['sku' => ['operator' => 'eq', 'value' => sprintf('SKU-%08d', intdiv($size, 2))]]),
            // Search over title, sku and body: a rare word (1 record in 100), its prefix, two words, a common
            // title word, and a two-letter prefix below the FULLTEXT token size (checked row by row).
            'search_text' => static fn (): Closure => $listing(search: self::SEARCH_TOKEN),
            'search_prefix' => static fn (): Closure => $listing(search: substr(self::SEARCH_TOKEN, 0, 6)),
            'search_two_words' => static fn (): Closure => $listing(search: self::SEARCH_TOKEN . ' cedar'),
            'search_common_word' => static fn (): Closure => $listing(search: 'harbor'),
            'search_short_term' => static fn (): Closure => $listing(search: 'qu'),
            'matched_count' => static fn (): Closure => $listing(limit: 1),
            // The count a bulk action confirms: exact, however large.
            'matched_count_exact' => static fn (): Closure => $listing(limit: 1, count: StorageWorkbenchCountMode::Exact),
            'read_record' => fn (int $iteration): Closure => fn (): int => $this->workbench
                ->readRecord($this->scopeRef, $structure->structureId, $pick($iteration), self::ACTOR)->revision,
            'update_record' => function (int $iteration) use ($structure, $pick): Closure {
                $recordId = $pick($iteration + 1);
                $current = $this->workbench->readRecord($this->scopeRef, $structure->structureId, $recordId, self::ACTOR);
                $values = array_filter($current->values, static fn (string $key): bool => !str_starts_with($key, 'larena_'), ARRAY_FILTER_USE_KEY);
                $values['qty'] = (is_int($values['qty'] ?? null) ? $values['qty'] + 1 : 1) % 1000;

                return fn (): int => $this->workbench
                    ->updateRecord($this->scopeRef, $structure->structureId, $recordId, $current->revision, $values, self::ACTOR)->revision;
            },
            'create_record' => fn (int $iteration): Closure => fn (): int => $this->workbench
                ->createRecord($this->scopeRef, $structure->structureId, $this->values($size + $iteration, $sectionIds), self::ACTOR)->revision,
        ];

        // The board by status (5 lanes of a fifth each): one call for every lane's first 20 and the totals of all
        // lanes, by the list's sort or none, cut by a filter with lane sums, and «Показать ещё» of one lane.
        $boardRead = fn (array $filters = [], array $sort = [], array $sums = [], ?string $lane = null, ?string $continuation = null): int => $this->workbench->listBoard(
            new StorageWorkbenchBoardQuery(new StorageWorkbenchRecordQuery($this->scopeRef, $structure->structureId, $filters, null, $sort,
                StorageWorkbenchBoardQuery::PER_LANE, $continuation), 'status', $sums, $lane),
            self::ACTOR,
        )->total;
        $boardOperations = [
            'board_lanes' => static fn (): Closure => static fn (): int => $boardRead(),
            'board_lanes_sorted' => static fn (): Closure => static fn (): int => $boardRead([], [['field' => 'price', 'direction' => 'desc']]),
            'board_lanes_filtered_sum' => static fn (): Closure => static fn (): int => $boardRead(['active' => ['operator' => 'eq', 'value' => true]], [], ['price', 'qty']),
            'board_lane_more' => function () use ($structure, $boardRead): Closure {
                $first = $this->workbench->listBoard(new StorageWorkbenchBoardQuery(new StorageWorkbenchRecordQuery(
                    $this->scopeRef, $structure->structureId, [], null, [], StorageWorkbenchBoardQuery::PER_LANE), 'status'), self::ACTOR);
                $continuation = $first->lane('review')?->continuation;

                return static fn (): int => $boardRead([], [], [], 'review', $continuation);
            },
        ];
        // The calendar by start and end (date-times, 1 in 100 records without a start; about 2 700 a day): a week and
        // a month answer their first 1000 in start order with the exact total, a week cut by a filter, a week of a
        // date field without an end, the undated records; and a cards window of 24 with its total.
        $calendarRead = fn (array $filters, string $from, string $to, ?string $end = 'ends_at', string $start = 'starts_at'): int => $this->workbench->listCalendar(
            new StorageWorkbenchCalendarQuery(new StorageWorkbenchRecordQuery($this->scopeRef, $structure->structureId, $filters, null, [], 20),
                $start, $end, $from, $to),
            self::ACTOR,
        )->total;
        $calendarOperations = [
            'calendar_week' => static fn (): Closure => static fn (): int => $calendarRead([], '2026-06-01T00:00:00', '2026-06-08T00:00:00'),
            'calendar_month' => static fn (): Closure => static fn (): int => $calendarRead([], '2026-05-25T00:00:00', '2026-07-06T00:00:00'),
            'calendar_week_filtered' => static fn (): Closure => static fn (): int => $calendarRead(
                ['status' => ['operator' => 'eq', 'value' => 'review'], 'priority' => ['operator' => 'eq', 'value' => 'high']], '2026-06-01T00:00:00', '2026-06-08T00:00:00'),
            'calendar_week_date' => static fn (): Closure => static fn (): int => $calendarRead([], '2026-06-01', '2026-06-08', null, 'day'),
            'calendar_undated' => fn (): Closure => fn (): int => $this->workbench->listCalendar(new StorageWorkbenchCalendarQuery(
                new StorageWorkbenchRecordQuery($this->scopeRef, $structure->structureId, [], null, [], 20, null, false, 1), 'starts_at', 'ends_at', null, null, true),
                self::ACTOR)->total,
            'cards_window' => fn (): Closure => fn (): int => $this->workbench->listRecords(new StorageWorkbenchRecordQuery(
                $this->scopeRef, $structure->structureId, [], null, [['field' => 'price', 'direction' => 'desc']], 24, null, false, 2), self::ACTOR)->matchedCount,
        ];
        $operations = match (true) {
            $boardOnly => $boardOperations,
            $calendarOnly => $calendarOperations,
            default => $operations + $boardOperations + $calendarOperations,
        };

        $results = [];
        foreach ($operations as $name => $operation) {
            $results[$name] = $this->sample($name, $operation, $repeat);
            $progress(sprintf('  %-20s %s', $name, self::summary($results[$name])));
        }

        $fieldCount = count($structure->fields);
        if ($viewsOnly) {
            return ['records' => $size, 'structure_id' => $structure->structureId, 'field_count' => $fieldCount, 'seed_ms' => round($seedMs, 1),
                'view_build_ms' => $buildMs === null ? null : round($buildMs, 1), 'operations' => $results,
                'cleanup' => $keep ? ['kept' => true] : $this->cleanup($structure, $sections)];
        }
        // Field changes on the filled storage, timed once each: an optional field added (no
        // record version written) and a field retyped (every record checked, the витрина's new
        // column filled, then the switch; no record version written either).
        [$structure, $fieldAdd] = $this->fieldChange($structure, static function (array $fields): array {
            $fields[] = self::field('extra_note', 210, 'string');

            return $fields;
        }, false);
        $progress(sprintf('  %-20s %.1f ms, %d version rows written', 'field_add', $fieldAdd['ms'], $fieldAdd['version_rows_written']));
        [$structure, $fieldRetype] = $this->fieldChange($structure, static fn (array $fields): array => array_map(
            static fn (array $field): array => $field['key'] === 'rank' ? ['type' => 'number'] + $field : $field,
            $fields,
        ), true);
        $progress(sprintf('  %-20s %.1f ms, %d version rows written', 'field_retype', $fieldRetype['ms'], $fieldRetype['version_rows_written']));

        $cleanup = $keep ? ['kept' => true] : $this->cleanup($structure, $sections);
        $siteResults = $this->runSite($suffix, $size, $repeat, $keep, $progress);

        return [
            'records' => $size,
            'structure_id' => $structure->structureId,
            'field_count' => $fieldCount,
            'deep_page' => $deepPage,
            'seed_ms' => round($seedMs, 1),
            'seed_records_per_second' => $seedMs > 0 ? (int) round($size / ($seedMs / 1000)) : null,
            // Building the витрина from the seeded current versions (chunks of 1000).
            'view_build_ms' => $buildMs === null ? null : round($buildMs, 1),
            // Switching the field options on the filled storage: the structure change and the backfill.
            'options_backfill_ms' => $optionsMs === null ? null : round($optionsMs, 1),
            'operations' => $results,
            'field_add' => $fieldAdd,
            'field_retype' => $fieldRetype,
            'cleanup' => $cleanup,
            'site' => $siteResults,
        ];
    }

    /**
     * The public reads of a storage of public fields with `$size` published records,
     * through the exact path and through the site витрина.
     *
     * @param Closure(string): void $progress
     * @return array<string, mixed>
     */
    private function runSite(string $suffix, int $size, int $repeat, bool $keep, Closure $progress): array
    {
        $index = new PublishedSiteViewIndex($this->connection, new DatabaseReadContracts($this->connection), new PublishedSiteViewRegistry($this->connection, 0.0));
        if (!$index->enabled()) {
            return ['status' => 'skipped', 'reason' => 'storage_view_unavailable'];
        }
        $public = static fn (array $field): array => ['visibility' => 'public'] + $field;
        $structure = $this->workbench->createStructure($this->scopeRef, [
            'structure_id' => 'workbench.bench_site_' . $suffix,
            'label' => 'Benchmark site items',
            'fields' => [
                $public(self::field('title', 10, 'string', true, ['max_length' => 200])),
                $public(self::field('slug', 20, 'string', true, ['max_length' => 120], ['indexed' => true])),
                $public(self::field('body', 30, 'text')),
                $public(self::field('price', 40, 'number')),
                $public(self::field('status', 50, 'choice', false, ['options' => array_map(
                    static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)],
                    self::STATUS,
                )])),
                self::field('notes', 60, 'text'),
            ],
        ], self::ACTOR);
        $schemaId = $structure->schema->schemaId;
        $seedStarted = hrtime(true);
        $recordIds = $this->seed($structure, $size, fn (int $index): array => [
            'title' => 'Item ' . $index,
            'slug' => sprintf('item-%08d', $index),
            'body' => implode(' ', array_map(static fn (int $salt): string => self::WORDS[($index * 31 + $salt * 17) % count(self::WORDS)], range(1, 24))),
            'price' => sprintf('%d.%02d', ($index * 13) % 1000, $index % 100),
            'status' => self::STATUS[$index % 5],
            'notes' => 'Admin note ' . $index,
        ]);
        $this->publishAll($schemaId, $recordIds);
        $seedMs = (hrtime(true) - $seedStarted) / 1e6;
        $buildStarted = hrtime(true);
        $index->prepare($schemaId);
        (new PublishedSiteViewRebuilder($index))->rebuild($schemaId, PublishedSiteViewRebuilder::MAX_CHUNK);
        $buildMs = (hrtime(true) - $buildStarted) / 1e6;
        $progress(sprintf('Site: %d published records seeded in %.1f s, site витрина built in %.1f s; measuring...', $size, $seedMs / 1000, $buildMs / 1000));

        // Both readers list too: the legacy one from the published records (refused above
        // DatabaseReadContracts::LIST_FALLBACK_LIMIT), the site one from the slot.
        $legacy = new DatabaseReadContracts($this->connection, null, new RequestedLocaleOnly(), new StorageMetrics(), null, null,
            DatabaseReadContracts::KEY_SCAN_LIMIT, $this->propertyTypes, $this->cursorKey);
        $site = new DatabaseReadContracts($this->connection, null, new RequestedLocaleOnly(), new StorageMetrics(), null, new PublishedSiteViewReader($this->connection),
            DatabaseReadContracts::KEY_SCAN_LIMIT, $this->propertyTypes, $this->cursorKey);
        $list = static fn (array $filters, ?string $search, array $sort, StorageWorkbenchCountMode $count): Closure => static fn (DatabaseReadContracts $read): Closure => static fn (int $iteration): Closure => static function () use ($read, $schemaId, $filters, $search, $sort, $count): int {
            $page = $read->publishedList(new PublishedListQuery($schemaId, 'site:main', 'en', $filters, $search, $sort, 20, null, $count));

            return $page->matchedCount ?? count($page->records);
        };
        $sorted = $recordIds;
        sort($sorted, SORT_STRING);
        $middle = $sorted[intdiv(count($sorted), 2)];
        $pick = static fn (int $iteration): string => $recordIds[($iteration * 7919) % count($recordIds)];
        // The key of the record read last by a scan in record id order: the whole published set is read to find it.
        $lastKey = $legacy->publishedRecord($schemaId, 'site:main', 'en', $sorted[array_key_last($sorted)])['values']['slug'] ?? '';
        $operations = [
            'site_projection_page_1' => static fn (DatabaseReadContracts $read): Closure => static fn (int $iteration): Closure => static fn (): int => count($read->publishedProjection($schemaId, 'site:main', 'en', 50)->records),
            'site_projection_deep' => static fn (DatabaseReadContracts $read): Closure => static fn (int $iteration): Closure => static fn (): int => count($read->publishedProjection($schemaId, 'site:main', 'en', 50, null, $middle)->records),
            'site_published_record' => static fn (DatabaseReadContracts $read): Closure => static fn (int $iteration): Closure => static fn (): int => (int) ($read->publishedRecord($schemaId, 'site:main', 'en', $pick($iteration))['revision'] ?? 0),
            'site_resolve_key_set_silenced' => static fn (DatabaseReadContracts $read): Closure => static fn (int $iteration): Closure => static fn (): int => $read->resolveKey($schemaId, 'site:main', 'en', 'slug', (string) $lastKey)->revision ?? 0,
            // Public lists (storage.read.published_list): a page of 20.
            'site_list_filter_choice' => $list(['status' => ['operator' => 'eq', 'value' => 'approved']], null, [], StorageWorkbenchCountMode::Skip),
            'site_list_sort_number' => $list([], null, [['field' => 'price', 'direction' => 'desc']], StorageWorkbenchCountMode::Skip),
            'site_list_search' => $list([], 'harbor', [], StorageWorkbenchCountMode::Skip),
            'site_list_count_auto' => $list(['status' => ['operator' => 'in', 'values' => ['draft', 'review']]], null, [['field' => '_published_at', 'direction' => 'desc']], StorageWorkbenchCountMode::Auto),
        ];
        /** @return array{0: string, 1: ?string} */
        $identical = static function (DatabaseReadContracts $read) use ($schemaId, $middle, $pick, $lastKey): array {
            try {
                // Past the scan limit the exact path refuses: the slot's answer is not compared then.
                $key = $read->resolveKey($schemaId, 'site:main', 'en', 'slug', (string) $lastKey);
            } catch (ReadContractRejected $rejected) {
                $key = $rejected->reasonCode;
            }

            return [
                serialize([
                    $read->publishedProjection($schemaId, 'site:main', 'en', 50),
                    $read->publishedProjection($schemaId, 'site:main', 'en', 50, null, $middle),
                    $read->publishedRecord($schemaId, 'site:main', 'en', $pick(1)),
                ]),
                $key === 'key_scan_limit_exceeded' ? null : serialize($key),
            ];
        };
        // Compared once, untimed: this also warms both readers (their table checks are made once).
        $exact = $identical($legacy);
        $fromSite = $identical($site);
        $same = $exact[0] === $fromSite[0] && ($exact[1] === null || $exact[1] === $fromSite[1]);
        $results = [];
        foreach ($operations as $name => $operation) {
            foreach (['legacy' => $legacy, 'site' => $site] as $mode => $read) {
                $results[$name][$mode] = $this->sample($name, $operation($read), $repeat);
                $progress(sprintf('  %-30s %-6s %s', $name, $mode, self::summary($results[$name][$mode])));
            }
        }
        $cleanup = $keep ? ['kept' => true] : $this->deleteSiteRows($structure);

        return [
            'status' => 'ok',
            'records' => $size,
            'structure_id' => $structure->structureId,
            'seed_ms' => round($seedMs, 1),
            'build_ms' => round($buildMs, 1),
            'identical' => $same,
            'operations' => $results,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * Every seeded record published at revision 1 in `site:main`/`en`, written as the
     * lifecycle writes a state row (the site витрина is built afterwards).
     *
     * @param list<string> $recordIds
     */
    private function publishAll(string $schemaId, array $recordIds): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $started = time();
        $position = 0;
        foreach (array_chunk($recordIds, self::SEED_CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $recordId) {
                // One publication a minute, newest last: a list by publication time has distinct keys.
                $publishedAt = gmdate('Y-m-d H:i:s', $started - 60 * (count($recordIds) - $position++));
                $rows[] = [
                    'publication_id' => PublicationState::identity($schemaId, $recordId, 'site:main', 'en'),
                    'schema_id' => $schemaId, 'record_id' => $recordId, 'scope_ref' => 'site:main', 'locale' => 'en',
                    'state' => 'published', 'published_revision' => 1, 'previous_published_revision' => null,
                    'scheduled_at' => null, 'published_at' => $publishedAt, 'archived_at' => null,
                    'updated_by' => self::ACTOR, 'correlation_id' => 'storage-benchmark-seed-' . $this->runId,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $this->connection->table('larena_storage_publication_states')->insert($rows);
        }
    }

    /**
     * Removes the site benchmark storage: its publication rows, then its records,
     * workbench rows, витрина and site витрина, deleting exactly the rows it owns.
     *
     * @return array<string, mixed>
     */
    private function deleteSiteRows(StorageWorkbenchStructure $structure): array
    {
        $schemaId = $structure->schema->schemaId;
        $this->connection->transaction(function () use ($schemaId): void {
            $this->connection->table('larena_storage_publication_log')->where('schema_id', $schemaId)->delete();
            $this->connection->table('larena_storage_publication_states')->where('schema_id', $schemaId)->delete();
        });

        return ['method' => 'owned_rows_deleted'] + $this->deleteOwnedRows($structure);
    }

    /**
     * One structure change on the filled storage, timed, with the record version rows it wrote.
     *
     * @param Closure(list<array<string, mixed>>): list<array<string, mixed>> $change
     * @return array{0: StorageWorkbenchStructure, 1: array{ms: float, version_rows_written: int, schema_version: int, state: string}}
     */
    private function fieldChange(StorageWorkbenchStructure $structure, Closure $change, bool $migrate): array
    {
        $schemaId = $structure->schema->schemaId;
        $rows = fn (): int => $this->connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count();
        $before = $rows();
        $descriptor = ['structure_id' => $structure->structureId, 'label' => $structure->label, 'fields' => $change($structure->fields)];
        $started = hrtime(true);
        $changed = $migrate
            ? $this->workbench->migrateStructure($this->scopeRef, $structure->structureId, $structure->version, $descriptor, self::ACTOR)
            : $this->workbench->updateStructure($this->scopeRef, $structure->structureId, $structure->version, $descriptor, self::ACTOR);
        $ms = (hrtime(true) - $started) / 1e6;

        return [$changed, [
            'ms' => round($ms, 1),
            'version_rows_written' => $rows() - $before,
            'schema_version' => $changed->schema->version,
            'state' => $changed->fieldChange->state ?? 'switched',
        ]];
    }

    /**
     * @param Closure(int): (Closure(): int) $operation
     * @return array<string, mixed>
     */
    private function sample(string $name, Closure $operation, int $repeat): array
    {
        $timings = [];
        $queries = [];
        $memory = [];
        $value = null;
        $this->connection->enableQueryLog();
        try {
            for ($iteration = 0; $iteration < $repeat; $iteration++) {
                $timed = $operation($iteration);
                $this->connection->flushQueryLog();
                memory_reset_peak_usage();
                $base = memory_get_usage();
                $started = hrtime(true);
                try {
                    $value = $timed();
                } catch (StorageRejected|ReadContractRejected $exception) {
                    // The engine refused: that is the result, not a crash of the run.
                    return [
                        'status' => 'fails',
                        'reason' => $exception instanceof ReadContractRejected ? $exception->reasonCode : $exception->getMessage(),
                        'ms' => round((hrtime(true) - $started) / 1e6, 2),
                        'queries' => count($this->connection->getQueryLog()),
                        'peak_memory_bytes' => memory_get_peak_usage() - $base,
                    ];
                }
                $timings[] = (hrtime(true) - $started) / 1e6;
                $queries[] = count($this->connection->getQueryLog());
                $memory[] = memory_get_peak_usage() - $base;
            }
        } finally {
            $this->connection->disableQueryLog();
            $this->connection->flushQueryLog();
        }
        sort($timings);
        $count = count($timings);
        if ($count === 0) {
            return ['status' => 'skipped'];
        }

        return [
            'status' => 'ok',
            'samples' => $count,
            'p50_ms' => round($timings[intdiv($count - 1, 2)], 2),
            'p95_ms' => round($timings[max(0, (int) ceil(0.95 * $count) - 1)], 2),
            'max_ms' => round($timings[$count - 1], 2),
            'queries' => max($queries),
            'peak_memory_bytes' => max($memory),
            'matched_count' => str_starts_with($name, 'list_') || str_starts_with($name, 'filter_') || str_starts_with($name, 'site_list_') || str_starts_with($name, 'board_') || str_starts_with($name, 'calendar_') || str_starts_with($name, 'cards_')
                || str_starts_with($name, 'sort_') || str_starts_with($name, 'search_')
                || in_array($name, ['matched_count', 'matched_count_exact'], true) ? $value : null,
        ];
    }

    /**
     * @param Closure(int): array<string, mixed> $valuesOf The values of record number $index.
     * @return list<string>
     */
    private function seed(StorageWorkbenchStructure $structure, int $size, Closure $valuesOf): array
    {
        $schemaVersion = $this->storage->schemaVersion($structure->schema);
        $recordIds = [];
        for ($from = 0; $from < $size; $from += self::SEED_CHUNK) {
            $heads = [];
            $versions = [];
            $now = gmdate('Y-m-d H:i:s');
            $correlationId = 'storage-benchmark-seed-' . $this->runId;
            for ($index = $from; $index < min($size, $from + self::SEED_CHUNK); $index++) {
                $values = $valuesOf($index);
                $values[self::SCOPE_FIELD] = $this->scopeRef;
                $values[self::STATE_FIELD] = 'active';
                $valuesJson = $this->normalizer->canonicalJson($this->normalizer->normalizeValues($schemaVersion, $values));
                $hash = hash('sha256', $valuesJson);
                $recordId = 'record-' . bin2hex(random_bytes(16));
                $ownerRef = 'workbench.record:' . bin2hex(random_bytes(16));
                $heads[] = [
                    'record_id' => $recordId, 'schema_id' => $structure->schema->schemaId, 'owner_ref' => $ownerRef,
                    'current_revision' => 1, 'current_schema_version' => $structure->schema->version, 'current_hash' => $hash,
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $versions[] = [
                    'schema_id' => $structure->schema->schemaId, 'record_id' => $recordId, 'revision' => 1, 'owner_ref' => $ownerRef,
                    'schema_version' => $structure->schema->version, 'values_json' => $valuesJson, 'content_hash' => $hash,
                    'operation' => 'create', 'created_by' => self::ACTOR, 'correlation_id' => $correlationId, 'created_at' => $now,
                ];
                $recordIds[] = $recordId;
            }
            $this->connection->transaction(function () use ($heads, $versions): void {
                $this->connection->table('larena_storage_records')->insert($heads);
                $this->connection->table('larena_storage_record_versions')->insert($versions);
            });
        }

        return $recordIds;
    }

    /**
     * Deterministic, realistic values for record number $index.
     *
     * @param list<string> $sectionIds
     * @return array<string, mixed>
     */
    private function values(int $index, array $sectionIds): array
    {
        $word = static fn (int $salt): string => self::WORDS[($index * 31 + $salt * 17) % count(self::WORDS)];
        $body = implode(' ', array_map($word, range(1, 24)));
        if ($index % 100 === 42) {
            $body .= ' ' . self::SEARCH_TOKEN;
        }
        $day = gmdate('Y-m-d', 1767225600 + ($index % 365) * 86400);
        $tags = [self::TAGS[$index % 6], self::TAGS[($index * 5 + 1) % 6]];

        // A record without a start (one in a hundred) has no start key: the calendar's undated records.
        return array_filter([
            'title' => ucfirst($word(1)) . ' ' . $word(2) . ' ' . sprintf('%07d', ($index * 7919) % 10_000_000),
            'subtitle' => ucfirst($word(3)) . ' ' . $word(4),
            'sku' => sprintf('SKU-%08d', $index),
            'body' => $body,
            'notes' => 'Note ' . $word(5) . ' ' . $word(6),
            'qty' => $index % 1000,
            'rank' => ($index * 37) % 100_000,
            'price' => sprintf('%d.%02d', ($index * 13) % 1000, $index % 100),
            'weight' => sprintf('%d.%03d', $index % 50, ($index * 7) % 1000),
            'day' => $day,
            'due_on' => gmdate('Y-m-d', 1767225600 + (($index * 3) % 730) * 86400),
            'starts_at' => $index % 100 === 7 ? null : $day . 'T' . sprintf('%02d:%02d:00', $index % 24, ($index * 7) % 60),
            // One to four hours later; one in fifty runs two days more.
            'ends_at' => $index % 100 === 7 ? null : gmdate('Y-m-d\TH:i:s', strtotime($day . 'T00:00:00Z') + ($index % 24) * 3600 + (($index * 7) % 60) * 60
                + (1 + $index % 4) * 3600 + ($index % 50 === 0 ? 2 * 86400 : 0)),
            'published_at' => gmdate('Y-m-d\TH:i:s', 1767225600 + ($index * 977) % 31_536_000),
            'active' => $index % 3 !== 0,
            'featured' => $index % 10 === 0,
            'status' => self::STATUS[$index % 5],
            'priority' => self::PRIORITY[$index % 3],
            'tags' => array_values(array_unique($tags)),
            'section' => $sectionIds[$index % count($sectionIds)],
            'owner' => 'user:admin:' . (1 + $index % 25),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Removes the benchmark structures and their rows: through the engine while it
     * can (archive, purge), and by deleting exactly the rows the structure owns
     * above the engine's purge limit.
     *
     * @return array<string, mixed>
     */
    private function cleanup(StorageWorkbenchStructure $structure, StorageWorkbenchStructure $sections): array
    {
        $result = [];
        foreach ([$structure, $sections] as $target) {
            try {
                $archived = $this->workbench->archiveStructure($this->scopeRef, $target->structureId, $target->version, self::ACTOR);
                $receipt = $this->workbench->purgeStructure($this->scopeRef, $target->structureId, $archived->version, self::ACTOR);
                $result[$target->structureId] = ['method' => 'engine_purge', 'records' => count($receipt->recordIds)];
            } catch (StorageRejected $exception) {
                $result[$target->structureId] = ['method' => 'owned_rows_deleted', 'reason' => $exception->getMessage()] + $this->deleteOwnedRows($target);
            }
        }

        return $result;
    }

    /** @return array{records: int} */
    private function deleteOwnedRows(StorageWorkbenchStructure $structure): array
    {
        $result = $this->connection->transaction(function () use ($structure): array {
            $schemaId = $structure->schema->schemaId;
            $this->connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->delete();
            $records = $this->connection->table('larena_storage_records')->where('schema_id', $schemaId)->delete();
            $this->connection->table('larena_storage_workbench_structure_versions')
                ->where('scope_ref', $this->scopeRef)->where('structure_id', $structure->structureId)->delete();
            $this->connection->table('larena_storage_workbench_structures')
                ->where('scope_ref', $this->scopeRef)->where('structure_id', $structure->structureId)->delete();
            $this->views->structurePurged($schemaId);

            return ['records' => $records];
        });
        $this->views->settle();

        return $result;
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $driver = $this->connection->getDriverName();
        try {
            $version = $driver === 'sqlite'
                ? $this->connection->selectOne('select sqlite_version() as v')?->v
                : $this->connection->selectOne('select version() as v')?->v;
        } catch (Throwable) {
            $version = null;
        }

        return [
            'driver' => $driver,
            'server_version' => is_scalar($version) ? (string) $version : null,
            'php' => PHP_VERSION,
            'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        ];
    }

    /** @param array<string, mixed> $result */
    public static function summary(array $result): string
    {
        if (($result['status'] ?? null) !== 'ok') {
            return 'fails: ' . (string) ($result['reason'] ?? 'unknown');
        }

        return sprintf(
            'p50 %.1f ms, p95 %.1f ms, max %.1f ms, %d queries, %.1f MB',
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_ms'],
            $result['queries'],
            $result['peak_memory_bytes'] / 1048576,
        );
    }

    /**
     * @param array<string, mixed> $constraints
     * @param array<string, bool> $options The витрина options (searchable, indexed).
     * @return array<string, mixed>
     */
    private static function field(string $key, int $position, string $type, bool $required = false, array $constraints = [], array $options = []): array
    {
        return [
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'position' => $position,
            'type' => $type,
            'type_version' => 1,
            'required' => $required,
            'visibility' => 'admin',
            'constraints' => $constraints,
        ] + $options;
    }

    /**
     * The 20-field structure. Without $withOptions every option has its default (every
     * string and text field searchable, no string indexed); with it, title and sku are
     * indexed strings and subtitle and notes leave the search (title, sku and body stay).
     *
     * @return array<string, mixed>
     */
    private static function descriptor(string $structureId, string $sectionsStructureId, bool $withOptions = false): array
    {
        $indexed = $withOptions ? ['indexed' => true] : [];
        $unsearched = $withOptions ? ['searchable' => false] : [];
        $options = static fn (array $values): array => array_map(
            static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)],
            $values,
        );

        return [
            'structure_id' => $structureId,
            'label' => 'Benchmark items',
            'fields' => [
                self::field('title', 10, 'string', true, ['max_length' => 200], $indexed),
                self::field('subtitle', 20, 'string', false, [], $unsearched),
                self::field('sku', 30, 'string', false, ['max_length' => 32], $indexed),
                self::field('body', 40, 'text'),
                self::field('notes', 50, 'text', false, [], $unsearched),
                self::field('qty', 60, 'integer', false, ['min' => 0, 'max' => 100_000]),
                self::field('rank', 70, 'integer'),
                self::field('price', 80, 'number'),
                self::field('weight', 90, 'number'),
                self::field('day', 100, 'date'),
                self::field('due_on', 110, 'date'),
                self::field('starts_at', 120, 'datetime'),
                self::field('ends_at', 125, 'datetime'),
                self::field('published_at', 130, 'datetime'),
                self::field('active', 140, 'boolean'),
                self::field('featured', 150, 'boolean'),
                self::field('status', 160, 'choice', false, ['options' => $options(self::STATUS)]),
                self::field('priority', 170, 'choice', false, ['options' => $options(self::PRIORITY)]),
                self::field('tags', 180, 'choices', false, ['options' => $options(self::TAGS), 'max_items' => 4]),
                self::field('section', 190, 'record', false, ['target_structure_id' => $sectionsStructureId]),
                self::field('owner', 200, 'user'),
            ],
        ];
    }
}
