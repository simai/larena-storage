<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/**
 * Compares a витрина's tables with its registered layout. The витрина tables are
 * created at runtime, one pair per storage, so the static StorageOwnedTableShapeGuard
 * does not list them; this verifier checks them against their layouts instead.
 *
 * Some problems are only a missing system index that the DDL adds in place
 * (StorageViewDdl::ensureSystemIndexes, online on MySQL) to a table built before the
 * index joined its definition: ONLINE_PROBLEMS. The table still holds the layout and
 * is read and written as before (a sort on that index is slower until it is added),
 * so problems() reports them and matches() does not count them; no write path turns
 * a витрина pending, and no rebuild drops its rows, for an index alone.
 */
final readonly class StorageViewShapeVerifier
{
    public const SYSTEM_COLUMNS = [
        'record_id', 'revision', 'schema_version', 'content_hash', 'created_at', 'updated_at',
        'scope_ref', 'state', 'title', 'search_text',
    ];
    public const MULTI_COLUMNS = ['record_id', 'field_key', 'value'];
    /** The admin table's (state, created_at, record_id) index is missing. */
    public const CREATED_INDEX_MISSING = 'created_index_missing';
    /** Problems the DDL repairs in place, without touching the rows. */
    public const ONLINE_PROBLEMS = [self::CREATED_INDEX_MISSING];

    public function __construct(private StorageViewDdl $ddl)
    {
    }

    /**
     * @return list<string> What does not match; empty when the tables hold the layout.
     *                      With $includeReserved, columns still being added must exist too.
     */
    public function problems(string $table, StorageViewLayout $layout, bool $includeReserved = true, ?StorageViewTableShape $shape = null): array
    {
        $shape = StorageViewTableShape::of($shape);
        $system = $shape->systemColumns();
        if (!$this->ddl->tableExists($table)) {
            return ['table_missing'];
        }
        $multi = StorageViewNaming::multiTable($table);
        if (!$this->ddl->tableExists($multi)) {
            return ['multi_table_missing'];
        }
        $problems = [];
        $columns = $this->ddl->columns($table);
        $indexes = $this->ddl->indexes($table);
        foreach ($system as $column) {
            if (!in_array($column, $columns, true)) {
                $problems[] = 'system_column_missing:' . $column;
            }
        }
        if ($shape === StorageViewTableShape::Site) {
            if (!in_array(StorageViewDdl::siteRecordIndexName($table), $indexes, true)) {
                $problems[] = 'record_index_missing';
            }
            if (!in_array(StorageViewDdl::sitePublishedIndexName($table), $indexes, true)) {
                $problems[] = 'published_index_missing';
            }
        } else {
            if (!in_array(StorageViewDdl::stateIndexName($table), $indexes, true)) {
                $problems[] = 'state_index_missing';
            }
            if (!in_array(StorageViewDdl::stateCreatedIndexName($table), $indexes, true)) {
                $problems[] = self::CREATED_INDEX_MISSING;
            }
        }
        if ($layout->searchTable()) {
            $search = StorageViewNaming::searchTable($table);
            if (!$this->ddl->tableExists($search)) {
                $problems[] = 'search_table_missing';
            } elseif ($this->ddl->fulltext() && !in_array(StorageViewDdl::searchIndexName($table), $this->ddl->indexes($search), true)) {
                $problems[] = 'search_index_missing';
            }
        }
        $expected = [];
        foreach ($layout->physical() as $column) {
            $name = (string) $column->column;
            $expected[$name] = true;
            if ($column->state === StorageViewColumn::STATE_DROPPING
                || (!$includeReserved && $column->state === StorageViewColumn::STATE_ADDING)) {
                continue;
            }
            if (!in_array($name, $columns, true)) {
                $problems[] = 'column_missing:' . $name;
            } elseif ($column->indexed && !in_array(StorageViewDdl::fieldIndexName($table, $name), $indexes, true)) {
                $problems[] = 'index_missing:' . $name;
            }
        }
        foreach ($columns as $column) {
            if (!in_array($column, $system, true) && !isset($expected[$column])) {
                $problems[] = 'column_unexpected:' . $column;
            }
        }
        foreach ($shape->multiColumns() as $column) {
            if (!in_array($column, $this->ddl->columns($multi), true)) {
                $problems[] = 'multi_column_missing:' . $column;
            }
        }

        return $problems;
    }

    /** The tables hold the layout; a missing index the DDL adds in place (ONLINE_PROBLEMS) does not count. */
    public function matches(string $table, StorageViewLayout $layout, bool $includeReserved = true, ?StorageViewTableShape $shape = null): bool
    {
        return array_diff($this->problems($table, $layout, $includeReserved, $shape), self::ONLINE_PROBLEMS) === [];
    }
}
