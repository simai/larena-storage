<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PDO;
use WeakMap;

/**
 * Compiles a normalized record list query (filters, search, sort, archived) onto
 * a storage's витрина, with the semantics of StorageRecordQueryMatcher:
 *
 * - eq/in, gt/gte/lt/lte/between compare the typed column; a NULL never matches;
 * - contains/starts_with compare the lowercased column (Unicode, as mb_strtolower)
 *   with a LIKE whose wildcards in the value are escaped;
 * - choices match through EXISTS on the side table;
 * - search looks in the search table's `search_text`, the words of the
 *   searchable fields: every term of the query at the start of a word
 *   (StorageViewSearch), checked row by row, or on MySQL found through the
 *   FULLTEXT index first (MATCH ... AGAINST in boolean mode, `+word*`);
 *   StorageViewQuery picks the form. The витрина answers a
 *   search only while its search text holds exactly the structure's searchable
 *   fields; while it is being refilled the query compiles to null;
 * - archived records are left out unless asked for. There is no scope condition:
 *   a витрина holds the records of one scope (its table is named from the scoped
 *   schema id), and the records read for a page are checked against the scope;
 * - sorting is up to three keys and then `record_id` in the direction of the
 *   last key, NULL first ascending; `_record_id`, `_revision` and `_created_at`
 *   (when the record was created; the admin table's (state, created_at, record_id)
 *   index serves it either way, so «newest first» reads it backwards) sort by the
 *   row's own columns, and on a site table `_published_at` by when the record was
 *   published in the slot (a site row has no `created_at`).
 *
 * On MySQL, string equality is exact (binary, as PHP's ===) while the index on
 * the column still narrows the rows; string sorting follows the column's
 * collation there. A query the витрина cannot answer exactly (a field without a
 * written column, a column still being backfilled, a type it does not map)
 * compiles to null: the workbench then builds the витрина, or refuses the list
 * while it is built (DatabaseStorageWorkbench::VIEW_NOT_READY).
 *
 * A site table (StorageViewTableShape::Site) is compiled with its slot: the rows of
 * that slot (`slot_id = ?`) take the place of the active rows, and the side and
 * search tables are joined on the slot as well as the record.
 */
final class StorageViewQueryCompiler
{
    public const SQLITE_LOWER = 'larena_lower';
    /** The sort key of a site list by publication time (the `published_at` column of a site row). */
    public const SORT_PUBLISHED_AT = '_published_at';
    /** The sort key of an admin list by creation time (the `created_at` column of an admin row). */
    public const SORT_CREATED_AT = '_created_at';
    private const ESCAPE = '!';
    private const STRING_KINDS = ['string', 'text'];
    private const SCALAR_KINDS = ['string', 'text', 'integer', 'number', 'date', 'datetime', 'boolean', 'choice', 'user', 'file', 'relation', 'record', 'lookup', 'color', 'phone', 'url'];
    private const STATE_ACTIVE = 'active';

    /** @var WeakMap<PDO, true> SQLite connections that have the lowercase function. */
    private WeakMap $lowered;
    /** @var WeakMap<Connection, array{0: int, 1: int}> The FULLTEXT token sizes of each MySQL connection. */
    private WeakMap $tokenSizes;

    /** @param array{0: int, 1: int}|null $fulltextTokens The server's minimum and maximum FULLTEXT token size; read from the server when null. */
    public function __construct(private readonly ?array $fulltextTokens = null)
    {
        $this->lowered = new WeakMap();
        $this->tokenSizes = new WeakMap();
    }

    /**
     * @param array<string, array<string, mixed>> $fields The structure's fields by key.
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $query
     */
    public function compile(Connection $connection, StorageViewRegistration $view, array $fields, array $query, ?int $slotId = null): ?StorageViewQuery
    {
        return $this->compileLayout($connection, $view->tableName, $view->layout, $fields, $query, $slotId);
    }

