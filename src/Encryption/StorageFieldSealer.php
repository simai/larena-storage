<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;

/**
 * Seals the encrypted fields of one record version (or one locale of its translations) on the write path, after the
 * values are normalized and validated and before they are hashed and stored:
 *
 * 1. an envelope given for an encrypted field (a value read and sent back unchanged, an archive, a restore, a revert,
 *    an editor who replaces other fields without reading this one) is opened: it must belong to this storage,
 *    record, locale and field, else `storage_field_envelope_invalid`;
 * 2. every value is normalized and validated as any write does: validation runs on the plaintext, before encryption;
 * 3. each encrypted value is sealed: the envelope given for it, or the previous version's, when it holds the same
 *    value (so an unchanged value keeps its ciphertext and the content hash stays stable); else a new envelope under
 *    the storage's active key.
 *
 * The stored values hold envelopes only; withoutEncrypted() gives the values the витрина may see. Nothing here
 * writes: a failure (no sodium, no key-encryption key, a key that does not unwrap) is a StorageRejected the caller's
 * transaction rolls back on, so a write fails closed before anything changes.
 */
final readonly class StorageFieldSealer
{
    public function __construct(
        private StorageDataKeys $keys,
        private StorageFieldCipher $cipher = new StorageFieldCipher(),
    ) {
    }

    public function keys(): StorageDataKeys
    {
        return $this->keys;
    }

    /**
     * The stored values of one record version of $schema: normalized, its encrypted fields sealed.
     *
     * @param array<array-key, mixed> $values the caller's values (plaintext or envelopes for encrypted fields)
     * @param array<string, mixed> $previous the stored values of the record's previous version, whose envelopes an
     *        unchanged value reuses
     * @return array<string, mixed>
     * @throws StorageRejected
     */
    public function sealRecord(
        SchemaDefinitionNormalizer $normalizer,
        StorageSchemaVersion $schema,
        string $recordId,
        #[\SensitiveParameter] array $values,
        array $previous = [],
    ): array {
        $encrypted = StorageEncryptionPolicy::encryptedKeys($schema->fields);
        $sealed = self::envelopeKeys($values);
        if ($encrypted === [] && $sealed === []) {
            return $normalizer->normalizeValues($schema, $values);
        }
        $this->keys->assertAvailable();
        // An envelope given for a field that is no longer encrypted (a value read while a decrypt run opens the
        // field, sent back unchanged) is opened too, and stored plain.
        [$plain, $given] = $this->open($schema->ref->schemaId, array_values(array_unique(array_merge($encrypted, $sealed))), $recordId, null, $values);
        $normalized = $normalizer->normalizeValues($schema, $plain);

        return $this->seal($schema->ref->schemaId, $encrypted, $recordId, null, $normalized, $given, $previous);
    }

    /**
     * Opens the envelopes given for encrypted fields, for validation; other values pass as they are.
     *
     * @param list<string> $encrypted
     * @param array<array-key, mixed> $values
     * @return array{0: array<array-key, mixed>, 1: array<string, array{envelope: StorageFieldEnvelope, plain: string}>}
     * @throws StorageRejected
     */
    public function open(string $schemaId, array $encrypted, string $recordId, ?string $locale, #[\SensitiveParameter] array $values): array
    {
        $given = [];
        foreach ($encrypted as $key) {
            if (!array_key_exists($key, $values) || !StorageFieldEnvelope::isEnvelope($values[$key])) {
                continue;
            }
            $envelope = StorageFieldEnvelope::fromValue($values[$key]);
            $value = $this->cipher->decrypt($this->keys->key($schemaId, $envelope->keyId), $envelope, $schemaId, $recordId, $locale, $key);
            $given[$key] = ['envelope' => $envelope, 'plain' => StorageFieldCipher::canonicalJson($value)];
            $values[$key] = $value;
        }

        return [$values, $given];
    }

    /**
     * Seals the encrypted values of normalized values.
     *
     * @param list<string> $encrypted
     * @param array<string, mixed> $normalized
     * @param array<string, array{envelope: StorageFieldEnvelope, plain: string}> $given what open() found
     * @param array<string, mixed> $previous stored values whose envelopes an unchanged value reuses
     * @return array<string, mixed>
     * @throws StorageRejected
     */
    public function seal(
        string $schemaId,
        array $encrypted,
        string $recordId,
        ?string $locale,
        #[\SensitiveParameter] array $normalized,
        #[\SensitiveParameter] array $given = [],
        array $previous = [],
    ): array {
        $active = null;
        // An envelope is reused only when the active key sealed it: a value of a retiring key (a rotation runs) is
        // sealed again under the active one.
        $activeId = null;
        foreach ($encrypted as $key) {
            if (!array_key_exists($key, $normalized) || $normalized[$key] === null) {
                continue;
            }
            $plain = StorageFieldCipher::canonicalJson($normalized[$key]);
            $sealed = null;
            if (isset($given[$key]) && hash_equals($given[$key]['plain'], $plain)
                && $given[$key]['envelope']->keyId === ($activeId ??= $this->keys->ensureActive($schemaId))) {
                $sealed = $given[$key]['envelope'];
            }
            $before = $sealed === null ? self::envelopeOrNull($previous[$key] ?? null) : null;
            if ($before !== null && $before->keyId !== ($activeId ??= $this->keys->ensureActive($schemaId))) {
                $before = null;
            }
            if ($before !== null) {
                try {
                    $opened = $this->cipher->decrypt($this->keys->key($schemaId, $before->keyId), $before, $schemaId, $recordId, $locale, $key);
                    if (hash_equals(StorageFieldCipher::canonicalJson($opened), $plain)) {
                        $sealed = $before;
                    }
                } catch (StorageRejected) {
                    // An earlier envelope that no longer opens is not reused; the value is sealed afresh.
                }
            }
            if ($sealed === null) {
                $active ??= $this->keys->active($schemaId);
                $sealed = $this->cipher->encrypt($active[1], $active[0], $schemaId, $recordId, $locale, $key, $normalized[$key]);
            }
            $normalized[$key] = $sealed->toArray();
        }

        return $normalized;
    }

    /**
     * The values without the encrypted fields: what the витрина and any derived copy may see.
     *
     * @param list<string> $encrypted
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function withoutEncrypted(array $encrypted, array $values): array
    {
        foreach ($encrypted as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    /**
     * The keys whose given value is an envelope.
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    public static function envelopeKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && StorageFieldEnvelope::isEnvelope($value)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * A value stored in plaintext in a field that is encrypted now (an encryption run has not reached it yet), shown
     * as an envelope: sealed under the active key for this read only, so it opens through a reveal and an editor who
     * sends it back unchanged keeps the value. Null when it cannot be sealed here (the reader then leaves it out).
     * A read never makes a key.
     *
     * @return array{larena_enc: int, a: string, k: string, n: string, c: string}|null
     */
    public function sealForRead(string $schemaId, string $recordId, ?string $locale, string $fieldKey, #[\SensitiveParameter] mixed $value): ?array
    {
        try {
            $keyId = $this->keys->activeKeyId($schemaId);
            if ($keyId === null) {
                return null;
            }

            return $this->cipher->encrypt($this->keys->key($schemaId, $keyId), $keyId, $schemaId, $recordId, $locale, $fieldKey, $value)->toArray();
        } catch (StorageRejected) {
            return null;
        }
    }

    /**
     * The values a reader shows, given the encrypted fields now: an encrypted field whose stored value is still
     * plaintext is sealed for the read (sealForRead), or left out when it cannot be.
     *
     * @param list<string> $encrypted
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function maskPlaintext(string $schemaId, string $recordId, ?string $locale, array $encrypted, #[\SensitiveParameter] array $values): array
    {
        foreach ($encrypted as $key) {
            if (!array_key_exists($key, $values) || $values[$key] === null || StorageFieldEnvelope::isEnvelope($values[$key])) {
                continue;
            }
            $sealed = $this->sealForRead($schemaId, $recordId, $locale, $key, $values[$key]);
            if ($sealed === null) {
                unset($values[$key]);
            } else {
                $values[$key] = $sealed;
            }
        }

        return $values;
    }

    private static function envelopeOrNull(mixed $value): ?StorageFieldEnvelope
    {
        if (!StorageFieldEnvelope::isEnvelope($value)) {
            return null;
        }
        try {
            return StorageFieldEnvelope::fromValue($value);
        } catch (StorageRejected) {
            return null;
        }
    }
}
