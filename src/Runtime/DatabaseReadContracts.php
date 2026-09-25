<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\LocaleFallbackChain;
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

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LocalizedValues $localizedValues = null,
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
        $publicFields = $this->publicFieldKeys($schemaId);

        $candidates = [];

        foreach ($this->publishedHeads($schemaId, $scopeRef, $locale, self::DEFAULT_BUDGET + 1) as $head) {
            $values = $this->publicValues($schemaId, $head['record_id'], (int) $head['revision'], $locale, $publicFields);

            if (($values[$keyField] ?? null) !== $keyValue) {
                continue;
            }

            $candidates[] = ['head' => $head, 'values' => $values];
        }

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
        );
    }

    /** @phpstan-impure */
    public function publishedProjection(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
    ): PublishedProjectionPage {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        $limit = $this->budget($budget);
        $schemaId = $this->schemaId($roleRefOrSchemaId);
        $publicFields = $this->publicFieldKeys($schemaId);

        $heads = $this->publishedHeads($schemaId, $scopeRef, $locale, $limit + 1);
        $truncated = count($heads) > $limit;
        $heads = array_slice($heads, 0, $limit);

        $records = [];
        $filtered = 0;

        foreach ($heads as $head) {
            if ($visibilityFilter !== null && $visibilityFilter($head['record_id']) !== true) {
                ++$filtered;

                continue;
            }

            $records[] = [
                'record_id' => (string) $head['record_id'],
                'revision' => (int) $head['revision'],
                'locale' => $locale,
                'values' => $this->publicValues($schemaId, $head['record_id'], (int) $head['revision'], $locale, $publicFields),
            ];
        }

        return new PublishedProjectionPage($records, $filtered, $truncated, $limit);
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function projectionExplain(string $roleRefOrSchemaId, string $scopeRef, string $locale): array
    {
        $this->assertSchema();

        $schemaId = $this->schemaId($roleRefOrSchemaId);

        return [
            'schema_id' => $schemaId,
            'scope_ref' => $scopeRef,
            'locale' => $locale,
            'public_field_keys' => $this->publicFieldKeys($schemaId),
            'published_record_count' => count($this->publishedHeads($schemaId, $scopeRef, $locale, self::DEFAULT_BUDGET)),
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
    private function publishedHeads(string $schemaId, string $scopeRef, string $locale, int $limit): array
    {
        $rows = $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->select(['record_id', 'published_revision'])
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->where('state', PublicationStateValue::Published->value)
            ->whereNotNull('published_revision')
            ->orderBy('record_id')
            ->limit($limit)
            ->get();

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
     * The public fields of one revision, with localized values resolved for the locale.
     *
     * @param list<string> $publicFields
     * @return array<string, mixed>
     * @phpstan-impure
     */
    private function publicValues(string $schemaId, string $recordId, int $revision, string $locale, array $publicFields): array
    {
        $row = $this->connection->table(self::VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->first();

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) ((array) $row)['values_json'], true);
        $values = is_array($decoded) ? $decoded : [];

        // No public field means nothing projects. Returning early matters: the
        // localized reader treats an empty field list as "every field", so falling
        // through would hand a reader every translated value of a schema whose
        // visibility nobody declared.
        if ($publicFields === []) {
            return [];
        }

        $public = [];
        foreach ($publicFields as $fieldKey) {
            if (array_key_exists($fieldKey, $values)) {
                $public[$fieldKey] = $values[$fieldKey];
            }
        }

        // A localized value overrides the shared one for this locale. Visibility is
        // still decided by the field, so a translated protected field stays out.
        if ($this->localizedValues !== null) {
            foreach ($this->localizedValues->resolve($schemaId, $recordId, $revision, LocaleFallbackChain::of($locale), $publicFields) as $fieldKey => $value) {
                $public[$fieldKey] = $value->value;
            }
        }

        ksort($public);

        return $public;
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
