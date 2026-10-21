<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * What an encrypted field («Шифровать», `encrypted: true` in the versioned schema) may be, and the reason codes of
 * every refusal (larena-specs docs/architecture/2026-10-22-storage-field-encryption.md):
 *
 * - types: string, text, integer, number, date and datetime; choice, choices, boolean, references and files cannot
 *   (TYPE_UNSUPPORTED);
 * - visibility `admin` only (VISIBILITY_REFUSED): no site витрина, search or published read can ever see it;
 * - only a schema owned by larena/storage (OWNER_UNSUPPORTED);
 * - no uniqueness (UNIQUE_UNSUPPORTED), no search text (SEARCHABLE_REFUSED), no index (INDEXED_REFUSED), never a
 *   published key (KEY_FIELD_REFUSED);
 * - a list cannot filter, sort or search by it (FILTER_UNSUPPORTED, SORT_UNSUPPORTED, SEARCH_UNSUPPORTED);
 * - while encrypted a field is not retyped, and a field with values does not change its constraints or become
 *   required (CHANGE_REFUSED); switching encryption on or off a field with values needs an encryption run
 *   (assertSwitch; a workbench structure starts one), elsewhere it is refused (REWRITE_REQUIRED);
 * - the runs (slice B): a structure whose run is queued or running takes no change (RUNNING), nor one whose run
 *   failed until it is retried (FAILED); switching off a field with values needs an explicit confirmation
 *   (DISABLE_UNCONFIRMED); one change switches fields one way only and with nothing every record must pass
 *   (CHANGE_COMBINED); a key rotation needs a data key (NOT_ENABLED); only a failed run is retried (NOT_FAILED);
 * - no sodium or no key-encryption key (UNAVAILABLE), a data key that does not unwrap (KEY_UNAVAILABLE).
 */
final class StorageEncryptionPolicy
{
    public const OWNER_PACKAGE = 'larena/storage';
    public const ENCRYPTABLE_TYPES = ['string', 'text', 'integer', 'number', 'date', 'datetime'];
    public const VISIBILITY = 'admin';

    public const TYPE_UNSUPPORTED = 'storage_field_encrypted_type_unsupported';
    public const VISIBILITY_REFUSED = 'storage_field_encrypted_visibility_refused';
    public const SEARCHABLE_REFUSED = 'storage_field_encrypted_searchable_refused';
    public const INDEXED_REFUSED = 'storage_field_encrypted_indexed_refused';
    public const KEY_FIELD_REFUSED = 'storage_field_encrypted_key_field_refused';
    public const CHANGE_REFUSED = 'storage_field_encrypted_change_refused';
    public const OWNER_UNSUPPORTED = 'storage_field_encrypted_owner_unsupported';
    public const FILTER_UNSUPPORTED = 'storage_field_encrypted_filter_unsupported';
    public const SORT_UNSUPPORTED = 'storage_field_encrypted_sort_unsupported';
    public const SEARCH_UNSUPPORTED = 'storage_field_encrypted_search_unsupported';
    public const UNIQUE_UNSUPPORTED = 'storage_field_encrypted_unique_unsupported';
    public const UNAVAILABLE = 'storage_field_encryption_unavailable';
    public const KEY_UNAVAILABLE = 'storage_field_encryption_key_unavailable';
    public const REWRITE_REQUIRED = 'storage_field_encryption_rewrite_required';
    // Slice B: the encryption runs.
    public const RUNNING = 'storage_field_encryption_running';
    public const FAILED = 'storage_field_encryption_failed';
    public const DISABLE_UNCONFIRMED = 'storage_field_encryption_disable_unconfirmed';
    public const CHANGE_COMBINED = 'storage_field_encryption_change_combined';
    public const NOT_ENABLED = 'storage_field_encryption_not_enabled';
    public const NOT_FAILED = 'storage_field_encryption_run_not_failed';
    public const ENVELOPE_INVALID = StorageFieldEnvelope::REASON_INVALID;

    /** Every reason code of the policy, for documentation and tests. */
    public const REASON_CODES = [
        self::TYPE_UNSUPPORTED,
        self::VISIBILITY_REFUSED,
        self::SEARCHABLE_REFUSED,
        self::INDEXED_REFUSED,
        self::KEY_FIELD_REFUSED,
        self::CHANGE_REFUSED,
        self::OWNER_UNSUPPORTED,
        self::FILTER_UNSUPPORTED,
        self::SORT_UNSUPPORTED,
        self::SEARCH_UNSUPPORTED,
        self::UNIQUE_UNSUPPORTED,
        self::UNAVAILABLE,
        self::KEY_UNAVAILABLE,
        self::REWRITE_REQUIRED,
        self::ENVELOPE_INVALID,
        self::RUNNING,
        self::FAILED,
        self::DISABLE_UNCONFIRMED,
        self::CHANGE_COMBINED,
        self::NOT_ENABLED,
        self::NOT_FAILED,
    ];

    private const VERSIONS_TABLE = 'larena_storage_record_versions';
    private const LOCALIZED_TABLE = 'larena_storage_localized_values';

    /** @param array<array-key, mixed> $field */
    public static function isEncrypted(array $field): bool
    {
        return ($field['encrypted'] ?? false) === true;
    }

