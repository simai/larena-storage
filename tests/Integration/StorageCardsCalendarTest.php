<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Calendar\StorageCalendarDescription;
use Larena\Storage\Calendar\StorageCalendarMove;
use Larena\Storage\Cards\StorageCardsDescription;
use Larena\Storage\Contracts\StorageWorkbenchCalendarQuery;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// Cards and a calendar of a storage (C306, C307), on SQLite:
// - cards: `layouts.cards` checked against the fields (cover field and frame, title, up to four card fields, size),
//   defaults (the first picture field is the cover), describe(); the window {page, per_page: 24} → {records, total,
//   has_next}, cut by the list's filter;
// - calendar: `layouts.calendar` checked (start and end of one type, all-day, colour, modes, rules); describe() names
//   the source's zone on date-time fields; a range is every record overlapping the days on screen (a start inside, or
//   started before and still running), in start order, up to its limit with the exact total and `truncated`; the
//   request's zone turns the days into the source's; the undated records apart, paged; a date field never shifts;
// - updates: per-record rights with reasons (a locked record, a missing ability), and a refused update with its code
//   and reason (end before start, too long, the past, a day left out); the storage's own refusal (a stale revision).

$path = tempnam(sys_get_temp_dir(), 'larena-storage-cards-calendar-');
if (!is_string($path)) {
    throw new RuntimeException('storage_cards_calendar_tempfile_failed');
}
$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());

