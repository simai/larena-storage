<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\Exceptions\StorageRejected;

/** One row of `larena_storage_field_encryption_runs` (StorageFieldEncryptionRuns). */
final readonly class StorageFieldEncryptionRunRow
{
    public const PHASE_VERSIONS = 'versions';
    public const PHASE_LOCALIZED = 'localized';
    public const PHASE_FINAL = 'final';
    public const PHASES = [self::PHASE_VERSIONS, self::PHASE_LOCALIZED, self::PHASE_FINAL];
    public const RUN_REF_PATTERN = '/\Astorage-encryption-run-[0-9a-f]{32}\z/D';

    /** @param list<string> $fields */
    public function __construct(
        public string $runRef,
        public string $schemaId,
        public string $scopeRef,
        public string $structureId,
        public string $mode,
        public array $fields,
        public ?string $sourceKeyId,
        public ?string $targetKeyId,
        public string $state,
        public string $phase,
        public ?string $cursor,
        public ?string $workerRef,
        public int $rewritten,
        public int $skipped,
        public int $total,
        public ?string $reason,
        public string $createdBy,
        public ?string $correlationId,
        public string $createdAt,
        public string $updatedAt,
        public ?string $finishedAt,
    ) {
        if (preg_match(self::RUN_REF_PATTERN, $runRef) !== 1
            || !in_array($mode, StorageWorkbenchEncryptionRun::MODES, true)
            || !in_array($state, StorageWorkbenchEncryptionRun::STATES, true)
            || !in_array($phase, self::PHASES, true)) {
            throw new StorageRejected('storage_field_encryption_run_corrupt');
        }
    }

    public static function newRef(): string
    {
        return 'storage-encryption-run-' . bin2hex(random_bytes(16));
    }

    public function running(): bool
    {
        return $this->state === StorageWorkbenchEncryptionRun::STATE_QUEUED || $this->state === StorageWorkbenchEncryptionRun::STATE_RUNNING;
    }

    public function open(): bool
    {
        return $this->state !== StorageWorkbenchEncryptionRun::STATE_DONE;
    }

    public function taken(string $workerRef, string $now): self
    {
        return $this->copy(['state' => StorageWorkbenchEncryptionRun::STATE_RUNNING, 'worker_ref' => $workerRef, 'updated_at' => $now]);
    }

    public function withProgress(string $phase, ?string $cursor, int $rewritten, int $skipped, string $now): self
    {
        return $this->copy(['phase' => $phase, 'cursor' => $cursor, 'rewritten' => $rewritten, 'skipped' => $skipped, 'updated_at' => $now]);
    }

    public function ended(string $state, ?string $reason, string $now): self
    {
        return $this->copy(['state' => $state, 'reason' => $reason, 'worker_ref' => null, 'updated_at' => $now, 'finished_at' => $now]);
    }

    /** A failed run queued again: it resumes where it stopped. */
    public function retried(string $now): self
    {
        return $this->copy(['state' => StorageWorkbenchEncryptionRun::STATE_QUEUED, 'reason' => null, 'worker_ref' => null, 'updated_at' => $now, 'finished_at' => null]);
    }

    public function contract(): StorageWorkbenchEncryptionRun
    {
        return new StorageWorkbenchEncryptionRun(
            $this->runRef,
            $this->mode,
            $this->state,
            $this->fields,
            $this->rewritten,
            $this->skipped,
            $this->total,
            $this->reason,
            $this->createdAt,
            $this->finishedAt,
        );
    }

    /** @param array{state?: string, phase?: string, cursor?: ?string, worker_ref?: ?string, rewritten?: int, skipped?: int, reason?: ?string, updated_at?: string, finished_at?: ?string} $changes */
    private function copy(array $changes): self
    {
        return new self(
            $this->runRef,
            $this->schemaId,
            $this->scopeRef,
            $this->structureId,
            $this->mode,
            $this->fields,
            $this->sourceKeyId,
            $this->targetKeyId,
            $changes['state'] ?? $this->state,
            $changes['phase'] ?? $this->phase,
            array_key_exists('cursor', $changes) ? $changes['cursor'] : $this->cursor,
            array_key_exists('worker_ref', $changes) ? $changes['worker_ref'] : $this->workerRef,
            $changes['rewritten'] ?? $this->rewritten,
            $changes['skipped'] ?? $this->skipped,
            $this->total,
            array_key_exists('reason', $changes) ? $changes['reason'] : $this->reason,
            $this->createdBy,
            $this->correlationId,
            $this->createdAt,
            $changes['updated_at'] ?? $this->updatedAt,
            array_key_exists('finished_at', $changes) ? $changes['finished_at'] : $this->finishedAt,
        );
    }
}
