<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The one normalization of a record list query (filters, search, sort), shared by
 * the workbench record list and the public site list, so both accept the same
 * shapes and refuse with the same reasons:
 *
 * - filters: a map of field key to `{operator, value}` or `{operator, values}`, at
 *   most MAX_FILTERS; the operators each field type takes (FILTER_OPERATORS_BY_TYPE);
 *   a value normalized by the field's Property type (eq/in with the field's
 *   constraints, a range bound or a text fragment without them); `contains` and
 *   `starts_with` lowercased, at most MAX_FILTER_TEXT_BYTES; `in` and `between` at
 *   most MAX_FILTER_VALUES distinct values, `between` ordered;
 * - search: trimmed, 1..MAX_SEARCH_BYTES bytes, no control character;
 * - sort: at most MAX_SORTS distinct `{field, direction}` over the given fields or
 *   the caller's system keys.
 *
 * An encrypted field is refused as a filter (storage_field_encrypted_filter_unsupported)
 * or a sort (storage_field_encrypted_sort_unsupported); a search over a storage whose
 * only text fields are encrypted is refused (storage_field_encrypted_search_unsupported).
 *
 * The caller decides which fields exist: the workbench passes every field of the
 * structure, the site list only its public ones, so a field it leaves out is refused
 * exactly like a field that does not exist.
 */
