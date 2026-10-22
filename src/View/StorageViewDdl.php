<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * DDL for a storage's витрина: its table, its side tables for multi-values and
 * for the search text, typed columns and their indexes. Every operation is idempotent, so a step that was
 * interrupted is simply run again.
 *
 * The operations that create something take an optional table shape
 * (StorageViewTableShape): without one they build the admin витрина exactly as
 * before; the site shape keys every row by (slot_id, record_id) and starts every
 * field index with slot_id where the admin one starts with state.
 */
abstract class StorageViewDdl
{
    public function __construct(protected readonly ConnectionInterface $database)
    {
    }

    /** The DDL for the connection's driver, or null when the driver has no витрина support. */
    public static function for(ConnectionInterface $database): ?self
    {
        if (!$database instanceof Connection) {
            return null;
        }

        return match ($database->getDriverName()) {
            'mysql', 'mariadb' => new MySqlStorageViewDdl($database),
            'sqlite' => new SqliteStorageViewDdl($database),
            default => null,
        };
    }

    /**
     * Whether DDL may run inside an open transaction and roll back with it. MySQL
     * commits the open transaction on any DDL, so there it runs outside, before
     * the transaction or after it commits.
     */
    abstract public function transactional(): bool;

    /** Creates the table and the side table when they are missing, with the layout's columns and indexes. */
    abstract public function ensureTable(string $table, StorageViewLayout $layout, ?StorageViewTableShape $shape = null): void;

    abstract public function addColumn(string $table, StorageViewColumn $column): void;

    abstract public function dropColumn(string $table, string $column): void;

    abstract public function createIndex(string $table, StorageViewColumn $column, ?StorageViewTableShape $shape = null): void;

    abstract public function dropIndex(string $table, string $column): void;

    /**
     * Adds the admin table's system indexes it lacks (a table built before an index
     * was added to its definition), in place: on MySQL INPLACE with LOCK=NONE, so
     * writers are not blocked and the rows are not rewritten. Nothing else changes.
     */
    abstract public function ensureSystemIndexes(string $table): void;

    abstract public function dropTables(string $table): void;

    abstract public function tableExists(string $table): bool;

    /** @return list<string> */
    abstract public function columns(string $table): array;

    /** @return list<string> */
    abstract public function indexes(string $table): array;

    /** @return list<string> Every витрина table of the shape (admin by default) in the database, side tables included. */
    abstract public function viewTables(?StorageViewTableShape $shape = null): array;

    /** Whether the search table carries a FULLTEXT index (MySQL and MariaDB). */
    public function fulltext(): bool
    {
        return false;
    }

    /**
     * Creates the search table when it is missing: `(record_id, search_text)`, one row
     * per record, and on MySQL the FULLTEXT index on `search_text`. It is a table of its
     * own because InnoDB adds no column in place (neither INSTANT nor INPLACE) to a
     * table that has a FULLTEXT index; the main table keeps its online column changes.
     */
    abstract public function ensureSearchTable(string $table, ?StorageViewTableShape $shape = null): void;

    /**
     * Empties the search table before its text is refilled from scratch. On MySQL it
     * is truncated, which also gives the FULLTEXT index a fresh start: InnoDB keeps
     * the ids of changed and deleted rows until the index is optimized, and every
     * search reads them. Writers that are in a transaction finish first.
     */
    abstract public function clearSearchTable(string $table): void;

    /** Drops the field indexes of columns the layout no longer indexes. */
    public function dropStaleIndexes(string $table, StorageViewLayout $layout): void
    {
        $this->assertTable($table);
        $indexes = $this->indexes($table);
        foreach ($layout->physical() as $column) {
            if (!$column->indexed && $column->state !== StorageViewColumn::STATE_DROPPING
                && in_array(self::fieldIndexName($table, (string) $column->column), $indexes, true)) {
                $this->dropIndex($table, (string) $column->column);
            }
        }
    }

    public static function searchIndexName(string $table): string
    {
        return StorageViewNaming::index(StorageViewNaming::searchTable($table), 'search');
    }

    public static function fieldIndexName(string $table, string $column): string
    {
        return StorageViewNaming::index($table, 'field:' . $column);
    }

    public static function stateIndexName(string $table): string
    {
        return StorageViewNaming::index($table, 'state_updated');
    }

    /** (state, record_id): the list's default order, and the order of a sort's ties. */
    public static function stateRecordIndexName(string $table): string
    {
        return StorageViewNaming::index($table, 'state_record');
    }

    /** (state, created_at, record_id): the list by creation time, «newest first» read backwards. */
    public static function stateCreatedIndexName(string $table): string
    {
        return StorageViewNaming::index($table, 'state_created');
    }

    /** A site table's (record_id) index: a record's rows in every slot, for a purge. */
    public static function siteRecordIndexName(string $table): string
    {
        return StorageViewNaming::index($table, 'record');
    }

    /** A site table's (slot_id, published_at, record_id) index: a slot's records in publication order. */
    public static function sitePublishedIndexName(string $table): string
    {
        return StorageViewNaming::index($table, 'published');
    }

    /** A site search table's (record_id) index. */
    public static function siteSearchRecordIndexName(string $table): string
    {
        return StorageViewNaming::index(StorageViewNaming::searchTable($table), 'record');
    }

    public static function multiRecordIndexName(string $table): string
    {
        return StorageViewNaming::index(StorageViewNaming::multiTable($table), 'record');
    }

    /** Makes the table hold exactly the layout: adds what is missing and drops what is dropping. */
    public function materialize(string $table, StorageViewLayout $layout, ?StorageViewTableShape $shape = null): void
    {
        $this->ensureTable($table, $layout, $shape);
        foreach ($layout->physical() as $column) {
            if ($column->state === StorageViewColumn::STATE_DROPPING) {
                $this->dropIndex($table, (string) $column->column);
                $this->dropColumn($table, (string) $column->column);
                continue;
            }
            $this->addColumn($table, $column);
            if ($column->indexed) {
                $this->createIndex($table, $column, $shape);
            }
        }
        if ($layout->searchTable()) {
            $this->ensureSearchTable($table, $shape);
        }
    }

    /**
     * A витрина table of the shape; without a shape, of either shape (an operation
     * that is the same for both, such as dropping a column or the tables).
     */
    protected function assertTable(string $table, ?StorageViewTableShape $shape = null): void
    {
        $valid = $shape === null
            ? StorageViewNaming::isTable($table) || StorageViewNaming::isSiteTable($table)
            : StorageViewNaming::isTableOf($table, $shape);
        if (!$valid) {
            throw new \InvalidArgumentException('storage_view_table_invalid');
        }
    }

    protected function assertColumn(string $column): void
    {
        if (preg_match('/\Ac[1-9][0-9]{0,8}\z/D', $column) !== 1) {
            throw new \InvalidArgumentException('storage_view_column_invalid');
        }
    }
}
