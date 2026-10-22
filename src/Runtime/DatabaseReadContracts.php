<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\LocalizedValuesBatch;
use Larena\Storage\Contracts\PublishedListPage;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Contracts\PublishedListReads;
use Larena\Storage\Contracts\PublishedProjectionPage;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Contracts\ResolvedKey;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use Larena\Storage\View\Site\PublishedListCursor;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewProjector;
use Larena\Storage\View\Site\PublishedSiteViewReader;
use Larena\Storage\View\Site\PublishedSiteViewRegistration;
use Larena\Storage\View\Site\PublishedSiteViewSlot;
use Larena\Storage\View\StorageRecordQueryMatcher;
use Larena\Storage\View\StorageRecordQueryNormalizer;
use Larena\Storage\View\StorageViewCounter;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewQuery;
use Larena\Storage\View\StorageViewQueryCompiler;
use Throwable;

/**
 * The two read models a site is served from.
 *
 * Both are derived: the projection can be rebuilt from Storage at any time and no
 * consumer may treat a copy of it as a source of truth. Both read only the published
 * head, so a draft cannot reach a public reader by any path through this class.
 *
 * Three rules are load-bearing.
 *
 * A key resolves to at most one record, and two candidates fail closed. Returning the
 * first would make a site serve a different page depending on row order, which is the
 * kind of bug that survives for years.
 *
 * Only fields whose visibility is `public` appear. Visibility belongs to the field, in
 * every locale — a translated value of a protected field is still protected.
 *
 * A record the caller may not read is counted, never named.
 *
 * A page costs a constant number of queries whatever its size: versions, localized
 * values and projection versions are read with one `whereIn` per chunk of records,
 * never one query per record. A key is looked up in the published-key table when its
 * set is ready for the current locale chain, and found by a batched scan of the
 * published records otherwise.
 *
 * With a site витрина reader, a slot that answers (ready, its public fields current,
 * built along this reader's locale chain) serves `publishedProjection`,
 * `publishedRecord`, `projectionExplain` and the key lookup the published-key table
 * cannot answer: a page in three queries, a record by its key, a count, a key through
 * the key field's column. Every answer is checked against the publication states in
 * one query (the page's record ids and revisions, the record's revision, the count,
 * the holders' revisions); a disagreement is read once more in one snapshot, and if
 * it stays the slot is silenced, its rebuild queued and the request answered by the
 * exact path. Both paths return the same values, byte for byte. What the site
 * витрина is built from (`entriesForHeads`, `publishedHeadPage`, `keyValuesAt`,
 * `publicValuesAt`) always reads the versions.
 */
final class DatabaseReadContracts implements ReadContracts, PublishedListReads
{
    public const RECORDS_TABLE = 'larena_storage_records';

    public const VERSIONS_TABLE = 'larena_storage_record_versions';

    public const SCHEMA_VERSIONS_TABLE = 'larena_storage_schema_versions';

    public const DEFAULT_BUDGET = 500;

    /**
     * How many published records a key lookup reads at most. A key is found by
     * reading every published record of the scope and locale page by page; past
     * this many the lookup refuses rather than answer "not found" for a key that
     * may exist further on.
     */
    public const KEY_SCAN_LIMIT = 50_000;

    /** How many record ids an exact count through a visibility filter asks for at a time. */
    private const COUNT_PAGE = 1000;

    /** How many records one batched read asks for at most. */
    public const BATCH = 500;

    /**
     * Up to this many published records a public list is answered from them directly
     * when its site витрина slot cannot answer; above it the list refuses.
     */
    public const LIST_FALLBACK_LIMIT = 5000;

    /** How many rows one list page scans at most through a visibility filter. */
    public const LIST_VISIBILITY_SCAN = 500;

    /** The field types a public list sorts by: those with one value a column can hold. */
    private const LIST_SORTABLE_TYPES = ['string', 'text', 'integer', 'number', 'date', 'datetime', 'boolean', 'choice', 'user', 'file', 'relation', 'record'];

