<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\ConnectionInterface;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;
use Throwable;

/**
 * The витрина options of a structure's fields: which fields feed the search
 * text, and which strings get an index of their own.
 *
 * - `searchable` (bool) on a field whose Property type is searchable (string, text).
 *   A field that says nothing is searchable, as every string and text value was
 *   searched before the витрина; `false` leaves a field out of the search text.
 * - `indexed` (bool) on a field whose Property type is indexable (string) and whose
 *   `max_length` the type's index bound takes (at most 255): its витрина column is
 *   VARCHAR(max_length) with a `(state, cN, record_id)` index.
 *   Choice, record, user, date, datetime, number, integer and boolean fields are
 *   indexed automatically; long text never is.
 * - A table carries at most 40 field indexes: the automatic ones first, then the
 *   strings indexed on request.
 * - An encrypted field takes neither: it has no витрина column and no search text
 *   (storage_field_encrypted_searchable_refused, storage_field_encrypted_indexed_refused).
 *
 * Which types take which option is Property's declaration (PropertyTypeSettingsCatalog);
 * the index budget is Storage's own.
 *
 * The options are presentation of the витрина, not of the data: they live in the
 * workbench structure, not in the versioned storage schema, so changing them
 * never rewrites a record version. The витрина takes them online (a column or
 * an index added or dropped, the search text refilled by the queued rebuild).
 */
