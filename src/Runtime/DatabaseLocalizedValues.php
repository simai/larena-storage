<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Audit\LocalizedValueAuditEventCatalog;
use Larena\Storage\Contracts\LocaleCoverageReport;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocalizedValue;
use Larena\Storage\Contracts\LocalizedValueGuard;
use Larena\Storage\Contracts\LocalizedValueObserver;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\LocalizedValuesBatch;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Encryption\StorageFieldSealer;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewRegistry;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Property\Runtime\PropertyTypeRegistry as BuiltInPropertyTypes;
use Closure;
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
 *
 * A translation of an encrypted field is validated, then sealed for its locale (the
 * locale is part of the associated data) and stored as an envelope; every read here
 * returns the envelope. An envelope sent back is kept when it opens for this record,
 * locale and field. Without a sealer such a write is refused
 * (storage_field_encryption_unavailable) before anything is written.
 *
 * A revision's rows are copied onto the revision that follows it (carryForward()), so an edit in one language does not
 * take the others off the next publication; a draft's inherited row gives way to a translation written for it. The copy is of the stored bytes: an envelope's associated data names the
 * storage, record, locale and field, never the revision, so it still opens; the encryption runs reach the new rows by
 * their row id and their written-at time like any other.
 */
final class DatabaseLocalizedValues implements LocalizedValues, LocalizedValuesBatch
{
    public const TABLE = 'larena_storage_localized_values';

    /** How many revisions one batched read asks for at most. */
    public const BATCH = 500;

    private PropertyTypeRegistry $types;
    private ?bool $publications = null;
    private ?PublishedKeyTable $keyTable = null;
    private ?PublishedSiteViewRegistry $siteViews = null;
    private ?bool $table = null;

    /**
     * @param string|null $primaryLocale the language of the shared values, from the application
     * @param (Closure(): LocalizedValueObserver)|null $observer gives the index that hears of every write;
     *        a closure so that the index, which reads through this class, is built only when a write happens
     */
    public function __construct(
        private readonly Connection $connection,
        ?PropertyTypeRegistry $types = null,
        private readonly ?string $primaryLocale = null,
        private readonly ?StorageSecurityEventSink $securityEvents = null,
        private readonly ?Closure $observer = null,
        // Seals the translations of encrypted fields; without it they are refused.
        private readonly ?StorageFieldSealer $sealer = null,
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
     * The storage's current schema version and its encrypted fields; null when the storage has no schema head (a
     * caller outside the versioned storage).
     *
     * @return array{version: int, encrypted: list<string>}|null
     * @phpstan-impure
     */
    private function currentEncryption(string $schemaId): ?array
    {
        try {
            $version = $this->connection->table('larena_storage_schemas')->where('schema_id', $schemaId)->value('current_version');
        } catch (Throwable) {
            return null;
        }
        if ($version === null) {
            return null;
        }
        $definition = $this->connection->table('larena_storage_schema_versions')
            ->where('schema_id', $schemaId)
            ->where('version', (int) $version)
            ->value('definition');
        $decoded = is_string($definition) ? json_decode($definition, true) : null;

        return [
            'version' => (int) $version,
            'encrypted' => StorageEncryptionPolicy::encryptedKeys(is_array($decoded) && is_array($decoded['fields'] ?? null) ? $decoded['fields'] : []),
        ];
    }

