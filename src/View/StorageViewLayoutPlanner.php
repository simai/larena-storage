<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * Plans a storage's витрина from its schema fields, keeping the columns of an
 * earlier layout where a field is unchanged, and turns two layouts into DDL
 * operations.
 *
 * Mapping: integer BIGINT; number DECIMAL(65,30); date DATE; datetime DATETIME;
 * boolean TINYINT; choice, record, file and relation VARCHAR(64) binary; user
 * VARCHAR(96) binary (a user reference is up to 90 characters); string
 * VARCHAR(255) when it is indexed on request and its max_length is at most 255,
 * otherwise long text; text long text, never indexed; choices go to the side
 * table. Fields of other types are kept as long text.
 */
final readonly class StorageViewLayoutPlanner
{
    /** The workbench's own fields; they have system columns (scope_ref, state). */
    public const SYSTEM_FIELDS = ['larena_scope_ref', 'larena_record_state'];
    public const AUTO_INDEX_KINDS = ['choice', 'record', 'user', 'date', 'datetime', 'number', 'integer', 'boolean'];
    public const MAX_AUTO_INDEXES = 40;
    // MySQL allows 64 indexes; the primary key and the (state, updated_at) and (state, record_id) indexes take three.
    public const MAX_FIELD_INDEXES = 60;
    public const ROW_BYTES_LIMIT = 65535;
    public const STRING_INDEX_MAX_LENGTH = 255;

    /** Bytes a column counts against MySQL's row limit (utf8mb4: 4 bytes a character, plus the length prefix). */
    private const COLUMN_BYTES = [
        StorageViewColumn::SQL_BIGINT => 8,
        StorageViewColumn::SQL_DECIMAL => 30,
        StorageViewColumn::SQL_DATE => 3,
        StorageViewColumn::SQL_DATETIME => 5,
        StorageViewColumn::SQL_TINYINT => 1,
        StorageViewColumn::SQL_REF => 258,
        StorageViewColumn::SQL_USER_REF => 386,
        StorageViewColumn::SQL_VARCHAR => 1022,
        StorageViewColumn::SQL_TEXT => 12,
        StorageViewColumn::SQL_SIDE => 0,
    ];
    // record_id, revision, schema_version, content_hash, created_at, updated_at, scope_ref, state, title, search_text.
    private const SYSTEM_BYTES = 258 + 8 + 4 + 64 + 5 + 5 + 766 + 66 + 1022 + 12;

    /**
     * @param list<array<string, mixed>> $fields Schema fields (key, type, constraints, ...).
     * @param list<string> $indexedStrings String fields indexed on request.
     */
    public function plan(array $fields, int $schemaVersion, ?StorageViewLayout $current = null, array $indexedStrings = []): StorageViewLayout
    {
        $next = $current->nextOrdinal ?? 1;
        $existing = $current->columns ?? [];
        $used = [];
        $columns = [];
        $new = [];
        $seen = [];
        foreach ($fields as $field) {
            $key = is_string($field['key'] ?? null) ? $field['key'] : '';
            $kind = is_string($field['type'] ?? null) ? $field['type'] : '';
            if (in_array($key, self::SYSTEM_FIELDS, true)) {
                continue;
            }
            if (isset($seen[$key])) {
                throw new StorageRejected('storage_view_layout_invalid');
            }
            $seen[$key] = true;
            $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
            $sqlType = self::sqlType($kind, $constraints, in_array($key, $indexedStrings, true));
            $reused = null;
            foreach ($existing as $position => $column) {
                if (!isset($used[$position]) && $column->fieldKey === $key && $column->kind === $kind
                    && $column->sqlType === $sqlType && $column->state !== StorageViewColumn::STATE_DROPPING) {
                    $reused = $position;
                    break;
                }
            }
            if ($reused !== null) {
                $used[$reused] = true;
                $columns[] = $existing[$reused];
                continue;
            }
            $column = new StorageViewColumn(
                $key,
                $sqlType === StorageViewColumn::SQL_SIDE ? null : StorageViewNaming::column($next++),
                $kind,
                $sqlType,
                false,
                StorageViewColumn::STATE_ADDING,
            );
            $new[] = ['column' => $column, 'opt_in' => in_array($key, $indexedStrings, true)];
        }
        foreach ($existing as $position => $column) {
            if (isset($used[$position])) {
                continue;
            }
            // A side field that was only reserved has nothing to drop.
            if ($column->column === null && $column->state === StorageViewColumn::STATE_ADDING) {
                continue;
            }
            $columns[] = $column->withState(StorageViewColumn::STATE_DROPPING);
        }

        $autoIndexes = 0;
        $fieldIndexes = 0;
        foreach ($columns as $column) {
            if ($column->indexed && $column->state !== StorageViewColumn::STATE_DROPPING) {
                $fieldIndexes++;
                $autoIndexes += in_array($column->kind, self::AUTO_INDEX_KINDS, true) ? 1 : 0;
            }
        }
        foreach ($new as $entry) {
            $column = $entry['column'];
            $index = false;
            if ($column->column !== null && $fieldIndexes < self::MAX_FIELD_INDEXES) {
                if (in_array($column->kind, self::AUTO_INDEX_KINDS, true) && $autoIndexes < self::MAX_AUTO_INDEXES) {
                    $index = true;
                    $autoIndexes++;
                } elseif ($entry['opt_in'] && $column->sqlType === StorageViewColumn::SQL_VARCHAR) {
                    $index = true;
                }
            }
            $fieldIndexes += $index ? 1 : 0;
            $columns[] = $index ? $column->withType($column->sqlType, true) : $column;
        }

        // Over MySQL's row limit, the newest indexed strings become unindexed long text.
        while (self::rowBytes($columns) > self::ROW_BYTES_LIMIT) {
            $demote = null;
            foreach ($columns as $position => $column) {
                if ($column->state === StorageViewColumn::STATE_ADDING && $column->sqlType === StorageViewColumn::SQL_VARCHAR
                    && ($demote === null || $column->ordinal() > $columns[$demote]->ordinal())) {
                    $demote = $position;
                }
            }
            if ($demote === null) {
                throw new StorageRejected('storage_view_row_budget_exceeded');
            }
            $columns[$demote] = $columns[$demote]->withType(StorageViewColumn::SQL_TEXT, false);
        }

        return new StorageViewLayout($schemaVersion, $next, $columns);
    }

    /**
     * The DDL that turns the table of one layout into the table of another.
     *
     * @return list<array{op: 'add_column'|'create_index'|'drop_index'|'drop_column', column: StorageViewColumn}>
     */
    public function diff(?StorageViewLayout $from, StorageViewLayout $to): array
    {
        $before = [];
        foreach ($from?->physical() ?? [] as $column) {
            if ($column->column !== null) {
                $before[$column->column] = $column;
            }
        }
        $ops = [];
        $kept = [];
        foreach ($to->physical() as $column) {
            $name = (string) $column->column;
            if ($column->state === StorageViewColumn::STATE_DROPPING) {
                continue;
            }
            $kept[$name] = true;
            $previous = $before[$name] ?? null;
            if ($previous === null || $previous->state === StorageViewColumn::STATE_DROPPING) {
                $ops[] = ['op' => 'add_column', 'column' => $column];
                if ($column->indexed) {
                    $ops[] = ['op' => 'create_index', 'column' => $column];
                }
            } elseif ($column->indexed && !$previous->indexed) {
                $ops[] = ['op' => 'create_index', 'column' => $column];
            }
        }
        $dropping = [];
        foreach ($to->physical() as $column) {
            if ($column->state === StorageViewColumn::STATE_DROPPING) {
                $dropping[(string) $column->column] = $column;
            }
        }
        foreach ($before as $name => $column) {
            if (!isset($kept[$name]) && !isset($dropping[$name]) && $column->state !== StorageViewColumn::STATE_DROPPING) {
                $dropping[$name] = $column;
            }
        }
        ksort($dropping, SORT_NATURAL);
        foreach ($dropping as $column) {
            if ($column->indexed) {
                $ops[] = ['op' => 'drop_index', 'column' => $column];
            }
            $ops[] = ['op' => 'drop_column', 'column' => $column];
        }

        return $ops;
    }

    /** @param array<array-key, mixed> $constraints */
    public static function sqlType(string $kind, array $constraints, bool $indexedString = false): string
    {
        return match ($kind) {
            'integer' => StorageViewColumn::SQL_BIGINT,
            'number' => StorageViewColumn::SQL_DECIMAL,
            'date' => StorageViewColumn::SQL_DATE,
            'datetime' => StorageViewColumn::SQL_DATETIME,
            'boolean' => StorageViewColumn::SQL_TINYINT,
            'choice', 'record', 'file', 'relation' => StorageViewColumn::SQL_REF,
            'user' => StorageViewColumn::SQL_USER_REF,
            'string' => $indexedString && is_int($constraints['max_length'] ?? null)
                && $constraints['max_length'] <= self::STRING_INDEX_MAX_LENGTH
                ? StorageViewColumn::SQL_VARCHAR
                : StorageViewColumn::SQL_TEXT,
            'choices' => StorageViewColumn::SQL_SIDE,
            default => StorageViewColumn::SQL_TEXT,
        };
    }

    /** @param list<StorageViewColumn> $columns Every column the table holds, dropping ones included. */
    public static function rowBytes(array $columns): int
    {
        $bytes = self::SYSTEM_BYTES;
        $nullable = 4;
        foreach ($columns as $column) {
            if ($column->column === null) {
                continue;
            }
            $bytes += self::COLUMN_BYTES[$column->sqlType];
            $nullable++;
        }

        return $bytes + intdiv($nullable + 7, 8);
    }
}
