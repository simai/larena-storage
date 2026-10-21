<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use PDO;
use stdClass;
use Throwable;

/**
 * Counts the rows a витрина query matches (owner decision 2026-10-20: exact under
 * a threshold, «~N» above it, exact on request; bulk actions always exact).
 *
 * - Exact: COUNT(*) over the matched rows, however long it takes.
 * - Auto on MySQL/MariaDB: COUNT(*) over at most threshold + 1 rows, under a
 *   time budget (MAX_EXECUTION_TIME on MySQL, max_statement_time on MariaDB).
 *   Up to the threshold the count is exact; at the cap, or when the budget runs
 *   out, it is the optimizer's estimate of the matched rows (and at least the
 *   cap when the cap was reached).
 * - Auto on SQLite (tests and local development): exact, unless the counter is
 *   told to estimate there too.
 */
final class StorageViewCounter
{
    public const THRESHOLD = 10_000;
    public const BUDGET_MS = 250;
    /** From this many matched records a list is large: sorting it without an index is slow. */
    public const LARGE_LIST = 50_000;

    private ?bool $mariaDb = null;

    public function __construct(
        private readonly int $threshold = self::THRESHOLD,
        private readonly int $budgetMs = self::BUDGET_MS,
        private readonly bool $exactOnSqlite = true,
        private readonly int $largeList = self::LARGE_LIST,
    ) {
    }

    /** Whether a list of this many matched records is large (LARGE_LIST unless told otherwise). */
    public function large(int $count): bool
    {
        return $count >= $this->largeList;
    }

    /** @return array{count: int, exact: bool} */
    public function count(StorageViewQuery $query, StorageWorkbenchCountMode $mode): array
    {
        $connection = $query->connection;
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        // A FULLTEXT search counted its matches with its page.
        if ($query->knownTotal() !== null) {
            return ['count' => $query->knownTotal(), 'exact' => true];
        }
        if ($mode === StorageWorkbenchCountMode::Exact || (!$mysql && $this->exactOnSqlite)) {
            return ['count' => $query->filtered()->count(), 'exact' => true];
        }
        $capped = $query->filtered()->selectRaw('1')->limit($this->threshold + 1);
        $sql = 'select count(*) as aggregate from (' . $capped->toSql() . ') as capped';
        if ($mysql) {
            $sql = $this->mariaDb($connection)
                ? 'SET STATEMENT max_statement_time=' . number_format($this->budgetMs / 1000, 3, '.', '') . ' FOR ' . $sql
                : 'select /*+ MAX_EXECUTION_TIME(' . $this->budgetMs . ') */ count(*) as aggregate from (' . $capped->toSql() . ') as capped';
        }
        try {
            $row = $connection->selectOne($sql, $capped->getBindings());
            $counted = $row instanceof stdClass ? (int) $row->aggregate : 0;
        } catch (QueryException $exception) {
            if (!$mysql || !self::timedOut($exception)) {
                throw $exception;
            }
            $counted = null;
        }
        if ($counted !== null && $counted <= $this->threshold) {
            return ['count' => $counted, 'exact' => true];
        }
        $estimate = $mysql ? $this->estimate($query) : 0;

        return ['count' => max($estimate, $counted ?? 0), 'exact' => false];
    }

    /**
     * The optimizer's estimate of the matched rows: EXPLAIN's rows × filtered of the
     * витрина table. The optimizer cannot estimate a search, so a searched list is
     * estimated as the list without it, scaled by the share of the first rows the
     * search matches.
     */
    private function estimate(StorageViewQuery $query): int
    {
        if ($query->hasSearch()) {
            try {
                $share = $query->searchShare();
            } catch (Throwable) {
                return 0;
            }

            return (int) round($this->explained($query->unsearched()->selectRaw('1'), $query) * $share);
        }

        return $this->explained($query->filtered()->selectRaw('1'), $query);
    }

    private function explained(Builder $select, StorageViewQuery $query): int
    {
        try {
            $rows = $query->connection->select('explain ' . $select->toSql(), $select->getBindings());
        } catch (Throwable) {
            return 0;
        }
        foreach ($rows as $row) {
            $values = array_change_key_case((array) $row, CASE_LOWER);
            if (($values['table'] ?? null) !== StorageViewQuery::ALIAS) {
                continue;
            }
            $estimated = is_numeric($values['rows'] ?? null) ? (float) $values['rows'] : 0.0;
            $filtered = is_numeric($values['filtered'] ?? null) ? (float) $values['filtered'] : 100.0;

            return (int) round($estimated * $filtered / 100);
        }

        return 0;
    }

    private function mariaDb(Connection $connection): bool
    {
        if ($this->mariaDb === null) {
            $version = '';
            try {
                $attribute = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
                $version = is_string($attribute) ? $attribute : '';
            } catch (Throwable) {
            }
            $this->mariaDb = $connection->getDriverName() === 'mariadb' || stripos($version, 'mariadb') !== false;
        }

        return $this->mariaDb;
    }

    // MySQL 3024 (maximum statement execution time exceeded), MariaDB 1969 (query execution was interrupted).
    private static function timedOut(QueryException $exception): bool
    {
        $code = $exception->errorInfo[1] ?? null;
        $message = strtolower($exception->getMessage());

        return in_array($code, [3024, 1969], true)
            || str_contains($message, 'maximum statement execution time exceeded')
            || str_contains($message, 'max_statement_time exceeded');
    }
}
