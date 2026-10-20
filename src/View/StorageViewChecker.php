<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Storage\Exceptions\StorageRejected;
use stdClass;

/**
 * Checks that a storage's витрина agrees with its current versions: it is
 * registered and ready for the current schema version, its table has the
 * layout's shape, and it holds one row per current record at that record's
 * current revision and hash. Counting runs in SQL, by indexed joins on the
 * record id.
 *
 * Problem codes: `schema_unknown`, `view_missing`, `view_pending`,
 * `view_building`, `view_failed`, `schema_version_behind`, `backfill_pending`
 * (rows still lack a column or their search text after a change of field
 * options, or the layout predates the declared options), `table_missing`,
 * `shape_mismatch`, `rows_missing`, `rows_extra`, `rows_stale`.
 */
final readonly class StorageViewChecker
{
    public function __construct(private StorageViewSync $views)
    {
    }

    /**
     * @return array{schema_id: string, state: ?string, problems: list<string>, records: int, rows: int, missing: int, extra: int, stale: int}
     */
    public function check(string $schemaId): array
    {
        $database = $this->views->connection();
        $ddl = $this->views->ddl();
        if (!$this->views->enabled() || $ddl === null) {
            throw new StorageRejected('storage_view_unavailable');
        }
        $report = [
            'schema_id' => $schemaId,
            'state' => null,
            'problems' => [],
            'records' => 0,
            'rows' => 0,
            'missing' => 0,
            'extra' => 0,
            'stale' => 0,
        ];
        $head = $database->table('larena_storage_schemas')->where('schema_id', $schemaId)->first();
        $registration = $this->views->registry()->find($schemaId, true);
        $report['state'] = $registration?->state;
        if (!$head instanceof stdClass) {
            $report['problems'][] = 'schema_unknown';

            return $report;
        }
        $report['records'] = $database->table('larena_storage_records')->where('schema_id', $schemaId)->count();
        if ($registration === null) {
            $report['problems'][] = 'view_missing';

            return $report;
        }
        if ($registration->state !== StorageViewRegistration::STATE_READY) {
            $report['problems'][] = 'view_' . $registration->state;
        } elseif ($registration->builtSchemaVersion !== (int) $head->current_version) {
            $report['problems'][] = 'schema_version_behind';
        } elseif ($registration->layout->needsBackfill() || !self::optionsApplied($database, $schemaId, $registration)) {
            $report['problems'][] = 'backfill_pending';
        }
        $table = $registration->tableName;
        if (!$ddl->tableExists($table)) {
            $report['problems'][] = 'table_missing';

            return $report;
        }
        if (!(new StorageViewShapeVerifier($ddl))->matches($table, $registration->layout)) {
            $report['problems'][] = 'shape_mismatch';
        }
        $report['rows'] = $database->table($table)->count();
        $report['missing'] = $database->table('larena_storage_records as heads')
            ->leftJoin($table . ' as vt', 'vt.record_id', '=', 'heads.record_id')
            ->where('heads.schema_id', $schemaId)
            ->whereNull('vt.record_id')
            ->count();
        $report['extra'] = $database->table($table . ' as vt')
            ->leftJoin('larena_storage_records as heads', static function ($join) use ($schemaId): void {
                $join->on('heads.record_id', '=', 'vt.record_id')->where('heads.schema_id', '=', $schemaId);
            })
            ->whereNull('heads.record_id')
            ->count();
        $report['stale'] = $database->table('larena_storage_records as heads')
            ->join($table . ' as vt', 'vt.record_id', '=', 'heads.record_id')
            ->where('heads.schema_id', $schemaId)
            ->where(static function ($query): void {
                $query->whereColumn('vt.revision', '<>', 'heads.current_revision')
                    ->orWhereColumn('vt.content_hash', '<>', 'heads.current_hash');
            })
            ->count();
        foreach (['missing' => 'rows_missing', 'extra' => 'rows_extra', 'stale' => 'rows_stale'] as $count => $problem) {
            if ($report[$count] > 0) {
                $report['problems'][] = $problem;
            }
        }

        return $report;
    }

    /** The layout searches and indexes what the storage's workbench structure declares. */
    private static function optionsApplied(\Illuminate\Database\ConnectionInterface $database, string $schemaId, StorageViewRegistration $registration): bool
    {
        $options = StorageViewFieldOptions::load($database, $schemaId);
        if ($options === null) {
            return true;
        }
        $carried = StorageViewLayoutPlanner::carried([], $registration->layout);

        return $registration->layout->searchFields !== null && $carried->searchable === $options->searchable && $carried->indexed === $options->indexed;
    }

    /**
     * The storages to check: every workbench structure, and every registered
     * витрина (one whose storage is gone shows as `schema_unknown`).
     *
     * @return list<string>
     */
    public function targets(): array
    {
        $ids = [];
        foreach ($this->views->connection()->table('larena_storage_workbench_structures')->pluck('storage_schema_id') as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }
        foreach ($this->views->registry()->all() as $registration) {
            $ids[$registration->schemaId] = true;
        }
        $list = array_map('strval', array_keys($ids));
        sort($list, SORT_STRING);

        return $list;
    }
}
