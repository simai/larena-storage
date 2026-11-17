<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Query\Builder;

/**
 * A record list cut into a board's lanes on the витрина (C304, BV-3): the lane field's column groups the list's rows,
 * so the totals of every lane (the count and the sums of number columns) come from one grouped query, and each lane
 * is the list narrowed to its value, paged like any list (keyset, the list's sort, its search).
 *
 * On MySQL a string lane value is compared exactly (binary), as the list's own equality filter is; NULL is the lane
 * of the rows without a value.
 */
final readonly class StorageViewBoard
{
    /**
     * @param StorageViewColumn $lane The lane field's column (scalar, written).
     * @param array<string, StorageViewColumn> $sums The summed fields' columns, by field key.
     */
    public function __construct(
        private StorageViewQuery $query,
        private StorageViewColumn $lane,
        private array $sums = [],
    ) {
    }

    /**
     * Every lane value the list holds, with its count and sums, in one grouped query.
     *
     * @return list<array{value: string|bool|null, count: int, sums: array<string, string|null>}>
     */
    public function totals(): array
    {
        $grammar = $this->query->connection->getQueryGrammar();
        $column = StorageViewQuery::ALIAS . '.' . $this->lane->column;
        $select = $this->query->filtered();
        $select->select([$column . ' as lane'])->selectRaw('count(*) as larena_count');
        $names = [];
        foreach (array_keys($this->sums) as $position => $field) {
            $names['s' . $position] = $field;
            $select->selectRaw('sum(' . $grammar->wrap(StorageViewQuery::ALIAS . '.' . $this->sums[$field]->column) . ') as s' . $position);
        }
        $select->groupBy($column);

        $lanes = [];
        foreach ($select->get() as $row) {
            $sums = [];
            foreach ($names as $alias => $field) {
                $value = $row->{$alias} ?? null;
                $sums[$field] = $value === null ? null : self::decimal($value);
            }
            $lanes[] = ['value' => $this->value($row->lane ?? null), 'count' => (int) $row->larena_count, 'sums' => $sums];
        }

        return $lanes;
    }

    /** The list narrowed to one lane: the rows whose lane field holds the value (NULL: no value). */
    public function lane(string|bool|null $value): StorageViewQuery
    {
        $column = StorageViewQuery::ALIAS . '.' . $this->lane->column;
        $cell = is_bool($value) ? ($value ? 1 : 0) : $value;
        $binary = is_string($cell) && in_array($this->query->connection->getDriverName(), ['mysql', 'mariadb'], true);
        $wrapped = $this->query->connection->getQueryGrammar()->wrap($column);

        return $this->query->narrowed(static function (Builder $where) use ($column, $cell, $binary, $wrapped): void {
            if ($cell === null) {
                $where->whereNull($column);

                return;
            }
            $where->where($column, '=', $cell);
            if ($binary) {
                $where->whereRaw('CAST(' . $wrapped . ' AS BINARY) = CAST(? AS BINARY)', [$cell]);
            }
        });
    }

    /** A lane value as the field holds it: a yes/no column holds 0 and 1. */
    private function value(mixed $cell): string|bool|null
    {
        if ($cell === null) {
            return null;
        }
        if ($this->lane->kind === 'boolean') {
            return (int) $cell === 1;
        }

        return is_scalar($cell) ? (string) $cell : null;
    }

    /** A sum as a decimal string without a float's noise (SQLite sums decimals as floats). */
    private static function decimal(mixed $value): string
    {
        if (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }

        return is_scalar($value) ? (string) $value : '0';
    }
}
