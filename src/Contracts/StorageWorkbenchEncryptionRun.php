<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * An encryption run of a structure's storage (larena-specs docs/architecture/2026-10-22-storage-field-encryption.md,
 * «Turning encryption on and off»): the stored form of fields rewritten in place, in every record version and every
 * translation, as the `storage.field.encryption.rewrite` queue job (or in the request for a small storage).
 *
 * - `encrypt`: the fields were switched to encrypted; new writes are sealed at once, the run seals what was stored
 *   before. Until it is done a reader shows a value that is still plaintext as an envelope.
 * - `decrypt`: the fields were switched off; new writes are plain at once, the run opens what was sealed. The
 *   витрина column of the field comes back when the run is done.
 * - `rotate`: the storage got a new data key; the run seals again every value the retiring key sealed, and the
 *   retiring key is deleted at the end.
 *
 * While a run is queued, running or failed the structure takes no other change (`storage_field_encryption_running`,
 * `storage_field_encryption_failed`); a failed run is retried (StorageWorkbenchEncryption::retryEncryptionRun).
 */
final readonly class StorageWorkbenchEncryptionRun
{
    public const MODE_ENCRYPT = 'encrypt';
    public const MODE_DECRYPT = 'decrypt';
    public const MODE_ROTATE = 'rotate';
    public const MODES = [self::MODE_ENCRYPT, self::MODE_DECRYPT, self::MODE_ROTATE];

    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';
    public const STATES = [self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_DONE, self::STATE_FAILED];

    /** @param list<string> $fields */
    public function __construct(
        public string $runRef,
        public string $mode,
        public string $state,
        public array $fields,
        // Rows rewritten and rows read and left as they were, so far; total is the rows (record versions and
        // translations) the storage had when the run started.
        public int $rewritten,
        public int $skipped,
        public int $total,
        // Why it failed (a reason code), or null.
        public ?string $reason,
        public string $startedAt,
        public ?string $finishedAt = null,
    ) {
    }

    /** Queued or running: the walk is not done. */
    public function running(): bool
    {
        return $this->state === self::STATE_QUEUED || $this->state === self::STATE_RUNNING;
    }

    /** Not done: queued, running or failed; the structure takes no other change. */
    public function open(): bool
    {
        return $this->state !== self::STATE_DONE;
    }

    /** @return array{run_ref: string, mode: string, state: string, fields: list<string>, rewritten: int, skipped: int, total: int, reason: ?string, started_at: string, finished_at: ?string} */
    public function toArray(): array
    {
        return [
            'run_ref' => $this->runRef,
            'mode' => $this->mode,
            'state' => $this->state,
            'fields' => $this->fields,
            'rewritten' => $this->rewritten,
            'skipped' => $this->skipped,
            'total' => $this->total,
            'reason' => $this->reason,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }
}
