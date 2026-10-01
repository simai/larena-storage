<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\PublishedProjectionPage;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Contracts\ResolvedKey;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\ReadContractRejected;

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

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LocalizedValues $localizedValues = null,
        private readonly LocaleFallbackResolver $localeFallback = new RequestedLocaleOnly(),
    ) {
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
            recordId: (string) $only['head']['record_id'],
            scopeRef: $scopeRef,
            locale: $locale,
            revision: (int) $only['head']['revision'],
            keyField: $keyField,
            keyValue: $keyValue,
            values: $only['values'],
            valueSources: $only['sources'],
        );
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
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        $limit = $this->budget($budget);
        $schemaId = $this->schemaId($roleRefOrSchemaId);
        $publicFields = $this->publicFieldKeys($schemaId);

        $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, $limit + 1, $afterRecordId);
        $truncated = count($heads) > $limit;
        $heads = array_slice($heads, 0, $limit);

        $records = [];
        $filtered = 0;

        foreach ($heads as $head) {
            if ($visibilityFilter !== null && $visibilityFilter($head['record_id']) !== true) {
                ++$filtered;

                continue;
            }

            $records[] = $this->projectionEntry($schemaId, $scopeRef, $locale, $head, $publicFields);
        }

        return new PublishedProjectionPage($records, $filtered, $truncated, $limit);
    }

    /** @phpstan-impure */
    public function publishedRecord(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        string $recordId,
        ?callable $visibilityFilter = null,
    ): ?array {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        $schemaId = $this->schemaId($roleRefOrSchemaId);
        $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, 1, null, $recordId);
        if ($heads === [] || ($visibilityFilter !== null && $visibilityFilter($recordId) !== true)) {
            return null;
        }

        return $this->projectionEntry($schemaId, $scopeRef, $locale, $heads[0], $this->publicFieldKeys($schemaId));
    }

    /**
     * @param array{record_id: string, revision: int} $head
     * @param list<string> $publicFields
     * @return array{record_id: string, revision: int, locale: string, projection_version: int, values: array<string, mixed>, value_sources: array<string, string>}
     * @phpstan-impure
     */
    private function projectionEntry(string $schemaId, string $scopeRef, string $locale, array $head, array $publicFields): array
    {
        return [
            'record_id' => (string) $head['record_id'],
            'revision' => (int) $head['revision'],
            'locale' => $locale,
            'projection_version' => ProjectionVersion::of($this->connection, $schemaId, (string) $head['record_id'], $scopeRef, $locale),
        ] + $this->entryValues($schemaId, $head, $locale, $publicFields);
    }

    /**
     * @param array{record_id: string, revision: int} $head
     * @param list<string> $publicFields
     * @return array{values: array<string, mixed>, value_sources: array<string, string>}
     * @phpstan-impure
     */
    private function entryValues(string $schemaId, array $head, string $locale, array $publicFields): array
    {
        $resolved = $this->publicValuesWithSources($schemaId, $head['record_id'], (int) $head['revision'], $locale, $publicFields);

        return ['values' => $resolved['values'], 'value_sources' => $resolved['sources']];
    }

    /**
     * The ids of the published records that answer to a key in a scope and locale,
     * found by reading every published record — not only the first page.
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

        return $this->publicValues($schemaId, $recordId, $revision, $locale, $this->publicFieldKeys($schemaId));
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
     * The published heads of one schema in one scope and locale.
     *
     * Only `published` counts. A scheduled record is not published until the sweep
     * runs, and an archived one is not published any more.
     *
     * @return list<array{record_id: string, revision: int}>
     * @phpstan-impure
     */
    private function publishedHeads(string $schemaId, string $scopeRef, string $locale, int $limit, ?string $afterRecordId = null, ?string $recordId = null): array
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
        if ($recordId !== null) {
            $query->where('record_id', $recordId);
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
     * Published records whose public key field equals the value, reading the whole
     * published set page by page and stopping once enough are found.
     *
     * @return list<array{head: array{record_id: string, revision: int}, values: array<string, mixed>, sources: array<string, string>}>
     * @phpstan-impure
     */
    private function keyHolders(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue, int $stopAfter): array
    {
        $publicFields = $this->publicFieldKeys($schemaId);
        $holders = [];
        $after = null;
        $scanned = 0;

        do {
            $page = $this->publishedHeads($schemaId, $scopeRef, $locale, self::DEFAULT_BUDGET, $after);
            foreach ($page as $head) {
                $resolved = $this->publicValuesWithSources($schemaId, $head['record_id'], $head['revision'], $locale, $publicFields);
                if (($resolved['values'][$keyField] ?? null) === $keyValue) {
                    $holders[] = ['head' => $head, 'values' => $resolved['values'], 'sources' => $resolved['sources']];
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
     * @param list<string> $publicFields
     * @return array<string, mixed>
     * @phpstan-impure
     */
    private function publicValues(string $schemaId, string $recordId, int $revision, string $locale, array $publicFields): array
    {
        return $this->publicValuesWithSources($schemaId, $recordId, $revision, $locale, $publicFields)['values'];
    }

    /**
     * The public fields of one revision, with localized values resolved for the locale,
     * and where each value came from: `exact` (a translation in the requested locale),
     * `fallback:<locale>` (a translation found further along Lang's chain) or `shared`
     * (the revision's own value).
     *
     * @param list<string> $publicFields
     * @return array{values: array<string, mixed>, sources: array<string, string>}
     * @phpstan-impure
     */
    private function publicValuesWithSources(string $schemaId, string $recordId, int $revision, string $locale, array $publicFields): array
    {
        $row = $this->connection->table(self::VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->first();

        if ($row === null) {
            return ['values' => [], 'sources' => []];
        }

        $decoded = json_decode((string) ((array) $row)['values_json'], true);
        $values = is_array($decoded) ? $decoded : [];

        // No public field means nothing projects. Returning early matters: the
        // localized reader treats an empty field list as "every field", so falling
        // through would hand a reader every translated value of a schema whose
        // visibility nobody declared.
        if ($publicFields === []) {
            return ['values' => [], 'sources' => []];
        }

        $public = [];
        $sources = [];
        foreach ($publicFields as $fieldKey) {
            if (array_key_exists($fieldKey, $values)) {
                $public[$fieldKey] = $values[$fieldKey];
                $sources[$fieldKey] = 'shared';
            }
        }

        // A localized value overrides the shared one for this locale. Visibility is
        // still decided by the field, so a translated protected field stays out.
        if ($this->localizedValues !== null) {
            foreach ($this->localizedValues->resolve($schemaId, $recordId, $revision, $this->localeFallback->chainFor($locale), $publicFields) as $fieldKey => $value) {
                $public[$fieldKey] = $value->value;
                $sources[$fieldKey] = $value->exact ? 'exact' : 'fallback:' . $value->sourceLocale;
            }
        }

        ksort($public);
        ksort($sources);

        return ['values' => $public, 'sources' => $sources];
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
