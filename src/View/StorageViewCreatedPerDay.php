<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * How many records of one storage were created on each of the last days, read from its витрина (the Storage
 * section's chart «Новые записи по дням»).
 *
 * One query: the rows of a kept state (active or archived) created since the first day, grouped by their date. It
 * walks the витрина's (`state`, `created_at`, `record_id`) index from the first day on and reads nothing else, so a
 * storage of millions answers by the records of the period only; on MySQL/MariaDB the read keeps the counter's time
 * budget and gives up (null) past it rather than slow the page. Days are UTC, as `created_at` is written.
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
     * @param string $today the last day, YYYY-MM-DD (UTC)
     * @return list<array{day: string, created: int}>|null every day from the first to $today, a day without records
     *         as 0; null when the read ran out of its budget
     */
    public function count(Connection $connection, string $table, string $today, int $days = self::DAYS): ?array
    {
        if (!StorageViewNaming::isTable($table)) {
            throw new InvalidArgumentException('storage_view_table_invalid');
        }
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $today, new \DateTimeZone('UTC'));
        if ($last === false || $last->format('Y-m-d') !== $today || $days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('storage_view_created_per_day_invalid');
        }
        $first = $last->modify('-' . ($days - 1) . ' days');
        $end = $last->modify('+1 day');
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $rows = $this->counter->budgetedSelect(
            $connection,
            'select date(created_at) as day, count(*) as created from ' . $wrapped
                . ' where state in (?, ?) and created_at >= ? and created_at < ? group by date(created_at)',
            [...self::STATES, $first->format('Y-m-d 00:00:00'), $end->format('Y-m-d 00:00:00')],
        );
        if ($rows === null) {
            return null;
        }
        $counted = [];
        foreach ($rows as $row) {
            $day = is_scalar($row->day ?? null) ? substr((string) $row->day, 0, 10) : '';
            $counted[$day] = ($counted[$day] ?? 0) + max(0, (int) ($row->created ?? 0));
        }
        $series = [];
        for ($day = $first; $day < $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $series[] = ['day' => $key, 'created' => $counted[$key] ?? 0];
        }

        return $series;
    }
}
