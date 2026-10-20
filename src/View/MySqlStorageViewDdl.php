<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\QueryException;

/**
 * MySQL and MariaDB DDL for the витрина. Columns are added and dropped in place
 * (ALGORITHM=INSTANT, or INPLACE with LOCK=NONE where INSTANT is not available),
 * indexes are built INPLACE with LOCK=NONE, so writers are not blocked. MySQL
 * commits the open transaction on any DDL, so none of this runs inside one.
 */
final class MySqlStorageViewDdl extends StorageViewDdl
{
    private const TYPES = [
        StorageViewColumn::SQL_BIGINT => 'BIGINT',
        StorageViewColumn::SQL_DECIMAL => 'DECIMAL(65,30)',
        StorageViewColumn::SQL_DATE => 'DATE',
        StorageViewColumn::SQL_DATETIME => 'DATETIME',
        StorageViewColumn::SQL_TINYINT => 'TINYINT',
        StorageViewColumn::SQL_REF => 'VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
        StorageViewColumn::SQL_USER_REF => 'VARCHAR(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
        StorageViewColumn::SQL_VARCHAR => 'VARCHAR(255)',
        // MEDIUMTEXT: a text value may be longer than TEXT's 64 KB.
        StorageViewColumn::SQL_TEXT => 'MEDIUMTEXT',
    ];

    public function transactional(): bool
    {
        return false;
    }

    public function ensureTable(string $table, StorageViewLayout $layout): void
    {
        $this->assertTable($table);
        if (!$this->tableExists($table)) {
            $columns = [
                '`record_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
                '`revision` BIGINT UNSIGNED NOT NULL',
                '`schema_version` INT UNSIGNED NOT NULL',
                '`content_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                '`created_at` DATETIME NOT NULL',
                '`updated_at` DATETIME NOT NULL',
                '`scope_ref` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
                '`state` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
                '`title` VARCHAR(255) NULL',
                '`search_text` MEDIUMTEXT NULL',
            ];
            $indexes = [
                'PRIMARY KEY (`record_id`)',
                'KEY `' . self::stateIndexName($table) . '` (`state`, `updated_at`, `record_id`)',
            ];
            foreach ($layout->physical() as $column) {
                if ($column->state === StorageViewColumn::STATE_DROPPING) {
                    continue;
                }
                $columns[] = $this->definition($column);
                if ($column->indexed) {
                    $indexes[] = 'KEY `' . self::fieldIndexName($table, (string) $column->column) . '` (`state`, `' . $column->column . '`, `record_id`)';
                }
            }
            $this->database->statement('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', array_merge($columns, $indexes))
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC');
        }
        $multi = StorageViewNaming::multiTable($table);
        if (!$this->tableExists($multi)) {
            $this->database->statement('CREATE TABLE IF NOT EXISTS `' . $multi . '` ('
                . '`record_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
                . '`field_key` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
                . '`value` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
                . 'PRIMARY KEY (`field_key`, `value`, `record_id`), '
                . 'KEY `' . self::multiRecordIndexName($table) . '` (`record_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC');
        }
    }

    public function addColumn(string $table, StorageViewColumn $column): void
    {
        $this->assertTable($table);
        if ($column->column === null || in_array($column->column, $this->columns($table), true)) {
            return;
        }
        $this->alter('ALTER TABLE `' . $table . '` ADD COLUMN ' . $this->definition($column));
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->assertTable($table);
        $this->assertColumn($column);
        if (!in_array($column, $this->columns($table), true)) {
            return;
        }
        $this->dropIndex($table, $column);
        $this->alter('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
    }

    public function createIndex(string $table, StorageViewColumn $column): void
    {
        $this->assertTable($table);
        if ($column->column === null) {
            return;
        }
        $this->assertColumn($column->column);
        $name = self::fieldIndexName($table, $column->column);
        if (in_array($name, $this->indexes($table), true)) {
            return;
        }
        $this->database->statement('CREATE INDEX `' . $name . '` ON `' . $table . '` (`state`, `' . $column->column . '`, `record_id`) ALGORITHM=INPLACE LOCK=NONE');
    }

    public function dropIndex(string $table, string $column): void
    {
        $this->assertTable($table);
        $this->assertColumn($column);
        $name = self::fieldIndexName($table, $column);
        if (!in_array($name, $this->indexes($table), true)) {
            return;
        }
        $this->database->statement('DROP INDEX `' . $name . '` ON `' . $table . '` ALGORITHM=INPLACE LOCK=NONE');
    }

    public function dropTables(string $table): void
    {
        $this->assertTable($table);
        $this->database->statement('DROP TABLE IF EXISTS `' . StorageViewNaming::multiTable($table) . '`');
        $this->database->statement('DROP TABLE IF EXISTS `' . $table . '`');
    }

    public function tableExists(string $table): bool
    {
        return $this->database->selectOne(
            'select TABLE_NAME as name from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME = ?',
            [$table],
        ) !== null;
    }

    public function columns(string $table): array
    {
        $this->assertTable($table);

        return array_map(static fn (object $row): string => (string) $row->name, $this->database->select(
            'select COLUMN_NAME as name from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? order by ORDINAL_POSITION',
            [$table],
        ));
    }

    public function indexes(string $table): array
    {
        $this->assertTable($table);

        return array_map(static fn (object $row): string => (string) $row->name, $this->database->select(
            'select distinct INDEX_NAME as name from information_schema.STATISTICS where TABLE_SCHEMA = database() and TABLE_NAME = ?',
            [$table],
        ));
    }

    public function viewTables(): array
    {
        $names = [];
        foreach ($this->database->select(
            'select TABLE_NAME as name from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME like ? order by TABLE_NAME',
            [StorageViewNaming::tableLikePattern()],
        ) as $row) {
            if (StorageViewNaming::isTable((string) $row->name)) {
                $names[] = (string) $row->name;
            }
        }

        return $names;
    }

    /** Adds or drops a column instantly where the server can, otherwise in place without a lock. */
    private function alter(string $statement): void
    {
        try {
            $this->database->statement($statement . ', ALGORITHM=INSTANT');
        } catch (QueryException) {
            $this->database->statement($statement . ', ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    private function definition(StorageViewColumn $column): string
    {
        $this->assertColumn((string) $column->column);

        return '`' . $column->column . '` ' . self::TYPES[$column->sqlType] . ' NULL';
    }
}