    /**
     * The same compilation for any витрина table and layout; with a slot, onto the
     * rows of that slot of a site table.
     *
     * @param array<string, array<string, mixed>> $fields The structure's fields by key.
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $query
     */
    public function compileLayout(Connection $connection, string $table, StorageViewLayout $layout, array $fields, array $query, ?int $slotId = null): ?StorageViewQuery
    {
        $columns = [];
        foreach ($layout->columns as $column) {
            if ($column->state === StorageViewColumn::STATE_READY) {
                $columns[$column->fieldKey] = $column;
            }
        }
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $alias = StorageViewQuery::ALIAS;
        $multiTable = StorageViewNaming::multiTable($table);

        $conditions = [];
        $needsLower = false;
        $searchCheck = null;
        $searchIndexed = null;
        foreach ($query['filters'] as $key => $filter) {
            $type = (string) ($fields[$key]['type'] ?? '');
            $column = $columns[$key] ?? null;
            if ($column === null || $column->kind !== $type) {
                return null;
            }
            if ($type === 'choices') {
                if (!$column->multiValued() || !in_array($filter['operator'], ['eq', 'in'], true)) {
                    return null;
                }
                $values = $filter['operator'] === 'eq' ? [$filter['value'] ?? null] : ($filter['values'] ?? []);
                if ($values === [] || array_filter($values, 'is_string') !== $values) {
                    return null;
                }
                $conditions[] = static function (Builder $where) use ($multiTable, $alias, $key, $values, $slotId): void {
                    $where->whereExists(static function (Builder $exists) use ($multiTable, $alias, $key, $values, $slotId): void {
                        $exists->selectRaw('1')->from($multiTable . ' as m')
                            ->whereColumn('m.record_id', '=', $alias . '.record_id');
                        if ($slotId !== null) {
                            $exists->where('m.slot_id', '=', $slotId);
                        }
                        $exists->where('m.field_key', '=', $key)
                            ->whereIn('m.value', $values);
                    });
                };
                continue;
            }
            if ($column->multiValued() || !in_array($type, self::SCALAR_KINDS, true)) {
                return null;
            }
            $name = $alias . '.' . $column->column;
            $operator = $filter['operator'];
            $string = in_array($type, self::STRING_KINDS, true);
            if (in_array($operator, ['contains', 'starts_with'], true)) {
                if (!$string || !is_string($filter['value'] ?? null)) {
                    return null;
                }
                $needsLower = true;
                $pattern = ($operator === 'contains' ? '%' : '') . self::escapeLike($filter['value']) . '%';
                $sql = $mysql
                    ? 'LOWER(' . $connection->getQueryGrammar()->wrap($name) . ') COLLATE utf8mb4_bin LIKE ? ESCAPE \'' . self::ESCAPE . '\''
                    : self::SQLITE_LOWER . '(' . $connection->getQueryGrammar()->wrap($name) . ') LIKE ? ESCAPE \'' . self::ESCAPE . '\'';
                $conditions[] = static fn (Builder $where): Builder => $where->whereRaw($sql, [$pattern]);
                if ($mysql && $operator === 'starts_with' && $column->indexed) {
                    // The column's collation folds case, so a plain prefix LIKE reads a range of its index;
                    // the lowercased binary LIKE above keeps the result exact.
                    $range = $connection->getQueryGrammar()->wrap($name) . ' LIKE ? ESCAPE \'' . self::ESCAPE . '\'';
                    $conditions[] = static fn (Builder $where): Builder => $where->whereRaw($range, [$pattern]);
                }
                continue;
            }
            $values = array_key_exists('values', $filter) ? $filter['values'] : [$filter['value'] ?? null];
            $converted = [];
            foreach ($values as $value) {
                $cell = self::cell($type, $value);
                if ($cell === null) {
                    return null;
                }
                $converted[] = $cell;
            }
            if (!in_array($operator, ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'between'], true)
                || ($operator === 'between' && count($converted) !== 2) || $converted === []) {
                return null;
            }
            $binary = $mysql && $string;
            $wrapped = $connection->getQueryGrammar()->wrap($name);
            $conditions[] = match ($operator) {
                'eq' => static function (Builder $where) use ($name, $converted, $binary, $wrapped): void {
                    $where->where($name, '=', $converted[0]);
                    if ($binary) {
                        $where->whereRaw('CAST(' . $wrapped . ' AS BINARY) = CAST(? AS BINARY)', [$converted[0]]);
                    }
                },
                'in' => static function (Builder $where) use ($name, $converted, $binary, $wrapped): void {
                    $where->whereIn($name, $converted);
                    if ($binary) {
                        $where->whereRaw('CAST(' . $wrapped . ' AS BINARY) IN (' . implode(', ', array_fill(0, count($converted), 'CAST(? AS BINARY)')) . ')', $converted);
                    }
                },
                'between' => static fn (Builder $where): Builder => $where->whereBetween($name, [$converted[0], $converted[1]]),
                default => static fn (Builder $where): Builder => $where->where($name, match ($operator) {
                    'gt' => '>', 'gte' => '>=', 'lt' => '<', default => '<=',
                }, $converted[0]),
            };
        }

