<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\PublishedListPage;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseReadContracts;

require_once __DIR__ . '/published-site-view-fixture.php';

/**
 * Public list checks shared by the SQLite and the MySQL tests: the fields of a
 * listed storage, its records, an oracle for what each case must find, and the
 * walk of every page of a list through one reader.
 */

/** @return list<array<string, mixed>> The listed storage's fields: every filterable type, and one admin field. */
function publishedListFields(): array
{
    $options = static fn (array $values): array => array_map(static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)], $values);

    return [
        publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], true),
        publishedSiteViewField('slug', 20, 'string', 'public', ['max_length' => 120]) + ['indexed' => true],
        publishedSiteViewField('body', 30, 'text', 'public', [], true),
        publishedSiteViewField('price', 40, 'number', 'public'),
        publishedSiteViewField('tags', 50, 'choices', 'public', ['options' => $options(['a', 'b'])]),
        publishedSiteViewField('code', 60, 'integer', 'public'),
        publishedSiteViewField('day', 70, 'date', 'public'),
        publishedSiteViewField('starts', 80, 'datetime', 'public'),
        publishedSiteViewField('active', 90, 'boolean', 'public'),
        publishedSiteViewField('status', 100, 'choice', 'public', ['options' => $options(['draft', 'live', 'gone'])]),
        publishedSiteViewField('secret', 110, 'string', 'admin', ['max_length' => 200], true),
    ];
}

/** @return array<string, mixed> The values of record number $index. */
function publishedListValues(int $index): array
{
    $values = [
        'title' => 'item ' . sprintf('%02d', $index),
        'slug' => sprintf('item-%02d', $index),
        'body' => ['oak chair number ' . $index, 'pine table ' . $index, 'birch armchair ' . $index][$index % 3],
        'price' => sprintf('%d.%02d', ($index * 7) % 50, ($index % 4) * 25),
        'tags' => $index % 5 === 0 ? ['b'] : ($index % 2 === 0 ? ['a'] : ['a', 'b']),
        'day' => sprintf('2026-01-%02d', 1 + $index % 28),
        'starts' => sprintf('2026-02-01T%02d:%02d:00', $index % 24, ($index * 7) % 60),
        'active' => $index % 3 !== 0,
        'status' => ['draft', 'live', 'gone'][$index % 3],
        'secret' => 'SENTINEL-' . $index,
    ];
    if ($index % 10 !== 9) {
        // Every tenth record leaves its integer out: a missing value never matches and sorts first.
        $values['code'] = $index % 7;
    }

    return $values;
}

/**
 * The cases every reader must answer alike: a query, and what it must find among
 * the published entries (null: no oracle, the readers are compared with each other).
 *
 * @return list<array{0: array<string, mixed>, 1: (Closure(array<string, mixed>): bool)|null}>
 */
