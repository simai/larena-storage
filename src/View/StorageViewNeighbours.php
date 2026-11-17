<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Query\Builder;

/**
 * The rows just before and just after one record in a list compiled onto a витрина (StorageViewQuery): the record's
 * own sort keys are read once, then one row each way past them in list order, with the list's WHERE and search.
 * Three indexed reads of one row, whatever the list's size: the same keyset the list pages with, read both ways.
 *
 * Ascending keys put NULL first and descending keys put it last (as the list orders them), so «before» is the
 * same comparison with every direction turned round.
 */
final class StorageViewNeighbours
{
    /** @return array{previous: ?string, next: ?string, listed: bool} */
    public static function of(StorageViewQuery $list, string $recordId): array
    {
        $alias = StorageViewQuery::ALIAS;
        $select = [$alias . '.record_id'];
        foreach ($list->sort as $position => $item) {
            $select[] = $alias . '.' . $item['column'] . ' as k' . $position;
        }
        $row = $list->filtered()->where($alias . '.record_id', $recordId)->select($select)->first();
        if (!is_object($row)) {
            return ['previous' => null, 'next' => null, 'listed' => false];
        }
        $keys = [];
        foreach ($list->sort as $position => $item) {
            $keys[] = ['column' => $alias . '.' . $item['column'], 'direction' => $item['direction'], 'value' => $row->{'k' . $position} ?? null];
        }
        $keys[] = ['column' => $alias . '.record_id', 'direction' => $list->tiebreak(), 'value' => $recordId];

        return ['previous' => self::step($list, $keys, true), 'next' => self::step($list, $keys, false), 'listed' => true];
    }

    /** @param list<array{column: string, direction: string, value: mixed}> $keys */
    private static function step(StorageViewQuery $list, array $keys, bool $backwards): ?string
    {
        if ($backwards) {
            $keys = array_map(static fn (array $key): array => ['direction' => $key['direction'] === 'asc' ? 'desc' : 'asc'] + $key, $keys);
        }
        $query = $list->filtered();
        self::after($query, $keys);
        foreach ($keys as $key) {
            $query->orderBy($key['column'], $key['direction']);
        }
        $id = $query->limit(1)->value(StorageViewQuery::ALIAS . '.record_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Rows past the given keys in the order the keys name (as StorageViewQuery pages after the last row).
     *
     * @param list<array{column: string, direction: string, value: mixed}> $keys
     */
    private static function after(Builder $query, array $keys): void
    {
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
                        $value === null ? $branch->whereNotNull($key['column']) : $branch->where($key['column'], '>', $value);

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
}
