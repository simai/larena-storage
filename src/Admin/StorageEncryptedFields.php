<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldEnvelope;

/**
 * How the Storage section treats an encrypted field («Шифровать», larena-specs
 * docs/architecture/2026-10-22-storage-field-encryption.md, «Reading»): Storage returns every encrypted value as an
 * envelope, and the envelope never leaves the server.
 *
 * - The section's snapshot carries a masked value in its place: {larena_sealed: true, fingerprint}. The
 *   fingerprint is a digest of the envelope (the ciphertext is random, so it says nothing about the value); the
 *   history compares two versions by it («изменено» / «без изменений»), and nothing draws it.
 * - Lists, the record panel and CSV show «•••• зашифровано»; a filter, a sort or a search never offers the field.
 * - A record.update that leaves an encrypted field out (or empty) keeps its stored envelope: Storage's sealer takes
 *   an envelope of the same record and field back as the unchanged value. An encrypted value is replaced, never
 *   cleared through the section, as a password is.
 */
final class StorageEncryptedFields
{
    /** The member that marks a masked value in the section's snapshot. */
    public const MASKED = 'larena_sealed';

    /** @param array<array-key, mixed> $field */
    public static function isEncrypted(array $field): bool
    {
        return StorageEncryptionPolicy::isEncrypted($field);
    }

    /** Whether a field of this type may be encrypted. */
    public static function encryptable(string $type): bool
    {
        return StorageEncryptionPolicy::encryptable($type);
    }

    /**
     * The keys of the encrypted fields.
     *
     * @param iterable<mixed> $fields
     * @return list<string>
     */
    public static function keys(iterable $fields): array
    {
        return StorageEncryptionPolicy::encryptedKeys($fields);
    }

    /** Whether a stored value is an envelope. */
    public static function isEnvelope(mixed $value): bool
    {
        return StorageFieldEnvelope::isEnvelope($value);
    }

    /** Whether a value of the snapshot is a masked encrypted value. */
    public static function isMasked(mixed $value): bool
    {
        return is_array($value) && ($value[self::MASKED] ?? null) === true;
    }

    /** Whether a value stands for an encrypted value: an envelope or its mask. */
    public static function isSealed(mixed $value): bool
    {
        return self::isEnvelope($value) || self::isMasked($value);
    }

    /**
     * The values with every envelope masked.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public static function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (self::isEnvelope($value)) {
                $values[$key] = [self::MASKED => true, 'fingerprint' => self::fingerprint($value)];
            }
        }

        return $values;
    }

    /** The digest an envelope or its mask is compared by; null for any other value. */
    public static function fingerprintOf(mixed $value): ?string
    {
        if (self::isMasked($value)) {
            return is_string($value['fingerprint'] ?? null) ? $value['fingerprint'] : null;
        }

        return self::isEnvelope($value) ? self::fingerprint($value) : null;
    }

    /**
     * Whether two versions hold the same encrypted value: both empty, or the same envelope (Storage keeps the
     * envelope of an unchanged value, so a new envelope is a changed value).
     */
    public static function same(mixed $before, mixed $after): bool
    {
        $left = self::fingerprintOf($before);
        $right = self::fingerprintOf($after);
        if ($left === null || $right === null) {
            return ($before === null) === ($after === null) && $left === $right;
        }

        return hash_equals($left, $right);
    }

    /** What the section shows for an encrypted value, in the person's language. */
    public static function maskText(): string
    {
        return self::word('masked', '•••• encrypted');
    }

    public static function word(string $key, string $fallback): string
    {
        $container = \Illuminate\Container\Container::getInstance();
        if ($container->bound('translator')) {
            $text = $container->make('translator')->get('larena-storage::admin.section.encryption.'.$key);
            if (is_string($text) && !str_starts_with($text, 'larena-storage::')) {
                return $text;
            }
        }

        return $fallback;
    }

    /** @param array<array-key, mixed> $envelope */
    private static function fingerprint(array $envelope): string
    {
        ksort($envelope, SORT_STRING);

        return hash('sha256', (string) json_encode($envelope, JSON_UNESCAPED_SLASHES));
    }
}