function publishedListCases(): array
{
    $code = static fn (array $values): ?int => is_int($values['code'] ?? null) ? $values['code'] : null;
    $price = static fn (array $values): float => (float) ($values['price'] ?? 0);

    return [
        [[], static fn (array $values): bool => true],
        [['sort' => [['field' => '_record_id', 'direction' => 'desc']]], static fn (array $values): bool => true],
        [['filters' => ['title' => ['operator' => 'contains', 'value' => 'EM 1']]], static fn (array $values): bool => str_contains(strtolower((string) $values['title']), 'em 1')],
        [['filters' => ['slug' => ['operator' => 'starts_with', 'value' => 'item-2']]], static fn (array $values): bool => str_starts_with((string) $values['slug'], 'item-2')],
        [['filters' => ['slug' => ['operator' => 'eq', 'value' => 'item-05']]], static fn (array $values): bool => $values['slug'] === 'item-05'],
        [['filters' => ['slug' => ['operator' => 'in', 'values' => ['item-01', 'item-07', 'item-99']]]], static fn (array $values): bool => in_array($values['slug'], ['item-01', 'item-07'], true)],
        [['filters' => ['code' => ['operator' => 'eq', 'value' => 5]]], static fn (array $values): bool => $code($values) === 5],
        [['filters' => ['code' => ['operator' => 'in', 'values' => [1, 6]]]], static fn (array $values): bool => in_array($code($values), [1, 6], true)],
        [['filters' => ['code' => ['operator' => 'gt', 'value' => 3]], 'sort' => [['field' => 'code', 'direction' => 'asc']]], static fn (array $values): bool => $code($values) !== null && $code($values) > 3],
        [['filters' => ['code' => ['operator' => 'gte', 'value' => 3]]], static fn (array $values): bool => $code($values) !== null && $code($values) >= 3],
        [['filters' => ['code' => ['operator' => 'lt', 'value' => 2]]], static fn (array $values): bool => $code($values) !== null && $code($values) < 2],
        [['filters' => ['code' => ['operator' => 'lte', 'value' => 2]]], static fn (array $values): bool => $code($values) !== null && $code($values) <= 2],
        [['filters' => ['code' => ['operator' => 'between', 'values' => [2, 4]]]], static fn (array $values): bool => $code($values) !== null && $code($values) >= 2 && $code($values) <= 4],
        [['filters' => ['price' => ['operator' => 'between', 'values' => ['5.00', '20.5']]], 'sort' => [['field' => 'price', 'direction' => 'desc']]], static fn (array $values): bool => $price($values) >= 5.0 && $price($values) <= 20.5],
        [['filters' => ['price' => ['operator' => 'gt', 'value' => '10.5']]], static fn (array $values): bool => $price($values) > 10.5],
        [['filters' => ['day' => ['operator' => 'lte', 'value' => '2026-01-10']], 'sort' => [['field' => 'day', 'direction' => 'asc']]], static fn (array $values): bool => (string) $values['day'] <= '2026-01-10'],
        [['filters' => ['day' => ['operator' => 'between', 'values' => ['2026-01-05', '2026-01-08']]]], static fn (array $values): bool => (string) $values['day'] >= '2026-01-05' && (string) $values['day'] <= '2026-01-08'],
        [['filters' => ['starts' => ['operator' => 'gte', 'value' => '2026-02-01T10:00:00']], 'sort' => [['field' => 'starts', 'direction' => 'desc']]], static fn (array $values): bool => (string) $values['starts'] >= '2026-02-01T10:00:00'],
        [['filters' => ['active' => ['operator' => 'eq', 'value' => true]]], static fn (array $values): bool => ($values['active'] ?? null) === true],
        [['filters' => ['status' => ['operator' => 'eq', 'value' => 'live']]], static fn (array $values): bool => $values['status'] === 'live'],
        [['filters' => ['status' => ['operator' => 'in', 'values' => ['draft', 'gone']]], 'sort' => [['field' => 'status', 'direction' => 'asc'], ['field' => 'title', 'direction' => 'desc']]], static fn (array $values): bool => in_array($values['status'], ['draft', 'gone'], true)],
        [['filters' => ['tags' => ['operator' => 'eq', 'value' => 'b']]], static fn (array $values): bool => in_array('b', (array) $values['tags'], true)],
        [['filters' => ['tags' => ['operator' => 'in', 'values' => ['a']]], 'sort' => [['field' => 'active', 'direction' => 'asc'], ['field' => 'price', 'direction' => 'asc']]], static fn (array $values): bool => in_array('a', (array) $values['tags'], true)],
        [['search' => 'oak'], static fn (array $values): bool => str_contains((string) $values['body'], 'oak')],
        [['search' => 'chair'], static fn (array $values): bool => str_contains(' ' . (string) $values['body'], ' chair')],
        [['search' => 'birch arm'], static fn (array $values): bool => str_contains((string) $values['body'], 'birch armchair')],
        [['search' => 'SENTINEL'], static fn (array $values): bool => false],
        [['search' => '!!!'], static fn (array $values): bool => false],
        [['filters' => ['code' => ['operator' => 'gte', 'value' => 1], 'active' => ['operator' => 'eq', 'value' => true]], 'search' => 'table', 'sort' => [['field' => '_published_at', 'direction' => 'desc']]], static fn (array $values): bool => ($values['active'] ?? null) === true && $code($values) !== null && $code($values) >= 1 && str_contains((string) $values['body'], 'table')],
        [['sort' => [['field' => '_published_at', 'direction' => 'asc'], ['field' => 'code', 'direction' => 'desc']]], static fn (array $values): bool => true],
        [['sort' => [['field' => 'code', 'direction' => 'desc'], ['field' => 'day', 'direction' => 'asc'], ['field' => 'starts', 'direction' => 'desc']]], static fn (array $values): bool => true],
    ];
}

/** @param array<string, mixed> $case */
function publishedListQuery(string $schemaId, string $scopeRef, string $locale, array $case, int $limit, ?string $continuation = null, StorageWorkbenchCountMode $count = StorageWorkbenchCountMode::Skip): PublishedListQuery
{
    return new PublishedListQuery(
        $schemaId,
        $scopeRef,
        $locale,
        $case['filters'] ?? [],
        $case['search'] ?? null,
        $case['sort'] ?? [],
        $limit,
        $continuation,
        $count,
    );
}

/**
 * Every page of a list through one reader (at most 200), and the pages themselves.
 *
 * @param array<string, mixed> $case
 * @return array{records: list<array<string, mixed>>, pages: list<PublishedListPage>, cursors: list<string>}
 */
