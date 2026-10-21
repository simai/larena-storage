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
 *
 * A search has two forms that find the same rows (StorageViewSearch): a check of
 * each row against the search table, and on MySQL the FULLTEXT form, which takes
 * the candidates from the index. The check is fast when many rows match (the
 * list stops after its page) and slow when few do; the index the other way
 * round. A page first reads a window of the first rows in list order and checks
 * them; when the window holds the page, or every row, that is the answer, and
 * otherwise the FULLTEXT form answers, and counts its rows in the same query
 * (COUNT(*) OVER ()). A count then uses that total, or the form the page found
 * cheaper.
 */
final class StorageViewQuery
{
    public const ALIAS = 'v';
    public const PROBE_WINDOW = 1000;
    public const MAX_PROBE_WINDOW = 20000;
    private const TOTAL = 'larena_total';

    /** True once a page found the search matched by many rows (or every row checked): the check form is the cheap one. */
    private bool $searchCommon = false;
    /** The rows the list matches, when a page read them anyway (the FULLTEXT form counts them as it pages). */
    private ?int $total = null;

    /**
     * @param Closure(Builder): void $where The scope, state and filters.
     * @param list<array{column: string, direction: 'asc'|'desc'}> $sort Columns of the витрина table.
     * @param (Closure(Builder): void)|null $search The search as a check of each row.
     * @param (Closure(Builder): void)|null $searchIndexed The same search through the FULLTEXT index.
     * @param list<string> $unindexedSort The sorted fields whose column has no index of its own.
     */
    public function __construct(
        public readonly Connection $connection,
        public readonly string $table,
        private readonly Closure $where,
        public readonly array $sort,
        private readonly ?Closure $search = null,
        private readonly ?Closure $searchIndexed = null,
        public readonly array $unindexedSort = [],
    ) {
    }

    /**
     * The matched rows, unordered, as a builder on `<table> as v`; a search in the
     * form the last page found cheaper (the FULLTEXT form until then).
     */
    public function filtered(): Builder
    {
        $query = $this->base();
        $this->applySearch($query, $this->searchIndexed !== null && !$this->searchCommon);

        return $query;
    }

    /** Whether the search has a FULLTEXT form and the last page answered it with the check form. */
    public function searchCommon(): bool
    {
        return $this->searchCommon;
    }

    public function hasSearch(): bool
    {
        return $this->search !== null;
    }

    /** The rows the list matches, when the last page counted them (a FULLTEXT search from the start); null otherwise. */
    public function knownTotal(): ?int
    {
        return $this->total;
    }

    /** The rows the list matches without its search, unordered. */
    public function unsearched(): Builder
    {
        return $this->base();
    }

    /**
     * The share of rows the search matches, from the first PROBE_WINDOW rows in list
     * order (1.0 without a search): what an estimate of a searched count scales by.
     */
    public function searchShare(): float
    {
        if ($this->search === null) {
            return 1.0;
        }
        $candidates = $this->base()->select([self::ALIAS . '.record_id']);
        $this->order($candidates);
        $candidates->limit(self::PROBE_WINDOW);
        $total = $this->connection->query()->fromSub($candidates, 'w')->count();
        if ($total === 0) {
            return 0.0;
        }
        $matched = $this->connection->query()->fromSub($candidates, self::ALIAS);
        $this->applySearch($matched, false);

        return $matched->count() / $total;
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
        $this->total = null;
        if ($this->search !== null && $this->searchIndexed !== null) {
            $probed = $this->probe($limit, $offset, $after);
            if ($probed !== null) {
                return $probed;
            }
        }
        $query = $this->filtered();
        $this->selectKeys($query);
        // The FULLTEXT form finds every match before it sorts them: it counts them on the way.
        $counted = $after === null && $this->searchIndexed !== null && !$this->searchCommon;
        if ($counted) {
            $query->selectRaw('count(*) over () as ' . self::TOTAL);
        }
        if ($after !== null) {
            $this->applyAfter($query, $after);
        }
        $this->order($query);
        $query->limit($limit + 1);
        if ($offset > 0) {
            $query->offset($offset);
        }
        $rows = $query->get();
        if ($counted) {
            $first = $rows->first();
            // Past the last match there is no row to carry the total; the count runs then.
            $this->total = is_object($first) && is_numeric($first->{self::TOTAL} ?? null) ? (int) $first->{self::TOTAL} : null;
        }

        return $this->keyRows($rows);
    }

    /**
     * The page from the first rows in list order, checked one by one; null when the
     * window holds neither the page nor every row (few rows match: the FULLTEXT form
     * answers).
     *
     * @param array{values: list<int|string|null>, record_id: string}|null $after
     * @return list<array{record_id: string, keys: list<int|string|null>}>|null
     */
    private function probe(int $limit, int $offset, ?array $after): ?array
    {
        $window = max(self::PROBE_WINDOW, 4 * ($offset + $limit + 1));
        if ($window > self::MAX_PROBE_WINDOW) {
            return null;
        }
        $candidates = $this->base();
        $columns = [self::ALIAS . '.record_id'];
        foreach ($this->sort as $item) {
            $columns[] = self::ALIAS . '.' . $item['column'];
        }
        $candidates->select(array_values(array_unique($columns)));
        if ($after !== null) {
            $this->applyAfter($candidates, $after);
        }
        $this->order($candidates);
        $candidates->limit($window);

        $query = $this->connection->query()->fromSub($candidates, self::ALIAS);
        $this->applySearch($query, false);
        $this->selectKeys($query);
        $this->order($query);
        $query->limit($limit + 1);
        if ($offset > 0) {
            $query->offset($offset);
        }
        $rows = $this->keyRows($query->get());
        if (count($rows) > $limit || !$this->windowFull($after, $window)) {
            $this->searchCommon = true;

            return $rows;
        }

        return null;
    }

    /**
     * Whether the list has at least $window rows (after the keyset) before its search:
     * one look at the window's last row in list order.
     *
     * @param array{values: list<int|string|null>, record_id: string}|null $after
     */
    private function windowFull(?array $after, int $window): bool
    {
        $last = $this->base()->select([self::ALIAS . '.record_id']);
        if ($after !== null) {
            $this->applyAfter($last, $after);
        }
        $this->order($last);

        return $last->offset($window - 1)->limit(1)->get()->isNotEmpty();
    }

    private function base(): Builder
    {
        $query = $this->connection->table($this->table . ' as ' . self::ALIAS);
        ($this->where)($query);

        return $query;
    }

    private function applySearch(Builder $query, bool $indexed): void
    {
        $search = $indexed ? $this->searchIndexed : $this->search;
        if ($search !== null) {
            $search($query);
        }
    }

    private function selectKeys(Builder $query): void
    {
        $select = [self::ALIAS . '.record_id'];
        foreach ($this->sort as $position => $item) {
            $select[] = self::ALIAS . '.' . $item['column'] . ' as k' . $position;
        }
        $query->select($select);
    }

    private function order(Builder $query): void
    {
        foreach ($this->sort as $item) {
            $query->orderBy(self::ALIAS . '.' . $item['column'], $item['direction']);
        }
        $query->orderBy(self::ALIAS . '.record_id', $this->tiebreak());
    }

    /**
     * @param iterable<mixed> $result
     * @return list<array{record_id: string, keys: list<int|string|null>}>
     */
    private function keyRows(iterable $result): array
    {
        $rows = [];
        foreach ($result as $row) {
            if (!is_object($row)) {
                continue;
            }
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
