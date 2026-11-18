<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A calendar of a record list (C307, calendar study CL-3): the records whose date field falls on the days on screen,
 * read from the витрина by an SQL range over the date column, not page by page; the records without a date are asked
 * apart (StorageWorkbenchCalendarQuery::$undated), paged like a list.
 */
interface StorageWorkbenchCalendar
{
    /** The types a calendar's start and end may have; the end is of the start's type. */
    public const DATE_TYPES = ['date', 'datetime'];

    /** The records one range answers at most; more are counted and the page says it is cut (truncated). */
    public const MAX_EVENTS = 1000;

    /** The longest event a range looks back for: an event that started before the range is found within this many days. */
    public const MAX_SPAN_DAYS = 366;

    public function listCalendar(StorageWorkbenchCalendarQuery $query, string $actor): StorageWorkbenchCalendarPage;
}