final readonly class StorageRecordQueryNormalizer
{
    public const MAX_FILTERS = 8;
    public const MAX_SORTS = 3;
    public const MAX_FILTER_VALUES = 100;
    public const MAX_FILTER_TEXT_BYTES = 1000;
    public const MAX_SEARCH_BYTES = 100;
    public const FILTER_OPERATORS_BY_TYPE = [
        'string' => ['eq', 'in', 'contains', 'starts_with'],
        'text' => ['eq', 'in', 'contains', 'starts_with'],
        'integer' => ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'between'],
        'number' => ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'between'],
        'date' => ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'between'],
        'datetime' => ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'between'],
        'boolean' => ['eq'],
        'choice' => ['eq', 'in'],
        'choices' => ['eq', 'in'],
        'user' => ['eq', 'in'],
        'file' => ['eq', 'in'],
        'relation' => ['eq', 'in'],
        'record' => ['eq', 'in'],
    ];
    public const FILTER_OPERATORS = ['eq', 'in', 'contains', 'starts_with', 'gt', 'gte', 'lt', 'lte', 'between'];
    /** The workbench's system sort keys. */
    public const WORKBENCH_SYSTEM_SORTS = ['_record_id', '_revision'];

    public function __construct(private PropertyTypeRegistry $propertyTypes)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $fields The fields a query may name, by key.
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $sort
     * @param list<string> $systemSorts Sort keys that are no field.
     * @return array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool}
     */
    public function normalize(
        array $fields,
        array $filters,
        ?string $search,
        array $sort,
        bool $includeArchived = false,
        array $systemSorts = self::WORKBENCH_SYSTEM_SORTS,
    ): array {
        if ($filters !== [] && array_is_list($filters)) {
            throw new StorageRejected('storage_workbench_record_filters_invalid');
        }
        if (count($filters) > self::MAX_FILTERS || count($sort) > self::MAX_SORTS) {
            throw new StorageRejected('storage_workbench_record_query_too_large');
        }
        $normalizedFilters = [];
        foreach ($filters as $field => $filter) {
            if (!is_string($field) || !isset($fields[$field]) || !is_array($filter)) {
                throw new StorageRejected('storage_workbench_record_filter_invalid');
            }
            if (StorageEncryptionPolicy::isEncrypted($fields[$field])) {
                throw new StorageRejected(StorageEncryptionPolicy::FILTER_UNSUPPORTED);
            }
            $normalizedFilters[$field] = $this->normalizeFilter($fields[$field], $filter);
        }
        ksort($normalizedFilters, SORT_STRING);
        if ($search !== null) {
            $search = trim($search);
            if ($search === '' || strlen($search) > self::MAX_SEARCH_BYTES || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $search) === 1) {
                throw new StorageRejected('storage_workbench_record_search_invalid');
            }
            $plainText = false;
            $sealedText = false;
            foreach ($fields as $definition) {
                if (!in_array($definition['type'] ?? null, ['string', 'text'], true)) {
                    continue;
                }
                if (StorageEncryptionPolicy::isEncrypted($definition)) {
                    $sealedText = true;
                } else {
                    $plainText = true;
                }
            }
            if ($sealedText && !$plainText) {
                throw new StorageRejected(StorageEncryptionPolicy::SEARCH_UNSUPPORTED);
            }
        }
        $normalizedSort = [];
        $seen = [];
        foreach ($sort as $item) {
            if (!is_array($item)) {
                throw new StorageRejected('storage_workbench_record_sort_invalid');
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            $field = $item['field'] ?? null;
            $direction = $item['direction'] ?? null;
            if ($keys !== ['direction', 'field']
                || !is_string($field)
                || (!isset($fields[$field]) && !in_array($field, $systemSorts, true))
                || isset($seen[$field])
                || !in_array($direction, ['asc', 'desc'], true)) {
                throw new StorageRejected('storage_workbench_record_sort_invalid');
            }
            if (isset($fields[$field]) && StorageEncryptionPolicy::isEncrypted($fields[$field])) {
                throw new StorageRejected(StorageEncryptionPolicy::SORT_UNSUPPORTED);
            }
            $seen[$field] = true;
            $normalizedSort[] = ['field' => $field, 'direction' => $direction];
        }

        return ['filters' => $normalizedFilters, 'search' => $search, 'sort' => $normalizedSort, 'include_archived' => $includeArchived];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<array-key, mixed> $filter
     * @return array{operator: string, value?: mixed, values?: list<mixed>}
     */
    public function normalizeFilter(array $definition, array $filter): array
    {
        $keys = array_keys($filter);
        sort($keys, SORT_STRING);
        $operator = $filter['operator'] ?? null;
        if (!is_string($operator) || ($keys !== ['operator', 'value'] && $keys !== ['operator', 'values'])) {
            throw new StorageRejected('storage_workbench_record_filter_invalid');
        }
        $type = (string) ($definition['type'] ?? '');
        $allowed = self::FILTER_OPERATORS_BY_TYPE[$type] ?? ['eq'];
        if (!in_array($operator, self::FILTER_OPERATORS, true) || !in_array($operator, $allowed, true)) {
            throw new StorageRejected('storage_query_filter_operator_unsupported');
        }
        $expectsList = in_array($operator, ['in', 'between'], true);
        if ($keys !== ($expectsList ? ['operator', 'values'] : ['operator', 'value'])) {
            throw new StorageRejected('storage_workbench_record_filter_invalid');
        }

        if (!$expectsList) {
            $value = $this->normalizeFilterValue($definition, $operator, $filter['value'] ?? null);
            if (in_array($operator, ['contains', 'starts_with'], true)) {
                if (!is_string($value) || $value === '' || strlen($value) > self::MAX_FILTER_TEXT_BYTES) {
                    throw new StorageRejected('storage_workbench_record_filter_invalid');
                }
                $value = StorageViewQueryCompiler::lower($value);
            }

            return ['operator' => $operator, 'value' => $value];
        }

        $rawValues = $filter['values'] ?? null;
        if (!is_array($rawValues) || !array_is_list($rawValues) || $rawValues === []
            || count($rawValues) > self::MAX_FILTER_VALUES
            || ($operator === 'between' && count($rawValues) !== 2)) {
            throw new StorageRejected('storage_workbench_record_filter_invalid');
        }
        $values = [];
        foreach ($rawValues as $rawValue) {
            $normalized = $this->normalizeFilterValue($definition, $operator, $rawValue);
            if ($operator === 'between' || !in_array($normalized, $values, true)) {
                $values[] = $normalized;
            }
        }
        if ($operator === 'between' && StorageRecordQueryMatcher::compareFilterValues($values[0], $values[1], $type) > 0) {
            throw new StorageRejected('storage_workbench_record_filter_invalid');
        }

        return ['operator' => $operator, 'values' => $values];
    }

    /** @param array<string, mixed> $definition */
    private function normalizeFilterValue(array $definition, string $operator, mixed $rawValue): mixed
    {
        if (($definition['type'] ?? null) === 'choices') {
            $normalized = $this->normalizeWithProperty($definition, [$rawValue], true);
            if (!is_array($normalized) || count($normalized) !== 1 || !array_is_list($normalized)) {
                throw new StorageRejected('storage_workbench_record_filter_invalid');
            }

            return $normalized[0];
        }

        // Range bounds and text fragments are not stored values, so the field's value
        // constraints (min/max, length) do not apply to them; the type contract does.
        $withConstraints = in_array($operator, ['eq', 'in'], true);

        return $this->normalizeWithProperty($definition, $rawValue, $withConstraints);
    }

    /** @param array<string, mixed> $definition */
    private function normalizeWithProperty(array $definition, mixed $rawValue, bool $withConstraints): mixed
    {
        $result = $this->propertyTypes->normalizeAndValidate(
            (string) $definition['type'],
            // A schema written without a type version (a starter site's, a fixture's) means the first.
            is_int($definition['type_version'] ?? null) ? $definition['type_version'] : 1,
            $rawValue,
            $withConstraints && is_array($definition['constraints'] ?? null) ? $definition['constraints'] : [],
        );
        if (!$result->canBePersistedByOwner()) {
            throw new StorageRejected('storage_workbench_record_filter_invalid');
        }

        return $result->normalizedValue;
    }
}
