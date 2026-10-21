<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Illuminate\Database\ConnectionInterface;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Property\Runtime\PropertyTypeRegistry as BuiltInPropertyTypes;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;
use Throwable;

/**
 * The read converter: the values of a record version written under an older
 * schema version, read under a newer one. A field change no longer rewrites the
 * record versions; each version keeps the schema version it was written under,
 * and every reader of current values passes it through here.
 *
 * The conversion walks the schema versions one change at a time, so a field that
 * was removed and later added again with the same key does not bring the removed
 * value back. One step, from the fields of version N to those of version N+1:
 *
 * - a value whose field is gone is dropped (the owner removed it);
 * - a value whose field keeps its type and type version is kept as stored;
 * - a value whose field changed type is converted by the fixed rules of
 *   DeclaredFieldValueTransform and normalized and checked by the field's Property
 *   type with its constraints; a value that cannot be converted or does not pass
 *   is dropped (a current record always passes: a retype is applied only once
 *   every current record converts; only an older history version can lose one);
 * - a field the record has no value for stays absent, which is how an optional
 *   field added later reads: empty;
 * - an encrypted value (an envelope) passes through unopened while its field keeps
 *   its type (also when the field was switched off: its decrypt run opens it); it is
 *   never converted, and dropped (refused when strict) when its field changed type,
 *   or (strict) is no longer encrypted.
 *
 * Schema versions never change once written, so their fields are kept for the
 * life of the converter. Each is checked against its stored hash on first use.
 */
final class StorageRecordConverter
{
    private const SCHEMA_VERSIONS_TABLE = 'larena_storage_schema_versions';

    private readonly DeclaredFieldValueTransform $transform;
    private readonly PropertyTypeRegistry $propertyTypes;
    /** @var array<string, array<string, array<string, mixed>>> Fields by key, by schema id and version. */
    private array $fields = [];

    public function __construct(
        private readonly ConnectionInterface $database,
        ?PropertyTypeRegistry $propertyTypes = null,
    ) {
        $this->transform = new DeclaredFieldValueTransform();
        $this->propertyTypes = $propertyTypes ?? BuiltInPropertyTypes::builtIns();
    }

    /**
     * The values of a version written under schema version $from, read under $to.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function convert(string $schemaId, int $from, int $to, array $values): array
    {
        if ($from === $to) {
            return $values;
        }
        if ($from > $to || $from < 1) {
            throw new StorageRejected('storage_record_schema_version_ahead');
        }
        for ($version = $from; $version < $to; $version++) {
            $values = $this->step($values, $this->fieldsOf($schemaId, $version), $this->fieldsOf($schemaId, $version + 1));
        }

        return $values;
    }

    /**
     * One schema change.
     *
     * Strict, for the check a field change runs before it switches: a value that
     * cannot be converted refuses (storage_schema_migration_record_unconvertible)
     * instead of being dropped.
     *
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $from Fields by key before the change.
     * @param array<string, array<string, mixed>> $to Fields by key after it.
     * @return array<string, mixed>
     */
    public function step(array $values, array $from, array $to, bool $strict = false): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $target = $to[$key] ?? null;
            if ($target === null) {
                continue;
            }
            $source = $from[$key] ?? null;
            if (StorageFieldEnvelope::isEnvelope($value)) {
                // An envelope keeps its field while the type stays. A field switched off keeps it too: its decrypt
                // run opens it in place (until then a reader shows the envelope).
                if ($source !== null && self::sameType($source, $target)
                    && (StorageEncryptionPolicy::isEncrypted($target) || !$strict)) {
                    $result[$key] = $value;
                } elseif ($strict) {
                    throw new StorageRejected('storage_schema_migration_record_unconvertible');
                }
                continue;
            }
            if ($source !== null && self::sameType($source, $target)) {
                $result[$key] = $value;
                continue;
            }
            if ($value === null) {
                continue;
            }
            $converted = $this->retype($value, $target);
            if ($converted !== null) {
                $result[$key] = $converted[0];
            } elseif ($strict) {
                throw new StorageRejected('storage_schema_migration_record_unconvertible');
            }
        }

        return $result;
    }

    /**
     * Whether the fields of one change need values converted: a field that keeps its
     * key but changes its type or type version.
     *
     * @param array<string, array<string, mixed>> $from
     * @param array<string, array<string, mixed>> $to
     * @return list<string> The retyped keys.
     */
    public static function retyped(array $from, array $to): array
    {
        $keys = [];
        foreach ($to as $key => $field) {
            if (isset($from[$key]) && !self::sameType($from[$key], $field)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public static function byKey(array $fields): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $byKey[$field['key']] = $field;
            }
        }

        return $byKey;
    }

    /** @return array<string, array<string, mixed>> */
    public function fieldsOf(string $schemaId, int $version): array
    {
        $memo = $schemaId . "\0" . $version;
        if (isset($this->fields[$memo])) {
            return $this->fields[$memo];
        }
        try {
            $row = $this->database->table(self::SCHEMA_VERSIONS_TABLE)
                ->where('schema_id', $schemaId)
                ->where('version', $version)
                ->first(['definition', 'definition_hash']);
        } catch (Throwable) {
            throw new StorageRejected('storage_schema_version_unknown');
        }
        if (!$row instanceof stdClass) {
            throw new StorageRejected('storage_schema_version_unknown');
        }
        try {
            $decoded = json_decode((string) $row->definition, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_schema_definition_corrupt');
        }
        // The hash is of the canonical JSON (a MySQL JSON column stores it reformatted).
        if (!hash_equals((string) $row->definition_hash, hash('sha256', StorageFieldChanges::canonicalJson($decoded)))) {
            throw new StorageRejected('storage_schema_definition_corrupt');
        }
        $fields = is_array($decoded) && is_array($decoded['fields'] ?? null) && array_is_list($decoded['fields']) ? $decoded['fields'] : null;
        if ($fields === null) {
            throw new StorageRejected('storage_schema_definition_corrupt');
        }
        $list = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new StorageRejected('storage_schema_definition_corrupt');
            }
            $entry = [];
            foreach ($field as $name => $item) {
                $entry[(string) $name] = $item;
            }
            $list[] = $entry;
        }

        return $this->fields[$memo] = self::byKey($list);
    }

    /**
     * @param array<string, mixed> $field
     * @return array{0: mixed}|null The converted value, or null when it cannot be.
     */
    private function retype(mixed $value, array $field): ?array
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : '';
        $typeVersion = is_int($field['type_version'] ?? null) ? $field['type_version'] : 1;
        $constraints = [];
        if (is_array($field['constraints'] ?? null)) {
            foreach ($field['constraints'] as $name => $constraint) {
                $constraints[(string) $name] = $constraint;
            }
        }
        try {
            $converted = $this->transform->convertValue($value, $type);
        } catch (StorageRejected) {
            return null;
        }
        if ($this->propertyTypes->resolve($type, $typeVersion) === null) {
            return [$converted];
        }
        $result = $this->propertyTypes->normalizeAndValidate($type, $typeVersion, $converted, $constraints);

        return $result->canBePersistedByOwner() ? [$result->normalizedValue] : null;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function sameType(array $left, array $right): bool
    {
        return ($left['type'] ?? null) === ($right['type'] ?? null)
            && ($left['type_version'] ?? 1) === ($right['type_version'] ?? 1);
    }
}