        $search = $query['search'];
        if ($search !== null) {
            if (!$layout->searchesExactly(StorageViewFieldOptions::resolve(array_values($fields))->searchable)) {
                return null;
            }
            $terms = StorageViewSearch::terms($search);
            if ($terms === []) {
                // A query of punctuation alone finds nothing.
                $conditions[] = static fn (Builder $where): Builder => $where->whereRaw('1 = 0');
            } else {
                $searchTable = StorageViewNaming::searchTable($table);
                $column = $connection->getQueryGrammar()->wrap('s.search_text');
                $like = $column . ($mysql ? ' COLLATE utf8mb4_bin' : '') . ' LIKE ? ESCAPE \'' . self::ESCAPE . '\'';
                $patterns = array_map(StorageViewSearch::likePattern(...), $terms);
                $searchCheck = static function (Builder $where) use ($alias, $searchTable, $like, $patterns, $slotId): void {
                    $where->whereExists(static function (Builder $exists) use ($alias, $searchTable, $like, $patterns, $slotId): void {
                        $exists->selectRaw('1')->from($searchTable . ' as s')->whereColumn('s.record_id', '=', $alias . '.record_id');
                        if ($slotId !== null) {
                            // The slot itself, not a column of the row: a probe checks rows of a
                            // window that carries only their ids and sort keys.
                            $exists->where('s.slot_id', '=', $slotId);
                        }
                        foreach ($patterns as $pattern) {
                            $exists->whereRaw($like, [$pattern]);
                        }
                    });
                };
                if ($mysql) {
                    [$minToken, $maxToken] = $this->tokenSizes($connection);
                    $boolean = StorageViewSearch::booleanQuery($terms, $minToken, $maxToken);
                    if ($boolean !== null) {
                        $searchIndexed = static function (Builder $where) use ($alias, $searchTable, $column, $boolean, $like, $patterns, $slotId): void {
                            $where->whereIn($alias . '.record_id', static function (Builder $matched) use ($searchTable, $column, $boolean, $like, $patterns, $slotId): void {
                                $matched->select('s.record_id')->from($searchTable . ' as s')
                                    ->whereRaw('MATCH(' . $column . ') AGAINST (? IN BOOLEAN MODE)', [$boolean]);
                                if ($slotId !== null) {
                                    $matched->where('s.slot_id', '=', $slotId);
                                }
                                foreach ($patterns as $pattern) {
                                    $matched->whereRaw($like, [$pattern]);
                                }
                            });
                        };
                    }
                }
            }
        }