function publishedListWalk(DatabaseReadContracts $read, string $schemaId, string $scopeRef, string $locale, array $case, int $limit, ?callable $visibility = null, StorageWorkbenchCountMode $count = StorageWorkbenchCountMode::Skip): array
{
    $records = [];
    $pages = [];
    $cursors = [];
    $continuation = null;
    do {
        $page = $read->publishedList(publishedListQuery($schemaId, $scopeRef, $locale, $case, $limit, $continuation, $count), $visibility);
        $pages[] = $page;
        array_push($records, ...$page->records);
        $continuation = $page->continuation;
        if ($continuation !== null) {
            $cursors[] = $continuation;
        }
        storageViewExpect(count($pages) <= 200, 'a list did not end');
    } while ($continuation !== null);

    return ['records' => $records, 'pages' => $pages, 'cursors' => $cursors];
}

/**
 * Runs a call with the query log on: its result (or the reason it was refused) and
 * whether it read the site table.
 *
 * @return array{0: mixed, 1: bool}
 */
function publishedListTrace(Connection $connection, string $siteTable, Closure $call): array
{
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $result = $call();
    } catch (ReadContractRejected $rejected) {
        $result = 'rejected:' . $rejected->reasonCode;
    } finally {
        $log = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
    $answered = false;
    foreach ($log as $entry) {
        $sql = (string) $entry['query'];
        $answered = $answered || str_contains($sql, '"' . $siteTable . '"') || str_contains($sql, '`' . $siteTable . '`');
    }

    return [$result, $answered];
}

/**
 * Every case through the slot and through the published records, walked a few
 * records at a time: the same records in the same order, each page of the slot read
 * from the slot, the records the oracle expects, in the list's order. Returns how
 * many lists the slot answered.
 *
 * @param list<string> $scopes
 * @param list<string> $locales
 */
function publishedListMatrix(Connection $connection, DatabaseReadContracts $slot, DatabaseReadContracts $exact, string $schemaId, string $siteTable, array $scopes, array $locales, string $message): int
{
    $answered = 0;
    foreach ($scopes as $scopeRef) {
        foreach ($locales as $locale) {
            $published = [];
            foreach ($exact->publishedProjection($schemaId, $scopeRef, $locale)->records as $entry) {
                $published[(string) $entry['record_id']] = $entry;
            }
            foreach (publishedListCases() as $number => [$case, $oracle]) {
                $label = $message . ' ' . $scopeRef . '/' . $locale . ' case ' . $number . ' ' . json_encode($case);
                [$fromSlot, $readSlot] = publishedListTrace($connection, $siteTable, static fn () => publishedListWalk($slot, $schemaId, $scopeRef, $locale, $case, 3));
                [$fromRecords, $readTable] = publishedListTrace($connection, $siteTable, static fn () => publishedListWalk($exact, $schemaId, $scopeRef, $locale, $case, 3));
                storageViewExpect(is_array($fromSlot) && is_array($fromRecords), $label . ': refused: ' . json_encode([$fromSlot, $fromRecords]));
                storageViewExpect(!$readTable, $label . ': the reader without a slot read the site table');
                storageViewExpect(serialize($fromSlot['records']) === serialize($fromRecords['records']), $label . ': the slot and the records differ: '
                    . json_encode(array_column($fromSlot['records'], 'record_id')) . ' vs ' . json_encode(array_column($fromRecords['records'], 'record_id')));
                if ($readSlot) {
                    $answered++;
                }
                if ($oracle !== null) {
                    $expected = [];
                    foreach ($published as $recordId => $entry) {
                        if ($oracle($entry['values'])) {
                            $expected[] = $recordId;
                        }
                    }
                    $found = array_column($fromSlot['records'], 'record_id');
                    $sortedFound = $found;
                    sort($sortedFound, SORT_STRING);
                    storageViewExpect($sortedFound === $expected, $label . ': found ' . json_encode($found) . ' instead of ' . json_encode($expected));
                }
                foreach ($fromSlot['records'] as $entry) {
                    storageViewExpect(serialize($entry) === serialize($published[$entry['record_id']] ?? null), $label . ': a listed entry is not the projection entry');
                }
                // Count modes on the first page: skip has no total, exact and auto count every match.
                $total = count($fromSlot['records']);
                foreach ([$slot, $exact] as $reader) {
                    $exactPage = $reader->publishedList(publishedListQuery($schemaId, $scopeRef, $locale, $case, 3, null, StorageWorkbenchCountMode::Exact));
                    $autoPage = $reader->publishedList(publishedListQuery($schemaId, $scopeRef, $locale, $case, 3, null, StorageWorkbenchCountMode::Auto));
                    storageViewExpect($exactPage->matchedCount === $total && $exactPage->countExact, $label . ': the exact count is ' . json_encode($exactPage->matchedCount) . ' instead of ' . $total);
                    storageViewExpect($autoPage->matchedCount === $total && $autoPage->countExact, $label . ': the auto count is ' . json_encode($autoPage->matchedCount) . ' instead of ' . $total);
                    storageViewExpect($fromSlot['pages'][0]->matchedCount === null && !$fromSlot['pages'][0]->countExact, $label . ': skip counted');
                }
            }
        }
    }

    return $answered;
}