final readonly class StorageViewFieldOptions
{
    public const SEARCHABLE = 'searchable';
    public const INDEXED = 'indexed';
    public const KEYS = [self::SEARCHABLE, self::INDEXED];
    public const MAX_FIELD_INDEXES = 40;

    public const REASON_OPTION_INVALID = 'storage_field_option_invalid';
    public const REASON_SEARCHABLE_TYPE = 'storage_field_searchable_type_unsupported';
    public const REASON_INDEXED_TYPE = 'storage_field_indexed_type_unsupported';
    public const REASON_INDEXED_MAX_LENGTH = 'storage_field_indexed_max_length_invalid';
    public const REASON_INDEX_LIMIT = 'storage_field_index_limit_exceeded';

    private const SYSTEM_PREFIX = 'larena_';

    /** @var list<string> */
    public array $searchable;
    /** @var list<string> */
    public array $indexed;

    /**
     * @param list<string> $searchable
     * @param list<string> $indexed
     */
    public function __construct(array $searchable, array $indexed)
    {
        $searchable = array_values(array_unique($searchable));
        $indexed = array_values(array_unique($indexed));
        sort($searchable, SORT_STRING);
        sort($indexed, SORT_STRING);
        $this->searchable = $searchable;
        $this->indexed = $indexed;
    }

    /**
     * The options of structure fields, checked: each option is a boolean on a field
     * type that takes it, an indexed string has a max_length of at most 255, and the
     * table stays within its index budget.
     *
     * @param list<array<string, mixed>> $fields Fields with key, type, constraints and the optional options.
     */
    public static function validate(array $fields): self
    {
        $automatic = 0;
        $indexed = 0;
        foreach ($fields as $field) {
            $kind = is_string($field['type'] ?? null) ? $field['type'] : '';
            foreach (self::KEYS as $option) {
                if (array_key_exists($option, $field) && !is_bool($field[$option])) {
                    throw new StorageRejected(self::REASON_OPTION_INVALID);
                }
            }
            StorageEncryptionPolicy::assertOptions($field);
            if (StorageEncryptionPolicy::isEncrypted($field)) {
                continue;
            }
            if (array_key_exists(self::SEARCHABLE, $field) && !self::searchableKind($kind)) {
                throw new StorageRejected(self::REASON_SEARCHABLE_TYPE);
            }
            if (array_key_exists(self::INDEXED, $field)) {
                if (!self::indexedKind($kind)) {
                    throw new StorageRejected(self::REASON_INDEXED_TYPE);
                }
                if ($field[self::INDEXED] === true && self::indexableLength($field) === null) {
                    throw new StorageRejected(self::REASON_INDEXED_MAX_LENGTH);
                }
            }
            if (StorageViewLayoutPlanner::autoIndexed($kind)) {
                $automatic++;
            }
            if (($field[self::INDEXED] ?? false) === true) {
                $indexed++;
            }
        }
        if (min($automatic, StorageViewLayoutPlanner::MAX_AUTO_INDEXES) + $indexed > self::MAX_FIELD_INDEXES) {
            throw new StorageRejected(self::REASON_INDEX_LIMIT);
        }

        return self::resolve($fields);
    }

    /**
     * The options of fields as they are, without checking them: an option on a field
     * that cannot take it is ignored.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function resolve(array $fields): self
    {
        $searchable = [];
        $indexed = [];
        foreach ($fields as $field) {
            $key = is_string($field['key'] ?? null) ? $field['key'] : '';
            $kind = is_string($field['type'] ?? null) ? $field['type'] : '';
            // An encrypted field is never searched nor indexed.
            if ($key === '' || str_starts_with($key, self::SYSTEM_PREFIX) || StorageEncryptionPolicy::isEncrypted($field)) {
                continue;
            }
            if (self::searchableKind($kind)) {
                $flag = $field[self::SEARCHABLE] ?? null;
                if ($flag !== false) {
                    $searchable[] = $key;
                }
            }
            if (self::indexedKind($kind) && ($field[self::INDEXED] ?? false) === true
                && self::indexableLength($field) !== null) {
                $indexed[] = $key;
            }
        }

        return new self($searchable, $indexed);
    }

    /**
     * The options the current workbench structure of a storage declares, or null when
     * the storage has no workbench structure (or it cannot be read).
     */
    public static function load(ConnectionInterface $database, string $schemaId): ?self
    {
        try {
            $head = $database->table('larena_storage_workbench_structures')->where('storage_schema_id', $schemaId)->first();
            if (!$head instanceof stdClass) {
                return null;
            }
            $json = $database->table('larena_storage_workbench_structure_versions')
                ->where('scope_ref', (string) $head->scope_ref)
                ->where('structure_id', (string) $head->structure_id)
                ->where('version', (int) $head->current_version)
                ->value('fields_json');
        } catch (Throwable) {
            return null;
        }

        return self::fromFieldsJson($json);
    }

    /**
     * The options of a workbench structure version's `fields_json`, or null when there
     * is none or it cannot be read: what `load` returns for the version it finds.
     */
    public static function fromFieldsJson(mixed $json): ?self
    {
        try {
            $fields = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            return null;
        }
        if (!is_array($fields) || !array_is_list($fields)) {
            return null;
        }
        $list = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                $list[] = $field;
            }
        }

        return self::resolve($list);
    }

    public function equals(self $other): bool
    {
        return $this->searchable === $other->searchable && $this->indexed === $other->indexed;
    }

    /** Whether a field type feeds the search text (Property: searchable). */
    public static function searchableKind(string $kind): bool
    {
        return PropertyTypeSettingsCatalog::builtIns()->can($kind, 'searchable');
    }

    /** Whether a field type can be indexed on request (Property: indexable). */
    public static function indexedKind(string $kind): bool
    {
        return PropertyTypeSettingsCatalog::builtIns()->can($kind, 'indexable');
    }

    /**
     * The max_length of a field that can be indexed on request (its Property type is indexable and the length is
     * within the type's index bound and the VARCHAR limit), or null.
     *
     * @param array<string, mixed> $field
     */
    public static function indexableLength(array $field): ?int
    {
        $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
        $length = $constraints['max_length'] ?? null;
        $typed = ['type' => $field['type'] ?? 'string'] + $field;

        return is_int($length) && $length <= StorageViewColumn::VARCHAR_MAX
            && PropertyTypeSettingsCatalog::builtIns()->indexableField($typed) ? $length : null;
    }
}
