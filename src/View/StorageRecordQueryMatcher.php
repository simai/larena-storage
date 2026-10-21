<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/**
 * The PHP side of a normalized record list query (StorageRecordQueryNormalizer):
 * whether values match its filters and search, and how two values compare in its
 * sort. The site list's fallback (and the tests' reference list) use it, with the
 * semantics the витрина compiles to SQL (StorageViewQueryCompiler):
 *
 * - a missing or null value never matches a filter;
 * - eq/in compare exactly; gt/gte/lt/lte/between compare integers as numbers,
 *   numbers as decimals and every other type as strings;
 * - contains/starts_with compare the lowercased value with the lowercased fragment;
 * - choices match when the list holds the value (eq) or any of the values (in);
 * - the search matches every term at the start of a word (StorageViewSearch);
 * - a sort puts null first ascending.
 */
final class StorageRecordQueryMatcher
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, array{operator: string, value?: mixed, values?: list<mixed>}> $filters
     * @param array<string, string> $fieldTypes By field key.
     */
    public static function filtersMatch(array $values, array $filters, array $fieldTypes): bool
    {
        foreach ($filters as $field => $filter) {
            if (!array_key_exists($field, $values)
                || !self::filterMatches($values[$field], $filter, $fieldTypes[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every term of the search at the start of a word of these searchable fields.
     *
     * @param array<string, mixed> $values
     * @param list<string> $searchable
     */
    public static function searchMatches(array $values, string $search, array $searchable): bool
    {
        $texts = [];
        foreach ($searchable as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value)) {
                $texts[] = $value;
            }
        }

        return StorageViewSearch::matches(StorageViewSearch::text($texts), StorageViewSearch::terms($search));
    }

    /** @param array{operator: string, value?: mixed, values?: list<mixed>} $filter */
    public static function filterMatches(mixed $actual, array $filter, string $type): bool
    {
        if ($actual === null) {
            return false;
        }
        $operator = $filter['operator'];
        $value = $filter['value'] ?? null;
        $values = $filter['values'] ?? [];
        if ($type === 'choices') {
            if (!is_array($actual)) {
                return false;
            }

            return $operator === 'eq'
                ? in_array($value, $actual, true)
                : array_intersect($values, $actual) !== [];
        }

        return match ($operator) {
            'eq' => $actual === $value,
            'in' => in_array($actual, $values, true),
            'contains' => is_string($actual) && is_string($value) && str_contains(StorageViewQueryCompiler::lower($actual), $value),
            'starts_with' => is_string($actual) && is_string($value) && str_starts_with(StorageViewQueryCompiler::lower($actual), $value),
            'gt' => self::comparableFilterValue($actual, $type) && self::compareFilterValues($actual, $value, $type) > 0,
            'gte' => self::comparableFilterValue($actual, $type) && self::compareFilterValues($actual, $value, $type) >= 0,
            'lt' => self::comparableFilterValue($actual, $type) && self::compareFilterValues($actual, $value, $type) < 0,
            'lte' => self::comparableFilterValue($actual, $type) && self::compareFilterValues($actual, $value, $type) <= 0,
            'between' => count($values) === 2
                && self::comparableFilterValue($actual, $type)
                && self::compareFilterValues($actual, $values[0], $type) >= 0
                && self::compareFilterValues($actual, $values[1], $type) <= 0,
            default => false,
        };
    }

    public static function compareFilterValues(mixed $left, mixed $right, string $type): int
    {
        return match ($type) {
            'integer' => self::int($left) <=> self::int($right),
            'number' => self::compareDecimals(self::text($left), self::text($right)),
            default => strcmp(self::text($left), self::text($right)),
        };
    }

    /** Two sort values: null first, integers and decimals by value, booleans false first, anything else as strings. */
    public static function compareValues(mixed $left, mixed $right, ?string $type): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === null) {
            return -1;
        }
        if ($right === null) {
            return 1;
        }
        if ($type === 'integer' || is_int($left) && is_int($right)) {
            return self::int($left) <=> self::int($right);
        }
        if ($type === 'number' && is_string($left) && is_string($right)) {
            return self::compareDecimals($left, $right);
        }
        if (is_bool($left) && is_bool($right)) {
            return (int) $left <=> (int) $right;
        }

        return strcmp(self::text($left), self::text($right));
    }

    public static function compareDecimals(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $leftParts = explode('.', ltrim($left, '-'), 2);
        $rightParts = explode('.', ltrim($right, '-'), 2);
        $integer = strlen($leftParts[0]) <=> strlen($rightParts[0]);
        if ($integer === 0) {
            $integer = strcmp($leftParts[0], $rightParts[0]);
        }
        if ($integer === 0) {
            $scale = max(strlen($leftParts[1] ?? ''), strlen($rightParts[1] ?? ''));
            $integer = strcmp(str_pad($leftParts[1] ?? '', $scale, '0'), str_pad($rightParts[1] ?? '', $scale, '0'));
        }

        return $leftNegative ? -$integer : $integer;
    }

    private static function comparableFilterValue(mixed $value, string $type): bool
    {
        return $type === 'integer' ? is_int($value) : is_string($value);
    }

    private static function int(mixed $value): int
    {
        return is_int($value) || is_float($value) || is_string($value) || is_bool($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
