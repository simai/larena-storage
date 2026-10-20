<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * DDL for a storage's витрина: its table, its side table for multi-values, typed
 * columns and their indexes. Every operation is idempotent, so a step that was
 * interrupted is simply run again.
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
    abstract public function ensureTable(string $table, StorageViewLayout $layout): void;

    abstract public function addColumn(string $table, StorageViewColumn $column): void;

    abstract public function dropColumn(string $table, string $column): void;

    abstract public function createIndex(string $table, StorageViewColumn $column): void;

    abstract public function dropIndex(string $table, string $column): void;

    abstract public function dropTables(string $table): void;

    abstract public function tableExists(string $table): bool;

    /** @return list<string> */
    abstract public function columns(string $table): array;

    /** @return list<string> */
    abstract public function indexes(string $table): array;

    /** @return list<string> Every витрина table in the database, side tables included. */
    abstract public function viewTables(): array;

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

    public static function multiRecordIndexName(string $table): string
    {
        return StorageViewNaming::index(StorageViewNaming::multiTable($table), 'record');
    }

    /** Makes the table hold exactly the layout: adds what is missing and drops what is dropping. */
    public function materialize(string $table, StorageViewLayout $layout): void
    {
        $this->ensureTable($table, $layout);
        foreach ($layout->physical() as $column) {
            if ($column->state === StorageViewColumn::STATE_DROPPING) {
                $this->dropIndex($table, (string) $column->column);
                $this->dropColumn($table, (string) $column->column);
                continue;
            }
            $this->addColumn($table, $column);
            if ($column->indexed) {
                $this->createIndex($table, $column);
            }
        }
    }

    protected function assertTable(string $table): void
    {
        if (!StorageViewNaming::isTable($table)) {
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
