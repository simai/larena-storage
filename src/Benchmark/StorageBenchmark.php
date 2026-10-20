<?php

declare(strict_types=1);

namespace Larena\Storage\Benchmark;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;
use Throwable;

/**
 * Measures today's Storage engine on a disposable workbench structure of about
 * twenty fields: list pages, filters, sorting, search, the matched count, and
 * single-record read, update and create. Every operation reports p50/p95/max
 * milliseconds, peak memory and the SQL query count of one call. An operation
 * the engine refuses (for example the 5000-record scan limit of a list without
 * a ready витрина) is recorded as failed with its reason; the run goes on.
 *
 * Records are seeded by writing exactly the rows the engine's create writes:
 * values normalized against the stored schema version, canonical JSON, a
 * sha256 content hash shared by the head and the first version. Seeding
 * through the engine one record at a time would take hours at 100k.
 *
 * Writes keep each structure's витрина, as in the application; the seeded
 * records get theirs from one rebuild after seeding (not timed).
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

    public function __construct(private Connection $connection, PropertyTypeRegistry $propertyTypes)
    {
        $this->runId = bin2hex(random_bytes(4));
        $this->scopeRef = 'scope:storage-benchmark-' . $this->runId;
        $access = new StorageBenchmarkAccess(self::ACTOR, $this->scopeRef);
        $events = new NullStorageSecurityEventSink();
        $cursorKey = bin2hex(random_bytes(32));
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
            new DatabaseStorageSchemaEvolution($connection, $propertyTypes, $access, $events, $policies, $this->views),
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
    public function run(array $sizes, int $repeat, bool $keep = false, ?Closure $progress = null): array
    {
        $progress ??= static function (string $line): void {
        };
        $runs = [];
        foreach ($sizes as $size) {
            $progress(sprintf('Seeding %d records...', $size));
            $runs[] = $this->runSize($size, $repeat, $keep, $progress);
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
    private function runSize(int $size, int $repeat, bool $keep, Closure $progress): array
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
        $recordIds = $this->seed($structure, $size, $sectionIds);
        $seedMs = (hrtime(true) - $seedStarted) / 1e6;
        if ($this->views->enabled()) {
            // Nothing else writes the benchmark storage, so the build does not wait for writers.
            $builder = new StorageViewSync($this->connection, new StorageViewRegistry($this->connection, 0.0), $this->views->ddl());
            (new StorageViewRebuilder($builder))->rebuild($structure->schema->schemaId, StorageViewRebuilder::DEFAULT_CHUNK, true);
            $this->views->registry()->forget($structure->schema->schemaId);
        }
        $progress(sprintf('Seeded %d records in %.1f s; measuring...', $size, $seedMs / 1000));

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
            'sort_title' => static fn (): Closure => $listing(sort: [['field' => 'title', 'direction' => 'asc']]),
            'search_text' => static fn (): Closure => $listing(search: self::SEARCH_TOKEN),
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

        $results = [];
        foreach ($operations as $name => $operation) {
            $results[$name] = $this->sample($name, $operation, $repeat);
            $progress(sprintf('  %-20s %s', $name, self::summary($results[$name])));
        }

        $cleanup = $keep ? ['kept' => true] : $this->cleanup($structure, $sections);

        return [
            'records' => $size,
            'structure_id' => $structure->structureId,
            'field_count' => count($structure->fields),
            'deep_page' => $deepPage,
            'seed_ms' => round($seedMs, 1),
            'seed_records_per_second' => $seedMs > 0 ? (int) round($size / ($seedMs / 1000)) : null,
            'operations' => $results,
            'cleanup' => $cleanup,
        ];
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
                } catch (StorageRejected $exception) {
                    // The engine refused: that is the result, not a crash of the run.
                    return [
                        'status' => 'fails',
                        'reason' => $exception->getMessage(),
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
            'matched_count' => str_starts_with($name, 'list_') || str_starts_with($name, 'filter_')
                || str_starts_with($name, 'sort_') || in_array($name, ['search_text', 'matched_count', 'matched_count_exact'], true) ? $value : null,
        ];
    }

    /**
     * @param list<string> $sectionIds
     * @return list<string>
     */
    private function seed(StorageWorkbenchStructure $structure, int $size, array $sectionIds): array
    {
        $schemaVersion = $this->storage->schemaVersion($structure->schema);
        $recordIds = [];
        for ($from = 0; $from < $size; $from += self::SEED_CHUNK) {
            $heads = [];
            $versions = [];
            $now = gmdate('Y-m-d H:i:s');
            $correlationId = 'storage-benchmark-seed-' . $this->runId;
            for ($index = $from; $index < min($size, $from + self::SEED_CHUNK); $index++) {
                $values = $this->values($index, $sectionIds);
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

        return [
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
            'starts_at' => $day . 'T' . sprintf('%02d:%02d:00', $index % 24, ($index * 7) % 60),
            'published_at' => gmdate('Y-m-d\TH:i:s', 1767225600 + ($index * 977) % 31_536_000),
            'active' => $index % 3 !== 0,
            'featured' => $index % 10 === 0,
            'status' => self::STATUS[$index % 5],
            'priority' => self::PRIORITY[$index % 3],
            'tags' => array_values(array_unique($tags)),
            'section' => $sectionIds[$index % count($sectionIds)],
            'owner' => 'user:admin:' . (1 + $index % 25),
        ];
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
     * @return array<string, mixed>
     */
    private static function field(string $key, int $position, string $type, bool $required = false, array $constraints = []): array
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
        ];
    }

    /** @return array<string, mixed> */
    private static function descriptor(string $structureId, string $sectionsStructureId): array
    {
        $options = static fn (array $values): array => array_map(
            static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)],
            $values,
        );

        return [
            'structure_id' => $structureId,
            'label' => 'Benchmark items',
            'fields' => [
                self::field('title', 10, 'string', true, ['max_length' => 200]),
                self::field('subtitle', 20, 'string'),
                self::field('sku', 30, 'string'),
                self::field('body', 40, 'text'),
                self::field('notes', 50, 'text'),
                self::field('qty', 60, 'integer', false, ['min' => 0, 'max' => 100_000]),
                self::field('rank', 70, 'integer'),
                self::field('price', 80, 'number'),
                self::field('weight', 90, 'number'),
                self::field('day', 100, 'date'),
                self::field('due_on', 110, 'date'),
                self::field('starts_at', 120, 'datetime'),
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
