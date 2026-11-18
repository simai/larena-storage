<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One calendar read (C307, CL-3): the list's filters and search (`records`; its sort is not asked, a range is in start
 * order), the start field, the optional end field, and either the range or the undated records.
 *
 * - A range is [from, to) in the start field's own form and in the source's time zone: dates `YYYY-MM-DD` for a date
 *   field, `YYYY-MM-DDTHH:MM:SS` for a date-time field (StorageCalendarDescription::window() converts the days on
 *   screen from the request's zone). It answers every record that overlaps the range — a start inside it, or with an
 *   end field a start up to `spanDays` before it and an end inside or past it — up to `max` records in start order.
 * - `undated` answers the records without a start instead, a page of `records->limit` in the list's sort
 *   (`records->page`, from 1).
 */
final readonly class StorageWorkbenchCalendarQuery
{
    public const SPAN_DAYS = 31;

    public function __construct(
        public StorageWorkbenchRecordQuery $records,
        public string $dateField,
        public ?string $endField = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $undated = false,
        public int $spanDays = self::SPAN_DAYS,
        public int $max = StorageWorkbenchCalendar::MAX_EVENTS,
    ) {
    }
}