    private readonly PublishedKeyTable $keys;
    private readonly StorageRecordConverter $converter;
    private readonly StorageViewCounter $counter;
    private readonly StorageViewQueryCompiler $compiler;
    private bool $schemaReady = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LocalizedValues $localizedValues = null,
        private readonly LocaleFallbackResolver $localeFallback = new RequestedLocaleOnly(),
        private readonly StorageMetrics $metrics = new StorageMetrics(),
        // Reads a revision written under an older schema version under the current one.
        ?StorageRecordConverter $converter = null,
        // A ready site витрина slot answers the public reads; without it every read takes the exact path.
        private readonly ?PublishedSiteViewReader $siteViews = null,
        // How many published records a key scan reads at most before it refuses.
        private readonly int $keyScanLimit = self::KEY_SCAN_LIMIT,
        // Normalizes a public list's filter values; without it publishedList is unavailable.
        private readonly ?PropertyTypeRegistry $propertyTypes = null,
        // Signs and seals a public list's continuation (at least 32 bytes); without it publishedList is unavailable.
        private readonly string $cursorKey = '',
        // Counts a public list read from its slot: exact under a threshold, an estimate above it.
        ?StorageViewCounter $counter = null,
        // Up to this many published records a list is answered from them when its slot cannot answer.
        private readonly int $listFallbackLimit = self::LIST_FALLBACK_LIMIT,
    ) {
        $this->keys = new PublishedKeyTable($connection);
        $this->converter = $converter ?? new StorageRecordConverter($connection);
        $this->counter = $counter ?? new StorageViewCounter();
        $this->compiler = new StorageViewQueryCompiler();
    }

    /** @phpstan-impure */
    public function resolveKey(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        string $keyField,
        string $keyValue,
        ?callable $visibilityFilter = null,
    ): ?ResolvedKey {
        return $this->metrics->timeRead(function () use ($roleRefOrSchemaId, $scopeRef, $locale, $keyField, $keyValue, $visibilityFilter): ?ResolvedKey {
            $this->assertSchema();
            LocaleFallbackChain::assertLocale($locale);

            $schemaId = $this->schemaId($roleRefOrSchemaId);
            $candidates = $this->keyHolders($schemaId, $scopeRef, $locale, $keyField, $keyValue, 2);

            if ($candidates === []) {
                // A miss and a record the caller may not read are the same answer on
                // purpose: otherwise the absence of a page would confirm its existence.
                return null;
            }

            if (count($candidates) > 1) {
                throw new ReadContractRejected(
                    'ambiguous_key',
                    'Key ' . $keyField . '=' . $keyValue . ' resolves to ' . count($candidates)
                    . ' published records in ' . $scopeRef . '/' . $locale . '.',
                );
            }

            $only = $candidates[0];

            if ($visibilityFilter !== null && $visibilityFilter($only['head']['record_id']) !== true) {
                return null;
            }

            return new ResolvedKey(
                schemaId: $schemaId,
                recordId: $only['head']['record_id'],
                scopeRef: $scopeRef,
                locale: $locale,
                revision: $only['head']['revision'],
                keyField: $keyField,
                keyValue: $keyValue,
                values: $only['values'],
                valueSources: $only['sources'],
            );
        });
    }

    /** @phpstan-impure */
    public function publishedProjection(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
        ?string $afterRecordId = null,
    ): PublishedProjectionPage {
        return $this->metrics->timeRead(function () use ($roleRefOrSchemaId, $scopeRef, $locale, $budget, $visibilityFilter, $afterRecordId): PublishedProjectionPage {
            $this->assertSchema();
            LocaleFallbackChain::assertLocale($locale);

            $limit = $this->budget($budget);
            $schemaId = $this->schemaId($roleRefOrSchemaId);

            $site = $this->siteRead(
                $schemaId,
                $scopeRef,
                $locale,
                static fn (PublishedSiteViewReader $reader, array $source): ?array => $reader->page($source, $limit + 1, $afterRecordId),
                fn (array $entries): bool => self::sameHeads($entries, $this->publishedHeads($schemaId, $scopeRef, $locale, $limit + 1, $afterRecordId)),
            );
            if (is_array($site)) {
                $truncated = count($site) > $limit;
                $visible = [];
                $filtered = 0;
                foreach (array_slice($site, 0, $limit) as $entry) {
                    if ($visibilityFilter !== null && $visibilityFilter($entry['record_id']) !== true) {
                        ++$filtered;

                        continue;
                    }
                    $visible[] = $entry;
                }

                return new PublishedProjectionPage($visible, $filtered, $truncated, $limit);
            }

            $publicFields = $this->publicFieldKeys($schemaId);

            $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, $limit + 1, $afterRecordId);
            $truncated = count($heads) > $limit;
            $heads = array_slice($heads, 0, $limit);

            $visible = [];
            $filtered = 0;

            foreach ($heads as $head) {
                if ($visibilityFilter !== null && $visibilityFilter($head['record_id']) !== true) {
                    ++$filtered;

                    continue;
                }

                $visible[] = $head;
            }

            return new PublishedProjectionPage(
                $this->projectionEntries($schemaId, $scopeRef, $locale, $visible, $publicFields),
                $filtered,
                $truncated,
                $limit,
            );
        });
    }

    /** @phpstan-impure */
    public function publishedRecord(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        string $recordId,
        ?callable $visibilityFilter = null,
    ): ?array {
        return $this->metrics->timeRead(function () use ($roleRefOrSchemaId, $scopeRef, $locale, $recordId, $visibilityFilter): ?array {
            $this->assertSchema();
            LocaleFallbackChain::assertLocale($locale);

            $schemaId = $this->schemaId($roleRefOrSchemaId);
            $site = $this->siteRead(
                $schemaId,
                $scopeRef,
                $locale,
                static fn (PublishedSiteViewReader $reader, array $source): ?array => $reader->record($source, $recordId),
                fn (array $entries): bool => self::sameHeads($entries, $this->publishedHeads($schemaId, $scopeRef, $locale, 1, null, [$recordId])),
            );
            if (is_array($site)) {
                if ($site === [] || ($visibilityFilter !== null && $visibilityFilter($recordId) !== true)) {
                    return null;
                }

                return $site[0];
            }

            $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, 1, null, [$recordId]);
            if ($heads === [] || ($visibilityFilter !== null && $visibilityFilter($recordId) !== true)) {
                return null;
            }

            return $this->projectionEntries($schemaId, $scopeRef, $locale, $heads, $this->publicFieldKeys($schemaId))[0] ?? null;
        });
    }

    /**
     * One page of a public list (PublishedListReads).
     *
     * A ready slot of the site витрина answers in SQL (StorageViewQueryCompiler onto the
     * slot, keyset paging, StorageViewCounter), each page checked against the
     * publication states as every slot read is; any other storage is listed from its
     * published records in PHP, with the same filters, search and order, while it has at
     * most LIST_FALLBACK_LIMIT of them, and refused (`site_view_not_ready`) above.
     *
     * @phpstan-impure
     */
    public function publishedList(PublishedListQuery $query, ?callable $visibilityFilter = null): PublishedListPage
    {
        $started = hrtime(true);
        $mode = 'none';
        try {
            $page = $this->listPublished($query, $visibilityFilter, $mode);
        } catch (Throwable $exception) {
            $outcome = $exception instanceof ReadContractRejected || $exception instanceof \InvalidArgumentException ? 'rejected' : StorageMetrics::outcomeOf($exception);
            $this->metrics->publishedList($mode, null, $outcome, StorageMetrics::elapsedMs($started));
            throw $exception;
        }
        $count = $page->matchedCount === null ? 'skip' : ($page->countExact ? 'exact' : 'approx');
        $this->metrics->publishedList($mode, $count, 'ok', StorageMetrics::elapsedMs($started));

        return $page;
    }

    /**
     * @param 'none'|'slot'|'fallback' $mode Set to the path the list took.
     * @phpstan-impure
     */
    private function listPublished(PublishedListQuery $query, ?callable $visibilityFilter, string &$mode): PublishedListPage
    {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($query->locale);
        if ($this->propertyTypes === null || strlen($this->cursorKey) < 32) {
            throw new ReadContractRejected('published_list_unavailable', 'Public lists need the property types and a cursor key.');
        }
        if ($query->limit < 1 || $query->limit > PublishedListQuery::MAX_LIMIT) {
            throw self::invalidQuery();
        }
        $schemaId = $this->schemaId($query->target);
        // The answering slot and the schema it was checked against, in one query; without it the schema is read alone.
        $answering = $this->siteViews?->answering($schemaId, $query->scopeRef, $query->locale, $this->localeChainKey($query->locale));
        $list = $answering === null
            ? $this->listFields($schemaId)
            : self::listFieldsOf($answering['fields'], $answering['options']);
        try {
            $normalized = (new StorageRecordQueryNormalizer($this->propertyTypes))
                ->normalize($list['fields'], $query->filters, $query->search, $query->sort, false, PublishedListQuery::SYSTEM_SORTS);
        } catch (StorageRejected) {
            // One answer for a field that does not exist, one that is not public and a
            // value that is wrong: the list never says which fields a structure hides.
            throw self::invalidQuery();
        }
        foreach ($normalized['sort'] as $item) {
            $type = $list['types'][$item['field']] ?? null;
            if ($type !== null && !in_array($type, self::LIST_SORTABLE_TYPES, true)) {
                throw self::invalidQuery();
            }
        }
        $queryHash = hash('sha256', PublishedListCursor::canonicalJson(['filters' => $normalized['filters'], 'search' => $normalized['search'], 'sort' => $normalized['sort']]));
        $slotHash = hash('sha256', PublishedListCursor::canonicalJson([
            'read' => 'storage.read.published_list',
            'slot' => PublishedSiteViewSlot::keyOf($schemaId, $query->scopeRef, $query->locale),
        ]));
        $cursor = new PublishedListCursor($this->cursorKey);
        $position = $query->continuation === null
            ? ['after' => null, 'offset' => 0]
            : $cursor->decode($query->continuation, $queryHash, $slotHash, count($normalized['sort']));

        if ($normalized['search'] !== null && $list['searchable'] === []) {
            // Nothing public is searched: no record can match.
            return new PublishedListPage([], null, $query->countMode === StorageWorkbenchCountMode::Skip ? null : 0, $query->countMode !== StorageWorkbenchCountMode::Skip);
        }

        $page = $answering === null ? null : $this->listFromSlot(
            $schemaId,
            ['registration' => $answering['registration'], 'slot' => $answering['slot']],
            $query,
            $normalized,
            $list,
            $position,
            $visibilityFilter,
            $cursor,
            $queryHash,
            $slotHash,
        );
        if ($page !== null) {
            $mode = 'slot';

            return $page;
        }
        $mode = 'fallback';

        return $this->listFromRecords($schemaId, $query, $normalized, $list, $position, $visibilityFilter, $cursor, $queryHash, $slotHash);
    }

    /**
     * The public fields of a storage's current schema a list may name, by key, each with
     * the `searchable` flag of its витрина options; their types; and the searchable ones.
     *
     * @return array{fields: array<string, array<string, mixed>>, types: array<string, string>, searchable: list<string>}
     * @phpstan-impure
     */
    private function listFields(string $schemaId): array
    {
        $row = $this->connection->table(self::SCHEMA_VERSIONS_TABLE)->where('schema_id', $schemaId)->orderByDesc('version')->first(['definition']);
        $definition = $row === null ? null : PublishedSiteViewIndex::definitionFields(((array) $row)['definition']);
        if ($definition === null) {
            // No schema: nothing is public, and any field a query names is unknown.
            return ['fields' => [], 'types' => [], 'searchable' => []];
        }

        return self::listFieldsOf($definition, StorageViewFieldOptions::load($this->connection, $schemaId));
    }

    /**
     * @param list<array<string, mixed>> $definition The fields of a schema version.
     * @return array{fields: array<string, array<string, mixed>>, types: array<string, string>, searchable: list<string>}
     */
    private static function listFieldsOf(array $definition, ?StorageViewFieldOptions $options): array
    {
        $searchable = PublishedSiteViewProjector::options($definition, $options)->searchable;
        $fields = [];
        $types = [];
        foreach (PublishedSiteViewProjector::publicFields($definition) as $field) {
            $key = (string) $field['key'];
            $field[StorageViewFieldOptions::SEARCHABLE] = in_array($key, $searchable, true);
            $fields[$key] = $field;
            $types[$key] = is_string($field['type'] ?? null) ? $field['type'] : '';
        }

        return ['fields' => $fields, 'types' => $types, 'searchable' => $searchable];
    }

    /**
     * The page from the answering slot, or null when it cannot answer (no ready slot,
     * a query its columns cannot answer exactly, a page that disagrees with the
     * publication states): the caller lists from the published records then.
     *
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $normalized
     * @param array{fields: array<string, array<string, mixed>>, types: array<string, string>, searchable: list<string>} $list
     * @param array{after: array{values: list<int|string|null>, record_id: string}|null, offset: int} $position
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source
     * @phpstan-impure
     */
    private function listFromSlot(
        string $schemaId,
        array $source,
        PublishedListQuery $query,
        array $normalized,
        array $list,
        array $position,
        ?callable $visibilityFilter,
        PublishedListCursor $cursor,
        string $queryHash,
        string $slotHash,
    ): ?PublishedListPage {
        $reader = $this->siteViews;
        if ($reader === null) {
            return null;
        }
        try {
            $compiled = $this->compiler->compileLayout($reader->connection(), $source['registration']->tableName, $source['registration']->layout, $list['fields'], $normalized, $source['slot']->id);
        } catch (Throwable) {
            return null;
        }
        if ($compiled === null) {
            return null;
        }
        $scopeRef = $query->scopeRef;
        $locale = $query->locale;
        $after = $position['after'];
        $offset = $position['offset'];
        $records = [];
        $hidden = 0;
        $scanned = 0;
        $last = null;
        $more = false;
        do {
            // A keyset page reads after the last row; a position page (a key too long to carry) from its offset.
            $batchAfter = $after;
            $batchOffset = $after === null ? $offset + $scanned : 0;
            $rows = $this->siteRead(
                $schemaId,
                $scopeRef,
                $locale,
                static function (PublishedSiteViewReader $reader, array $source) use ($compiled, $query, $batchAfter, $batchOffset): ?array {
                    $ids = $compiled->page($query->limit, $batchOffset, $batchAfter);
                    $listed = $reader->listed($source, array_column($ids, 'record_id'));
                    if ($listed === null) {
                        return null;
                    }
                    $rows = [];
                    foreach ($ids as $id) {
                        $rows[] = ['record_id' => $id['record_id'], 'keys' => $id['keys'], 'listed' => $listed[$id['record_id']] ?? null];
                    }

                    return $rows;
                },
                fn (array $rows): bool => $this->listAgrees($schemaId, $scopeRef, $locale, $rows),
                $source,
            );
            if ($rows === null) {
                return null;
            }
            $more = count($rows) > $query->limit;
            $rows = array_slice($rows, 0, $query->limit);
            foreach ($rows as $index => $row) {
                $scanned++;
                $last = $row;
                $listed = $row['listed'];
                $entry = is_array($listed) ? ($listed['entry'] ?? null) : null;
                if (!is_array($entry)) {
                    // Every row of an agreeing page has its entry.
                    return null;
                }
                if ($visibilityFilter !== null && $visibilityFilter((string) $row['record_id']) !== true) {
                    $hidden++;
                } else {
                    $records[] = $entry;
                }
                if (count($records) === $query->limit) {
                    $more = $more || $index < count($rows) - 1;
                    break 2;
                }
            }
            if ($last !== null) {
                $after = ['values' => $last['keys'], 'record_id' => (string) $last['record_id']];
            }
        } while ($more && $visibilityFilter !== null && $scanned < self::LIST_VISIBILITY_SCAN);

        $passed = $offset + $scanned;
        $continuation = $more && $last !== null
            ? $cursor->encode($queryHash, $slotHash, $last['keys'], (string) $last['record_id'], $passed)
            : null;
        if ($visibilityFilter !== null || $query->countMode === StorageWorkbenchCountMode::Skip) {
            return new PublishedListPage($records, $continuation, null, false, $hidden);
        }
        $fromStart = $position['after'] === null && $position['offset'] === 0;
        if ($query->countMode === StorageWorkbenchCountMode::Auto && !$more && $fromStart) {
            // The only page holds every match.
            return new PublishedListPage($records, $continuation, $scanned, true, 0);
        }
        try {
            $count = $this->counter->count($compiled, $query->countMode);
        } catch (Throwable $exception) {
            throw new ReadContractRejected('site_view_not_ready', 'The list could not be counted: ' . $exception->getMessage());
        }
        // An estimate is never below what the list has already shown.
        $matched = $count['exact'] ? $count['count'] : max($count['count'], $passed + ($more ? 1 : 0));

        return new PublishedListPage($records, $continuation, $matched, $count['exact'], 0);
    }

    /**
     * Whether a slot page agrees with the publication states: every row has its entry,
     * and each is the published revision of its record, published when the row says.
     *
     * @param list<array<string, mixed>> $rows
     * @phpstan-impure
     */
    private function listAgrees(string $schemaId, string $scopeRef, string $locale, array $rows): bool
    {
        $states = $this->publishedStates($schemaId, $scopeRef, $locale, array_map('strval', array_column($rows, 'record_id')));
        foreach ($rows as $row) {
            $listed = $row['listed'] ?? null;
            $entry = is_array($listed) ? ($listed['entry'] ?? null) : null;
            $state = $states[(string) $row['record_id']] ?? null;
            if ($state === null || !is_array($listed) || !is_array($entry)
                || ($entry['revision'] ?? null) !== $state['revision']
                || ($listed['published_at'] ?? null) !== $state['published_at']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The page from the published records themselves, for a storage whose slot cannot
     * answer: at most LIST_FALLBACK_LIMIT published records, read in batches, filtered,
     * searched and sorted in PHP with the semantics the slot compiles to SQL, and paged
     * by the same keys.
     *
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $normalized
     * @param array{fields: array<string, array<string, mixed>>, types: array<string, string>, searchable: list<string>} $list
     * @param array{after: array{values: list<int|string|null>, record_id: string}|null, offset: int} $position
     * @phpstan-impure
     */
    private function listFromRecords(
        string $schemaId,
        PublishedListQuery $query,
        array $normalized,
        array $list,
        array $position,
        ?callable $visibilityFilter,
        PublishedListCursor $cursor,
        string $queryHash,
        string $slotHash,
    ): PublishedListPage {
        $total = $this->publishedCount($schemaId, $query->scopeRef, $query->locale);
        if ($total > $this->listFallbackLimit) {
            throw new ReadContractRejected(
                'site_view_not_ready',
                'More than ' . $this->listFallbackLimit . ' published records in ' . $query->scopeRef . '/' . $query->locale . ' and no ready site витрина slot to list them from.',
            );
        }
        $heads = [];
        $afterId = null;
        do {
            $page = $this->publishedHeads($schemaId, $query->scopeRef, $query->locale, self::BATCH, $afterId);
            array_push($heads, ...$page);
            $afterId = $page === [] ? null : $page[array_key_last($page)]['record_id'];
            if (count($heads) > $this->listFallbackLimit) {
                throw new ReadContractRejected('site_view_not_ready', 'Too many published records to list without a ready site витрина slot.');
            }
        } while (count($page) === self::BATCH);

        $sort = $normalized['sort'];
        $byPublication = false;
        foreach ($sort as $item) {
            $byPublication = $byPublication || $item['field'] === PublishedListQuery::SORT_PUBLISHED_AT;
        }
        $published = $byPublication ? $this->publishedStates($schemaId, $query->scopeRef, $query->locale, array_column($heads, 'record_id')) : [];

        $matched = [];
        foreach ($this->projectionEntries($schemaId, $query->scopeRef, $query->locale, $heads, $this->publicFieldKeys($schemaId)) as $entry) {
            if (!StorageRecordQueryMatcher::filtersMatch($entry['values'], $normalized['filters'], $list['types'])) {
                continue;
            }
            if ($normalized['search'] !== null && !StorageRecordQueryMatcher::searchMatches($entry['values'], $normalized['search'], $list['searchable'])) {
                continue;
            }
            $keys = [];
            foreach ($sort as $item) {
                $keys[] = match ($item['field']) {
                    PublishedListQuery::SORT_RECORD_ID => $entry['record_id'],
                    PublishedListQuery::SORT_PUBLISHED_AT => $published[$entry['record_id']]['published_at'] ?? null,
                    default => self::listKey($entry['values'][$item['field']] ?? null, $list['types'][$item['field']] ?? ''),
                };
            }
            $matched[] = ['entry' => $entry, 'keys' => $keys];
        }
        $types = [];
        foreach ($sort as $item) {
            $types[] = in_array($item['field'], PublishedListQuery::SYSTEM_SORTS, true) ? 'string' : ($list['types'][$item['field']] ?? '');
        }
        $compare = static fn (array $leftKeys, string $leftId, array $rightKeys, string $rightId): int => self::compareListRows($sort, $types, $leftKeys, $leftId, $rightKeys, $rightId);
        usort($matched, static fn (array $left, array $right): int => $compare($left['keys'], $left['entry']['record_id'], $right['keys'], $right['entry']['record_id']));

        $start = 0;
        $after = $position['after'];
        if ($after !== null) {
            while ($start < count($matched) && $compare($matched[$start]['keys'], $matched[$start]['entry']['record_id'], $after['values'], $after['record_id']) <= 0) {
                $start++;
            }
        } else {
            $start = min($position['offset'], count($matched));
        }
        $records = [];
        $hidden = 0;
        $scanned = 0;
        $last = null;
        $budget = $visibilityFilter === null ? $query->limit : max($query->limit, self::LIST_VISIBILITY_SCAN);
        for ($index = $start; $index < count($matched) && $scanned < $budget && count($records) < $query->limit; $index++) {
            $row = $matched[$index];
            $scanned++;
            $last = $row;
            if ($visibilityFilter !== null && $visibilityFilter($row['entry']['record_id']) !== true) {
                $hidden++;

                continue;
            }
            $records[] = $row['entry'];
        }
        $more = $start + $scanned < count($matched);
        $passed = ($after === null ? $start : $position['offset']) + $scanned;
        $continuation = $more && $last !== null
            ? $cursor->encode($queryHash, $slotHash, $last['keys'], $last['entry']['record_id'], $passed)
            : null;
        if ($visibilityFilter !== null || $query->countMode === StorageWorkbenchCountMode::Skip) {
            return new PublishedListPage($records, $continuation, null, false, $hidden);
        }

        return new PublishedListPage($records, $continuation, count($matched), true, 0);
    }

    /**
     * A sort key of an entry value as the site row's column holds it, so a page read
     * from the records and one read from the slot continue each other: integers and
     * booleans as integers, datetimes with a space, anything else as its string.
     */
    private static function listKey(mixed $value, string $type): int|string|null
    {
        return match (true) {
            $value === null, is_array($value) => null,
            is_bool($value) => $value ? 1 : 0,
            is_int($value) => $value,
            $type === 'datetime' && is_string($value) => str_replace('T', ' ', $value),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * Two rows of a list in its order: each sort key (null first ascending, integers and
     * booleans by value, numbers as decimals, anything else as strings), then the record
     * id, in the direction of the last key.
     *
     * @param list<array{field: string, direction: string}> $sort
     * @param list<string> $types
     * @param list<int|string|null> $leftKeys
     * @param list<int|string|null> $rightKeys
     */
    private static function compareListRows(array $sort, array $types, array $leftKeys, string $leftId, array $rightKeys, string $rightId): int
    {
        foreach ($sort as $position => $item) {
            $left = $leftKeys[$position] ?? null;
            $right = $rightKeys[$position] ?? null;
            $type = $types[$position] ?? '';
            $comparison = match (true) {
                $left === $right => 0,
                $left === null => -1,
                $right === null => 1,
                in_array($type, ['integer', 'boolean'], true) => (int) $left <=> (int) $right,
                $type === 'number' => StorageRecordQueryMatcher::compareDecimals((string) $left, (string) $right),
                default => strcmp((string) $left, (string) $right),
            };
            if ($comparison !== 0) {
                return $item['direction'] === 'desc' ? -$comparison : $comparison;
            }
        }
        $tiebreak = strcmp($leftId, $rightId);

        return ($sort[count($sort) - 1]['direction'] ?? 'asc') === 'desc' ? -$tiebreak : $tiebreak;
    }

    private static function invalidQuery(): ReadContractRejected
    {
        return new ReadContractRejected('invalid_query', 'The list query is invalid.');
    }

    /**
     * The ids of the published records that answer to a key in a scope and locale,
     * found among every published record — not only the first page.
     *
     * @return list<string>
     * @phpstan-impure
     */
    public function keyHolderIds(string $roleRefOrSchemaId, string $scopeRef, string $locale, string $keyField, string $keyValue): array
    {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        return array_map(
            static fn (array $candidate): string => $candidate['head']['record_id'],
            $this->keyHolders($this->schemaId($roleRefOrSchemaId), $scopeRef, $locale, $keyField, $keyValue, PHP_INT_MAX),
        );
    }

    /**
     * The public values one revision would show in a locale, published or not:
     * what a key would be if this revision were published.
     *
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function publicValuesAt(string $schemaId, string $recordId, int $revision, string $locale): array
    {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        $head = ['record_id' => $recordId, 'revision' => $revision];

        return $this->valuesOf($schemaId, [$head], $locale, $this->publicFieldKeys($schemaId))[$recordId]['values'] ?? [];
    }

    /**
     * The value each revision shows for one field in a locale, translations along
     * the fallback chain included, whatever the field's visibility: what the
     * published-key table stores. A value that is not a string is no key.
     *
     * @param list<array{record_id: string, revision: int}> $heads
     * @return array<string, string> by record id, only the records that have one
     * @phpstan-impure
     */
    public function keyValuesAt(string $schemaId, array $heads, string $locale, string $keyField): array
    {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);
        $this->assertKeyFieldPlain($schemaId, $keyField);

        $resolved = $this->valuesOf($schemaId, $heads, $locale, [$keyField]);
        $keys = [];
        foreach ($heads as $head) {
            $value = $resolved[$head['record_id']]['values'][$keyField] ?? null;
            if (is_string($value)) {
                $keys[$head['record_id']] = $value;
            }
        }

        return $keys;
    }

    /** The locale chain a read in this locale resolves along. */
    public function localeChain(string $locale): LocaleFallbackChain
    {
        return $this->localeFallback->chainFor($locale);
    }

    /** The locale chain as the published-key table records it, so that a change of it is seen. */
    public function localeChainKey(string $locale): string
    {
        return implode(',', $this->localeChain($locale)->locales);
    }

    /**
     * One page of the published heads of a structure in a scope and locale, after a
     * record id: what the published-key table is built from.
     *
     * @return list<array{record_id: string, revision: int}>
     * @phpstan-impure
     */
    public function publishedHeadPage(string $schemaId, string $scopeRef, string $locale, int $limit, ?string $afterRecordId = null, bool $lock = false): array
    {
        return $this->publishedHeads($schemaId, $scopeRef, $locale, $limit, $afterRecordId, null, $lock);
    }

    /**
     * The projection entries of these published heads in a scope and locale, exactly as
     * `publishedProjection` and `publishedRecord` return them, in the heads' order. For
     * the site витрина, which keeps a copy of each entry: with `$lock` every row is read
     * with a shared lock inside the caller's transaction, so the entry is built from the
     * latest committed rows and none of them changes before the transaction ends.
     *
     * @internal
     * @param list<array{record_id: string, revision: int}> $heads
     * @return list<array{record_id: string, revision: int, locale: string, projection_version: int, values: array<string, mixed>, value_sources: array<string, string>}>
     * @phpstan-impure
     */
    public function entriesForHeads(string $schemaId, string $scopeRef, string $locale, array $heads, bool $lock = false): array
    {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        return $this->projectionEntries($schemaId, $scopeRef, $locale, $heads, $this->publicFieldKeys($schemaId, $lock), $lock);
    }

    /**
     * The published revision of each of these records in a scope and locale; a
     * record that is not published there is absent.
     *
     * @param list<string> $recordIds
     * @return array<string, int>
     * @phpstan-impure
     */
    public function publishedRevisions(string $schemaId, string $scopeRef, string $locale, array $recordIds): array
    {
        $revisions = [];
        foreach (array_chunk($recordIds, self::BATCH) as $chunk) {
            foreach ($this->publishedHeads($schemaId, $scopeRef, $locale, count($chunk), null, $chunk) as $head) {
                $revisions[$head['record_id']] = $head['revision'];
            }
        }

        return $revisions;
    }

    /**
     * The published revision of each of these records in a scope and locale and when
     * it was published there; a record that is not published there is absent. With
     * `$lock`, read with a shared lock inside the caller's transaction (what the site
     * витрина writes from).
     *
     * @internal
     * @param list<string> $recordIds
     * @return array<string, array{revision: int, published_at: ?string}>
     * @phpstan-impure
     */
    public function publishedStates(string $schemaId, string $scopeRef, string $locale, array $recordIds, bool $lock = false): array
    {
        $states = [];
        foreach (array_chunk(array_values(array_unique($recordIds)), self::BATCH) as $chunk) {
            $query = $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
                ->select(['record_id', 'published_revision', 'published_at'])
                ->where('schema_id', $schemaId)
                ->where('scope_ref', $scopeRef)
                ->where('locale', $locale)
                ->where('state', PublicationStateValue::Published->value)
                ->whereNotNull('published_revision')
                ->whereIn('record_id', $chunk);
            if ($lock) {
                $query->sharedLock();
            }
            foreach ($query->get() as $row) {
                $row = (array) $row;
                $states[(string) $row['record_id']] = [
                    'revision' => (int) $row['published_revision'],
                    'published_at' => $row['published_at'] === null ? null : (string) $row['published_at'],
                ];
            }
        }

        return $states;
    }

    /**
     * When each of these records was published in a scope and locale, as an ISO-8601
     * UTC string (`2026-10-22T09:30:00+00:00`), read in one query per BATCH of ids.
     *
     * The time is that of the transition that made the current head published: an
     * editor's publish, or the sweep promoting a scheduled (possibly newer) revision.
     * A record that is not published there, or whose state carries no time, is absent.
     * It is an addition beside `publishedProjection`, whose entries keep their shape,
     * and it answers from the publication states, not from a projection: the caller
     * applies the same visibility filter it applied to the projection it pairs it with
     * (a sitemap's `lastmod`, for instance).
     *
     * @param list<string> $recordIds
     * @return array<string, string> record id => published at
     * @phpstan-impure
     */
    public function publishedTimes(string $roleRefOrSchemaId, string $scopeRef, string $locale, array $recordIds): array
    {
        return $this->metrics->timeRead(function () use ($roleRefOrSchemaId, $scopeRef, $locale, $recordIds): array {
            $this->assertSchema();
            LocaleFallbackChain::assertLocale($locale);
            if ($recordIds === []) {
                return [];
            }

            $times = [];
            foreach ($this->publishedStates($this->schemaId($roleRefOrSchemaId), $scopeRef, $locale, $recordIds) as $recordId => $state) {
                $time = self::isoUtc($state['published_at']);
                if ($time !== null) {
                    $times[$recordId] = $time;
                }
            }

            return $times;
        });
    }

    /** A stored UTC time (`Y-m-d H:i:s`) as ISO-8601; null when there is none or it does not parse. */
    private static function isoUtc(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        try {
            $moment = new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Each scope and locale where one revision of a record is the published head.
     *
     * @return list<array{scope_ref: string, locale: string}>
     * @phpstan-impure
     */
    public function publishedPlacements(string $schemaId, string $recordId, int $revision): array
    {
        $placements = [];
        foreach ($this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->select(['scope_ref', 'locale'])
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('state', PublicationStateValue::Published->value)
            ->where('published_revision', $revision)
            ->orderBy('scope_ref')
            ->orderBy('locale')
            ->get() as $row) {
            $row = (array) $row;
            $placements[] = ['scope_ref' => (string) $row['scope_ref'], 'locale' => (string) $row['locale']];
        }

        return $placements;
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function projectionExplain(string $roleRefOrSchemaId, string $scopeRef, string $locale, ?callable $visibilityFilter = null): array
    {
        $this->assertSchema();

        $schemaId = $this->schemaId($roleRefOrSchemaId);
        [$visible, $total] = $this->siteCount($schemaId, $scopeRef, $locale, $visibilityFilter) ?? [null, null];
        if ($visible === null || $total === null) {
            // The exact path counts the first page of published records.
            $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, self::DEFAULT_BUDGET);
            $total = count($heads);
            $visible = $visibilityFilter === null
                ? $total
                : count(array_filter($heads, static fn (array $head): bool => $visibilityFilter($head['record_id']) === true));
        }

        return [
            'schema_id' => $schemaId,
            'scope_ref' => $scopeRef,
            'locale' => $locale,
            'public_field_keys' => $this->publicFieldKeys($schemaId),
            'published_record_count' => $visible,
            'filtered_record_count' => $total - $visible,
            'budget' => self::DEFAULT_BUDGET,
            'derived' => true,
            'note' => 'the projection is rebuildable from Storage; no consumer may treat a copy of it as a source of truth',
        ];
    }

    /**
     * The projection entries of a page of heads, in their order: one versions read,
     * one localized read and two projection-version reads per chunk, whatever the
     * page size.
     *
     * @param list<array{record_id: string, revision: int}> $heads
     * @param list<string> $publicFields
     * @return list<array{record_id: string, revision: int, locale: string, projection_version: int, values: array<string, mixed>, value_sources: array<string, string>}>
     * @phpstan-impure
     */
    private function projectionEntries(string $schemaId, string $scopeRef, string $locale, array $heads, array $publicFields, bool $lock = false): array
    {
        if ($heads === []) {
            return [];
        }

        $resolved = $this->valuesOf($schemaId, $heads, $locale, $publicFields, $lock);
        $versions = $this->projectionVersions($schemaId, $scopeRef, $locale, array_column($heads, 'record_id'), $lock);

        $entries = [];
        foreach ($heads as $head) {
            $entries[] = [
                'record_id' => $head['record_id'],
                'revision' => $head['revision'],
                'locale' => $locale,
                'projection_version' => $versions[$head['record_id']] ?? 1,
                'values' => $resolved[$head['record_id']]['values'] ?? [],
                'value_sources' => $resolved[$head['record_id']]['sources'] ?? [],
            ];
        }

        return $entries;
    }

    /**
     * What `ProjectionVersion::of` gives for each record, read for the whole page at
     * once: the newest log row of each publication plus the newest localized value
     * of the record in the locale, of any revision.
     *
     * @param list<string> $recordIds
     * @return array<string, int>
     * @phpstan-impure
     */
    private function projectionVersions(string $schemaId, string $scopeRef, string $locale, array $recordIds, bool $lock = false): array
    {
        $localizedTable = $this->connection->getSchemaBuilder()->hasTable(DatabaseLocalizedValues::TABLE);
        $versions = [];

        foreach (array_chunk($recordIds, self::BATCH) as $chunk) {
            $publications = [];
            foreach ($chunk as $recordId) {
                $publications[PublicationState::identity($schemaId, $recordId, $scopeRef, $locale)] = $recordId;
            }

            $transitions = [];
            $log = $this->connection->table(DatabasePublicationLifecycle::LOG_TABLE)
                ->select('publication_id')
                ->selectRaw('MAX(id) AS newest')
                ->whereIn('publication_id', array_keys($publications))
                ->groupBy('publication_id');
            if ($lock) {
                $log->sharedLock();
            }
            foreach ($log->get() as $row) {
                $row = (array) $row;
                $recordId = $publications[(string) $row['publication_id']] ?? null;
                if ($recordId !== null) {
                    $transitions[$recordId] = (int) $row['newest'];
                }
            }

            $localized = [];
            if ($localizedTable) {
                $newest = $this->connection->table(DatabaseLocalizedValues::TABLE)
                    ->select('record_id')
                    ->selectRaw('MAX(id) AS newest')
                    ->where('schema_id', $schemaId)
                    ->where('locale', $locale)
                    ->whereIn('record_id', $chunk)
                    ->groupBy('record_id');
                if ($lock) {
                    $newest->sharedLock();
                }
                foreach ($newest->get() as $row) {
                    $row = (array) $row;
                    $localized[(string) $row['record_id']] = (int) $row['newest'];
                }
            }

            foreach ($chunk as $recordId) {
                $versions[$recordId] = max(1, ($transitions[$recordId] ?? 0) + ($localized[$recordId] ?? 0));
            }
        }

        return $versions;
    }

    /**
     * The published heads of one schema in one scope and locale.
     *
     * Only `published` counts. A scheduled record is not published until the sweep
     * runs, and an archived one is not published any more.
     *
     * @param list<string>|null $recordIds only these records
     * @return list<array{record_id: string, revision: int}>
     * @phpstan-impure
     */
    private function publishedHeads(string $schemaId, string $scopeRef, string $locale, int $limit, ?string $afterRecordId = null, ?array $recordIds = null, bool $lock = false): array
    {
        $query = $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->select(['record_id', 'published_revision'])
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->where('state', PublicationStateValue::Published->value)
            ->whereNotNull('published_revision');
        if ($afterRecordId !== null) {
            $query->where('record_id', '>', $afterRecordId);
        }
        if ($recordIds !== null) {
            $query->whereIn('record_id', $recordIds);
        }
        $query->orderBy('record_id')->limit($limit);
        if ($lock) {
            $query->sharedLock();
        }
        $rows = $query->get();

        $heads = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $heads[] = [
                'record_id' => (string) $row['record_id'],
                'revision' => (int) $row['published_revision'],
            ];
        }

        return $heads;
    }

    /**
     * Published records whose public key field equals the value.
     *
     * The published-key table answers when its set for the structure, scope, locale
     * and key field is ready and was built along the same locale chain, and its
     * answer is checked against the head and the values before it is trusted.
     * Otherwise the whole published set is read page by page, each page in a
     * constant number of queries, stopping once enough are found.
     *
     * @return list<array{head: array{record_id: string, revision: int}, values: array<string, mixed>, sources: array<string, string>}>
     * @phpstan-impure
     */
    private function keyHolders(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue, int $stopAfter): array
    {
        $publicFields = $this->publicFieldKeys($schemaId);
        if (!in_array($keyField, $publicFields, true)) {
            // An encrypted field (never public) is refused outright: it never is a key.
            $this->assertKeyFieldPlain($schemaId, $keyField);
            // Only a public field is a key a reader may route by: no record answers
            // to any other, because no reader sees its value.
            return [];
        }

        $indexed = $this->indexedKeyHolders($schemaId, $scopeRef, $locale, $keyField, $keyValue, $publicFields);
        if ($indexed !== null) {
            return array_slice($indexed, 0, $stopAfter);
        }

        // The site витрина finds the key through its column, however many records are published.
        $site = $this->siteRead(
            $schemaId,
            $scopeRef,
            $locale,
            static fn (PublishedSiteViewReader $reader, array $source): ?array => $reader->keyHolders($source, $keyField, $keyValue, $stopAfter),
            function (array $entries) use ($schemaId, $scopeRef, $locale): bool {
                $published = $this->publishedRevisions($schemaId, $scopeRef, $locale, array_column($entries, 'record_id'));
                foreach ($entries as $entry) {
                    if (($published[$entry['record_id']] ?? null) !== $entry['revision']) {
                        return false;
                    }
                }

                return true;
            },
        );
        if (is_array($site)) {
            return array_map(static fn (array $entry): array => [
                'head' => ['record_id' => (string) $entry['record_id'], 'revision' => (int) $entry['revision']],
                'values' => $entry['values'],
                'sources' => $entry['value_sources'],
            ], $site);
        }

        $holders = [];
        $after = null;
        $scanned = 0;

        do {
            $page = $this->publishedHeads($schemaId, $scopeRef, $locale, self::DEFAULT_BUDGET, $after);
            $resolved = $this->valuesOf($schemaId, $page, $locale, $publicFields);
            foreach ($page as $head) {
                $values = $resolved[$head['record_id']] ?? ['values' => [], 'sources' => []];
                if (($values['values'][$keyField] ?? null) === $keyValue) {
                    $holders[] = ['head' => $head, 'values' => $values['values'], 'sources' => $values['sources']];
                    if (count($holders) >= $stopAfter) {
                        return $holders;
                    }
                }
            }
            $scanned += count($page);
            if ($scanned > $this->keyScanLimit) {
                throw new ReadContractRejected(
                    'key_scan_limit_exceeded',
                    'More than ' . $this->keyScanLimit . ' published records in ' . $scopeRef . '/' . $locale . '; a key lookup refuses rather than miss.',
                );
            }
            $after = $page === [] ? null : $page[array_key_last($page)]['record_id'];
        } while (count($page) === self::DEFAULT_BUDGET);

        return $holders;
    }

    /**
     * The key holders from the published-key table, or null when the table cannot
     * answer: its set is missing, being built, holds a conflict, was built along
     * another locale chain, or names a holder that no longer shows this key.
     *
     * @param list<string> $publicFields
     * @return list<array{head: array{record_id: string, revision: int}, values: array<string, mixed>, sources: array<string, string>}>|null
     * @phpstan-impure
     */
    private function indexedKeyHolders(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue, array $publicFields): ?array
    {
        if ($keyValue === '') {
            // An empty key is never stored; only the scan can say who holds it.
            return null;
        }

        $rows = $this->keys->holders($schemaId, $scopeRef, $locale, $keyField, $keyValue, $this->localeChainKey($locale));
        if ($rows === null) {
            return null;
        }
        if ($rows === []) {
            return [];
        }

        $published = $this->publishedRevisions($schemaId, $scopeRef, $locale, array_column($rows, 'record_id'));
        foreach ($rows as $row) {
            if (($published[$row['record_id']] ?? null) !== $row['revision']) {
                return null;
            }
        }

        $resolved = $this->valuesOf($schemaId, $rows, $locale, $publicFields);
        $holders = [];
        foreach ($rows as $head) {
            $values = $resolved[$head['record_id']] ?? ['values' => [], 'sources' => []];
            if (($values['values'][$keyField] ?? null) !== $keyValue) {
                return null;
            }
            $holders[] = ['head' => $head, 'values' => $values['values'], 'sources' => $values['sources']];
        }

        return $holders;
    }

    /**
     * The slot that answers for a storage in a scope and locale along this reader's
     * locale chain, or null (no site reader, no ready slot, changed public fields, a
     * slot built along another chain).
     *
     * @return array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot}|null
     */
    private function siteSource(string $schemaId, string $scopeRef, string $locale): ?array
    {
        return $this->siteViews?->source($schemaId, $scopeRef, $locale, $this->localeChainKey($locale));
    }

    /**
     * Entries read from the answering slot, once the publication states agree with
     * them; null sends the caller to the exact path. A disagreement is read once more
     * in one snapshot (a publication may have committed between the two reads); if it
     * stays, the slot is silenced and its rebuild queued. A failed site read (a table
     * being made again) is the exact path too; a failure of the states read is not
     * hidden.
     *
     * @param \Closure(PublishedSiteViewReader, array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot}): (list<array<string, mixed>>|null) $read
     * @param \Closure(list<array<string, mixed>>): bool $agrees
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot}|null $source The slot, when the caller found it already.
     * @return list<array<string, mixed>>|null
     * @phpstan-impure
     */
    private function siteRead(string $schemaId, string $scopeRef, string $locale, \Closure $read, \Closure $agrees, ?array $source = null): ?array
    {
        $reader = $this->siteViews;
        $source ??= $reader === null ? null : $this->siteSource($schemaId, $scopeRef, $locale);
        if ($reader === null || $source === null) {
            return null;
        }
        $attempt = static function () use ($reader, $source, $read, $agrees): array {
            try {
                $entries = $read($reader, $source);
            } catch (Throwable) {
                return ['failed' => true, 'entries' => null, 'agrees' => false];
            }

            return ['failed' => false, 'entries' => $entries, 'agrees' => $entries !== null && $agrees($entries)];
        };
        $result = $attempt();
        if ($result['failed']) {
            return null;
        }
        if ($result['agrees']) {
            return $result['entries'];
        }
        if ($this->connection->transactionLevel() === 0) {
            try {
                $result = $this->connection->transaction($attempt);
            } catch (Throwable) {
                return null;
            }
            if ($result['failed']) {
                return null;
            }
            if ($result['agrees']) {
                return $result['entries'];
            }
        }
        $reader->mismatch($source);

        return null;
    }

    /**
     * The exact count of the answering slot, and how many of its records the filter
     * admits, once the publication states hold as many published records; null sends
     * the caller to the exact path.
     *
     * @return array{0: int, 1: int}|null [visible, total]
     * @phpstan-impure
     */
    private function siteCount(string $schemaId, string $scopeRef, string $locale, ?callable $visibilityFilter): ?array
    {
        $count = $this->siteRead(
            $schemaId,
            $scopeRef,
            $locale,
            static fn (PublishedSiteViewReader $reader, array $source): array => [['total' => $reader->count($source), 'source' => $source]],
            fn (array $rows): bool => $rows[0]['total'] === $this->publishedCount($schemaId, $scopeRef, $locale),
        );
        if ($count === null || $this->siteViews === null) {
            return null;
        }
        $total = (int) $count[0]['total'];
        if ($visibilityFilter === null) {
            return [$total, $total];
        }
        $visible = 0;
        $after = null;
        do {
            try {
                $ids = $this->siteViews->recordIds($count[0]['source'], self::COUNT_PAGE, $after);
            } catch (Throwable) {
                return null;
            }
            foreach ($ids as $recordId) {
                if ($visibilityFilter($recordId) === true) {
                    ++$visible;
                }
            }
            $after = $ids === [] ? null : $ids[array_key_last($ids)];
        } while (count($ids) === self::COUNT_PAGE);

        return [$visible, $total];
    }

    /** @phpstan-impure */
    private function publishedCount(string $schemaId, string $scopeRef, string $locale): int
    {
        return $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->where('state', PublicationStateValue::Published->value)
            ->whereNotNull('published_revision')
            ->count();
    }

    /**
     * Whether entries are exactly these heads: the same records, in the same order, at
     * the same revisions.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<array{record_id: string, revision: int}> $heads
     */
    private static function sameHeads(array $entries, array $heads): bool
    {
        if (count($entries) !== count($heads)) {
            return false;
        }
        foreach ($heads as $index => $head) {
            if (($entries[$index]['record_id'] ?? null) !== $head['record_id'] || ($entries[$index]['revision'] ?? null) !== $head['revision']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The given fields of each revision, with localized values resolved for the
     * locale, and where each value came from: `exact` (a translation in the
     * requested locale), `fallback:<locale>` (a translation found further along
     * Lang's chain) or `shared` (the revision's own value).
     *
     * One versions read and one localized read per chunk of revisions, never one
     * per record. A revision that does not exist has no values.
     *
     * @param list<array{record_id: string, revision: int}> $heads
     * @param list<string> $fieldKeys
     * @return array<string, array{values: array<string, mixed>, sources: array<string, string>}> by record id
     * @phpstan-impure
     */
    private function valuesOf(string $schemaId, array $heads, string $locale, array $fieldKeys, bool $lock = false): array
    {
        $resolved = [];
        foreach ($heads as $head) {
            $resolved[$head['record_id']] = ['values' => [], 'sources' => []];
        }

        // No field means nothing projects. Returning early matters: the localized
        // reader treats an empty field list as "every field", so falling through
        // would hand a reader every translated value of a schema whose visibility
        // nobody declared.
        if ($heads === [] || $fieldKeys === []) {
            return $resolved;
        }

        $found = [];
        foreach (array_chunk($heads, self::BATCH) as $chunk) {
            $wanted = [];
            foreach ($chunk as $head) {
                $wanted[$head['record_id']] = $head['revision'];
            }
            $versions = $this->connection->table(self::VERSIONS_TABLE)
                ->select(['record_id', 'revision', 'schema_version', 'values_json'])
                // The schema's current version, in the same query.
                ->selectSub(fn ($query) => $query->from(self::SCHEMA_VERSIONS_TABLE)->selectRaw('max(version)')->where('schema_id', $schemaId), 'latest_schema_version')
                ->where('schema_id', $schemaId)
                ->whereIn('record_id', array_values(array_unique(array_column($chunk, 'record_id'))))
                ->whereIn('revision', array_values(array_unique(array_column($chunk, 'revision'))));
            if ($lock) {
                $versions->sharedLock();
            }
            foreach ($versions->get() as $row) {
                $row = (array) $row;
                $recordId = (string) $row['record_id'];
                if (($wanted[$recordId] ?? null) !== (int) $row['revision']) {
                    continue;
                }

                $decoded = json_decode((string) $row['values_json'], true);
                $values = [];
                foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                    $values[(string) $key] = $value;
                }
                // A field change rewrites no revision: one written under an older schema version
                // reads under the current one (a removed field is gone, a retyped one converted).
                $currentVersion = (int) $row['latest_schema_version'];
                if ((int) $row['schema_version'] < $currentVersion) {
                    $values = $this->converter->convert($schemaId, (int) $row['schema_version'], $currentVersion, $values);
                }
                $shared = [];
                $sources = [];
                foreach ($fieldKeys as $fieldKey) {
                    if (array_key_exists($fieldKey, $values)) {
                        $shared[$fieldKey] = $values[$fieldKey];
                        $sources[$fieldKey] = 'shared';
                    }
                }
                $resolved[$recordId] = ['values' => $shared, 'sources' => $sources];
                $found[] = ['record_id' => $recordId, 'revision' => (int) $row['revision']];
            }
        }

        // A localized value overrides the shared one for this locale. Visibility is
        // still decided by the field, so a translated protected field stays out.
        if ($this->localizedValues !== null && $found !== []) {
            $chain = $this->localeFallback->chainFor($locale);
            if ($lock && $this->localizedValues instanceof DatabaseLocalizedValues) {
                $localized = $this->localizedValues->resolveMany($schemaId, $found, $chain, $fieldKeys, true);
            } elseif ($this->localizedValues instanceof LocalizedValuesBatch) {
                $localized = $this->localizedValues->resolveMany($schemaId, $found, $chain, $fieldKeys);
            } else {
                $localized = [];
                foreach ($found as $head) {
                    $localized[$head['record_id']] = $this->localizedValues->resolve($schemaId, $head['record_id'], $head['revision'], $chain, $fieldKeys);
                }
            }
            foreach ($found as $head) {
                foreach ($localized[$head['record_id']] ?? [] as $fieldKey => $value) {
                    $resolved[$head['record_id']]['values'][$fieldKey] = $value->value;
                    $resolved[$head['record_id']]['sources'][$fieldKey] = $value->exact ? 'exact' : 'fallback:' . $value->sourceLocale;
                }
            }
        }

        foreach ($heads as $head) {
            ksort($resolved[$head['record_id']]['values']);
            ksort($resolved[$head['record_id']]['sources']);
        }

        return $resolved;
    }

    /**
     * An encrypted field is never a key: no published key, slug or route resolves by it.
     *
     * @phpstan-impure
     */
    private function assertKeyFieldPlain(string $schemaId, string $keyField): void
    {
        $definition = $this->connection->table(self::SCHEMA_VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->orderByDesc('version')
            ->value('definition');
        $decoded = is_string($definition) ? json_decode($definition, true) : null;
        $fields = is_array($decoded) && is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];
        if (in_array($keyField, StorageEncryptionPolicy::encryptedKeys($fields), true)) {
            throw new ReadContractRejected(StorageEncryptionPolicy::KEY_FIELD_REFUSED, 'An encrypted field cannot be a key.');
        }
    }

    /**
     * @return list<string>
     * @phpstan-impure
     */
    private function publicFieldKeys(string $schemaId, bool $lock = false): array
    {
        $query = $this->connection->table(self::SCHEMA_VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->orderByDesc('version');
        if ($lock) {
            $query->sharedLock();
        }
        $row = $query->first();

        if ($row === null) {
            // No schema means no public fields. Failing closed here keeps an
            // unregistered schema from projecting its whole document.
            return [];
        }

        $definition = json_decode((string) ((array) $row)['definition'], true);
        $fields = is_array($definition) ? ($definition['fields'] ?? []) : [];

        $public = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null)) {
                continue;
            }

            $visibility = is_string($field['visibility'] ?? null)
                ? FieldVisibility::tryFrom($field['visibility'])
                : null;

            // Anything that is not explicitly public stays out, including a field whose
            // visibility is absent or unrecognised: a reader must never see a field
            // because nobody said what it was.
            if ($visibility === FieldVisibility::Public) {
                $public[] = $field['key'];
            }
        }

        sort($public);

        return $public;
    }

    private function schemaId(string $roleRefOrSchemaId): string
    {
        // A role reference carries an @v suffix; a schema id never does. Accepting both
        // keeps the caller from having to know which it holds.
        if (!str_contains($roleRefOrSchemaId, '@v')) {
            return $roleRefOrSchemaId;
        }

        $binding = $this->connection->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', $roleRefOrSchemaId)
            ->where('status', 'active')
            ->orderBy('schema_id')
            ->first();

        if ($binding === null) {
            throw new ReadContractRejected(
                'unknown_role_binding',
                'No active structure is bound to ' . $roleRefOrSchemaId . '.',
            );
        }

        return (string) ((array) $binding)['schema_id'];
    }

    private function budget(?int $budget): int
    {
        if ($budget === null) {
            return self::DEFAULT_BUDGET;
        }

        if ($budget < 1) {
            throw new ReadContractRejected('invalid_budget', 'A projection budget must be a positive integer.');
        }

        return min($budget, self::DEFAULT_BUDGET);
    }

    /**
     * The read tables exist. Only a yes is remembered: two table lookups less on every
     * read once they are there, and a missing table is still refused at every call.
     *
     * @phpstan-impure
     */
    private function assertSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }
        $schema = $this->connection->getSchemaBuilder();
        foreach ([self::VERSIONS_TABLE, DatabasePublicationLifecycle::STATES_TABLE] as $table) {
            if (!$schema->hasTable($table)) {
                throw new ReadContractRejected('schema_missing', 'The table ' . $table . ' is not migrated.');
            }
        }
        $this->schemaReady = true;
    }
}
