<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\LocalizedValuesBatch;
use Larena\Storage\Contracts\PublishedProjectionPage;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Contracts\ResolvedKey;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;

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
 */
final class DatabaseReadContracts implements ReadContracts
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

    /** How many records one batched read asks for at most. */
    public const BATCH = 500;

    private readonly PublishedKeyTable $keys;
    private readonly StorageRecordConverter $converter;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LocalizedValues $localizedValues = null,
        private readonly LocaleFallbackResolver $localeFallback = new RequestedLocaleOnly(),
        private readonly StorageMetrics $metrics = new StorageMetrics(),
        // Reads a revision written under an older schema version under the current one.
        ?StorageRecordConverter $converter = null,
    ) {
        $this->keys = new PublishedKeyTable($connection);
        $this->converter = $converter ?? new StorageRecordConverter($connection);
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
            $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, 1, null, [$recordId]);
            if ($heads === [] || ($visibilityFilter !== null && $visibilityFilter($recordId) !== true)) {
                return null;
            }

            return $this->projectionEntries($schemaId, $scopeRef, $locale, $heads, $this->publicFieldKeys($schemaId))[0] ?? null;
        });
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
    public function publishedHeadPage(string $schemaId, string $scopeRef, string $locale, int $limit, ?string $afterRecordId = null): array
    {
        return $this->publishedHeads($schemaId, $scopeRef, $locale, $limit, $afterRecordId);
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
        $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, self::DEFAULT_BUDGET);
        $visible = $visibilityFilter === null
            ? count($heads)
            : count(array_filter($heads, static fn (array $head): bool => $visibilityFilter($head['record_id']) === true));

        return [
            'schema_id' => $schemaId,
            'scope_ref' => $scopeRef,
            'locale' => $locale,
            'public_field_keys' => $this->publicFieldKeys($schemaId),
            'published_record_count' => $visible,
            'filtered_record_count' => count($heads) - $visible,
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
    private function projectionEntries(string $schemaId, string $scopeRef, string $locale, array $heads, array $publicFields): array
    {
        if ($heads === []) {
            return [];
        }

        $resolved = $this->valuesOf($schemaId, $heads, $locale, $publicFields);
        $versions = $this->projectionVersions($schemaId, $scopeRef, $locale, array_column($heads, 'record_id'));

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
    private function projectionVersions(string $schemaId, string $scopeRef, string $locale, array $recordIds): array
    {
        $localizedTable = $this->connection->getSchemaBuilder()->hasTable(DatabaseLocalizedValues::TABLE);
        $versions = [];

        foreach (array_chunk($recordIds, self::BATCH) as $chunk) {
            $publications = [];
            foreach ($chunk as $recordId) {
                $publications[PublicationState::identity($schemaId, $recordId, $scopeRef, $locale)] = $recordId;
            }

            $transitions = [];
            foreach ($this->connection->table(DatabasePublicationLifecycle::LOG_TABLE)
                ->select('publication_id')
                ->selectRaw('MAX(id) AS newest')
                ->whereIn('publication_id', array_keys($publications))
                ->groupBy('publication_id')
                ->get() as $row) {
                $row = (array) $row;
                $recordId = $publications[(string) $row['publication_id']] ?? null;
                if ($recordId !== null) {
                    $transitions[$recordId] = (int) $row['newest'];
                }
            }

            $localized = [];
            if ($localizedTable) {
                foreach ($this->connection->table(DatabaseLocalizedValues::TABLE)
                    ->select('record_id')
                    ->selectRaw('MAX(id) AS newest')
                    ->where('schema_id', $schemaId)
                    ->where('locale', $locale)
                    ->whereIn('record_id', $chunk)
                    ->groupBy('record_id')
                    ->get() as $row) {
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
    private function publishedHeads(string $schemaId, string $scopeRef, string $locale, int $limit, ?string $afterRecordId = null, ?array $recordIds = null): array
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
        $rows = $query->orderBy('record_id')->limit($limit)->get();

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
            // Only a public field is a key a reader may route by: no record answers
            // to any other, because no reader sees its value.
            return [];
        }

        $indexed = $this->indexedKeyHolders($schemaId, $scopeRef, $locale, $keyField, $keyValue, $publicFields);
        if ($indexed !== null) {
            return array_slice($indexed, 0, $stopAfter);
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
            if ($scanned > self::KEY_SCAN_LIMIT) {
                throw new ReadContractRejected(
                    'key_scan_limit_exceeded',
                    'More than ' . self::KEY_SCAN_LIMIT . ' published records in ' . $scopeRef . '/' . $locale . '; a key lookup refuses rather than miss.',
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
    private function valuesOf(string $schemaId, array $heads, string $locale, array $fieldKeys): array
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
            foreach ($this->connection->table(self::VERSIONS_TABLE)
                ->select(['record_id', 'revision', 'schema_version', 'values_json'])
                // The schema's current version, in the same query.
                ->selectSub(fn ($query) => $query->from(self::SCHEMA_VERSIONS_TABLE)->selectRaw('max(version)')->where('schema_id', $schemaId), 'latest_schema_version')
                ->where('schema_id', $schemaId)
                ->whereIn('record_id', array_values(array_unique(array_column($chunk, 'record_id'))))
                ->whereIn('revision', array_values(array_unique(array_column($chunk, 'revision'))))
                ->get() as $row) {
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
            if ($this->localizedValues instanceof LocalizedValuesBatch) {
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
     * @return list<string>
     * @phpstan-impure
     */
    private function publicFieldKeys(string $schemaId): array
    {
        $row = $this->connection->table(self::SCHEMA_VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->orderByDesc('version')
            ->first();

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

    /** @phpstan-impure */
    private function assertSchema(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach ([self::VERSIONS_TABLE, DatabasePublicationLifecycle::STATES_TABLE] as $table) {
            if (!$schema->hasTable($table)) {
                throw new ReadContractRejected('schema_missing', 'The table ' . $table . ' is not migrated.');
            }
        }
    }
}
