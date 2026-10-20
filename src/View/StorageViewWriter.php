<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\ConnectionInterface;

/**
 * Writes витрина rows: an upsert of the main row and a replacement of the record's
 * side rows. It runs inside the caller's transaction.
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
     * @param list<array{row: array<string, mixed>, multi: list<array{record_id: string, field_key: string, value: string}>}> $projections
     */
    public function upsert(string $table, array $projections, bool $replaceMulti = true, bool $newRecords = false): void
    {
        if ($projections === []) {
            return;
        }
        $rows = array_map(static fn (array $projection): array => $projection['row'], $projections);
        if (count($rows) === 1) {
            $this->upsertOne($table, $rows[0]);
        } else {
            $update = array_values(array_diff(array_keys($rows[0]), ['record_id']));
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $this->database->table($table)->upsert($chunk, ['record_id'], $update);
            }
        }
        // A layout without multi-value fields has no side rows to replace.
        if (!$replaceMulti) {
            return;
        }
        $recordIds = array_map(static fn (array $row): string => (string) $row['record_id'], $rows);
        $multiTable = StorageViewNaming::multiTable($table);
        // A record created in this transaction has no side rows to replace yet.
        if (!$newRecords) {
            foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
                $this->database->table($multiTable)->whereIn('record_id', $chunk)->delete();
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
     * The write path upserts one row per write; its statement is compiled once per
     * table and column set, which is most of the builder's cost for a wide row.
     *
     * @param array<string, mixed> $row
     */
    private function upsertOne(string $table, array $row): void
    {
        ksort($row, SORT_STRING);
        $key = $table . '|' . implode(',', array_keys($row));
        $sql = $this->upsertSql[$key] ?? null;
        if ($sql === null) {
            $query = $this->database->table($table);
            $sql = $query->getGrammar()->compileUpsert($query, [$row], ['record_id'], array_values(array_diff(array_keys($row), ['record_id'])));
            if (count($this->upsertSql) >= self::SQL_CACHE_LIMIT) {
                $this->upsertSql = [];
            }
            $this->upsertSql[$key] = $sql;
        }
        $this->database->affectingStatement($sql, array_values($row));
    }

    /** @param list<string> $recordIds */
    public function delete(string $table, array $recordIds): void
    {
        foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
            $this->database->table(StorageViewNaming::multiTable($table))->whereIn('record_id', $chunk)->delete();
            $this->database->table($table)->whereIn('record_id', $chunk)->delete();
        }
    }

    public function deleteField(string $table, string $fieldKey): void
    {
        $this->database->table(StorageViewNaming::multiTable($table))->where('field_key', $fieldKey)->delete();
    }

    public function truncate(string $table): void
    {
        $this->database->table(StorageViewNaming::multiTable($table))->delete();
        $this->database->table($table)->delete();
    }
}
