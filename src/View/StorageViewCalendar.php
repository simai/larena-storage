<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Query\Builder;

/**
 * A record list cut into a calendar's range on the витрина (C307, CL-3). The list is compiled sorted by the start
 * column, so each part below is an index range on the start column (or, for events running into the range, on the
 * start and end columns), read in start order:
 *
 * - inside: the rows whose start is in [from, to);
 * - running (with an end column): the rows that started in [from − span, from) and end in the range or past it — for
 *   a date end on or after `from` (the end day is part of the event), for a date-time end after `from`;
 * - undated: the rows without a start.
 *
 * Bounds are column values: `YYYY-MM-DD` for a date column, `YYYY-MM-DD HH:MM:SS` for a date-time column.
 */
final readonly class StorageViewCalendar
{
    public function __construct(
        private StorageViewQuery $query,
        private StorageViewColumn $start,
        private ?StorageViewColumn $end = null,
    ) {
    }

    /** The rows that start inside [from, to). */
    public function inside(string $from, string $to): StorageViewQuery
    {
        $start = StorageViewQuery::ALIAS . '.' . $this->start->column;

        return $this->query->narrowed(static function (Builder $where) use ($start, $from, $to): void {
            $where->where($start, '>=', $from)->where($start, '<', $to);
        });
    }

    /** The rows that started in [lookback, from) and still run at `from`; null without an end column. */
    public function running(string $lookback, string $from): ?StorageViewQuery
    {
        if ($this->end === null) {
            return null;
        }
        $start = StorageViewQuery::ALIAS . '.' . $this->start->column;
        $end = StorageViewQuery::ALIAS . '.' . $this->end->column;
        $after = $this->end->sqlType === StorageViewColumn::SQL_DATE ? '>=' : '>';

        return $this->query->narrowed(static function (Builder $where) use ($start, $end, $lookback, $from, $after): void {
            $where->where($start, '>=', $lookback)->where($start, '<', $from)->where($end, $after, $from);
        });
    }

    /** The rows without a start. */
    public function undated(): StorageViewQuery
    {
        $start = StorageViewQuery::ALIAS . '.' . $this->start->column;

        return $this->query->narrowed(static function (Builder $where) use ($start): void {
            $where->whereNull($start);
        });
    }
}
