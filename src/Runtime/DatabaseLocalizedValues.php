<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Audit\LocalizedValueAuditEventCatalog;
use Larena\Storage\Contracts\LocaleCoverageReport;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocalizedValue;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Throwable;

/**
 * Localized values on their own immutable table.
 *
 * Two invariants are worth stating because the rest follows from them. A row
 * belongs to its revision and is written once, so a published revision cannot have
 * its translation changed under it. And an absent value is absent, never an empty
 * one: a caller has to be able to tell "this page has no German title" from "this
 * page's German title is an empty string", and folding the two together would make
 * coverage meaningless.
 */
final class DatabaseLocalizedValues implements LocalizedValues
{
    public const TABLE = 'larena_storage_localized_values';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $localizedFieldKeys
     * @param list<string> $requiredFieldKeys
     * @return list<string>
     * @phpstan-impure
     */
    public function write(
        string $schemaId,
        string $recordId,
        int $revision,
        string $locale,
        array $values,
        array $localizedFieldKeys,
        string $actorId,
        array $requiredFieldKeys = [],
        bool $partialLocalesAllowed = false,
        ?string $correlationId = null,
    ): array {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        if ($revision < 1) {
            throw new LocalizedValueRejected('invalid_revision', 'A revision must be a positive integer.');
        }

        foreach (array_keys($values) as $fieldKey) {
            if (!in_array($fieldKey, $localizedFieldKeys, true)) {
                throw new LocalizedValueRejected(
                    'field_not_localized',
                    'The schema does not declare "' . $fieldKey . '" as a localized field.',
                );
            }
        }

        // A required localized field may be missing in a secondary locale only when
        // the schema says partial locales are acceptable. Otherwise a half-translated
        // record would be publishable, and nothing downstream could tell.
        if (!$partialLocalesAllowed) {
            $missing = [];
            foreach ($requiredFieldKeys as $required) {
                if (!array_key_exists($required, $values)) {
                    $missing[] = $required;
                }
            }

            if ($missing !== []) {
                throw new LocalizedValueRejected(
                    'partial_locale_denied',
                    'Locale ' . $locale . ' is missing required field(s): ' . implode(', ', $missing) . '.',
                );
            }
        }

        $now = $this->now();
        $written = [];

        $this->connection->transaction(function () use (
            $schemaId,
            $recordId,
            $revision,
            $locale,
            $values,
            $actorId,
            $correlationId,
            $now,
            &$written
        ): void {
            foreach ($values as $fieldKey => $value) {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                try {
                    $this->connection->table(self::TABLE)->insert([
                        'schema_id' => $schemaId,
                        'record_id' => $recordId,
                        'revision' => $revision,
                        'locale' => $locale,
                        'field_key' => (string) $fieldKey,
                        'value_json' => $encoded,
                        'content_hash' => hash('sha256', $encoded),
                        'created_by' => $actorId,
                        'correlation_id' => $correlationId,
                        'created_at' => $now,
                    ]);
                } catch (Throwable $failure) {
                    if ($this->isUniqueViolation($failure)) {
                        // The unique index is what makes the revision immutable, so
                        // this is the guarantee reporting itself rather than a
                        // convenience check.
                        throw new LocalizedValueRejected(
                            'revision_locale_field_immutable',
                            'Revision ' . $revision . ' already has "' . $fieldKey . '" in ' . $locale
                            . '; a change is a new revision.',
                        );
                    }

                    throw $failure;
                }

                $written[] = (string) $fieldKey;
            }
        });

        return $written;
    }

    /**
     * @return array<string, LocalizedValue>
     * @phpstan-impure
     */
    public function read(string $schemaId, string $recordId, int $revision, string $locale): array
    {
        $this->assertSchema();
        LocaleFallbackChain::assertLocale($locale);

        $rows = $this->connection->table(self::TABLE)
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->where('locale', $locale)
            ->orderBy('field_key')
            ->get();

        $values = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $key = (string) $row['field_key'];
            $values[$key] = new LocalizedValue(
                fieldKey: $key,
                requestedLocale: $locale,
                sourceLocale: $locale,
                value: $this->decode($row['value_json']),
                exact: true,
            );
        }

