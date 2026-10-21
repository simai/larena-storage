<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\ConnectionInterface;

/**
 * Writes витрина rows: an upsert of the main row and of the search row, and a
 * replacement of the record's side rows. It runs inside the caller's transaction.
 *
 * A site table (StorageViewTableShape::Site) keys its rows, side rows and search
 * rows by (slot_id, record_id): a record's rows in one slot are replaced, the same
 * record in another slot is left alone.
 */
final class StorageViewWriter
{
    private const CHUNK = 500;
    private const SQL_CACHE_LIMIT = 256;

    /** @var array<string, string> Compiled single-row upserts, by table and columns. */
    private array $upsertSql = [];

    public function __construct(private readonly ConnectionInterface $database)
    {
    }

    /**
     * @param list<array{row: array<string, mixed>, multi: list<array{record_id: string, field_key: string, value: string}>, search?: array{record_id: string, search_text: ?string}|null}> $projections
     */
    public function upsert(string $table, array $projections, bool $replaceMulti = true, bool $newRecords = false, ?StorageViewTableShape $shape = null): void
    {
        if ($projections === []) {
            return;
        }
        $keys = StorageViewTableShape::of($shape)->keyColumns();
        $rows = array_map(static fn (array $projection): array => $projection['row'], $projections);
        $this->upsertRows($table, $rows, $keys);
        $search = [];
        foreach ($projections as $projection) {
            if (($projection['search'] ?? null) !== null) {
                $search[] = $projection['search'];
            }
        }
        $this->upsertRows(StorageViewNaming::searchTable($table), $search, $keys);
        // A layout without multi-value fields has no side rows to replace.
        if (!$replaceMulti) {
            return;
        }
        $multiTable = StorageViewNaming::multiTable($table);
        // A record created in this transaction has no side rows to replace yet.
        if (!$newRecords) {
            if (StorageViewTableShape::of($shape) === StorageViewTableShape::Site) {
                $bySlot = [];
                foreach ($rows as $row) {
                    $bySlot[(int) $row['slot_id']][] = (string) $row['record_id'];
                }
                foreach ($bySlot as $slotId => $slotRecords) {
                    foreach (array_chunk($slotRecords, self::CHUNK) as $chunk) {
                        $this->database->table($multiTable)->where('slot_id', $slotId)->whereIn('record_id', $chunk)->delete();
                    }
                }
            } else {
                $recordIds = array_map(static fn (array $row): string => (string) $row['record_id'], $rows);
                foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
                    $this->database->table($multiTable)->whereIn('record_id', $chunk)->delete();
                }
            }
        }
        $multi = [];
        foreach ($projections as $projection) {
            foreach ($projection['multi'] as $item) {
                $multi[] = $item;
            }
        }
        foreach (array_chunk($multi, self::CHUNK) as $chunk) {
            $this->database->table($multiTable)->insert($chunk);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keys
     */
    private function upsertRows(string $table, array $rows, array $keys): void
    {
        if ($rows === []) {
            return;
        }
        if (count($rows) === 1) {
            $this->upsertOne($table, $rows[0], $keys);

            return;
        }
        $update = array_values(array_diff(array_keys($rows[0]), $keys));
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->database->table($table)->upsert($chunk, $keys, $update);
        }
    }

    /**
     * The write path upserts one row per write; its statement is compiled once per
     * table and column set, which is most of the builder's cost for a wide row.
     *
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function upsertOne(string $table, array $row, array $keys): void
    {
        ksort($row, SORT_STRING);
        $key = $table . '|' . implode(',', array_keys($row));
        $sql = $this->upsertSql[$key] ?? null;
        if ($sql === null) {
            $query = $this->database->table($table);
            $sql = $query->getGrammar()->compileUpsert($query, [$row], $keys, array_values(array_diff(array_keys($row), $keys)));
            if (count($this->upsertSql) >= self::SQL_CACHE_LIMIT) {
                $this->upsertSql = [];
            }
            $this->upsertSql[$key] = $sql;
        }
        $this->database->affectingStatement($sql, array_values($row));
    }

    /**
     * @param list<string> $recordIds
     * @param bool $search Whether the table has a search table (its layout has search fields).
     */
    public function delete(string $table, array $recordIds, bool $search = true): void
    {
        foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
            $this->database->table(StorageViewNaming::multiTable($table))->whereIn('record_id', $chunk)->delete();
            if ($search) {
                $this->database->table(StorageViewNaming::searchTable($table))->whereIn('record_id', $chunk)->delete();
            }
            $this->database->table($table)->whereIn('record_id', $chunk)->delete();
        }
    }

    /**
     * Removes rows of one slot of a site table: these records, or every row of the
     * slot when no record is named.
     *
     * @param list<string>|null $recordIds
     * @param bool $search Whether the table has a search table.
     */
    public function deleteSlotRecords(string $table, int $slotId, ?array $recordIds = null, bool $search = true): void
    {
        $tables = [StorageViewNaming::multiTable($table)];
        if ($search) {
            $tables[] = StorageViewNaming::searchTable($table);
        }
        $tables[] = $table;
        if ($recordIds === null) {
            foreach ($tables as $name) {
                $this->database->table($name)->where('slot_id', $slotId)->delete();
            }

            return;
        }
        foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
            foreach ($tables as $name) {
                $this->database->table($name)->where('slot_id', $slotId)->whereIn('record_id', $chunk)->delete();
            }
        }
    }

    /**
     * Removes the rows of these records from every slot of a site table (a purge).
     *
     * @param list<string> $recordIds
     */
    public function deleteRecordsInAllSlots(string $table, array $recordIds, bool $search = true): void
    {
        foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
            $this->database->table(StorageViewNaming::multiTable($table))->whereIn('record_id', $chunk)->delete();
            if ($search) {
                $this->database->table(StorageViewNaming::searchTable($table))->whereIn('record_id', $chunk)->delete();
            }
            $this->database->table($table)->whereIn('record_id', $chunk)->delete();
        }
    }

    public function deleteField(string $table, string $fieldKey): void
    {
        $this->database->table(StorageViewNaming::multiTable($table))->where('field_key', $fieldKey)->delete();
    }

    public function truncate(string $table, bool $search = true): void
    {
        $this->database->table(StorageViewNaming::multiTable($table))->delete();
        if ($search) {
            $this->database->table(StorageViewNaming::searchTable($table))->delete();
        }
        $this->database->table($table)->delete();
    }
}
