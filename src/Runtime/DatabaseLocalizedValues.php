<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Audit\LocalizedValueAuditEventCatalog;
use Larena\Storage\Contracts\LocaleCoverageReport;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocalizedValue;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Property\Runtime\PropertyTypeRegistry as BuiltInPropertyTypes;
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

    private PropertyTypeRegistry $types;

    /**
     * @param string|null $primaryLocale the language of the shared values, from the application
     */
    public function __construct(
        private readonly Connection $connection,
        ?PropertyTypeRegistry $types = null,
        private readonly ?string $primaryLocale = null,
        private readonly ?StorageSecurityEventSink $securityEvents = null,
    ) {
        $this->types = $types ?? BuiltInPropertyTypes::builtIns();
    }

    /**
     * The fields of the schema version a revision was written under, by key, and
     * whether that version allows partial locales.
     *
     * @return array{fields: array<string, array<string, mixed>>, partial_locales: bool}
     * @phpstan-impure
     */
    private function revisionSchema(string $schemaId, string $recordId, int $revision): array
    {
        $version = $this->connection->table('larena_storage_record_versions')
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('revision', $revision)
            ->value('schema_version');
        if ($version === null) {
            throw new LocalizedValueRejected('unknown_revision', 'Revision ' . $revision . ' does not exist for this record.');
        }
        $definition = $this->connection->table('larena_storage_schema_versions')
            ->where('schema_id', $schemaId)
            ->where('version', (int) $version)
            ->value('definition');
        $decoded = is_string($definition) ? json_decode($definition, true) : null;
        $fields = [];
        foreach (is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $fields[$field['key']] = $field;
            }
        }

        return ['fields' => $fields, 'partial_locales' => ($decoded['partial_locales'] ?? false) === true];
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed> the normalized values
     */
    private function validatedValues(array $fields, array $values): array
    {

        $normalized = [];
        foreach ($values as $fieldKey => $value) {
            $field = $fields[(string) $fieldKey] ?? null;
            $type = is_array($field) && is_string($field['type'] ?? null) ? $field['type'] : null;
            $typeVersion = is_array($field) && is_int($field['type_version'] ?? null)
                ? $field['type_version']
                : ($type === null ? null : $this->types->latest($type)?->version);
            if ($type === null || $typeVersion === null) {
                throw new LocalizedValueRejected('field_unknown', 'The schema has no typed field "' . $fieldKey . '".');
            }
            // An explicit empty value is allowed for an optional field, as for the shared one.
            if ($value === null) {
                if (($field['required'] ?? false) === true) {
                    throw new LocalizedValueRejected('value_invalid', 'The required field "' . $fieldKey . '" cannot be empty.');
                }
                $normalized[$fieldKey] = null;

                continue;
            }
            $result = $this->types->normalizeAndValidate(
                $type,
                $typeVersion,
                $value,
                is_array($field['constraints'] ?? null) ? $field['constraints'] : [],
            );
            if (!$result->canBePersistedByOwner()) {
                throw new LocalizedValueRejected('value_invalid', 'The value of "' . $fieldKey . '" is not a valid ' . $type . '.');
            }
            $normalized[$fieldKey] = $result->normalizedValue;
        }

        return $normalized;
    }

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

        // The schema version of the revision decides which fields are localized,
        // which of them are required and whether partial locales are allowed. Only
        // a version written before schemas could say so — one that declares no
        // localized field at all — still takes these from the caller.
        $schema = $this->revisionSchema($schemaId, $recordId, $revision);
        $declared = array_keys(array_filter($schema['fields'], static fn (array $field): bool => ($field['localized'] ?? false) === true));
        if ($declared !== []) {
            $localizedFieldKeys = $declared;
            $requiredFieldKeys = array_values(array_filter($declared, static fn (string $key): bool => ($schema['fields'][$key]['required'] ?? false) === true));
            $partialLocalesAllowed = $schema['partial_locales'];
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
        // record would be publishable, and nothing downstream could tell. The
        // primary locale is the language of the shared values themselves, so a
        // value written for it only overrides and may be partial.
        if (!$partialLocalesAllowed && $locale !== $this->primaryLocale) {
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

        // A translation passes the same Property validation as the shared value: the
        // field's type, version and constraints come from the schema version of the
        // revision it translates. A field the schema does not know is refused.
        $values = $this->validatedValues($schema['fields'], $values);

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

            // One audit event per write, in its transaction: a translation whose
            // audit cannot be written is not written. Field keys, never values.
            $this->securityEvents?->emit(new StorageSecurityEvent(
                'locale',
                LocalizedValueAuditEventCatalog::WRITTEN,
                $actorId,
                'storage-record:' . $recordId,
                $correlationId ?? 'storage-locale:' . bin2hex(random_bytes(16)),
                [
                    'schema_id' => $schemaId,
                    'record_id' => $recordId,
                    'revision' => $revision,
                    'locale' => $locale,
                    'written_field_keys' => $written,
                ],
            ));
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
