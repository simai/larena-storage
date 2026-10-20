<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\QueryException;

/**
 * MySQL and MariaDB DDL for the витрина. Columns are added and dropped in place
 * (ALGORITHM=INSTANT, or INPLACE with LOCK=NONE where INSTANT is not available),
 * indexes are built INPLACE with LOCK=NONE, so writers are not blocked. MySQL
 * commits the open transaction on any DDL, so none of this runs inside one.
 *
 * Search: the search table's `search_text` carries a FULLTEXT index with InnoDB's default parser
 * (words of letters, digits and underscores, at least innodb_ft_min_token_size
 * characters; Russian and English alike), built without a stopword list so that
 * "the" or "about" are words like any other. The ngram parser is not used: it
 * indexes every pair of characters, which multiplies the index several times
 * over and makes word-prefix queries slower, for no gain in Russian or English
 * text. The index lives in a table of its own (`_s`): InnoDB cannot add a column
 * to a table with a FULLTEXT index without copying it, and the main table's columns
 * must stay INSTANT. The search table is created empty with its index, so no
 * FULLTEXT index is ever built over existing rows (which would block writes).
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
                'KEY `' . self::stateRecordIndexName($table) . '` (`state`, `record_id`)',
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
        } elseif (!in_array(self::stateRecordIndexName($table), $this->indexes($table), true)) {
            // A table built before the list read it gains its default-order index in place.
            $this->database->statement('CREATE INDEX `' . self::stateRecordIndexName($table) . '` ON `' . $table . '` (`state`, `record_id`) ALGORITHM=INPLACE LOCK=NONE');
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

    public function fulltext(): bool
    {
        return true;
    }

    public function ensureSearchTable(string $table): void
    {
        $this->assertTable($table);
        $search = StorageViewNaming::searchTable($table);
        if ($this->tableExists($search)) {
            return;
        }
        $this->withoutStopwords(fn () => $this->database->statement('CREATE TABLE IF NOT EXISTS `' . $search . '` ('
            . '`record_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
            . '`search_text` MEDIUMTEXT NULL, '
            . 'PRIMARY KEY (`record_id`), '
            . 'FULLTEXT KEY `' . self::searchIndexName($table) . '` (`search_text`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC'));
    }

    public function clearSearchTable(string $table): void
    {
        $this->assertTable($table);
        $search = StorageViewNaming::searchTable($table);
        if (!$this->tableExists($search)) {
            $this->ensureSearchTable($table);

            return;
        }
        $this->withoutStopwords(fn () => $this->database->statement('TRUNCATE TABLE `' . $search . '`'));
    }

    /**
     * Runs DDL that builds a FULLTEXT index with the session's stopword list off: the
     * index keeps the setting it was built with, so every word is searchable.
     */
    private function withoutStopwords(\Closure $ddl): void
    {
        $previous = null;
        try {
            $previous = $this->database->selectOne('select @@SESSION.innodb_ft_enable_stopword as enabled')?->enabled;
            $this->database->statement('SET SESSION innodb_ft_enable_stopword = OFF');
        } catch (QueryException) {
            $previous = null;
        }
        try {
            $ddl();
        } finally {
            if ($previous !== null) {
                try {
                    $this->database->statement('SET SESSION innodb_ft_enable_stopword = ' . ((int) $previous === 0 ? 'OFF' : 'ON'));
                } catch (QueryException) {
                }
            }
        }
    }

    public function dropTables(string $table): void
    {
        $this->assertTable($table);
        $this->database->statement('DROP TABLE IF EXISTS `' . StorageViewNaming::searchTable($table) . '`');
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

        $length = StorageViewColumn::varcharLength($column->sqlType);

        return '`' . $column->column . '` ' . ($length !== null ? 'VARCHAR(' . $length . ')' : self::TYPES[$column->sqlType]) . ' NULL';
    }
}
