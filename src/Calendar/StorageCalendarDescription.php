<?php

declare(strict_types=1);

namespace Larena\Storage\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Property\Contracts\PropertyField;
use Larena\Property\Runtime\PropertyFieldDescriber;
use Larena\Storage\Contracts\StorageWorkbenchCalendar;
use Larena\Storage\Contracts\StorageWorkbenchCalendarPage;
use Larena\Storage\Contracts\StorageWorkbenchCalendarQuery;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * A calendar's description (C307, calendar study CL-3…CL-7): the options of `layouts.calendar` in a view of
 * `simai.view-config/1`, checked against the storage's fields, in the source's time zone.
 *
 * - `date_field`: the start, a date or date-time field (the dataview role `date`); `end_field`: the end, of the
 *   start's type, or null; `all_day_field`: a yes/no field, or null (then a date field is all-day and a date-time
 *   field is timed); `color_field`: a choice field whose option colour the event takes, or null (CL-4);
 * - `mode` and `modes`: the open mode and the allowed ones among month, week, day and list;
 * - `event_fields`: up to three fields in list rows and the event tooltip; `per_day`: rows in a month day before
 *   «+N»; `hours` and `slot`: the visible hours of week and day and the step of a move in minutes; `weekends`: show or
 *   hide; `undated`: how the undated records are reached (panel, button, none);
 * - `span_days`: the longest event a range looks back for (31 by default, up to 366): an event that started before
 *   the days on screen is found when it started at most this many days before them;
 * - `rules`: the host's move rules (CL-7), each with a code and the `reason` a refused move gives:
 *   `past` (no start before today), `weekdays` (`days`: the ISO days a start may fall on), `locked` (`field` + `in`:
 *   a record whose field holds one of the values does not move, a sent order), `ability` (`ability`: what a person
 *   needs to move a record). Rules are the host's, not a person's: the levels below «для всех» may not change them
 *   (the view settings keep `layouts.calendar.rules` locked).
 *
 * There is no week start among the options: it comes with the request (`context.weekStart`, C293). Values stay as
 * the storage keeps them — a date-time is local time in the source's zone (CL-5: storing UTC is a later decision);
 * describe() names that zone, and window() turns the days on screen in the request's zone into the source's.
 */