    /**
     * What read() shows of one locale's translations (the admin's read): a field that is encrypted now but still
     * holds plaintext (its encryption run has not reached the row) is shown as an envelope sealed for this read, or
     * left out. The resolving reads serve public fields, which are never encrypted.
     *
     * @param array<string, LocalizedValue> $values
     * @return array<string, LocalizedValue>
     * @phpstan-impure
     */
    private function masked(string $schemaId, string $recordId, array $values): array
    {
        $plain = [];
        foreach ($values as $key => $value) {
            if ($value->value !== null && !StorageFieldEnvelope::isEnvelope($value->value)) {
                $plain[$key] = true;
            }
        }
        if ($plain === []) {
            return $values;
        }
        try {
            // Only a storage with a data key has encrypted fields.
            $keyed = $this->connection->table(StorageDataKeys::TABLE)->where('schema_id', $schemaId)->exists();
        } catch (Throwable) {
            $keyed = false;
        }
        if (!$keyed) {
            return $values;
        }
        $encrypted = $this->currentEncryption($schemaId)['encrypted'] ?? [];
        foreach (array_intersect(array_keys($plain), $encrypted) as $key) {
            $sealed = $this->sealer?->sealForRead($schemaId, $recordId, $values[$key]->sourceLocale, $key, $values[$key]->value);
            if ($sealed === null) {
                unset($values[$key]);
                continue;
            }
            $values[$key] = new LocalizedValue(
                fieldKey: $key,
                requestedLocale: $values[$key]->requestedLocale,
                sourceLocale: $values[$key]->sourceLocale,
                value: $sealed,
                exact: $values[$key]->exact,
            );
        }

        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed> the normalized values
     */
    private function validatedValues(array $fields, #[\SensitiveParameter] array $values): array
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
        #[\SensitiveParameter] array $values,
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
        // the schema says partial locales are acceptable, or when something answers for
        // it: a shared value (the read falls back to it, owner decision 2026-10-30: no
        // translation shows the source text) or a row this revision already has. Otherwise a
        // half-translated record would show nothing, and nothing downstream could tell. The
        // primary locale is the language of the shared values themselves, so a
        // value written for it only overrides and may be partial.
        if (!$partialLocalesAllowed && $locale !== $this->primaryLocale) {
            $missing = [];
            foreach ($requiredFieldKeys as $required) {
                if (!array_key_exists($required, $values)) {
                    $missing[] = $required;
                }
            }

            $missing = $this->withoutAnswered($schemaId, $recordId, $revision, $locale, $missing);
            if ($missing !== []) {
                throw new LocalizedValueRejected(
                    'partial_locale_denied',
                    'Locale ' . $locale . ' is missing required field(s): ' . implode(', ', $missing) . '.',
                );
            }
        }

        // A guard may refuse the translation for what it changes (a slug on a published revision).
        $observer = $this->observer !== null ? ($this->observer)() : null;
        if ($observer instanceof LocalizedValueObserverChain) {
            $observer->assertMayWrite($schemaId, $recordId, $revision, $locale, $values);
        } elseif ($observer instanceof LocalizedValueGuard) {
            $observer->assertMayWriteLocalized($schemaId, $recordId, $revision, $locale, $values);
        }

        // A translation passes the same Property validation as the shared value: the
        // field's type, version and constraints come from the schema version of the
        // revision it translates. A field the schema does not know is refused.
        // Whether a field is encrypted is the storage's setting now, whatever revision the translation belongs to: a
        // field switched on seals every new translation, one switched off writes them plain (its encryption run
        // brings the stored ones to the same).
        $now = $this->currentEncryption($schemaId);
        $encryptedNow = $now['encrypted'] ?? StorageEncryptionPolicy::encryptedKeys($schema['fields']);
        $encrypted = array_values(array_intersect($encryptedNow, array_map('strval', array_keys($values))));
        // An envelope given for a field that is plain now (read while its decrypt run opens it) is opened too.
        $given = StorageFieldSealer::envelopeKeys($values);
        if ($encrypted === [] && $given === []) {
            $values = $this->validatedValues($schema['fields'], $values);
        } else {
            if ($this->sealer === null) {
                throw new LocalizedValueRejected(StorageEncryptionPolicy::UNAVAILABLE, 'Encrypted values cannot be written here.');
            }
            try {
                $this->sealer->keys()->assertAvailable();
                [$plain, $given] = $this->sealer->open($schemaId, array_values(array_unique(array_merge($encrypted, $given))), $recordId, $locale, $values);
                $values = $this->sealer->seal($schemaId, $encrypted, $recordId, $locale, $this->validatedValues($schema['fields'], $plain), $given);
            } catch (StorageRejected $exception) {
                throw new LocalizedValueRejected($exception->reasonCode, 'An encrypted value cannot be written.');
            }
        }

        $schemaNow = $now['version'] ?? null;
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
            $schemaNow,
            &$written
        ): void {
            // As record writers do: the storage's schema row for share, so a schema switch waits for this write,
            // and one that came first (encryption switched meanwhile) refuses it.
            if ($schemaNow !== null) {
                $version = $this->connection->table('larena_storage_schemas')->where('schema_id', $schemaId)->sharedLock()->value('current_version');
                if ($version === null || (int) $version !== $schemaNow) {
                    throw new LocalizedValueRejected('schema_changed', 'The storage schema changed while the translation was written; write it again.');
                }
                // Sealed before this transaction: under the key that is still active (a rotation that came first
                // would leave the value under a key it is about to delete).
                $sealedWith = [];
                foreach ($values as $value) {
                    if (StorageFieldEnvelope::isEnvelope($value) && is_string($value['k'] ?? null)) {
                        $sealedWith[$value['k']] = true;
                    }
                }
                if ($sealedWith !== [] && array_keys($sealedWith) !== [$this->sealer?->keys()->activeKeyId($schemaId)]) {
                    throw new LocalizedValueRejected('schema_changed', 'The storage data key changed while the translation was written; write it again.');
                }
            }
            // A translation and a publication of one record are serialized on the record's
            // publication rows, taken first, so neither waits for the other in the opposite order.
            if ($this->observer !== null && $this->hasPublications()) {
                $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
                    ->where('schema_id', $schemaId)
                    ->where('record_id', $recordId)
                    ->lockForUpdate()
                    ->get(['publication_id']);
            }
            foreach ($values as $fieldKey => $value) {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($this->replaceCarried($schemaId, $recordId, $revision, $locale, (string) $fieldKey, $encoded, $actorId, $correlationId, $now)) {
                    $written[] = (string) $fieldKey;
                    continue;
                }

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

            if ($written !== []) {
                $this->tellIndexes($schemaId, $recordId, $revision, $locale);
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
     * Copies a revision's translations onto the next revision of the record, in the writer's transaction (sites plan,
     * step 4.1): the translations of the fields still localized under the next revision's schema version, but those
     * $except names, which the write itself replaces. A record's update, archive and restore do it; no audit event of
     * its own, since nothing changes that the revision's own event does not say.
     *
     * @param array<string, list<string>> $except by locale: the fields left behind
     * @return list<string> the locales copied
     * @phpstan-impure
     */
    public function carryForward(string $schemaId, string $recordId, int $fromRevision, int $toRevision, string $actorId, ?string $correlationId = null, array $except = []): array
    {
        if ($this->table !== true) {
            $this->table = $this->connection->getSchemaBuilder()->hasTable(self::TABLE);
        }
        if (!$this->table) {
            return [];
        }
        $rows = $this->connection->table(self::TABLE)
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $fromRevision)
            ->orderBy('id')->get(['locale', 'field_key', 'value_json', 'content_hash']);
        if ($rows->isEmpty()) {
            return [];
        }
        $localized = array_filter($this->revisionSchema($schemaId, $recordId, $toRevision)['fields'], static fn (array $field): bool => ($field['localized'] ?? false) === true);
        $now = $this->now();
        $inserts = [];
        $locales = [];
        foreach ($rows as $row) {
            $locale = (string) $row->locale;
            $field = (string) $row->field_key;
            if (isset($localized[$field]) && !in_array($field, $except[$locale] ?? [], true)) {
                $inserts[] = ['schema_id' => $schemaId, 'record_id' => $recordId, 'revision' => $toRevision, 'locale' => $locale, 'field_key' => $field,
                    'value_json' => $row->value_json, 'content_hash' => $row->content_hash, 'created_by' => $actorId, 'correlation_id' => $correlationId, 'created_at' => $now];
                $locales[$locale] = true;
            }
        }
        if ($inserts === []) {
            return [];
        }
        $this->connection->transaction(function () use ($schemaId, $recordId, $toRevision, $inserts, $locales): void {
            if ($this->observer !== null && $this->hasPublications()) {
                $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
                    ->where('schema_id', $schemaId)->where('record_id', $recordId)
                    ->lockForUpdate()->get(['publication_id']);
            }
            foreach (array_chunk($inserts, 200) as $chunk) {
                $this->connection->table(self::TABLE)->insert($chunk);
            }
            foreach (array_keys($locales) as $locale) {
                $this->tellIndexes($schemaId, $recordId, $toRevision, (string) $locale);
            }
        });

        return array_map('strval', array_keys($locales));
    }

    /**
     * A row a draft only inherited (carryForward(), the same content as a revision before it) is not the draft's own yet:
     * a translation written for the draft takes its place, so a writer's order (the update, then its translations) goes
     * on working. A revision that ever went out, or a row it wrote itself, stays immutable.
     *
     * @phpstan-impure
     */
    private function replaceCarried(string $schemaId, string $recordId, int $revision, string $locale, string $fieldKey, string $encoded, string $actorId, ?string $correlationId, string $now): bool
    {
        if ($revision < 2) {
            return false;
        }
        $row = fn () => $this->connection->table(self::TABLE)
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('locale', $locale)->where('field_key', $fieldKey);
        $hash = $row()->where('revision', $revision)->value('content_hash');
        if ($hash === null || !$row()->where('revision', '<', $revision)->where('content_hash', $hash)->exists()) {
            return false;
        }
        if ($this->hasPublications() && $this->connection->table(DatabasePublicationLifecycle::LOG_TABLE)
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists()) {
            return false;
        }
        $row()->where('revision', $revision)->update(['value_json' => $encoded, 'content_hash' => hash('sha256', $encoded),
            'created_by' => $actorId, 'correlation_id' => $correlationId, 'created_at' => $now]);

        return true;
    }

    /**
     * A translation of a published revision changes what it shows, and one of any revision moves the record's projection
     * version; the derived indexes learn of it in the same transaction. An index they do not include cannot follow it:
     * its sets or slots of the storage stop answering.
     *
     * @phpstan-impure
     */
    private function tellIndexes(string $schemaId, string $recordId, int $revision, string $locale): void
    {
        $observer = $this->observer !== null ? ($this->observer)() : null;
        $observer?->localizedValuesWritten($schemaId, $recordId, $revision, $locale);
        if (!LocalizedValueObserverChain::maintains($observer, PublishedKeyIndex::class)) {
            ($this->keyTable ??= new PublishedKeyTable($this->connection))->silenceSchema($schemaId);
        }
        if (!LocalizedValueObserverChain::maintains($observer, PublishedSiteViewIndex::class)) {
            ($this->siteViews ??= new PublishedSiteViewRegistry($this->connection, 0.0))->silenceSchema($schemaId);
        }
    }

    /**
     * The required fields nothing answers for in this revision: no shared value, no row of the language yet.
     *
     * @param list<string> $missing
     * @return list<string>
     * @phpstan-impure
     */
    private function withoutAnswered(string $schemaId, string $recordId, int $revision, string $locale, array $missing): array
    {
        if ($missing === []) {
            return [];
        }
        $shared = json_decode((string) $this->connection->table('larena_storage_record_versions')
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->value('values_json'), true);
        $present = $this->connection->table(self::TABLE)
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->where('locale', $locale)
            ->whereIn('field_key', $missing)->pluck('field_key')->all();

        return array_values(array_filter($missing, static fn (string $key): bool => !in_array($key, $present, true)
            && !(is_array($shared) && ($shared[$key] ?? null) !== null)));
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

        return $this->masked($schemaId, $recordId, $values);
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

        return $this->resolveRows($byFieldAndLocale, $chain);
    }

    /**
     * @param list<array{record_id: string, revision: int}> $heads
     * @param list<string> $fieldKeys
     * @param bool $lock read with a shared lock inside the caller's transaction (the site витрина
     *        builds its copy of an entry from the latest committed values)
     * @return array<string, array<string, LocalizedValue>>
     * @phpstan-impure
     */
    public function resolveMany(string $schemaId, array $heads, LocaleFallbackChain $chain, array $fieldKeys = [], bool $lock = false): array
    {
        $this->assertSchema();

        $resolved = [];
        // One query per chunk of revisions instead of one per record: the rows of
        // other revisions of the same records are dropped here, not asked for apart.
        foreach (array_chunk($heads, self::BATCH) as $chunk) {
            $wanted = [];
            foreach ($chunk as $head) {
                $wanted[$head['record_id']] = $head['revision'];
            }
            $query = $this->connection->table(self::TABLE)
                ->select(['record_id', 'revision', 'locale', 'field_key', 'value_json'])
                ->where('schema_id', $schemaId)
                ->whereIn('record_id', array_values(array_unique(array_column($chunk, 'record_id'))))
                ->whereIn('revision', array_values(array_unique(array_column($chunk, 'revision'))))
                ->whereIn('locale', $chain->locales);
            if ($fieldKeys !== []) {
                $query->whereIn('field_key', $fieldKeys);
            }
            if ($lock) {
                $query->sharedLock();
            }

            $byRecord = [];
            foreach ($query->get() as $row) {
                $row = (array) $row;
                $recordId = (string) $row['record_id'];
                if (($wanted[$recordId] ?? null) !== (int) $row['revision']) {
                    continue;
                }
                $byRecord[$recordId][(string) $row['field_key']][(string) $row['locale']] = $row['value_json'];
            }

            foreach ($chunk as $head) {
                $resolved[$head['record_id']] = $this->resolveRows($byRecord[$head['record_id']] ?? [], $chain);
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, array<string, mixed>> $byFieldAndLocale
     * @return array<string, LocalizedValue>
     */
    private function resolveRows(array $byFieldAndLocale, LocaleFallbackChain $chain): array
    {
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

    /** Whether the publication states are migrated. Only a yes is remembered. @phpstan-impure */
    private function hasPublications(): bool
    {
        if ($this->publications !== true) {
            $this->publications = $this->connection->getSchemaBuilder()->hasTable(DatabasePublicationLifecycle::STATES_TABLE);
        }

        return $this->publications;
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
