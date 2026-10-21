<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewShapeVerifier;
use Larena\Storage\View\StorageViewTableShape;

/**
 * Checks that a storage's site витрина agrees with what the read contracts return
 * from the versions (parity): for every ready slot, one row per published record of
 * its scope and locale, whose entry is exactly the contract entry and whose typed
 * columns, side rows and search row are that entry's projection. Pages of
 * DatabaseReadContracts::BATCH records, each read in a constant number of queries.
 *
 * Problem codes: `schema_unknown`, `view_missing` (published records and public fields,
 * but no site витрина), `view_pending`, `view_failed`, `public_fields_changed`,
 * `table_missing`, `shape_mismatch`, `slot_missing` (published records in a scope and
 * locale without a slot), `slot_pending`, `slot_building`, `slot_failed`,
 * `slot_chain_changed`, `rows_missing`, `rows_extra`, `rows_stale`.
 */
final readonly class PublishedSiteViewChecker
{
    private const PAGE = 500;

    public function __construct(private PublishedSiteViewIndex $site)
    {
    }

    /**
     * @return array{schema_id: string, state: ?string, problems: list<string>, slots: int, records: int, rows: int, missing: int, extra: int, stale: int}
     */
    public function check(string $schemaId): array
    {
        $database = $this->site->connection();
        $ddl = $this->site->ddl();
        if (!$this->site->enabled() || $ddl === null) {
            throw new StorageRejected('storage_view_unavailable');
        }
        $registry = $this->site->registry();
        $report = ['schema_id' => $schemaId, 'state' => null, 'problems' => [], 'slots' => 0, 'records' => 0, 'rows' => 0, 'missing' => 0, 'extra' => 0, 'stale' => 0];
        $schema = $this->site->currentSchema($schemaId);
        $registration = $registry->find($schemaId, true);
        $report['state'] = $registration?->state;
        if ($schema === null) {
            $report['problems'][] = 'schema_unknown';

            return $report;
        }
        $published = [];
        foreach ($database->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)->where('state', PublicationStateValue::Published->value)->whereNotNull('published_revision')
            ->selectRaw('scope_ref, locale, count(*) as records')->groupBy('scope_ref', 'locale')->get() as $row) {
            $published[PublishedSiteViewSlot::keyOf($schemaId, (string) $row->scope_ref, (string) $row->locale)] = (int) $row->records;
            $report['records'] += (int) $row->records;
        }
        $hash = PublishedSiteViewProjector::publicFields($schema['fields']) === []
            ? null
            : PublishedSiteViewProjector::publicFieldsHash($schema['fields'], $schema['options']);
        if ($registration === null) {
            if ($hash !== null && $published !== []) {
                $report['problems'][] = 'view_missing';
            }

            return $report;
        }
        if (!$registration->ready()) {
            $report['problems'][] = 'view_' . $registration->state;
        }
        if ($hash !== $registration->publicFieldsHash) {
            $report['problems'][] = 'public_fields_changed';
        }
        $table = $registration->tableName;
        if (!$ddl->tableExists($table)) {
            $report['problems'][] = 'table_missing';

            return $report;
        }
        if (!(new StorageViewShapeVerifier($ddl))->matches($table, $registration->layout, true, StorageViewTableShape::Site)) {
            $report['problems'][] = 'shape_mismatch';

            return $report;
        }
        $report['rows'] = $database->table($table)->count();
        $slots = [];
        foreach ($registry->slots($schemaId) as $slot) {
            $slots[$slot->key()] = $slot;
        }
        $report['slots'] = count($slots);
        foreach (array_keys($published) as $key) {
            if (!isset($slots[$key])) {
                $report['problems'][] = 'slot_missing';
                break;
            }
        }
        $problems = [];
        foreach ($slots as $slot) {
            if ($slot->state !== PublishedSiteViewSlot::STATE_READY) {
                $problems['slot_' . $slot->state] = true;
                continue;
            }
            if ($slot->localeChain !== $this->site->readContracts()->localeChainKey($slot->locale)) {
                $problems['slot_chain_changed'] = true;
                continue;
            }
            if (!$registration->ready() || $hash !== $registration->publicFieldsHash) {
                continue;
            }
            $counts = $this->compareSlot($registration, $slot);
            foreach (['missing', 'extra', 'stale'] as $count) {
                $report[$count] += $counts[$count];
            }
        }
        foreach (['missing' => 'rows_missing', 'extra' => 'rows_extra', 'stale' => 'rows_stale'] as $count => $problem) {
            if ($report[$count] > 0) {
                $problems[$problem] = true;
            }
        }
        foreach (array_keys($problems) as $problem) {
            $report['problems'][] = $problem;
        }

        return $report;
    }

    /**
     * The storages to check: every registered site витрина and every storage with a
     * published record.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        $ids = [];
        foreach ($this->site->registry()->all() as $registration) {
            $ids[$registration->schemaId] = true;
        }
        foreach ($this->site->connection()->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('state', PublicationStateValue::Published->value)->distinct()->pluck('schema_id') as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }
        $list = array_map('strval', array_keys($ids));
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * Compares one ready slot with the contract entries, page by page of record ids:
     * rows the versions do not publish are extra, published records without a row are
     * missing, and a row whose entry, columns, side rows or search row differ from the
     * entry's projection is stale.
     *
     * @return array{missing: int, extra: int, stale: int}
     */
    private function compareSlot(PublishedSiteViewRegistration $registration, PublishedSiteViewSlot $slot): array
    {
        $database = $this->site->connection();
        $read = $this->site->readContracts();
        $table = $registration->tableName;
        $counts = ['missing' => 0, 'extra' => 0, 'stale' => 0];
        $after = null;
        $seen = [];
        do {
            $heads = $read->publishedHeadPage($slot->schemaId, $slot->scopeRef, $slot->locale, self::PAGE, $after);
            if ($heads === []) {
                break;
            }
            $ids = array_column($heads, 'record_id');
            $rows = [];
            foreach ($database->table($table)->where('slot_id', $slot->id)->whereIn('record_id', $ids)->get() as $row) {
                $rows[(string) $row->record_id] = (array) $row;
            }
            $multi = [];
            foreach ($database->table(StorageViewNaming::multiTable($table))->where('slot_id', $slot->id)->whereIn('record_id', $ids)->get() as $row) {
                $multi[(string) $row->record_id][] = $row->field_key . '|' . $row->value;
            }
            $search = [];
            if ($registration->layout->searchTable()) {
                foreach ($database->table(StorageViewNaming::searchTable($table))->where('slot_id', $slot->id)->whereIn('record_id', $ids)->get() as $row) {
                    $search[(string) $row->record_id] = $row->search_text === null ? null : (string) $row->search_text;
                }
            }
            foreach ($read->entriesForHeads($slot->schemaId, $slot->scopeRef, $slot->locale, $heads) as $entry) {
                $recordId = $entry['record_id'];
                $seen[$recordId] = true;
                $row = $rows[$recordId] ?? null;
                if ($row === null) {
                    $counts['missing']++;
                    continue;
                }
                if (!$this->same($registration, $slot, $entry, $row, $multi[$recordId] ?? [], $search[$recordId] ?? null)) {
                    $counts['stale']++;
                }
            }
            $after = $ids[array_key_last($ids)];
        } while (count($heads) === self::PAGE);
        // Rows of records this slot does not publish.
        $extra = 0;
        $last = null;
        do {
            $query = $database->table($table)->where('slot_id', $slot->id)->orderBy('record_id')->limit(self::PAGE);
            if ($last !== null) {
                $query->where('record_id', '>', $last);
            }
            $page = $query->pluck('record_id')->all();
            foreach ($page as $recordId) {
                if (!isset($seen[(string) $recordId])) {
                    $extra++;
                }
                $last = (string) $recordId;
            }
        } while (count($page) === self::PAGE);
        $counts['extra'] = $extra;

        return $counts;
    }

    /**
     * @param array{record_id: string, revision: int, locale: string, projection_version: int, values: array<string, mixed>, value_sources: array<string, string>} $entry
     * @param array<string, mixed> $row
     * @param list<string> $multi
     */
    private function same(PublishedSiteViewRegistration $registration, PublishedSiteViewSlot $slot, array $entry, array $row, array $multi, ?string $search): bool
    {
        if (PublishedSiteViewProjector::decodeEntry((string) $row['entry_json']) !== $entry
            || (int) $row['revision'] !== $entry['revision'] || (int) $row['projection_version'] !== $entry['projection_version']
            || (string) $row['entry_hash'] !== hash('sha256', (string) $row['entry_json'])) {
            return false;
        }
        $expected = $this->site->projector()->project($registration->layout, $slot->id, $entry, (string) $row['updated_at']);
        foreach ($registration->layout->written() as $column) {
            if ($column->column === null) {
                continue;
            }
            $value = $expected['row'][$column->column] ?? null;
            $stored = $row[$column->column] ?? null;
            $same = $column->sqlType === StorageViewColumn::SQL_DECIMAL
                ? ($value === null ? $stored === null : $stored !== null && (float) $stored === (float) $value)
                : ($value === null ? $stored === null : $stored !== null && (string) $stored === (string) $value);
            if (!$same) {
                return false;
            }
        }
        if (($expected['row']['title'] ?? null) !== ($row['title'] === null ? null : (string) $row['title'])) {
            return false;
        }
        $expectedMulti = array_map(static fn (array $item): string => $item['field_key'] . '|' . $item['value'], $expected['multi']);
        sort($expectedMulti);
        sort($multi);
        if ($expectedMulti !== $multi) {
            return false;
        }

        return !$registration->layout->searchTable() || ($expected['search']['search_text'] ?? null) === $search;
    }
}