final readonly class StorageCalendarDescription
{
    public const MODES = ['month', 'week', 'day', 'list'];
    public const MAX_EVENT_FIELDS = 3;
    public const MAX_RANGE_DAYS = 62;
    public const MAX_REASON = 200;
    public const SLOTS = [5, 10, 15, 20, 30, 60];
    public const UNDATED = ['panel', 'button', 'none'];
    public const RULES = ['past', 'weekdays', 'locked', 'ability'];
    private const KEYS = ['date_field', 'end_field', 'all_day_field', 'color_field', 'mode', 'modes', 'event_fields', 'per_day', 'hours',
        'slot', 'weekends', 'undated', 'span_days', 'rules'];
    private const ABILITY_PATTERN = '/\A[a-z][a-z0-9_.:-]{0,127}\z/D';
    private const HOUR_PATTERN = '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z|\A24:00\z/D';

    /**
     * @param list<string> $modes
     * @param list<string> $eventFields
     * @param array{0: string, 1: string} $hours
     * @param list<array{code: string, reason: string|null, days?: list<int>, field?: string, in?: list<string|bool>, ability?: string}> $rules
     * @param array<string, array<string, mixed>> $fields the storage's fields by key
     */
    private function __construct(
        public string $dateField,
        public ?string $endField,
        public ?string $allDayField,
        public ?string $colorField,
        public string $mode,
        public array $modes,
        public array $eventFields,
        public int $perDay,
        public array $hours,
        public int $slot,
        public bool $weekends,
        public string $undated,
        public int $spanDays,
        public array $rules,
        public string $timeZone,
        private array $fields,
    ) {
    }

    /**
     * @param array<array-key, mixed> $calendar the `layouts.calendar` options
     * @param list<array<string, mixed>> $fields the storage's fields (a structure's `fields`)
     * @param string $timeZone the source's zone (the site's), in which its date-times are kept
     */
    public static function fromArray(array $calendar, array $fields, string $timeZone): self
    {
        try {
            new DateTimeZone($timeZone);
        } catch (Throwable) {
            self::reject('time_zone');
        }
        $byKey = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $byKey[$field['key']] = $field;
            }
        }
        if (array_diff(array_map('strval', array_keys($calendar)), self::KEYS) !== []) {
            self::reject('unknown_key');
        }
        $date = $calendar['date_field'] ?? self::firstDate($byKey);
        $type = is_string($date) ? ($byKey[$date]['type'] ?? null) : null;
        if (!is_string($date) || !in_array($type, StorageWorkbenchCalendar::DATE_TYPES, true)) {
            self::reject('date_field');
        }
        $end = $calendar['end_field'] ?? null;
        if ($end !== null && (!is_string($end) || $end === $date || ($byKey[$end]['type'] ?? null) !== $type)) {
            self::reject('end_field');
        }
        $allDay = $calendar['all_day_field'] ?? null;
        if ($allDay !== null && (!is_string($allDay) || ($byKey[$allDay]['type'] ?? null) !== 'boolean')) {
            self::reject('all_day_field');
        }
        $color = $calendar['color_field'] ?? null;
        if ($color !== null && (!is_string($color) || ($byKey[$color]['type'] ?? null) !== 'choice')) {
            self::reject('color_field');
        }
        $modes = $calendar['modes'] ?? self::MODES;
        if (!is_array($modes) || !array_is_list($modes) || $modes === [] || array_diff($modes, self::MODES) !== []
            || count(array_unique($modes)) !== count($modes)) {
            self::reject('modes');
        }
        $mode = $calendar['mode'] ?? (in_array('month', $modes, true) ? 'month' : $modes[0]);
        if (!is_string($mode) || !in_array($mode, $modes, true)) {
            self::reject('mode');
        }
        $eventFields = $calendar['event_fields'] ?? [];
        if (!is_array($eventFields) || !array_is_list($eventFields) || count($eventFields) > self::MAX_EVENT_FIELDS
            || count(array_unique($eventFields, SORT_REGULAR)) !== count($eventFields)) {
            self::reject('event_fields');
        }
        foreach ($eventFields as $key) {
            if (!is_string($key) || !array_key_exists($key, $byKey)) {
                self::reject('event_fields');
            }
        }
        $perDay = $calendar['per_day'] ?? 3;
        if (!is_int($perDay) || $perDay < 1 || $perDay > 10) {
            self::reject('per_day');
        }
        $hours = $calendar['hours'] ?? ['08:00', '20:00'];
        if (!is_array($hours) || !array_is_list($hours) || count($hours) !== 2 || !is_string($hours[0]) || !is_string($hours[1])
            || preg_match(self::HOUR_PATTERN, $hours[0]) !== 1 || preg_match(self::HOUR_PATTERN, $hours[1]) !== 1 || strcmp($hours[0], $hours[1]) >= 0) {
            self::reject('hours');
        }
        $slot = $calendar['slot'] ?? 30;
        if (!is_int($slot) || !in_array($slot, self::SLOTS, true)) {
            self::reject('slot');
        }
        $weekends = $calendar['weekends'] ?? 'show';
        if ($weekends !== 'show' && $weekends !== 'hide') {
            self::reject('weekends');
        }
        $undated = $calendar['undated'] ?? 'button';
        if (!is_string($undated) || !in_array($undated, self::UNDATED, true)) {
            self::reject('undated');
        }
        $span = $calendar['span_days'] ?? StorageWorkbenchCalendarQuery::SPAN_DAYS;
        if (!is_int($span) || $span < 1 || $span > StorageWorkbenchCalendar::MAX_SPAN_DAYS) {
            self::reject('span_days');
        }

        return new self($date, is_string($end) ? $end : null, is_string($allDay) ? $allDay : null, is_string($color) ? $color : null,
            $mode, $modes, array_map('strval', $eventFields), $perDay, [$hours[0], $hours[1]], $slot, $weekends === 'show', $undated, $span,
            self::rules($calendar['rules'] ?? [], $byKey), $timeZone, $byKey);
    }

    /** @return array<string, mixed> the normalized `layouts.calendar` options */
    public function toArray(): array
    {
        $rules = [];
        foreach ($this->rules as $rule) {
            $rules[] = array_filter($rule, static fn (mixed $value): bool => $value !== null);
        }

        return ['date_field' => $this->dateField, 'end_field' => $this->endField, 'all_day_field' => $this->allDayField,
            'color_field' => $this->colorField, 'mode' => $this->mode, 'modes' => $this->modes, 'event_fields' => $this->eventFields,
            'per_day' => $this->perDay, 'hours' => $this->hours, 'slot' => $this->slot, 'weekends' => $this->weekends ? 'show' : 'hide',
            'undated' => $this->undated, 'span_days' => $this->spanDays, 'rules' => $rules];
    }

    /**
     * The calendar's part of describe(): the fields in the Framework catalogue's words, a date-time field with the
     * source's zone (CL-5: `timeZone`), the options and the move rules with their reasons.
     *
     * @return array{fields: list<array<string, mixed>>, timeZone: string, calendar: array<string, mixed>, rules: list<array{code: string, reason: string|null}>}
     */
    public function describe(PropertyFieldDescriber $describer = new PropertyFieldDescriber()): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $described = $describer->describe(PropertyField::fromArray($field, false));
            if (($field['type'] ?? null) === 'datetime') {
                $described['timeZone'] = $this->timeZone;
            }
            $fields[] = $described;
        }
        $rules = [];
        foreach ($this->rules as $rule) {
            $rules[] = ['code' => $rule['code'], 'reason' => $rule['reason']];
        }

        return ['fields' => $fields, 'timeZone' => $this->timeZone, 'calendar' => $this->toArray(), 'rules' => $rules];
    }

    /** The start field's type: date or datetime. */
    public function dateType(): string
    {
        return (string) $this->fields[$this->dateField]['type'];
    }

    /** @return array<string, mixed> a field's definition */
    public function field(string $key): array
    {
        return $this->fields[$key] ?? [];
    }

    /**
     * The calendar read of the days on screen: [from, to) as days in the request's zone (`context.timeZone`, the
     * person's; the source's when it names none) turned into the start field's form in the source's zone. A date
     * field is all-day and never shifts; a date-time range starts and ends at midnight of the request's zone.
     *
     * @param array<array-key, mixed> $range the request's two days, as sent
     * @param array<string, mixed> $context the request's context (timeZone, weekStart, locale), as sent
     */
    public function query(StorageWorkbenchRecordQuery $records, array $range, array $context = []): StorageWorkbenchCalendarQuery
    {
        if (!array_is_list($range) || count($range) !== 2 || !is_string($range[0]) || !is_string($range[1])) {
            throw new StorageRejected('storage_calendar_range_invalid');
        }
        $zone = $this->requestZone($context);
        $from = self::day($range[0], $zone);
        $to = self::day($range[1], $zone);
        $days = (int) $from->diff($to)->format('%r%a');
        if ($days < 1 || $days > self::MAX_RANGE_DAYS) {
            throw new StorageRejected('storage_calendar_range_invalid');
        }
        $source = new DateTimeZone($this->timeZone);
        [$fromBound, $toBound] = $this->dateType() === 'date'
            ? [$range[0], $range[1]]
            : [$from->setTimezone($source)->format('Y-m-d\TH:i:s'), $to->setTimezone($source)->format('Y-m-d\TH:i:s')];

        return new StorageWorkbenchCalendarQuery(self::unsorted($records), $this->dateField, $this->endField, $fromBound, $toBound,
            false, $this->spanDays);
    }

    /** The read of the undated records: a page of the list (its sort) whose start is empty. */
    public function undatedQuery(StorageWorkbenchRecordQuery $records, int $page = 1, int $perPage = 20): StorageWorkbenchCalendarQuery
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new StorageRejected('storage_calendar_undated_invalid');
        }

        return new StorageWorkbenchCalendarQuery(new StorageWorkbenchRecordQuery($records->scopeRef, $records->structureId, $records->filters,
            $records->search, $records->sort, $perPage, null, $records->includeArchived, $page), $this->dateField, $this->endField, null, null, true, $this->spanDays);
    }

    /**
     * A read calendar as the adapter answers it: {records, total, truncated} for a range, {records, total, has_next}
     * for the undated ones; the source's zone with it.
     *
     * @return array{records: list<StorageWorkbenchRecord>, total: int, truncated: bool, has_next: bool, timeZone: string}
     */
    public function answer(StorageWorkbenchCalendarPage $page): array
    {
        return ['records' => $page->records, 'total' => $page->total, 'truncated' => $page->truncated, 'has_next' => $page->hasNext,
            'timeZone' => $this->timeZone];
    }

    /** @param array<string, mixed> $context */
    private function requestZone(array $context): DateTimeZone
    {
        $weekStart = $context['weekStart'] ?? null;
        if ($weekStart !== null && (!is_int($weekStart) || $weekStart < 0 || $weekStart > 7)) {
            throw new StorageRejected('storage_calendar_context_invalid');
        }
        $zone = $context['timeZone'] ?? null;
        if ($zone === null) {
            return new DateTimeZone($this->timeZone);
        }
        try {
            return new DateTimeZone(is_string($zone) ? $zone : throw new StorageRejected('storage_calendar_context_invalid'));
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new StorageRejected('storage_calendar_context_invalid');
        }
    }

    private static function day(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new StorageRejected('storage_calendar_range_invalid');
        }

        return $day;
    }

    /** The list's filters and search without its sort: a range is read in start order. */
    private static function unsorted(StorageWorkbenchRecordQuery $records): StorageWorkbenchRecordQuery
    {
        return new StorageWorkbenchRecordQuery($records->scopeRef, $records->structureId, $records->filters, $records->search, [],
            min(100, max(1, $records->limit)), null, $records->includeArchived);
    }

    /** @param array<string, array<string, mixed>> $byKey */
    private static function firstDate(array $byKey): ?string
    {
        foreach ($byKey as $key => $field) {
            if (in_array($field['type'] ?? null, StorageWorkbenchCalendar::DATE_TYPES, true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @return list<array{code: string, reason: string|null, days?: list<int>, field?: string, in?: list<string|bool>, ability?: string}>
     */
    private static function rules(mixed $rules, array $byKey): array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 20) {
            self::reject('rules');
        }
        $normalized = [];
        foreach ($rules as $rule) {
            $code = is_array($rule) ? ($rule['code'] ?? null) : null;
            if (!is_string($code) || !in_array($code, self::RULES, true)) {
                self::reject('rules');
            }
            $reason = $rule['reason'] ?? null;
            if ($reason !== null && (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > self::MAX_REASON)) {
                self::reject('rules');
            }
            $keys = array_map('strval', array_keys($rule));
            $entry = ['code' => $code, 'reason' => is_string($reason) ? trim($reason) : null];
            switch ($code) {
                case 'past':
                    if (array_diff($keys, ['code', 'reason']) !== []) {
                        self::reject('rules');
                    }
                    break;
                case 'weekdays':
                    $days = $rule['days'] ?? null;
                    if (array_diff($keys, ['code', 'reason', 'days']) !== [] || !is_array($days) || !array_is_list($days) || $days === []
                        || array_filter($days, static fn (mixed $day): bool => !is_int($day) || $day < 1 || $day > 7) !== []) {
                        self::reject('rules');
                    }
                    $days = array_values(array_unique($days));
                    sort($days);
                    $entry['days'] = $days;
                    break;
                case 'locked':
                    $field = $rule['field'] ?? null;
                    $in = $rule['in'] ?? null;
                    if (array_diff($keys, ['code', 'reason', 'field', 'in']) !== [] || !is_string($field) || !isset($byKey[$field])
                        || !in_array($byKey[$field]['type'] ?? null, ['choice', 'boolean', 'user', 'string'], true)
                        || !is_array($in) || !array_is_list($in) || $in === [] || count($in) > 50
                        || array_filter($in, static fn (mixed $value): bool => !is_string($value) && !is_bool($value)) !== []) {
                        self::reject('rules');
                    }
                    $entry['field'] = $field;
                    $entry['in'] = $in;
                    break;
                default:
                    $ability = $rule['ability'] ?? null;
                    if (array_diff($keys, ['code', 'reason', 'ability']) !== [] || !is_string($ability) || preg_match(self::ABILITY_PATTERN, $ability) !== 1) {
                        self::reject('rules');
                    }
                    $entry['ability'] = $ability;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    private static function reject(string $what): never
    {
        throw new StorageRejected('storage_calendar_description_invalid:' . $what);
    }
}
