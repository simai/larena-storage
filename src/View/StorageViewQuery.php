<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * One record list compiled onto a витрина: the WHERE of its scope, state,
 * filters and search, and its ORDER BY (up to three keys, then `record_id` in
 * the direction of the last key).
 * Rows are read as ids and sort keys only; the records themselves come from
 * their versions.
 */
final readonly class StorageViewQuery
{
    public const ALIAS = 'v';

    /**
     * @param Closure(Builder): void $where
     * @param list<array{column: string, direction: 'asc'|'desc'}> $sort Columns of the витрина table.
     */
    public function __construct(
        public Connection $connection,
        public string $table,
        private Closure $where,
        public array $sort,
    ) {
    }

    /** The matched rows, unordered, as a builder on `<table> as v`. */
    public function filtered(): Builder
    {
        $query = $this->connection->table($this->table . ' as ' . self::ALIAS);
        ($this->where)($query);

        return $query;
    }

    /**
     * One page of ids in list order, with one row more than asked when there is
     * more: either from an offset (page numbers) or after the keys of the last
     * row of the previous page (keyset).
     *
     * @param array{values: list<int|string|null>, record_id: string}|null $after
     * @return list<array{record_id: string, keys: list<int|string|null>}>
     */
    public function page(int $limit, int $offset = 0, ?array $after = null): array
    {
        $query = $this->filtered();
        $select = [self::ALIAS . '.record_id'];
        foreach ($this->sort as $position => $item) {
            $select[] = self::ALIAS . '.' . $item['column'] . ' as k' . $position;
        }
        $query->select($select);
        if ($after !== null) {
            $this->applyAfter($query, $after);
        }
        foreach ($this->sort as $item) {
            $query->orderBy(self::ALIAS . '.' . $item['column'], $item['direction']);
        }
        $query->orderBy(self::ALIAS . '.record_id', $this->tiebreak())->limit($limit + 1);
        if ($offset > 0) {
            $query->offset($offset);
        }
        $rows = [];
        foreach ($query->get() as $row) {
            $keys = [];
            foreach ($this->sort as $position => $_item) {
                $keys[] = self::key($row->{'k' . $position} ?? null);
            }
            $rows[] = ['record_id' => (string) $row->record_id, 'keys' => $keys];
        }

        return $rows;
    }

    /**
     * Rows after the given keys in list order. Ascending keys put NULL first and
     * descending keys put it last, as both SQLite and MySQL order them.
     *
     * @param array{values: list<int|string|null>, record_id: string} $after
     */
    private function applyAfter(Builder $query, array $after): void
    {
        $keys = [];
        foreach ($this->sort as $position => $item) {
            $keys[] = ['column' => self::ALIAS . '.' . $item['column'], 'direction' => $item['direction'], 'value' => $after['values'][$position] ?? null];
        }
        $keys[] = ['column' => self::ALIAS . '.record_id', 'direction' => $this->tiebreak(), 'value' => $after['record_id']];
        $query->where(static function (Builder $any) use ($keys): void {
            foreach ($keys as $index => $key) {
                $any->orWhere(static function (Builder $branch) use ($keys, $index, $key): void {
                    for ($equal = 0; $equal < $index; $equal++) {
                        $previous = $keys[$equal];
                        $previous['value'] === null
                            ? $branch->whereNull($previous['column'])
                            : $branch->where($previous['column'], '=', $previous['value']);
                    }
                    $value = $key['value'];
                    if ($key['direction'] === 'asc') {
                        $value === null
                            ? $branch->whereNotNull($key['column'])
                            : $branch->where($key['column'], '>', $value);

                        return;
                    }
                    if ($value === null) {
                        // Nothing comes after NULL in a descending key.
                        $branch->whereRaw('1 = 0');

                        return;
                    }
                    $branch->where(static function (Builder $later) use ($key, $value): void {
                        $later->where($key['column'], '<', $value)->orWhereNull($key['column']);
                    });
                });
            }
        });
    }

    /**
     * Equal keys are ordered by record id in the direction of the last key, so one
     * (state, column, record_id) index serves a sort either way, read backwards for
     * a descending one.
     *
     * @return 'asc'|'desc'
     */
    public function tiebreak(): string
    {
        return $this->sort === [] ? 'asc' : $this->sort[count($this->sort) - 1]['direction'];
    }

    /** A sort key as the continuation keeps it: an integer, a string, or NULL. */
    private static function key(mixed $value): int|string|null
    {
        return match (true) {
            $value === null, is_int($value), is_string($value) => $value,
            is_bool($value) => $value ? 1 : 0,
            is_float($value) => self::floatKey($value),
            default => (string) $value,
        };
    }

    private static function floatKey(float $value): string
    {
        // The shortest text that reads back as the same number.
        $text = (string) $value;

        return str_contains($text, 'E') || str_contains($text, 'e') ? sprintf('%.17F', $value) : $text;
    }
}
