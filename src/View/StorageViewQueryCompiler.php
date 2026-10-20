<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PDO;
use WeakMap;

/**
 * Compiles a normalized record list query (filters, search, sort, archived) onto
 * a storage's витрина, with the semantics of the PHP list it replaces:
 *
 * - eq/in, gt/gte/lt/lte/between compare the typed column; a NULL never matches;
 * - contains/starts_with compare the lowercased column (Unicode, as mb_strtolower)
 *   with a LIKE whose wildcards in the value are escaped;
 * - choices match through EXISTS on the side table;
 * - search looks in `search_text`, the lowercased string and text values;
 * - archived records are left out unless asked for. There is no scope condition:
 *   a витрина holds the records of one scope (its table is named from the scoped
 *   schema id), and the records read for a page are checked against the scope;
 * - sorting is up to three keys and then `record_id` in the direction of the
 *   last key, NULL first ascending.
 *
 * On MySQL, string equality is exact (binary, as PHP's ===) while the index on
 * the column still narrows the rows; string sorting follows the column's
 * collation there. A query the витрина cannot answer exactly (a field without a
 * written column, a column still being backfilled, a type it does not map)
 * compiles to null, and the caller keeps the PHP path.
 */
final class StorageViewQueryCompiler
{
    public const SQLITE_LOWER = 'larena_lower';
    private const ESCAPE = '!';
    private const STRING_KINDS = ['string', 'text'];
    private const SCALAR_KINDS = ['string', 'text', 'integer', 'number', 'date', 'datetime', 'boolean', 'choice', 'user', 'file', 'relation', 'record'];
    private const STATE_ACTIVE = 'active';

    /** @var WeakMap<PDO, true> SQLite connections that have the lowercase function. */
    private WeakMap $lowered;

    public function __construct()
    {
        $this->lowered = new WeakMap();
    }

    /**
     * @param array<string, array<string, mixed>> $fields The structure's fields by key.
     * @param array{filters: array<string, array{operator: string, value?: mixed, values?: list<mixed>}>, search: ?string, sort: list<array{field: string, direction: string}>, include_archived: bool} $query
     */
    public function compile(Connection $connection, StorageViewRegistration $view, array $fields, array $query): ?StorageViewQuery
    {
        $columns = [];
        foreach ($view->layout->columns as $column) {
            if ($column->state === StorageViewColumn::STATE_READY) {
                $columns[$column->fieldKey] = $column;
            }
        }
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $alias = StorageViewQuery::ALIAS;
        $multiTable = StorageViewNaming::multiTable($view->tableName);

        $conditions = [];
        $needsLower = false;
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
                $conditions[] = static function (Builder $where) use ($multiTable, $alias, $key, $values): void {
                    $where->whereExists(static function (Builder $exists) use ($multiTable, $alias, $key, $values): void {
                        $exists->selectRaw('1')->from($multiTable . ' as m')
                            ->whereColumn('m.record_id', '=', $alias . '.record_id')
                            ->where('m.field_key', '=', $key)
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
            $pattern = '%' . self::escapeLike(self::lower($search)) . '%';
            $sql = $connection->getQueryGrammar()->wrap($alias . '.search_text') . ($mysql ? ' COLLATE utf8mb4_bin' : '') . ' LIKE ? ESCAPE \'' . self::ESCAPE . '\'';
            $conditions[] = static fn (Builder $where): Builder => $where->whereRaw($sql, [$pattern]);
        }

        $sort = [];
        foreach ($query['sort'] as $item) {
            $direction = $item['direction'] === 'desc' ? 'desc' : 'asc';
            $field = $item['field'];
            if ($field === '_record_id' || $field === '_revision') {
                $sort[] = ['column' => $field === '_record_id' ? 'record_id' : 'revision', 'direction' => $direction];
                continue;
            }
            $column = $columns[$field] ?? null;
            if ($column === null || $column->multiValued() || $column->kind !== (string) ($fields[$field]['type'] ?? '')
                || !in_array($column->kind, self::SCALAR_KINDS, true)) {
                return null;
            }
            $sort[] = ['column' => (string) $column->column, 'direction' => $direction];
        }

        if ($needsLower && !$mysql) {
            $this->registerLower($connection);
        }
        $includeArchived = $query['include_archived'];
        $where = static function (Builder $where) use ($alias, $includeArchived, $conditions): void {
            if (!$includeArchived) {
                $where->where($alias . '.state', '=', self::STATE_ACTIVE);
            }
            foreach ($conditions as $condition) {
                $condition($where);
            }
        };

        return new StorageViewQuery($connection, $view->tableName, $where, $sort);
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
