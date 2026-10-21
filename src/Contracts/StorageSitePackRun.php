<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One SitePack run of a storage: an export now, a plan or an import later. It belongs to the person who started it.
 *
 * - `state`: queued, running, done, failed or cancelled; a failed run with a retryable reason is resumed by its queue
 *   job or by retry().
 * - `phase`: where the walk is (export: prepare, records, files, seal).
 * - `done` / `total`: records written so far / records of the structure when the run started.
 * - `counts`: what the run wrote so far (records, archived_records, versions, localized_values, publications,
 *   relations, files, files_missing, blobs, blob_bytes, references).
 */
final readonly class StorageSitePackRun
{
    public const string KIND_EXPORT = 'export';
    public const string KIND_IMPORT = 'import';

    public const string STATE_QUEUED = 'queued';
    public const string STATE_RUNNING = 'running';
    public const string STATE_DONE = 'done';
    public const string STATE_FAILED = 'failed';
    public const string STATE_CANCELLED = 'cancelled';

    public const string PHASE_PREPARE = 'prepare';
    public const string PHASE_RECORDS = 'records';
    public const string PHASE_FILES = 'files';
    public const string PHASE_SEAL = 'seal';
    public const string PHASE_DONE = 'done';

    /** @param array<string, int> $counts */
    public function __construct(
        public string $runRef,
        public string $kind,
        public string $scopeRef,
        public ?string $siteRef,
        public ?string $structureId,
        public string $actor,
        public string $state,
        public string $phase,
        public int $done,
        public int $total,
        public array $counts,
        public ?string $packageId,
        public ?string $archiveSha256,
        public ?int $archiveBytes,
        public ?string $jobId,
        public ?string $reason,
        public ?string $correlationId,
        public string $createdAt,
        public string $updatedAt,
        public ?string $finishedAt,
    ) {
    }

    public function finished(): bool
    {
        return in_array($this->state, [self::STATE_DONE, self::STATE_FAILED, self::STATE_CANCELLED], true);
    }

    public function isDone(): bool
    {
        return $this->state === self::STATE_DONE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_ref' => $this->runRef,
            'kind' => $this->kind,
            'scope_ref' => $this->scopeRef,
            'site_ref' => $this->siteRef,
            'structure_id' => $this->structureId,
            'state' => $this->state,
            'phase' => $this->phase,
            'done' => $this->done,
            'total' => $this->total,
            'counts' => $this->counts,
            'package_id' => $this->packageId,
            'archive_sha256' => $this->archiveSha256,
            'archive_bytes' => $this->archiveBytes,
            'job_id' => $this->jobId,
            'reason' => $this->reason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'finished_at' => $this->finishedAt,
        ];
    }
}
