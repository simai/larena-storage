<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\StorageSitePackPlan;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;
use Throwable;

/**
 * The run rows and the id map of SitePack runs (larena_storage_sitepack_runs, larena_storage_sitepack_ids).
 */
final readonly class StorageSitePackRuns
{
    public const string TABLE = 'larena_storage_sitepack_runs';
    public const string IDS_TABLE = 'larena_storage_sitepack_ids';

    public const string ID_KIND_STRUCTURE = 'structure';
    public const string ID_KIND_RECORD = 'record';
    public const string ID_KIND_FILE = 'file';
    /** An import: a record of another storage the pack points at, and its local target (null: unresolved). */
    public const string ID_KIND_REFERENCE = 'reference';
    /** An import: a user a user@1 value names, kept when this installation has it. */
    public const string ID_KIND_USER = 'user';
    /** An import into an adopted structure: a record of the target it replaces (the starter Home page), action `replaced`. */
    public const string ID_KIND_REPLACED = 'replaced';

    /** An export lists a file its live versions reference. */
    public const string ACTION_EXPORT = 'export';
    /** An export lists a file it could not find (deleted, or of another site). */
    public const string ACTION_MISSING = 'missing';
    /** An export lists a record of another storage its records point at. */
    public const string ACTION_REFERENCE = 'reference';

    public function __construct(private Connection $database)
    {
    }

    public function connection(): Connection
    {
        return $this->database;
    }

    public function available(): bool
    {
        try {
            $schema = $this->database->getSchemaBuilder();

            return $schema->hasTable(self::TABLE) && $schema->hasTable(self::IDS_TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): void
    {
        $this->database->table(self::TABLE)->insert($row);
    }

    public function row(string $runRef, bool $forUpdate = false): ?stdClass
    {
        $query = $this->database->table(self::TABLE)->where('run_ref', $runRef);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row instanceof stdClass ? $row : null;
    }

    public function find(string $runRef): ?StorageSitePackRun
    {
        $row = $this->row($runRef);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Updates the run when it is still held by $leaseRef (null: whoever holds it) and in one of $states.
     *
     * @param array<string, mixed> $changes
     * @param list<string>|null $states
     * @return int rows changed (0: the run moved on under the caller)
     */
    public function update(string $runRef, array $changes, ?string $leaseRef = null, ?array $states = null): int
    {
        $query = $this->database->table(self::TABLE)->where('run_ref', $runRef);
        if ($leaseRef !== null) {
            $query->where('lease_ref', $leaseRef);
        }
        if ($states !== null) {
            $query->whereIn('state', $states);
        }

        return $query->update($changes);
    }

    /**
     * Takes the run for one process: a run in one of $states that nobody holds, or whose holder has not saved since
     * $staleBefore (it died), gets $leaseRef and the running state.
     *
     * @param list<string> $states
     */
    public function take(string $runRef, string $leaseRef, array $states, string $now, string $staleBefore): bool
    {
        return $this->database->table(self::TABLE)
            ->where('run_ref', $runRef)
            ->whereIn('state', $states)
            ->where(static function ($query) use ($staleBefore): void {
                $query->whereNull('lease_ref')->orWhere('updated_at', '<', $staleBefore);
            })
            ->update(['lease_ref' => $leaseRef, 'state' => StorageSitePackRun::STATE_RUNNING, 'reason' => null, 'updated_at' => $now]) === 1;
    }

    public function delete(string $runRef): void
    {
        $this->clearIds($runRef);
        $this->database->table(self::TABLE)->where('run_ref', $runRef)->delete();
    }

    /** @return list<StorageSitePackRun> */
    public function recent(string $actor, int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->database->table(self::TABLE)
            ->where('actor', $actor)
            ->orderByDesc('created_at')
            ->orderByDesc('run_ref')
            ->limit($limit)
            ->get()
            ->all();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Runs created before $before, oldest first.
     *
     * @return list<StorageSitePackRun>
     */
    public function createdBefore(string $before, int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->database->table(self::TABLE)
            ->where('created_at', '<', $before)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Adds id map rows; a row already there stays as it is.
     *
     * @param list<array{run_ref: string, kind: string, source_ref: string, target_ref: ?string, action: string, sha256: ?string}> $rows
     */
    public function addIds(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            $this->database->table(self::IDS_TABLE)->insertOrIgnore($chunk);
        }
    }

    /**
     * Id map rows of one kind (and action) after $after, in source order.
     *
     * @return list<stdClass>
     */
    public function idsAfter(string $runRef, string $kind, ?string $action, ?string $after, int $limit): array
    {
        $query = $this->database->table(self::IDS_TABLE)
            ->where('run_ref', $runRef)
            ->where('kind', $kind);
        if ($action !== null) {
            $query->where('action', $action);
        }
        if ($after !== null) {
            $query->where('source_ref', '>', $after);
        }
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('source_ref')->limit($limit)->get()->all();

        return $rows;
    }

    public function countIds(string $runRef, string $kind, string $action): int
    {
        return $this->database->table(self::IDS_TABLE)
            ->where('run_ref', $runRef)
            ->where('kind', $kind)
            ->where('action', $action)
            ->count();
    }

    /** @param array<string, mixed> $changes */
    public function updateId(string $runRef, string $kind, string $sourceRef, array $changes): void
    {
        $this->database->table(self::IDS_TABLE)
            ->where('run_ref', $runRef)
            ->where('kind', $kind)
            ->where('source_ref', $sourceRef)
            ->update($changes);
    }

    /** @return list<string> the distinct content digests of the run's exported files, in order */
    public function blobDigests(string $runRef): array
    {
        /** @var list<string> $digests */
        $digests = array_values(array_map('strval', $this->database->table(self::IDS_TABLE)
            ->where('run_ref', $runRef)
            ->where('kind', self::ID_KIND_FILE)
            ->where('action', self::ACTION_EXPORT)
            ->whereNotNull('sha256')
            ->distinct()
            ->orderBy('sha256')
            ->pluck('sha256')
            ->all()));

        return $digests;
    }

    /**
     * Id map rows of one kind for these source refs: source ref => row.
     *
     * @param list<string> $sourceRefs
     * @return array<string, stdClass>
     */
    public function idsFor(string $runRef, string $kind, array $sourceRefs): array
    {
        $rows = [];
        foreach (array_chunk(array_values(array_unique($sourceRefs)), 500) as $chunk) {
            foreach ($this->database->table(self::IDS_TABLE)
                ->where('run_ref', $runRef)
                ->where('kind', $kind)
                ->whereIn('source_ref', $chunk)
                ->get() as $row) {
                $rows[(string) $row->source_ref] = $row;
            }
        }

        return $rows;
    }

    /** Removes the id map rows of a run but those of one kind. */
    public function clearIdsBut(string $runRef, string $kind): void
    {
        $this->database->table(self::IDS_TABLE)->where('run_ref', $runRef)->where('kind', '!=', $kind)->delete();
    }

    public function clearIds(string $runRef): void
    {
        $this->database->table(self::IDS_TABLE)->where('run_ref', $runRef)->delete();
    }

    private static function plan(mixed $json): ?StorageSitePackPlan
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_sitepack_run_corrupt');
        }

        return is_array($decoded) ? StorageSitePackPlan::fromArray($decoded) : null;
    }

    public static function hydrate(stdClass $row): StorageSitePackRun
    {
        $counts = [];
        if (is_string($row->counts_json ?? null) && $row->counts_json !== '') {
            try {
                $decoded = json_decode($row->counts_json, true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new StorageRejected('storage_sitepack_run_corrupt');
            }
            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                if (is_string($key) && is_int($value)) {
                    $counts[$key] = $value;
                }
            }
        }

        return new StorageSitePackRun(
            (string) $row->run_ref,
            (string) $row->kind,
            (string) $row->scope_ref,
            $row->site_ref === null ? null : (string) $row->site_ref,
            $row->structure_id === null ? null : (string) $row->structure_id,
            (string) $row->actor,
            (string) $row->state,
            (string) $row->phase,
            (int) $row->done,
            (int) $row->total,
            $counts,
            $row->package_id === null ? null : (string) $row->package_id,
            $row->archive_sha256 === null ? null : (string) $row->archive_sha256,
            $row->archive_bytes === null ? null : (int) $row->archive_bytes,
            $row->job_id === null ? null : (string) $row->job_id,
            $row->reason === null ? null : (string) $row->reason,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
            (string) $row->created_at,
            (string) $row->updated_at,
            $row->finished_at === null ? null : (string) $row->finished_at,
            ($row->plan_digest ?? null) === null ? null : (string) $row->plan_digest,
            self::plan($row->plan_json ?? null),
        );
    }
}
