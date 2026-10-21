<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * Encrypts and decrypts one field value with a storage's data key: XChaCha20-Poly1305-IETF (ext-sodium) with a random
 * 24-byte nonce.
 *
 * - Plaintext: the canonical JSON of `{"v": value}`, padded with sodium_pad to a block of 32 bytes (a plaintext under
 *   256 bytes) or 256 bytes (a longer one), so the stored length tells little about the value.
 * - Associated data: the canonical JSON list `["larena/storage/field", schema id, record id, locale or "", field key,
 *   key id]`. A value moved to another storage, record, locale or field, or relabelled with another key id, does not
 *   decrypt. The revision is left out on purpose: an unchanged value, an archive, a restore and a revert keep their
 *   ciphertext. The label is data: changing it would make every stored value unreadable.
 *
 * Every failure is a StorageRejected with a reason code only, never chained: the original exception (or its trace)
 * could hold the key or the value. The key and the value are #[\SensitiveParameter], so a trace never shows them.
 */
final readonly class StorageFieldCipher
{
    public const AAD_LABEL = 'larena/storage/field';
    public const KEY_BYTES = 32;
    public const REASON_UNAVAILABLE = 'storage_field_encryption_unavailable';

    private const SHORT_BLOCK = 32;
    private const LONG_BLOCK = 256;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            && function_exists('sodium_pad')
            && function_exists('sodium_unpad');
    }

    /**
     * @throws StorageRejected storage_field_encryption_unavailable
     */
    public function encrypt(
        #[\SensitiveParameter] string $dataKey,
        string $keyId,
        string $schemaId,
        string $recordId,
        ?string $locale,
        string $fieldKey,
        #[\SensitiveParameter] mixed $value,
    ): StorageFieldEnvelope {
        if (!self::available() || strlen($dataKey) !== self::KEY_BYTES) {
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        }
        $plaintext = null;
        $padded = null;
        try {
            $plaintext = self::canonicalJson(['v' => $value]);
            $padded = sodium_pad($plaintext, strlen($plaintext) < self::LONG_BLOCK ? self::SHORT_BLOCK : self::LONG_BLOCK);
            $nonce = random_bytes(StorageFieldEnvelope::NONCE_BYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $padded,
                self::aad($schemaId, $recordId, $locale, $fieldKey, $keyId),
                $nonce,
                $dataKey,
            );
        } catch (Throwable) {
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        } finally {
            if (is_string($plaintext)) {
                sodium_memzero($plaintext);
            }
            if (is_string($padded)) {
                sodium_memzero($padded);
            }
        }

        return new StorageFieldEnvelope($keyId, $nonce, $ciphertext);
    }

    /**
     * @throws StorageRejected storage_field_envelope_invalid when the envelope does not open with this key and
     *                         associated data, or its plaintext is not the padded `{"v": …}`
     */
    public function decrypt(
        #[\SensitiveParameter] string $dataKey,
        StorageFieldEnvelope $envelope,
        string $schemaId,
        string $recordId,
        ?string $locale,
        string $fieldKey,
    ): mixed {
        if (!self::available() || strlen($dataKey) !== self::KEY_BYTES) {
            throw new StorageRejected(self::REASON_UNAVAILABLE);
        }
        $padded = false;
        $plaintext = null;
        try {
            $padded = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $envelope->ciphertext,
                self::aad($schemaId, $recordId, $locale, $fieldKey, $envelope->keyId),
                $envelope->nonce,
                $dataKey,
            );
            if (!is_string($padded)) {
                throw new StorageRejected(StorageFieldEnvelope::REASON_INVALID);
            }
            $plaintext = sodium_unpad($padded, strlen($padded) <= self::LONG_BLOCK ? self::SHORT_BLOCK : self::LONG_BLOCK);
            $decoded = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected(StorageFieldEnvelope::REASON_INVALID);
        } finally {
            if (is_string($padded)) {
                sodium_memzero($padded);
            }
            if (is_string($plaintext)) {
                sodium_memzero($plaintext);
            }
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['v']) {
            throw new StorageRejected(StorageFieldEnvelope::REASON_INVALID);
        }

        return $decoded['v'];
    }

    /** The associated data of one value: where it belongs and the key that sealed it. */
    public static function aad(string $schemaId, string $recordId, ?string $locale, string $fieldKey, string $keyId): string
    {
        return self::canonicalJson([self::AAD_LABEL, $schemaId, $recordId, $locale ?? '', $fieldKey, $keyId]);
    }

    /** The canonical JSON every storage hash uses: maps sorted by key, lists kept in order. */
    public static function canonicalJson(#[\SensitiveParameter] mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function canonicalize(#[\SensitiveParameter] mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
