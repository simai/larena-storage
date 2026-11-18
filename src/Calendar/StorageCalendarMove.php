<?php

declare(strict_types=1);

namespace Larena\Storage\Calendar;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Moving and resizing an event on a calendar (C307, calendar study CL-7), as moves on a board (BV-5): a move is a
 * change of the start (and end) field, written as any update of the record (its update right, its revision, its
 * checks). Before it is written the calendar's rules are asked.
 *
 * permissions() answers, for the records a calendar shows, whether each may be changed and, when not, the code and
 * reason (`records[].can.update` + reason): a record a `locked` rule holds (a sent order), or one the person lacks the
 * `ability` for. update() writes the change or answers a refusal with its code and reason: the record's own refusal,
 * an end before the start, an event longer than the calendar looks back for, a start in the past or on a day the
 * `weekdays` rule leaves out. Nothing is thrown for a refused update, so the calendar puts the event back and says why.
 */
final readonly class StorageCalendarMove
{
    public const PATCH_INVALID = 'storage_calendar_update_patch_invalid';
    public const END_BEFORE_START = 'storage_calendar_update_end_before_start';
    public const TOO_LONG = 'storage_calendar_update_too_long';
    public const PAST = 'storage_calendar_update_past';
    public const WEEKDAY = 'storage_calendar_update_weekday';
    public const LOCKED = 'storage_calendar_update_locked';
    public const DENIED = 'storage_calendar_update_denied';

    /**
     * @param (Closure(string, string): bool)|null $can whether the actor holds the ability (actor, ability); without it
     *                                              an `ability` rule refuses every change
     * @param (Closure(): DateTimeImmutable)|null $now the present moment (the `past` rule), the clock by default
     */
    public function __construct(
        private StorageWorkbench $workbench,
        private ?Closure $can = null,
        private ?Closure $now = null,
    ) {
    }

    /**
     * Whether each record may be changed on the calendar: null when it may, else the refusal's code and reason.
     *
     * @param list<StorageWorkbenchRecord> $records
     * @return array<string, array{update: bool, code: string|null, reason: string|null}>
     */
    public function permissions(StorageCalendarDescription $calendar, array $records, string $actor): array
    {
        $permissions = [];
        foreach ($records as $record) {
            $refusal = $this->recordRefusal($calendar, $record, $actor);
            $permissions[$record->recordId] = ['update' => $refusal === null, 'code' => $refusal['code'] ?? null, 'reason' => $refusal['reason'] ?? null];
        }

        return $permissions;
    }

    /**
     * Moves or resizes the event from the revision the calendar showed: the patch names the start field and, with an
     * end field, the end (null clears it: back among the undated records, when the field allows no value).
     *
     * @param array<string, mixed> $patch
     * @return array{record: StorageWorkbenchRecord}|array{error: array{code: string, reason: string|null}}
     */
    public function update(StorageCalendarDescription $calendar, string $scopeRef, string $structureId, string $recordId, array $patch,
        int $expectedRevision, string $actor, ?string $correlationId = null): array
    {
        try {
            $allowed = array_values(array_filter([$calendar->dateField, $calendar->endField]));
            if ($patch === [] || array_diff(array_map('strval', array_keys($patch)), $allowed) !== []) {
                return self::error(self::PATCH_INVALID);
            }
            $record = $this->workbench->readRecord($scopeRef, $structureId, $recordId, $actor);
            $refusal = $this->recordRefusal($calendar, $record, $actor);
            if ($refusal !== null) {
                return ['error' => $refusal];
            }
            $values = array_filter($record->values, static fn (string $key): bool => !str_starts_with($key, 'larena_'), ARRAY_FILTER_USE_KEY);
            foreach ($patch as $key => $value) {
                if ($value !== null && !is_string($value)) {
                    return self::error(self::PATCH_INVALID);
                }
                if ($value === null) {
                    unset($values[$key]);
                } else {
                    $values[$key] = $value;
                }
            }
            $start = $values[$calendar->dateField] ?? null;
            $end = $calendar->endField === null ? null : ($values[$calendar->endField] ?? null);
            $refusal = $this->changeRefusal($calendar, $record, is_string($start) ? $start : null, is_string($end) ? $end : null);
            if ($refusal !== null) {
                return ['error' => $refusal];
            }

            return ['record' => $this->workbench->updateRecord($scopeRef, $structureId, $recordId, $expectedRevision, $values, $actor, $correlationId)];
        } catch (StorageRejected $exception) {
            // The storage refused (a right, a stale revision, a value the field refuses): the calendar shows its code.
            return self::error($exception->getMessage());
        }
    }

    /** @return array{code: string, reason: string|null}|null */
    private function recordRefusal(StorageCalendarDescription $calendar, StorageWorkbenchRecord $record, string $actor): ?array
    {
        foreach ($calendar->rules as $rule) {
            if ($rule['code'] === 'locked' && in_array($record->values[$rule['field'] ?? ''] ?? null, $rule['in'] ?? [], true)) {
                return ['code' => self::LOCKED, 'reason' => $rule['reason']];
            }
            if ($rule['code'] === 'ability' && ($this->can === null || !($this->can)($actor, (string) ($rule['ability'] ?? '')))) {
                return ['code' => self::DENIED, 'reason' => $rule['reason']];
            }
        }

        return null;
    }

    /**
     * The checks of the changed dates: the end not before the start and not longer than the calendar looks back for,
     * the start not in the past nor on a day left out (a start the record already had is not checked again).
     *
     * @return array{code: string, reason: string|null}|null
     */
    private function changeRefusal(StorageCalendarDescription $calendar, StorageWorkbenchRecord $record, ?string $start, ?string $end): ?array
    {
        if ($start === null) {
            return $end === null ? null : self::error(self::END_BEFORE_START)['error'];
        }
        $zone = new DateTimeZone($calendar->timeZone);
        $format = $calendar->dateType() === 'date' ? '!Y-m-d' : '!Y-m-d\TH:i:s';
        $startAt = DateTimeImmutable::createFromFormat($format, $start, $zone);
        $endAt = $end === null ? null : DateTimeImmutable::createFromFormat($format, $end, $zone);
        if ($startAt === false || $endAt === false) {
            // The storage's own check names the value it refuses.
            return null;
        }
        if ($endAt !== null) {
            if ($endAt < $startAt) {
                return self::error(self::END_BEFORE_START)['error'];
            }
            if ($endAt > $startAt->modify('+' . $calendar->spanDays . ' days')) {
                return self::error(self::TOO_LONG)['error'];
            }
        }
        if (($record->values[$calendar->dateField] ?? null) === $start) {
            return null;
        }
        foreach ($calendar->rules as $rule) {
            if ($rule['code'] === 'past') {
                $today = ($this->now !== null ? ($this->now)() : new DateTimeImmutable())->setTimezone($zone)->format('Y-m-d');
                if (strcmp($startAt->format('Y-m-d'), $today) < 0) {
                    return ['code' => self::PAST, 'reason' => $rule['reason']];
                }
            }
            if ($rule['code'] === 'weekdays' && !in_array((int) $startAt->format('N'), $rule['days'] ?? [], true)) {
                return ['code' => self::WEEKDAY, 'reason' => $rule['reason']];
            }
        }

        return null;
    }

    /** @return array{error: array{code: string, reason: string|null}} */
    private static function error(string $code): array
    {
        return ['error' => ['code' => $code, 'reason' => null]];
    }
}
