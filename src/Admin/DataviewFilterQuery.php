<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

/**
 * The data view's filter shape (simai.dataview-port: a field's filter is either a list of chosen
 * values or an {operator, value|values} object) translated into Storage record query filters,
 * and the applied filters echoed back in the shape the person chose. Shared by every records
 * list port (the Storage section and the Larena CMS). Operators are validated per field type
 * and relative date ranges resolved by {@see DataviewListPreferences::queryFilters()}.
 */
final class DataviewFilterQuery
{
    /** Relative date ranges the list offers for date and datetime fields, resolved when the list is read. */
    public const RELATIVE_DATE_OPERATORS = ['today', 'week', 'month'];

    /**
     * The operators each field type offers in the list toolbar: Storage's own, and for dates the
     * relative ranges.
     *
     * @param array<string,list<string>> $storageOperators
     * @return array<string,list<string>>
     */
    public static function operators(array $storageOperators): array
    {
        foreach (['date', 'datetime'] as $type) {
            if (isset($storageOperators[$type])) {
                $storageOperators[$type] = [...$storageOperators[$type], ...self::RELATIVE_DATE_OPERATORS];
            }
        }

        return $storageOperators;
    }

    /**
     * Filters of known fields in Storage's shape: a list of values means «any of» (in).
     * Unknown fields and empty filters are rejected, unknown fields first.
     *
     * @param array<array-key,mixed> $chosen
     * @param list<string> $known the field keys the list may filter by
     * @return array{filters:array<string,array<string,mixed>>,rejected:list<array-key>}
     */
    public static function fromDataview(array $chosen, array $known): array
    {
        $rejected = array_values(array_diff(array_keys($chosen), $known));
        $filters = [];
        foreach (array_diff_key($chosen, array_flip($rejected)) as $key => $value) {
            if (! is_array($value) || $value === []) {
                $rejected[] = $key;
                continue;
            }
            $filters[(string) $key] = self::filter($value);
        }

        return ['filters' => $filters, 'rejected' => $rejected];
    }

    /**
     * The applied filters as the list shows them: a filter chosen as a list of values is echoed as
     * that list; any other filter is echoed as applied (a relative date range as its dates).
     *
     * @param array<array-key,mixed> $chosen @param array<string,mixed> $applied
     * @return array<string,mixed>
     */
    public static function echo(array $chosen, array $applied): array
    {
        $echo = [];
        foreach ($applied as $key => $filter) {
            $echo[$key] = is_array($chosen[$key] ?? null) && array_is_list($chosen[$key])
                && is_array($filter) && ($filter['operator'] ?? null) === 'in' ? $filter['values'] : $filter;
        }

        return $echo;
    }

    /**
     * The filters a saved view keeps: the non-empty filters of a filter map, in Storage's shape.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function forSavedView(mixed $filters): array
    {
        $kept = [];
        foreach (is_array($filters) && ! array_is_list($filters) ? $filters : [] as $key => $value) {
            if (is_array($value) && $value !== []) {
                $kept[(string) $key] = self::filter($value);
            }
        }

        return $kept;
    }

    /**
     * «Any of» filters from a map of chosen values (bulk actions under the current filter).
     *
     * @param array<array-key,mixed> $filters
     * @return array<string,array{operator:'in',values:array<mixed>}>
     */
    public static function anyOf(array $filters): array
    {
        $mapped = [];
        foreach ($filters as $key => $values) {
            if (is_string($key) && is_array($values) && $values !== []) {
                $mapped[$key] = ['operator' => 'in', 'values' => $values];
            }
        }

        return $mapped;
    }

    /**
     * One filter in Storage's shape. The Framework table reports a range as {operator: between,
     * value: [from, to]} and a single-select choice as a one-item `values` list; both are read
     * as Storage expects them (`values` for in/between, `value` for the other operators).
     *
     * @param array<mixed> $value @return array<string,mixed>
     */
    private static function filter(array $value): array
    {
        if (array_is_list($value)) {
            return ['operator' => 'in', 'values' => $value];
        }
        $operator = $value['operator'] ?? null;
        if (in_array($operator, ['in', 'between'], true)) {
            if (! array_key_exists('values', $value) && is_array($value['value'] ?? null) && array_is_list($value['value'])) {
                $value['values'] = $value['value'];
            }
            unset($value['value']);
        } elseif (is_string($operator) && ! in_array($operator, self::RELATIVE_DATE_OPERATORS, true)) {
            if (! array_key_exists('value', $value) && is_array($value['values'] ?? null) && count($value['values']) === 1
                && array_is_list($value['values'])) {
                $value['value'] = $value['values'][0];
            }
            unset($value['values']);
        }

        return $value;
    }
}
