<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * Plans a storage's витрина from its schema fields and the fields' витрина
 * options, keeping the columns of an earlier layout where a field is unchanged,
 * and turns two layouts into DDL operations.
 *
 * Mapping: integer BIGINT; number DECIMAL(65,30); date DATE; datetime DATETIME;
 * boolean TINYINT; choice, record, file and relation VARCHAR(64) binary; user
 * VARCHAR(96) binary (a user reference is up to 90 characters); string
 * VARCHAR(max_length) with an index when it is indexed on request (max_length at
 * most 255), otherwise long text (a string that was indexed keeps its VARCHAR
 * column, without the index); text long text, never indexed; choices go to the
 * side table. Fields of other types are kept as long text.
 *
 * Indexes: at most 40 field indexes a table, the automatic ones (choice, record,
 * user, date, datetime, number, integer, boolean, in field order) first, then the
 * strings indexed on request. A string that changes column (it becomes indexed)
 * keeps its old column ready until the new one is filled.
 */
final readonly class StorageViewLayoutPlanner
{
    /** The workbench's own fields; they have system columns (scope_ref, state). */
    public const SYSTEM_FIELDS = ['larena_scope_ref', 'larena_record_state'];
    public const AUTO_INDEX_KINDS = ['choice', 'record', 'user', 'date', 'datetime', 'number', 'integer', 'boolean'];
    public const MAX_AUTO_INDEXES = 40;
    // Every field index, automatic or on request. MySQL allows 64 indexes; the primary key,
    // the (state, updated_at) and (state, record_id) indexes and the FULLTEXT search index take four.
    public const MAX_FIELD_INDEXES = StorageViewFieldOptions::MAX_FIELD_INDEXES;
    public const ROW_BYTES_LIMIT = 65535;
    public const STRING_INDEX_MAX_LENGTH = StorageViewColumn::VARCHAR_MAX;

    /** Bytes a column counts against MySQL's row limit (utf8mb4: 4 bytes a character, plus the length prefix). */
    private const COLUMN_BYTES = [
        StorageViewColumn::SQL_BIGINT => 8,
        StorageViewColumn::SQL_DECIMAL => 30,
        StorageViewColumn::SQL_DATE => 3,
        StorageViewColumn::SQL_DATETIME => 5,
        StorageViewColumn::SQL_TINYINT => 1,
        StorageViewColumn::SQL_REF => 258,
        StorageViewColumn::SQL_USER_REF => 386,
        StorageViewColumn::SQL_TEXT => 12,
        StorageViewColumn::SQL_SIDE => 0,
    ];
    // record_id, revision, schema_version, content_hash, created_at, updated_at, scope_ref, state, title, search_text.
    private const SYSTEM_BYTES = 258 + 8 + 4 + 64 + 5 + 5 + 766 + 66 + 1022 + 12;
    private const STATE_PREFERENCE = [
        StorageViewColumn::STATE_READY => 0,
        StorageViewColumn::STATE_BACKFILLING => 1,
        StorageViewColumn::STATE_ADDING => 2,
    ];

    /**
     * @param list<array<string, mixed>> $fields Schema fields (key, type, constraints, ...).
     * @param StorageViewFieldOptions|null $options The fields' витрина options; null keeps the
     *        current layout's (or, without one, the defaults).
     */
    public function plan(array $fields, int $schemaVersion, ?StorageViewLayout $current = null, ?StorageViewFieldOptions $options = null): StorageViewLayout
    {
        $options ??= self::carried($fields, $current);
        $next = $current->nextOrdinal ?? 1;
        $existing = $current->columns ?? [];
        $used = [];
        $decisions = [];
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
            $length = $kind === 'string' ? StorageViewFieldOptions::indexableLength($field) : null;
            $wantsIndex = $length !== null && in_array($key, $options->indexed, true);
            if ($kind === 'string') {
                $sqlType = $wantsIndex ? StorageViewColumn::varchar((int) $length) : StorageViewColumn::SQL_TEXT;
                // An unindexed string keeps a VARCHAR column it already has.
                $acceptable = $wantsIndex ? [$sqlType] : array_merge([$sqlType], $length === null ? [] : [StorageViewColumn::varchar($length)]);
            } else {
                $sqlType = self::sqlType($kind, $constraints);
                $acceptable = [$sqlType];
            }
            $reused = null;
            foreach ($existing as $position => $column) {
                if (isset($used[$position]) || $column->fieldKey !== $key || $column->kind !== $kind
                    || $column->state === StorageViewColumn::STATE_DROPPING || !in_array($column->sqlType, $acceptable, true)) {
                    continue;
                }
                if ($reused === null || self::STATE_PREFERENCE[$column->state] < self::STATE_PREFERENCE[$existing[$reused]->state]) {
                    $reused = $position;
                }
            }
            if ($reused !== null) {
                $used[$reused] = true;
            }
            $decisions[$key] = ['kind' => $kind, 'sql_type' => $sqlType, 'reused' => $reused, 'wants_index' => $wantsIndex, 'index' => false];
        }

        // Index budget: automatic indexes already in place, new automatic ones, then strings on request.
        $total = 0;
        $automatic = 0;
        foreach ($decisions as $key => $decision) {
            if ($decision['reused'] !== null && in_array($decision['kind'], self::AUTO_INDEX_KINDS, true) && $existing[$decision['reused']]->indexed) {
                $decisions[$key]['index'] = true;
                $total++;
                $automatic++;
            }
        }
        foreach ($decisions as $key => $decision) {
            if ($decision['reused'] === null && in_array($decision['kind'], self::AUTO_INDEX_KINDS, true)
                && $decision['sql_type'] !== StorageViewColumn::SQL_SIDE
                && $automatic < self::MAX_AUTO_INDEXES && $total < self::MAX_FIELD_INDEXES) {
                $decisions[$key]['index'] = true;
                $total++;
                $automatic++;
            }
        }
        foreach ($decisions as $key => $decision) {
            if (!$decision['wants_index']) {
                continue;
            }
            if ($total < self::MAX_FIELD_INDEXES) {
                $decisions[$key]['index'] = true;
                $total++;
            } elseif ($decision['reused'] === null) {
                // No index left: the string stays long text.
                $decisions[$key]['sql_type'] = StorageViewColumn::SQL_TEXT;
            }
        }

        $columns = [];
        $incoming = [];
        foreach ($decisions as $key => $decision) {
            if ($decision['reused'] !== null) {
                $column = $existing[$decision['reused']];
                if ($decision['kind'] === 'string') {
                    $column = $column->withType($column->sqlType, $decision['index'] && $column->isVarchar());
                }
                $columns[] = $column;
                if ($column->state !== StorageViewColumn::STATE_READY) {
                    $incoming[$key] = $decision['kind'];
                }
                continue;
            }
            $columns[] = new StorageViewColumn(
                $key,
                $decision['sql_type'] === StorageViewColumn::SQL_SIDE ? null : StorageViewNaming::column($next++),
                $decision['kind'],
                $decision['sql_type'],
                $decision['index'],
                StorageViewColumn::STATE_ADDING,
            );
            $incoming[$key] = $decision['kind'];
        }
        foreach ($existing as $position => $column) {
            if (isset($used[$position])) {
                continue;
            }
            // A side field that was only reserved has nothing to drop.
            if ($column->column === null && $column->state === StorageViewColumn::STATE_ADDING) {
                continue;
            }
            // The ready column of a field whose new column is not filled yet keeps answering.
            if ($column->state === StorageViewColumn::STATE_READY && ($incoming[$column->fieldKey] ?? null) === $column->kind) {
                $columns[] = $column;
                continue;
            }
            $columns[] = $column->withState(StorageViewColumn::STATE_DROPPING);
        }

        // Over MySQL's row limit, the newest indexed strings become unindexed long text.
        while (self::rowBytes($columns) > self::ROW_BYTES_LIMIT) {
            $demote = null;
            foreach ($columns as $position => $column) {
                if ($column->state === StorageViewColumn::STATE_ADDING && $column->isVarchar()
                    && ($demote === null || $column->ordinal() > $columns[$demote]->ordinal())) {
                    $demote = $position;
                }
            }
            if ($demote === null) {
                throw new StorageRejected('storage_view_row_budget_exceeded');
            }
            $columns[$demote] = $columns[$demote]->withType(StorageViewColumn::SQL_TEXT, false);
        }

        $searchFields = [];
        foreach ($options->searchable as $key) {
            if (in_array($decisions[$key]['kind'] ?? null, StorageViewFieldOptions::SEARCHABLE_KINDS, true)) {
                $searchFields[] = $key;
            }
        }
        sort($searchFields, SORT_STRING);
        $searchState = $current !== null && $current->searchFields === $searchFields
            ? $current->searchState
            : StorageViewLayout::SEARCH_BACKFILLING;

        return new StorageViewLayout($schemaVersion, $next, $columns, $searchFields, $searchState);
    }

    /**
     * The options a layout was planned with, for a change that does not say: the
     * search fields and indexed strings of the current layout, or the defaults.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function carried(array $fields, ?StorageViewLayout $current): StorageViewFieldOptions
    {
        if ($current === null || $current->searchFields === null) {
            return StorageViewFieldOptions::resolve($fields);
        }
        $indexed = [];
        foreach ($current->columns as $column) {
            if ($column->kind === 'string' && $column->indexed && $column->isVarchar() && $column->state !== StorageViewColumn::STATE_DROPPING) {
                $indexed[] = $column->fieldKey;
            }
        }

        return new StorageViewFieldOptions($current->searchFields, $indexed);
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
            } elseif (!$column->indexed && $previous->indexed) {
                $ops[] = ['op' => 'drop_index', 'column' => $column];
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
        $length = $constraints['max_length'] ?? null;

        return match ($kind) {
            'integer' => StorageViewColumn::SQL_BIGINT,
            'number' => StorageViewColumn::SQL_DECIMAL,
            'date' => StorageViewColumn::SQL_DATE,
            'datetime' => StorageViewColumn::SQL_DATETIME,
            'boolean' => StorageViewColumn::SQL_TINYINT,
            'choice', 'record', 'file', 'relation' => StorageViewColumn::SQL_REF,
            'user' => StorageViewColumn::SQL_USER_REF,
            'string' => $indexedString && is_int($length) && $length >= 1 && $length <= self::STRING_INDEX_MAX_LENGTH
                ? StorageViewColumn::varchar($length)
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
            $length = StorageViewColumn::varcharLength($column->sqlType);
            $bytes += $length !== null ? 4 * $length + 2 : (self::COLUMN_BYTES[$column->sqlType] ?? 12);
            $nullable++;
        }

        return $bytes + intdiv($nullable + 7, 8);
    }
}