try {
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    storageViewInstall($connection);
    $workbench = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope)['workbench'];
    $options = [['value' => 'new', 'label' => 'New'], ['value' => 'sent', 'label' => 'Sent']];
    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.deliveries', 'label' => 'Deliveries', 'fields' => [
        storageViewField('title', 10, 'string', [], true),
        storageViewField('photo', 20, 'file'),
        storageViewField('status', 30, 'choice', ['options' => $options]),
        storageViewField('starts_at', 40, 'datetime'),
        storageViewField('ends_at', 50, 'datetime'),
        storageViewField('day', 60, 'date'),
        storageViewField('until', 70, 'date'),
        storageViewField('whole_day', 80, 'boolean'),
        storageViewField('amount', 90, 'number'),
    ]], $actor);
    $id = $structure->structureId;
    $made = [];
    $make = static function (string $title, array $values) use ($workbench, $scope, $id, $actor, &$made): void {
        $made[$title] = $workbench->createRecord($scope, $id, ['title' => $title] + $values, $actor);
    };
    // 30 deliveries of two hours on 2026-11-16..18 (ten a day, 08:00 + n·30 min), one running from the Sunday before
    // into Monday, one three-day order 13..20 all over the week (date field), two undated.
    for ($n = 0; $n < 30; $n++) {
        $day = sprintf('2026-11-%02d', 16 + intdiv($n, 10));
        $minutes = 8 * 60 + ($n % 10) * 30;
        $start = sprintf('%sT%02d:%02d:00', $day, intdiv($minutes, 60), $minutes % 60);
        $end = sprintf('%sT%02d:%02d:00', $day, intdiv($minutes, 60) + 2, $minutes % 60);
        $make(sprintf('Delivery %02d', $n + 1), ['status' => $n % 3 === 0 ? 'sent' : 'new', 'starts_at' => $start, 'ends_at' => $end,
            'day' => $day, 'until' => $day, 'amount' => (string) ($n + 1)]);
    }
    $make('Night run', ['status' => 'new', 'starts_at' => '2026-11-15T22:00:00', 'ends_at' => '2026-11-16T03:00:00', 'day' => '2026-11-15', 'until' => '2026-11-16']);
    $make('Long order', ['status' => 'new', 'starts_at' => '2026-11-13T09:00:00', 'ends_at' => '2026-11-20T18:00:00', 'day' => '2026-11-13', 'until' => '2026-11-20', 'whole_day' => true]);
    $make('Next week', ['status' => 'new', 'starts_at' => '2026-11-23T10:00:00', 'ends_at' => '2026-11-23T11:00:00', 'day' => '2026-11-23', 'until' => '2026-11-23']);
    $make('Undated A', ['status' => 'new']);
    $make('Undated B', ['status' => 'sent']);
    $list = static fn (array $filters = [], ?string $search = null, array $sort = [['field' => 'title', 'direction' => 'asc']]): StorageWorkbenchRecordQuery
        => new StorageWorkbenchRecordQuery($scope, $id, $filters, $search, $sort, 20);
    $fields = $workbench->readStructure($scope, $id, $actor)->fields;
    $titles = static fn (array $records): array => array_map(static fn ($record): string => $record->values['title'], $records);

    // ---- Cards (C306)
    // Defaults: the picture field is the cover (a file field drawn as an image), the first text field the title.
    $pictured = array_map(static fn (array $field): array => $field['key'] === 'photo' ? $field + ['variant' => 'image'] : $field, $fields);
    $defaults = StorageCardsDescription::fromArray([], $pictured);
    storageViewExpect($defaults->coverField === 'photo' && $defaults->titleField === 'title' && $defaults->perPage === 24 && $defaults->fit === 'cover'
        && $defaults->ratio === '4:3' && $defaults->size === 'm' && $defaults->cardFields === ['status', 'starts_at', 'ends_at', 'day'],
        'the cards defaults are wrong: ' . json_encode($defaults->toArray()));
    storageViewExpect(StorageCardsDescription::fromArray([], $fields)->coverField === null, 'a file field not drawn as a picture became the cover');
    $cards = StorageCardsDescription::fromArray(['cover_field' => 'photo', 'cover' => ['fit' => 'contain', 'ratio' => '1:1'],
        'card_fields' => ['status', 'amount'], 'card_labels' => true, 'size' => 'l', 'more' => 'pages'], $fields);
    $cardsDescribed = $cards->describe();
    storageViewExpect($cardsDescribed['cards']['cover'] === ['fit' => 'contain', 'ratio' => '1:1'] && $cardsDescribed['cards']['cover_field'] === 'photo'
        && $cardsDescribed['cards']['card_fields'] === ['status', 'amount'] && $cardsDescribed['window'] === ['page' => 1, 'per_page' => 24]
        && array_column($cardsDescribed['fields'], 'type', 'key')['amount'] === 'number', 'describe() does not give the cards: ' . json_encode($cardsDescribed['cards']));
    foreach ([
        [['cover_field' => 'title'], 'cover_field'],
        [['cover' => ['fit' => 'stretch']], 'cover'],
        [['cover' => ['ratio' => '2:1']], 'cover'],
        [['title_field' => 'amount'], 'title_field'],
        [['card_fields' => ['status', 'amount', 'day', 'until', 'title']], 'card_fields'],
        [['size' => 'xl'], 'size'],
        [['per_page' => 500], 'per_page'],
        [['cover_field' => null], null],
        [['grouping' => 'status'], 'unknown_key'],
    ] as [$given, $refused]) {
        try {
            StorageCardsDescription::fromArray($given, $fields);
            storageViewExpect($refused === null, 'the cards took ' . json_encode($given));
        } catch (StorageRejected $exception) {
            storageViewExpect($exception->reasonCode === 'storage_cards_description_invalid:' . $refused, 'expected ' . $refused . ', got ' . $exception->reasonCode);
        }
    }
    // The window: 24 of 35 with more, then the last 11; the list's filter cuts it.
    $first = $cards->window($workbench, $list(), $actor, 1);
    storageViewExpect(count($first['records']) === 24 && $first['total'] === 35 && $first['total_exact'] && $first['has_next'] === true && $first['per_page'] === 24,
        'the first cards window is not 24 of 35 with more: ' . json_encode(['n' => count($first['records']), 'total' => $first['total'], 'next' => $first['has_next']]));
    storageViewExpect($first['records'][0]->values['title'] === 'Delivery 01' && $first['records'][23]->values['title'] === 'Delivery 24', 'the cards are not in the list order');
    $second = $cards->window($workbench, $list(), $actor, 2);
    storageViewExpect(count($second['records']) === 11 && $second['total'] === 35 && $second['has_next'] === false && $second['records'][0]->values['title'] === 'Delivery 25',
        'the second cards window is not the last 11');
    $more = $cards->window($workbench, $list(), $actor, null, null, $first['continuation']);
    storageViewExpect($titles($more['records']) === $titles($second['records']), '«Показать ещё» by continuation does not give the next window');
    $sentOnly = $cards->window($workbench, $list(['status' => ['operator' => 'eq', 'value' => 'sent']]), $actor, 1);
    storageViewExpect($sentOnly['total'] === 11 && count($sentOnly['records']) === 11 && $sentOnly['has_next'] === false, 'the filter does not cut the cards window');
    storageViewRejects(static fn () => $cards->window($workbench, $list(), $actor, 0), 'storage_cards_window_invalid');
    storageViewRejects(static fn () => $cards->window($workbench, $list(), $actor, 1, 101), 'storage_cards_window_invalid');

    // ---- Calendar (C307)
    $calendar = StorageCalendarDescription::fromArray([
        'date_field' => 'starts_at', 'end_field' => 'ends_at', 'color_field' => 'status', 'event_fields' => ['amount'],
        'modes' => ['month', 'week', 'list'], 'mode' => 'week',
        'rules' => [
            ['code' => 'past', 'reason' => 'Нельзя перенести в прошлое'],
            ['code' => 'weekdays', 'days' => [1, 2, 3, 4, 5, 6], 'reason' => 'В воскресенье доставок нет'],
            ['code' => 'locked', 'field' => 'status', 'in' => ['sent'], 'reason' => 'Отправленный заказ не переносится'],
        ],
    ], $fields, 'Europe/Moscow');
    $described = $calendar->describe();
    $byKey = array_column($described['fields'], null, 'key');
    storageViewExpect($described['timeZone'] === 'Europe/Moscow' && ($byKey['starts_at']['timeZone'] ?? null) === 'Europe/Moscow'
        && !isset($byKey['day']['timeZone']) && $described['calendar']['end_field'] === 'ends_at' && $described['calendar']['mode'] === 'week'
        && $described['calendar']['span_days'] === 31 && $described['rules'][2] === ['code' => 'locked', 'reason' => 'Отправленный заказ не переносится'],
        'describe() does not give the calendar with the source zone: ' . json_encode($described['calendar']));
    foreach ([
        [['date_field' => 'title'], 'date_field'],
        [['date_field' => 'starts_at', 'end_field' => 'until'], 'end_field'],
        [['date_field' => 'starts_at', 'all_day_field' => 'status'], 'all_day_field'],
        [['date_field' => 'starts_at', 'color_field' => 'amount'], 'color_field'],
        [['date_field' => 'starts_at', 'modes' => ['year']], 'modes'],
        [['date_field' => 'starts_at', 'modes' => ['week'], 'mode' => 'month'], 'mode'],
        [['date_field' => 'starts_at', 'event_fields' => ['a', 'b', 'c', 'd']], 'event_fields'],
        [['date_field' => 'starts_at', 'hours' => ['20:00', '08:00']], 'hours'],
        [['date_field' => 'starts_at', 'span_days' => 400], 'span_days'],
        [['date_field' => 'starts_at', 'rules' => [['code' => 'weekdays', 'days' => [0]]]], 'rules'],
        [['date_field' => 'starts_at', 'rules' => [['code' => 'locked', 'field' => 'nope', 'in' => ['x']]]], 'rules'],
        [['date_field' => 'starts_at', 'swimlanes' => true], 'unknown_key'],
        [['date_field' => 'day', 'end_field' => 'until', 'all_day_field' => 'whole_day'], null],
    ] as [$given, $refused]) {
        try {
            StorageCalendarDescription::fromArray($given, $fields, 'Europe/Moscow');
            storageViewExpect($refused === null, 'the calendar took ' . json_encode($given));
        } catch (StorageRejected $exception) {
            storageViewExpect($exception->reasonCode === 'storage_calendar_description_invalid:' . $refused, 'expected ' . $refused . ', got ' . $exception->reasonCode);
        }
    }
    storageViewRejects(static fn () => StorageCalendarDescription::fromArray([], $fields, 'Mars/Olympus'), 'storage_calendar_description_invalid:time_zone');

    // The week of 16..23 November in the source's zone: 30 deliveries, the night run and the long order run into it.
    $week = $workbench->listCalendar($calendar->query($list(), ['2026-11-16', '2026-11-23'], ['timeZone' => 'Europe/Moscow', 'weekStart' => 1]), $actor);
    storageViewExpect($week->total === 32 && count($week->records) === 32 && $week->truncated === false,
        'the week is not 32 records: ' . $week->total . ' ' . json_encode($titles($week->records)));
    storageViewExpect(array_slice($titles($week->records), 0, 3) === ['Long order', 'Night run', 'Delivery 01'] && $titles($week->records)[31] === 'Delivery 30',
        'the week is not in start order: ' . json_encode(array_slice($titles($week->records), 0, 3)));
    storageViewExpect(!in_array('Next week', $titles($week->records), true) && !in_array('Undated A', $titles($week->records), true),
        'the week holds a record outside it or an undated one');
    // Cut to 10: the exact total stays and the page says it is cut.
    $cut = $workbench->listCalendar(new StorageWorkbenchCalendarQuery($list(sort: []), 'starts_at', 'ends_at', '2026-11-16T00:00:00', '2026-11-23T00:00:00', max: 10), $actor);
    storageViewExpect(count($cut->records) === 10 && $cut->total === 32 && $cut->truncated === true, 'a cut range does not say it is cut');
    // The range and the list's filter work together.
    $sentWeek = $workbench->listCalendar($calendar->query($list(['status' => ['operator' => 'eq', 'value' => 'sent']]), ['2026-11-16', '2026-11-23']), $actor);
    storageViewExpect($sentWeek->total === 10, 'the filter does not cut the range: ' . $sentWeek->total);
    // The request's zone: Monday 16 November in Vladivostok (UTC+10) starts on Sunday 15 at 17:00 in Moscow, so
    // the night run (Sunday 22:00 Moscow) starts inside the day, and Monday ends at Monday 17:00 Moscow.
    $far = $workbench->listCalendar($calendar->query($list(), ['2026-11-16', '2026-11-17'], ['timeZone' => 'Asia/Vladivostok']), $actor);
    storageViewExpect(in_array('Night run', $titles($far->records), true) && in_array('Long order', $titles($far->records), true)
        && in_array('Delivery 10', $titles($far->records), true) && !in_array('Delivery 11', $titles($far->records), true),
        'the request zone does not turn the day into the source zone: ' . json_encode($titles($far->records)));
    // Without an end field only the starts count; a range looks back no further than span_days.
    $starts = StorageCalendarDescription::fromArray(['date_field' => 'starts_at'], $fields, 'Europe/Moscow');
    storageViewExpect($workbench->listCalendar($starts->query($list(), ['2026-11-16', '2026-11-23']), $actor)->total === 30, 'a calendar without an end shows running events');
    $short = StorageCalendarDescription::fromArray(['date_field' => 'starts_at', 'end_field' => 'ends_at', 'span_days' => 1], $fields, 'Europe/Moscow');
    storageViewExpect($titles($workbench->listCalendar($short->query($list(), ['2026-11-16', '2026-11-17']), $actor)->records)[0] === 'Night run',
        'a short look-back still finds the long order');
    // A date field is all-day and never shifts: the order of 13..20 is in the week whatever the zone.
    $days = StorageCalendarDescription::fromArray(['date_field' => 'day', 'end_field' => 'until', 'all_day_field' => 'whole_day'], $fields, 'Europe/Moscow');
    $dayWeek = $workbench->listCalendar($days->query($list(), ['2026-11-16', '2026-11-23'], ['timeZone' => 'Pacific/Kiritimati']), $actor);
    storageViewExpect($dayWeek->total === 32 && $titles($dayWeek->records)[0] === 'Long order', 'a date calendar shifted or lost the running order: ' . $dayWeek->total);
    storageViewExpect($workbench->listCalendar($days->query($list(), ['2026-11-20', '2026-11-21']), $actor)->total === 1, 'the end day of a date event is not part of it');
    // The undated records apart, a page in the list's sort.
    $undated = $workbench->listCalendar($calendar->undatedQuery($list(), 1, 1), $actor);
    storageViewExpect($titles($undated->records) === ['Undated A'] && $undated->total === 2 && $undated->hasNext === true, 'the undated page is wrong');
    $undatedRest = $workbench->listCalendar($calendar->undatedQuery($list(), 2, 1), $actor);
    storageViewExpect($titles($undatedRest->records) === ['Undated B'] && $undatedRest->hasNext === false && $undatedRest->total === 2, 'the undated second page is wrong');
    storageViewExpect($calendar->answer($undated)['timeZone'] === 'Europe/Moscow' && $calendar->answer($week)['truncated'] === false, 'the answer does not carry the zone');
    // Refused reads.
    storageViewRejects(static fn () => $calendar->query($list(), ['2026-11-16', '2026-11-16']), 'storage_calendar_range_invalid');
    storageViewRejects(static fn () => $calendar->query($list(), ['2026-11-01', '2027-02-01']), 'storage_calendar_range_invalid');
    storageViewRejects(static fn () => $calendar->query($list(), ['2026-11-16', '2026-11-23'], ['timeZone' => 'Nowhere/Land']), 'storage_calendar_context_invalid');
    storageViewRejects(static fn () => $workbench->listCalendar(new StorageWorkbenchCalendarQuery($list(), 'title', null, '2026-11-16', '2026-11-17'), $actor), 'storage_workbench_calendar_date_field_invalid');
    storageViewRejects(static fn () => $workbench->listCalendar(new StorageWorkbenchCalendarQuery($list(), 'starts_at', 'day', '2026-11-16T00:00:00', '2026-11-17T00:00:00'), $actor), 'storage_workbench_calendar_end_field_invalid');
    storageViewRejects(static fn () => $workbench->listCalendar(new StorageWorkbenchCalendarQuery($list(), 'starts_at', null, '2026-11-16', '2026-11-17'), $actor), 'storage_workbench_calendar_range_invalid');
    storageViewRejects(static fn () => $workbench->listCalendar(new StorageWorkbenchCalendarQuery($list(), 'starts_at', null, '2026-11-16T00:00:00', '2026-11-17T00:00:00', max: 1001), $actor), 'storage_workbench_calendar_window_invalid');
    storageViewRejects(static fn () => $workbench->listCalendar($calendar->query($list(), ['2026-11-16', '2026-11-23']), 'actor:admin:beta'), 'storage_workbench_scope_denied');

    // ---- Updates with reasons (CL-7). «Now» is Tuesday 17 November 2026, 12:00 in Moscow.
    $now = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17T12:00:00', new DateTimeZone('Europe/Moscow'));
    $mover = new StorageCalendarMove($workbench, null, $now);
    $permissions = $mover->permissions($calendar, $week->records, $actor);
    $sentOne = $made['Delivery 01'];
    $newOne = $made['Delivery 15'];
    storageViewExpect($permissions[$sentOne->recordId] === ['update' => false, 'code' => 'storage_calendar_update_locked', 'reason' => 'Отправленный заказ не переносится']
        && $permissions[$newOne->recordId] === ['update' => true, 'code' => null, 'reason' => null], 'the per-record rights are wrong');
    $update = static fn (string $title, array $patch, ?int $revision = null) => $mover->update($calendar, $scope, $id, $made[$title]->recordId, $patch,
        $revision ?? $made[$title]->revision, $actor);
    $locked = $update('Delivery 01', ['starts_at' => '2026-11-19T10:00:00', 'ends_at' => '2026-11-19T12:00:00']);
    storageViewExpect(($locked['error'] ?? null) === ['code' => 'storage_calendar_update_locked', 'reason' => 'Отправленный заказ не переносится'], 'a locked record moved');
    foreach ([
        [['starts_at' => '2026-11-16T10:00:00', 'ends_at' => '2026-11-16T12:00:00'], ['code' => 'storage_calendar_update_past', 'reason' => 'Нельзя перенести в прошлое']],
        [['starts_at' => '2026-11-22T10:00:00', 'ends_at' => '2026-11-22T12:00:00'], ['code' => 'storage_calendar_update_weekday', 'reason' => 'В воскресенье доставок нет']],
        [['starts_at' => '2026-11-19T10:00:00', 'ends_at' => '2026-11-19T09:00:00'], ['code' => 'storage_calendar_update_end_before_start', 'reason' => null]],
        [['starts_at' => '2026-11-19T10:00:00', 'ends_at' => '2027-01-19T09:00:00'], ['code' => 'storage_calendar_update_too_long', 'reason' => null]],
        [['title' => 'Renamed'], ['code' => 'storage_calendar_update_patch_invalid', 'reason' => null]],
    ] as [$patch, $error]) {
        $refused = $update('Delivery 15', $patch);
        storageViewExpect(($refused['error'] ?? null) === $error, 'expected ' . json_encode($error) . ', got ' . json_encode($refused));
    }
    storageViewExpect($workbench->readRecord($scope, $id, $newOne->recordId, $actor)->revision === $newOne->revision, 'a refused update wrote the record');
    $moved = $update('Delivery 15', ['starts_at' => '2026-11-19T10:00:00', 'ends_at' => '2026-11-19T13:00:00']);
    $record = $moved['record'] ?? null;
    storageViewExpect($record !== null && $record->values['starts_at'] === '2026-11-19T10:00:00' && $record->values['ends_at'] === '2026-11-19T13:00:00'
        && $record->values['amount'] === '15' && $record->revision === $newOne->revision + 1, 'the move did not change the dates alone: ' . json_encode($moved));
    // The stale revision the calendar showed is the storage's own refusal, said by its code.
    $stale = $update('Delivery 15', ['starts_at' => '2026-11-20T10:00:00', 'ends_at' => '2026-11-20T12:00:00']);
    storageViewExpect(isset($stale['error']) && str_starts_with($stale['error']['code'], 'storage_workbench_') && $stale['error']['reason'] === null,
        'a stale update was not refused by the storage: ' . json_encode($stale));
    // Back among the undated: the start cleared.
    $cleared = $update('Delivery 15', ['starts_at' => null, 'ends_at' => null], $record->revision)['record'] ?? null;
    storageViewExpect($cleared !== null && !array_key_exists('starts_at', $cleared->values)
        && $workbench->listCalendar($calendar->undatedQuery($list()), $actor)->total === 3, 'clearing the start did not make the record undated');
    // An ability rule: without it every record is refused with its reason.
    $guarded = StorageCalendarDescription::fromArray(['date_field' => 'starts_at', 'rules' => [['code' => 'ability', 'ability' => 'deliveries.plan', 'reason' => 'Планирует диспетчер']]],
        $fields, 'Europe/Moscow');
    $nobody = new StorageCalendarMove($workbench, static fn (string $who, string $ability): bool => false, $now);
    storageViewExpect(($nobody->update($guarded, $scope, $id, $made['Delivery 20']->recordId, ['starts_at' => '2026-11-19T10:00:00'], $made['Delivery 20']->revision, $actor)['error'] ?? null)
        === ['code' => 'storage_calendar_update_denied', 'reason' => 'Планирует диспетчер'], 'an update without the ability was not refused with its reason');
    $planner = new StorageCalendarMove($workbench, static fn (string $who, string $ability): bool => $ability === 'deliveries.plan', $now);
    storageViewExpect(($planner->update($guarded, $scope, $id, $made['Delivery 20']->recordId, ['starts_at' => '2026-11-19T10:00:00'], $made['Delivery 20']->revision, $actor)['record'] ?? null)
        ?->values['starts_at'] === '2026-11-19T10:00:00', 'an update with the ability was refused');

    echo "StorageCardsCalendarTest passed\n";
} finally {
    @unlink($path);
}