    /**
     * The keys of the encrypted fields among $fields.
     *
     * @param iterable<mixed> $fields
     * @return list<string>
     */
    public static function encryptedKeys(iterable $fields): array
    {
        $keys = [];
        foreach ($fields as $field) {
            if (is_array($field) && self::isEncrypted($field) && is_string($field['key'] ?? null)) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /**
     * One field of a schema definition that says `encrypted: true`.
     *
     * @param array<string, mixed> $field key, type, visibility, constraints
     * @throws StorageRejected
     */
    public static function assertField(array $field, string $ownerPackage): void
    {
        if ($ownerPackage !== self::OWNER_PACKAGE) {
            throw new StorageRejected(self::OWNER_UNSUPPORTED);
        }
        if (!in_array($field['type'] ?? null, self::ENCRYPTABLE_TYPES, true)) {
            throw new StorageRejected(self::TYPE_UNSUPPORTED);
        }
        if (($field['visibility'] ?? null) !== self::VISIBILITY) {
            throw new StorageRejected(self::VISIBILITY_REFUSED);
        }
        $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
        if (array_key_exists('unique', $constraints)) {
            throw new StorageRejected(self::UNIQUE_UNSUPPORTED);
        }
    }

    /**
     * The витрина options of an encrypted workbench field: neither search text nor an index.
     *
     * @param array<array-key, mixed> $field
     * @throws StorageRejected
     */
    public static function assertOptions(array $field): void
    {
        if (!self::isEncrypted($field)) {
            return;
        }
        if (($field['searchable'] ?? null) === true) {
            throw new StorageRejected(self::SEARCHABLE_REFUSED);
        }
        if (($field['indexed'] ?? null) === true) {
            throw new StorageRejected(self::INDEXED_REFUSED);
        }
    }

    /**
     * A schema change, field by field: encryption switches only on a field without values, an encrypted field is not
     * retyped, and one with values keeps its constraints and stays optional when it was. Added and removed fields
     * pass (a removed encrypted field's old values stay sealed in history).
     *
     * @param array<string, array<string, mixed>> $from fields by key before the change
     * @param array<string, array<string, mixed>> $to fields by key after it
     * @param Closure(string): bool $hasValues whether any version or translation holds a value of the field
     * @return list<string> the keys whose encryption switches
     * @throws StorageRejected
     */
    public static function assertChange(array $from, array $to, Closure $hasValues): array
    {
        $result = self::assertSwitch($from, $to, $hasValues);
        if ($result['rewrite'] !== []) {
            throw new StorageRejected(self::REWRITE_REQUIRED);
        }

        return $result['switched'];
    }

    /**
     * As assertChange(), for a change that can rewrite what is stored (a workbench structure with encryption runs):
     * a field with values whose encryption switches is not refused but listed in `rewrite`, for the encryption run
     * that seals or opens its values after the switch.
     *
     * @param array<string, array<string, mixed>> $from
     * @param array<string, array<string, mixed>> $to
     * @param Closure(string): bool $hasValues
     * @return array{switched: list<string>, rewrite: list<string>}
     * @throws StorageRejected
     */
    public static function assertSwitch(array $from, array $to, Closure $hasValues): array
    {
        $switched = [];
        $rewrite = [];
        foreach ($to as $key => $target) {
            $source = $from[$key] ?? null;
            if ($source === null) {
                continue;
            }
            $wasEncrypted = self::isEncrypted($source);
            $isEncrypted = self::isEncrypted($target);
            if ($wasEncrypted !== $isEncrypted) {
                if ($hasValues((string) $key)) {
                    $rewrite[] = (string) $key;
                }
                $switched[] = (string) $key;
                continue;
            }
            if (!$isEncrypted) {
                continue;
            }
            if (($source['type'] ?? null) !== ($target['type'] ?? null)
                || ($source['type_version'] ?? 1) !== ($target['type_version'] ?? 1)) {
                throw new StorageRejected(self::CHANGE_REFUSED);
            }
            $constraintsChanged = StorageFieldCipher::canonicalJson($source['constraints'] ?? [])
                !== StorageFieldCipher::canonicalJson($target['constraints'] ?? []);
            $madeRequired = ($source['required'] ?? false) !== true && ($target['required'] ?? false) === true;
            if (($constraintsChanged || $madeRequired) && $hasValues((string) $key)) {
                throw new StorageRejected(self::CHANGE_REFUSED);
            }
        }

        return ['switched' => $switched, 'rewrite' => $rewrite];
    }

    /**
     * Whether any record version or translation of a storage holds a value of the field (a JSON null counts on
     * MySQL, which keeps the check on the safe side).
     */
    public static function fieldHasValues(ConnectionInterface $database, string $schemaId, string $fieldKey): bool
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $fieldKey) !== 1) {
            return true;
        }
        if ($database->table(self::VERSIONS_TABLE)
            ->where('schema_id', $schemaId)
            ->whereNotNull('values_json->' . $fieldKey)
            ->exists()) {
            return true;
        }
        if ($database instanceof Connection && !$database->getSchemaBuilder()->hasTable(self::LOCALIZED_TABLE)) {
            return false;
        }

        return $database->table(self::LOCALIZED_TABLE)
            ->where('schema_id', $schemaId)
            ->where('field_key', $fieldKey)
            ->exists();
    }
}