        return $values;
    }

    /**
     * @param list<string> $fieldKeys
     * @return array<string, LocalizedValue>
     * @phpstan-impure
     */
    public function resolve(
        string $schemaId,
        string $recordId,
        int $revision,
        LocaleFallbackChain $chain,
        array $fieldKeys = [],
    ): array {
        $this->assertSchema();

        $query = $this->connection->table(self::TABLE)
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->whereIn('locale', $chain->locales);

        if ($fieldKeys !== []) {
            $query->whereIn('field_key', $fieldKeys);
        }

        // One query for the whole chain: walking locale by locale would be one
        // round trip per fallback step, and a page with ten fields and a three-locale
        // chain would pay thirty of them.
        $byFieldAndLocale = [];
        foreach ($query->get() as $row) {
            $row = (array) $row;
            $byFieldAndLocale[(string) $row['field_key']][(string) $row['locale']] = $row['value_json'];
        }

        $requested = $chain->requested();
        $resolved = [];

        foreach ($byFieldAndLocale as $fieldKey => $perLocale) {
            foreach ($chain->locales as $locale) {
                if (!array_key_exists($locale, $perLocale)) {
                    continue;
                }

                $resolved[$fieldKey] = new LocalizedValue(
                    fieldKey: $fieldKey,
                    requestedLocale: $requested,
                    sourceLocale: $locale,
                    value: $this->decode($perLocale[$locale]),
                    exact: $locale === $requested,
                );

                break;
            }
        }

        ksort($resolved);

        // A field with no row in any locale of the chain is simply not here. It is
        // not returned as null, because null is a value a field can legitimately
        // hold.
        return $resolved;
    }

    /**
     * @param list<string> $declaredLocales
     * @param list<string> $localizedFieldKeys
     * @phpstan-impure
     */
    public function coverage(
        string $schemaId,
        string $recordId,
        int $revision,
        array $declaredLocales,
        array $localizedFieldKeys,
    ): LocaleCoverageReport {
        $this->assertSchema();

        $rows = $this->connection->table(self::TABLE)
            ->select(['locale', 'field_key'])
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->get();

        $present = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $present[(string) $row['locale']][(string) $row['field_key']] = true;
        }

        $perLocale = [];
        foreach ($declaredLocales as $locale) {
            $here = [];
            $missing = [];

            foreach ($localizedFieldKeys as $fieldKey) {
                if (isset($present[$locale][$fieldKey])) {
                    $here[] = $fieldKey;

                    continue;
                }

                $missing[] = $fieldKey;
            }

            $perLocale[$locale] = [
                'locale' => $locale,
                'present' => $here,
                'missing' => $missing,
                'complete' => $missing === [],
            ];
        }

        return new LocaleCoverageReport(
            schemaId: $schemaId,
            recordId: $recordId,
            revision: $revision,
            declaredLocales: $declaredLocales,
            localizedFields: $localizedFieldKeys,
            perLocale: $perLocale,
        );
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function explain(string $schemaId, string $recordId, int $revision): array
    {
        $this->assertSchema();

        $rows = $this->connection->table(self::TABLE)
            ->select(['locale', 'field_key'])
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->get();

        $locales = [];
        foreach ($rows as $row) {
            $locales[(string) ((array) $row)['locale']] = true;
        }

        $storedLocales = array_keys($locales);
        sort($storedLocales);

        return [
            'schema_id' => $schemaId,
            'record_id' => $recordId,
            'revision' => $revision,
            'stored_locales' => $storedLocales,
            'value_count' => $rows->count(),
            'immutable' => true,
            'audit_events' => LocalizedValueAuditEventCatalog::all(),
        ];
    }

    private function decode(mixed $encoded): mixed
    {
        if (!is_string($encoded)) {
            return $encoded;
        }

        try {
            return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new LocalizedValueRejected('corrupt_value', 'A stored localized value is not valid JSON.');
        }
    }

    /** @phpstan-impure */
    private function assertSchema(): void
    {
        if (!$this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            throw new LocalizedValueRejected('schema_missing', 'The localized values table is not migrated.');
        }
    }

    private function isUniqueViolation(Throwable $failure): bool
    {
        $message = strtolower($failure->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, '1062');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
