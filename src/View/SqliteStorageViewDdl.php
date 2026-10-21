<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\ConnectionInterface;

/**
 * SQLite DDL for the витрина, for tests and local development. SQLite runs DDL
 * inside a transaction and rolls it back with it; a test can turn that off to
 * take the same path as MySQL.
 */
final class SqliteStorageViewDdl extends StorageViewDdl
{
    private const TYPES = [
        StorageViewColumn::SQL_BIGINT => 'INTEGER',
        StorageViewColumn::SQL_DECIMAL => 'NUMERIC',
        StorageViewColumn::SQL_DATE => 'TEXT',
        StorageViewColumn::SQL_DATETIME => 'TEXT',
        StorageViewColumn::SQL_TINYINT => 'INTEGER',
        StorageViewColumn::SQL_REF => 'TEXT',
        StorageViewColumn::SQL_USER_REF => 'TEXT',
        StorageViewColumn::SQL_TEXT => 'TEXT',
    ];

    public function __construct(ConnectionInterface $database, private readonly bool $transactionalDdl = true)
    {
        parent::__construct($database);
    }

    public function transactional(): bool
    {
        return $this->transactionalDdl;
    }

    public function ensureTable(string $table, StorageViewLayout $layout, ?StorageViewTableShape $shape = null): void
    {
        if (StorageViewTableShape::of($shape) === StorageViewTableShape::Site) {
            $this->ensureSiteTable($table, $layout);

            return;
        }
        $this->assertTable($table, StorageViewTableShape::Admin);
        if (!$this->tableExists($table)) {
            $columns = [
                '"record_id" TEXT NOT NULL PRIMARY KEY',
                '"revision" INTEGER NOT NULL',
                '"schema_version" INTEGER NOT NULL',
                '"content_hash" TEXT NOT NULL',
                '"created_at" TEXT NOT NULL',
                '"updated_at" TEXT NOT NULL',
                '"scope_ref" TEXT NULL',
                '"state" TEXT NULL',
                '"title" TEXT NULL',
                '"search_text" TEXT NULL',
            ];
            foreach ($layout->physical() as $column) {
                if ($column->state !== StorageViewColumn::STATE_DROPPING) {
                    $columns[] = $this->definition($column);
                }
            }
            $this->database->statement('CREATE TABLE "' . $table . '" (' . implode(', ', $columns) . ')');
        }
        $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::stateIndexName($table) . '" ON "' . $table . '" ("state", "updated_at", "record_id")');
        $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::stateRecordIndexName($table) . '" ON "' . $table . '" ("state", "record_id")');
        foreach ($layout->physical() as $column) {
            if ($column->indexed && $column->state !== StorageViewColumn::STATE_DROPPING) {
                $this->createIndex($table, $column);
            }
        }
        $multi = StorageViewNaming::multiTable($table);
        $this->database->statement('CREATE TABLE IF NOT EXISTS "' . $multi . '" ("record_id" TEXT NOT NULL, "field_key" TEXT NOT NULL, "value" TEXT NOT NULL, PRIMARY KEY ("field_key", "value", "record_id"))');
        $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::multiRecordIndexName($table) . '" ON "' . $multi . '" ("record_id")');
        if ($layout->searchTable()) {
            $this->ensureSearchTable($table);
        }
    }

    public function addColumn(string $table, StorageViewColumn $column): void
    {
        $this->assertTable($table);
        if ($column->column === null || in_array($column->column, $this->columns($table), true)) {
            return;
        }
        $this->database->statement('ALTER TABLE "' . $table . '" ADD COLUMN ' . $this->definition($column));
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->assertTable($table);
        $this->assertColumn($column);
        if (!in_array($column, $this->columns($table), true)) {
            return;
        }
        $this->dropIndex($table, $column);
        $this->database->statement('ALTER TABLE "' . $table . '" DROP COLUMN "' . $column . '"');
    }

    public function createIndex(string $table, StorageViewColumn $column, ?StorageViewTableShape $shape = null): void
    {
        $shape = StorageViewTableShape::of($shape);
        $this->assertTable($table, $shape);
        if ($column->column === null) {
            return;
        }
        $this->assertColumn($column->column);
        $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::fieldIndexName($table, $column->column) . '" ON "' . $table . '" ("' . $shape->partitionColumn() . '", "' . $column->column . '", "record_id")');
    }

    public function dropIndex(string $table, string $column): void
    {
        $this->assertTable($table);
        $this->assertColumn($column);
        $this->database->statement('DROP INDEX IF EXISTS "' . self::fieldIndexName($table, $column) . '"');
    }

    public function dropTables(string $table): void
    {
        $this->assertTable($table);
        $this->database->statement('DROP TABLE IF EXISTS "' . StorageViewNaming::searchTable($table) . '"');
        $this->database->statement('DROP TABLE IF EXISTS "' . StorageViewNaming::multiTable($table) . '"');
        $this->database->statement('DROP TABLE IF EXISTS "' . $table . '"');
    }

    public function ensureSearchTable(string $table, ?StorageViewTableShape $shape = null): void
    {
        if (StorageViewTableShape::of($shape) === StorageViewTableShape::Site) {
            $this->assertTable($table, StorageViewTableShape::Site);
            $search = StorageViewNaming::searchTable($table);
            $this->database->statement('CREATE TABLE IF NOT EXISTS "' . $search . '" ("slot_id" INTEGER NOT NULL, "record_id" TEXT NOT NULL, "search_text" TEXT NULL, PRIMARY KEY ("slot_id", "record_id"))');
            $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::siteSearchRecordIndexName($table) . '" ON "' . $search . '" ("record_id")');

            return;
        }
        $this->assertTable($table, StorageViewTableShape::Admin);
        $this->database->statement('CREATE TABLE IF NOT EXISTS "' . StorageViewNaming::searchTable($table) . '" ("record_id" TEXT NOT NULL PRIMARY KEY, "search_text" TEXT NULL)');
    }

    /**
     * The site table: one row per published record and slot, keyed by (slot_id,
     * record_id), with the exact contract entry, the typed columns of the public
     * fields, and the side tables keyed the same way.
     */
    private function ensureSiteTable(string $table, StorageViewLayout $layout): void
    {
        $this->assertTable($table, StorageViewTableShape::Site);
        if (!$this->tableExists($table)) {
            $columns = [
                '"slot_id" INTEGER NOT NULL',
                '"record_id" TEXT NOT NULL',
                '"revision" INTEGER NOT NULL',
                '"schema_version" INTEGER NOT NULL',
                '"projection_version" INTEGER NOT NULL',
                '"entry_hash" TEXT NOT NULL',
                '"entry_json" TEXT NOT NULL',
                '"title" TEXT NULL',
                '"published_at" TEXT NULL',
                '"updated_at" TEXT NOT NULL',
            ];
            foreach ($layout->physical() as $column) {
                if ($column->state !== StorageViewColumn::STATE_DROPPING) {
                    $columns[] = $this->definition($column);
                }
            }
            $columns[] = 'PRIMARY KEY ("slot_id", "record_id")';
            $this->database->statement('CREATE TABLE "' . $table . '" (' . implode(', ', $columns) . ')');
        }
        $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::siteRecordIndexName($table) . '" ON "' . $table . '" ("record_id")');
        if (in_array('published_at', $this->columns($table), true)) {
            $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::sitePublishedIndexName($table) . '" ON "' . $table . '" ("slot_id", "published_at", "record_id")');
        }
        foreach ($layout->physical() as $column) {
            if ($column->indexed && $column->state !== StorageViewColumn::STATE_DROPPING) {
                $this->createIndex($table, $column, StorageViewTableShape::Site);
            }
        }
        $multi = StorageViewNaming::multiTable($table);
        $this->database->statement('CREATE TABLE IF NOT EXISTS "' . $multi . '" ("slot_id" INTEGER NOT NULL, "record_id" TEXT NOT NULL, "field_key" TEXT NOT NULL, "value" TEXT NOT NULL, PRIMARY KEY ("slot_id", "field_key", "value", "record_id"))');
        $this->database->statement('CREATE INDEX IF NOT EXISTS "' . self::multiRecordIndexName($table) . '" ON "' . $multi . '" ("record_id", "slot_id")');
        if ($layout->searchTable()) {
            $this->ensureSearchTable($table, StorageViewTableShape::Site);
        }
    }

    public function clearSearchTable(string $table): void
    {
        $this->ensureSearchTable($table);
        $this->database->table(StorageViewNaming::searchTable($table))->delete();
    }

    public function tableExists(string $table): bool
    {
        return $this->database->selectOne("select name from sqlite_master where type = 'table' and name = ?", [$table]) !== null;
    }

    public function columns(string $table): array
    {
        $this->assertTable($table);
        $names = [];
        foreach ($this->database->select('PRAGMA table_info("' . $table . '")') as $row) {
            $names[] = (string) $row->name;
        }

        return $names;
    }

    public function indexes(string $table): array
    {
        $this->assertTable($table);
        $names = [];
        foreach ($this->database->select('PRAGMA index_list("' . $table . '")') as $row) {
            $names[] = (string) $row->name;
        }

        return $names;
    }

    public function viewTables(?StorageViewTableShape $shape = null): array
    {
        $names = [];
        foreach ($this->database->select(
            "select name from sqlite_master where type = 'table' and name like ? escape '\\' order by name",
            [StorageViewNaming::tableLikePattern($shape)],
        ) as $row) {
            if (StorageViewNaming::isTableOf((string) $row->name, $shape)) {
                $names[] = (string) $row->name;
            }
        }

        return $names;
    }

    private function definition(StorageViewColumn $column): string
    {
        $this->assertColumn((string) $column->column);

        return '"' . $column->column . '" ' . ($column->isVarchar() ? 'TEXT' : self::TYPES[$column->sqlType]) . ' NULL';
    }
}