        $sort = [];
        // The sorted fields whose column has no index of its own: on a large list the database
        // sorts every matched row for them (a string without «indexed», an index over the budget).
        $unindexedSort = [];
        foreach ($query['sort'] as $item) {
            $direction = $item['direction'] === 'desc' ? 'desc' : 'asc';
            $field = $item['field'];
            if ($field === '_record_id' || $field === '_revision') {
                $sort[] = ['column' => $field === '_record_id' ? 'record_id' : 'revision', 'direction' => $direction];
                continue;
            }
            if ($field === self::SORT_CREATED_AT) {
                if ($slotId !== null) {
                    // A site row keeps when its record was published, not when it was created.
                    return null;
                }
                $sort[] = ['column' => 'created_at', 'direction' => $direction];
                continue;
            }
            if ($field === self::SORT_PUBLISHED_AT) {
                if ($slotId === null) {
                    // Only a site row knows when its record was published.
                    return null;
                }
                $sort[] = ['column' => 'published_at', 'direction' => $direction];
                continue;
            }
            $column = $columns[$field] ?? null;
            if ($column === null || $column->multiValued() || $column->kind !== (string) ($fields[$field]['type'] ?? '')
                || !in_array($column->kind, self::SCALAR_KINDS, true)) {
                return null;
            }
            $sort[] = ['column' => (string) $column->column, 'direction' => $direction];
            if (!$column->indexed) {
                $unindexedSort[] = $field;
            }
        }

        if ($needsLower && !$mysql) {
            $this->registerLower($connection);
        }
        $includeArchived = $query['include_archived'];
        $where = static function (Builder $where) use ($alias, $includeArchived, $conditions, $slotId): void {
            if ($slotId !== null) {
                // A site table holds the published rows of each slot; the slot is the partition.
                $where->where($alias . '.slot_id', '=', $slotId);
            } elseif (!$includeArchived) {
                $where->where($alias . '.state', '=', self::STATE_ACTIVE);
            }
            foreach ($conditions as $condition) {
                $condition($where);
            }
        };

        return new StorageViewQuery($connection, $table, $where, $sort, $searchCheck, $searchIndexed, $unindexedSort);
    }

    /** A LIKE pattern part that matches the value literally (escape character `!`). */
    public static function escapeLike(string $value): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'], $value);
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @return array{0: int, 1: int} */
    private function tokenSizes(Connection $connection): array
    {
        if ($this->fulltextTokens !== null) {
            return $this->fulltextTokens;
        }
        if (!isset($this->tokenSizes[$connection])) {
            $sizes = [StorageViewSearch::DEFAULT_MIN_TOKEN, StorageViewSearch::DEFAULT_MAX_TOKEN];
            try {
                $row = $connection->selectOne('select @@innodb_ft_min_token_size as min_size, @@innodb_ft_max_token_size as max_size');
                if (is_object($row) && is_numeric($row->min_size ?? null) && is_numeric($row->max_size ?? null)) {
                    $sizes = [(int) $row->min_size, (int) $row->max_size];
                }
            } catch (\Throwable) {
            }
            $this->tokenSizes[$connection] = $sizes;
        }

        return $this->tokenSizes[$connection];
    }

    /** A filter value as the витрина column holds it, or null when it cannot be one. */
    private static function cell(string $type, mixed $value): int|string|null
    {
        return match ($type) {
            'integer' => is_int($value) ? $value : null,
            'boolean' => is_bool($value) ? ($value ? 1 : 0) : null,
            // Property datetimes are YYYY-MM-DDTHH:MM:SS; the column holds a space.
            'datetime' => is_string($value) ? str_replace('T', ' ', $value) : null,
            default => is_string($value) ? $value : null,
        };
    }

    /** SQLite's lower() folds ASCII only; the list folds as mb_strtolower does. */
    private function registerLower(Connection $connection): void
    {
        foreach ([$connection->getPdo(), $connection->getReadPdo()] as $pdo) {
            if (isset($this->lowered[$pdo])) {
                continue;
            }
            $lower = static fn (mixed $value): ?string => $value === null ? null : self::lower((string) $value);
            if ($pdo instanceof \Pdo\Sqlite) {
                $pdo->createFunction(self::SQLITE_LOWER, $lower, 1, \Pdo\Sqlite::DETERMINISTIC);
            } else {
                $pdo->sqliteCreateFunction(self::SQLITE_LOWER, $lower, 1, PDO::SQLITE_DETERMINISTIC);
            }
            $this->lowered[$pdo] = true;
        }
    }
}
