<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * How many records of one storage were created on each of the last days, read from its витрина (the Storage
 * section's chart «Новые записи по дням»).
 *
 * One query: the rows of a kept state (active or archived) created since the first day, each counted into its day. It
 * walks the витрина's (`state`, `created_at`, `record_id`) index from the first day on and reads nothing else, so a
 * storage of millions answers by the records of the period only; on MySQL/MariaDB the read keeps the counter's time
 * budget and gives up (null) past it rather than slow the page. `created_at` is written in UTC; the days are those of
 * the given time zone (lists acceptance PX-102): each day's bounds are its local midnights turned to UTC, so a zone
 * with a half hour or a summer time change counts exactly.
 */
final readonly class StorageViewCreatedPerDay
{
    public const DAYS = 30;
    public const MAX_DAYS = 90;
    /** The states a kept record has on the витрина: a purged record is gone from it. */
    private const STATES = ['active', 'archived'];

    public function __construct(private StorageViewCounter $counter = new StorageViewCounter())
    {
    }

    /**
     * @param string $today the last day, YYYY-MM-DD, in $timeZone
     * @param string $timeZone the zone whose days are counted (an IANA name), UTC by default
     * @return list<array{day: string, created: int}>|null every day from the first to $today, a day without records
     *         as 0; null when the read ran out of its budget
     */
    public function count(Connection $connection, string $table, string $today, int $days = self::DAYS, string $timeZone = 'UTC'): ?array
    {
        if (!StorageViewNaming::isTable($table)) {
            throw new InvalidArgumentException('storage_view_table_invalid');
        }
        try {
            $zone = new \DateTimeZone($timeZone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('storage_view_created_per_day_invalid');
        }
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $today, $zone);
        if ($last === false || $last->format('Y-m-d') !== $today || $days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('storage_view_created_per_day_invalid');
        }
        $utc = new \DateTimeZone('UTC');
        // The local midnights from the first day to the day after the last, in UTC as `created_at` is written.
        $keys = [];
        $bounds = [];
        for ($index = 0; $index <= $days; $index++) {
            $day = $last->modify('-' . ($days - 1 - $index) . ' days');
            $keys[] = $day->format('Y-m-d');
            $bounds[] = $day->setTimezone($utc)->format('Y-m-d H:i:s');
        }
        array_pop($keys);
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $sums = [];
        $bindings = [];
        for ($index = 0; $index < $days; $index++) {
            $sums[] = 'sum(case when created_at >= ? and created_at < ? then 1 else 0 end) as d' . $index;
            array_push($bindings, $bounds[$index], $bounds[$index + 1]);
        }
        $rows = $this->counter->budgetedSelect(
            $connection,
            'select ' . implode(', ', $sums) . ' from ' . $wrapped . ' where state in (?, ?) and created_at >= ? and created_at < ?',
            [...$bindings, ...self::STATES, $bounds[0], $bounds[$days]],
        );
        if ($rows === null) {
            return null;
        }
        $row = $rows[0] ?? new \stdClass();
        $series = [];
        foreach ($keys as $index => $key) {
            $value = $row->{'d' . $index} ?? 0;
            $series[] = ['day' => $key, 'created' => max(0, is_numeric($value) ? (int) $value : 0)];
        }

        return $series;
    }
}
