<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueJobSnapshot;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Exceptions\QueueOperationFailed;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Throwable;

/**
 * The Storage section's import and export jobs on the durable queue (larena/queue): starting them, the person's
 * jobs with their state and progress, retry and cancel through the queue's own control, and the finished export.
 * A person reaches only the jobs in their own ledger ({@see StorageJobLedger}); the section's controller checks
 * their rights in the storage before calling any of this. Without the queue the section keeps writing imports at
 * once and offers no background jobs.
 */
final readonly class StorageBackgroundJobs
{
    /** Retryable refusals end with these reasons only when retrying cannot help. */
    private const FINAL_REASONS = [
        'storage_import_payload_invalid', 'storage_import_job_unknown', 'storage_import_plan_has_errors',
        'storage_import_changed_meanwhile', 'storage_import_plan_unreadable', 'storage_import_file_missing',
        'storage_export_payload_invalid', 'storage_export_job_unknown',
    ];

    public function __construct(
        private StorageJobLedger $ledger,
        private StorageJobFiles $files,
        private ?DurableQueueDispatcher $dispatcher = null,
        private ?QueueControlService $control = null,
        private ?JobTypeRegistry $registry = null,
    ) {
    }

    /** The three job types: chunks of a few hundred rows, heartbeats between them, a slice of time per run. */
    public static function register(JobTypeRegistry $registry, QueueJobHandler $import, QueueJobHandler $export, QueueJobHandler $plan): void
    {
        foreach ([
            StorageImportPlanJobHandler::JOB_TYPE => ['storage.workbench.record.import', 'larena.admin.storage-import-plan-handler', 'larena://admin/storage-import-plan', $plan],
            StorageImportJobHandler::JOB_TYPE => ['storage.workbench.record.import', 'larena.admin.storage-import-handler', 'larena://admin/storage-import-apply', $import],
            StorageExportJobHandler::JOB_TYPE => ['storage.workbench.record.export', 'larena.admin.storage-export-handler', 'larena://admin/storage-export-csv', $export],
        ] as $type => [$operation, $handlerRef, $schema, $handler]) {
            if ($registry->has($type)) {
                continue;
            }
            // A run lasts at most 15 minutes; a longer job times out between chunks and the queue runs it again
            // from its saved progress, up to 50 times (12½ hours).
            $registry->register(new ImmutableJobDescriptor(
                jobType: $type,
                operationRef: $operation,
                handlerRef: $handlerRef,
                timeoutSeconds: 900,
                maxAttempts: 50,
                retryDelaySeconds: 5,
                leaseSeconds: 120,
                priority: QueuePriority::Low,
                auditPolicy: 'storage_owned_record_audit',
                payloadSchemaRef: $schema,
            ), $handler);
        }
    }

    public function available(): bool
    {
        return $this->dispatcher !== null && $this->control !== null && $this->registry !== null
            && $this->registry->has(StorageImportJobHandler::JOB_TYPE) && $this->registry->has(StorageExportJobHandler::JOB_TYPE)
            && $this->registry->has(StorageImportPlanJobHandler::JOB_TYPE);
    }

    /**
     * Queues the check of the person's uploaded file (storage.import.plan): a file too large to plan in the request.
     * The job keeps the file; its plan is applied from the same job with {@see applyPlan()}. Null when it cannot be queued.
     */
    public function startPlan(string $actor, string $scope, string $structureId, string $token, int $rows): ?string
    {
        $ref = StorageJobFiles::newRef();
        if (!$this->available() || !$this->files->adoptUpload($actor, $token, $ref)) {
            return null;
        }

        return $this->start($actor, $ref, 'import', $structureId, ['stage' => 'check', 'phase' => 'queued', 'total' => $rows, 'done' => 0],
            StorageImportPlanJobHandler::JOB_TYPE, ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref]);
    }

    /**
     * Writes a plan the queue checked: the same job goes on as storage.import.apply, bound to the digest of the
     * plan the person saw, and the worker refuses it when the file no longer plans so. Only the person's own
     * finished check without errors, that adds or changes something, is applied, and only once.
     */
    public function applyPlan(string $actor, string $scope, string $structureId, string $ref, string $digest): bool
    {
        $entry = StorageJobFiles::isRef($ref) ? $this->ledger->entry($actor, $ref) : null;
        if ($entry === null || $entry['kind'] !== 'import' || $entry['structure_id'] !== $structureId || $this->dispatcher === null
            || self::state($this->snapshot($entry['job_id'])) !== 'completed' || !$this->files->exists($ref, StorageJobFiles::SOURCE)) {
            return false;
        }
        $progress = $this->ledger->progress($actor, $ref);
        $plan = self::plan($progress);
        if (($progress['stage'] ?? null) !== 'check' || ($progress['phase'] ?? null) !== 'planned' || $plan === null
            || $plan['error_count'] > 0 || $plan['counts']['create'] + $plan['counts']['update'] === 0
            || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1 || !hash_equals($plan['digest'], $digest)) {
            return false;
        }
        $rows = $plan['counts']['create'] + $plan['counts']['update'] + $plan['counts']['same'];
        try {
            $this->ledger->saveProgress($actor, $ref, ['stage' => 'apply', 'phase' => 'queued', 'total' => $rows, 'done' => 0]);
            $result = $this->dispatcher->dispatch(new DispatchRequest(
                jobType: StorageImportJobHandler::JOB_TYPE,
                payload: ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref, 'digest' => $digest],
                idempotencyKey: StorageImportJobHandler::JOB_TYPE.':'.$ref,
                correlationId: 'storage-import:'.$ref,
            ));
            $this->ledger->setJobId($actor, $ref, $result->jobId);

            return true;
        } catch (Throwable) {
            try {
                $this->ledger->saveProgress($actor, $ref, $progress);
            } catch (Throwable) {
            }

            return false;
        }
    }

    /**
     * One of the person's jobs in a storage as {@see jobs()} lists it, or null when it is not theirs.
     *
     * @return array{ref: string, kind: string, stage: string, state: string, created_at: string, done: int, total: int, total_exact: bool,
     *     created: int, updated: int, failed: int, errors: list<array{line: int, column: string, reason: string}>, reason: ?string,
     *     plan: ?array{columns: list<array{header: string, key: ?string, field: ?string, skipped: ?string}>, counts: array{create: int, update: int, same: int},
     *         errors: list<array{line: int, column: string, reason: string}>, error_count: int, digest: string},
     *     can_retry: bool, can_cancel: bool, can_download: bool, can_apply: bool}|null
     */
    public function job(string $actor, string $structureId, string $ref): ?array
    {
        if ($this->kind($actor, $structureId, $ref) === null) {
            return null;
        }
        foreach ($this->jobs($actor, $structureId) as $job) {
            if ($job['ref'] === $ref) {
                return $job;
            }
        }

        return null;
    }

    /** Queues the person's uploaded file for the import of the plan they saw; null when it cannot be queued. */
    public function startImport(string $actor, string $scope, string $structureId, string $token, string $digest, int $rows): ?string
    {
        $ref = StorageJobFiles::newRef();
        if (!$this->available() || !$this->files->adoptUpload($actor, $token, $ref)) {
            return null;
        }

        return $this->start($actor, $ref, 'import', $structureId, ['phase' => 'queued', 'total' => $rows, 'done' => 0],
            StorageImportJobHandler::JOB_TYPE, ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref, 'digest' => $digest]);
    }

    /**
     * Queues an export of the storage with the list's query (in Storage's shape); null when it cannot be queued.
     *
     * @param array{search: string, filters: array<string, array<string, mixed>>, sort: list<array{field: string, direction: string}>} $query
     */
    public function startExport(string $actor, string $scope, string $structureId, array $query, string $locale): ?string
    {
        $ref = StorageJobFiles::newRef();
        if (!$this->available() || $this->files->jobDirectory($ref) === null) {
            return null;
        }

        return $this->start($actor, $ref, 'export', $structureId, ['phase' => 'queued', 'done' => 0],
            StorageExportJobHandler::JOB_TYPE, ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref,
                'locale' => $locale, 'query' => $query]);
    }

    /**
     * The person's jobs of one storage, newest first, as the jobs panel shows them.
     *
     * An import is first a check (stage «check», storage.import.plan) when its file was too large to plan in the
     * request, then a write (stage «apply»); a checked plan is in «plan» and «can_apply» says it may be applied.
     *
     * @return list<array{ref: string, kind: string, stage: string, state: string, created_at: string, done: int, total: int, total_exact: bool,
     *     created: int, updated: int, failed: int, errors: list<array{line: int, column: string, reason: string}>, reason: ?string,
     *     plan: ?array{columns: list<array{header: string, key: ?string, field: ?string, skipped: ?string}>, counts: array{create: int, update: int, same: int},
     *         errors: list<array{line: int, column: string, reason: string}>, error_count: int, digest: string},
     *     can_retry: bool, can_cancel: bool, can_download: bool, can_apply: bool}>
     */
    public function jobs(string $actor, string $structureId): array
    {
        $jobs = [];
        foreach ($this->ledger->entries($actor) as $entry) {
            if ($entry['structure_id'] !== $structureId) {
                continue;
            }
            $snapshot = $this->snapshot($entry['job_id']);
            $progress = $this->ledger->progress($actor, $entry['ref']);
            $state = self::state($snapshot);
            $reason = $snapshot?->failureReason;
            $final = $snapshot !== null && $snapshot->isTerminal();
            $errors = [];
            foreach (is_array($progress['errors'] ?? null) ? $progress['errors'] : [] as $error) {
                if (is_array($error) && is_int($error['line'] ?? null) && is_string($error['column'] ?? null) && is_string($error['reason'] ?? null)) {
                    $errors[] = ['line' => $error['line'], 'column' => $error['column'], 'reason' => $error['reason']];
                }
            }
            $stage = $entry['kind'] === 'import' && ($progress['stage'] ?? null) === 'check' ? 'check' : 'apply';
            $plan = $stage === 'check' && ($progress['phase'] ?? null) === 'planned' && $state === 'completed' ? self::plan($progress) : null;
            $jobs[] = [
                'ref' => $entry['ref'],
                'kind' => $entry['kind'],
                'stage' => $stage,
                'state' => $state,
                'created_at' => $entry['created_at'],
                'done' => self::int($progress['done'] ?? 0),
                'total' => self::int($progress['total'] ?? 0),
                'total_exact' => ($progress['total_exact'] ?? true) !== false,
                'created' => self::int($progress['created'] ?? 0),
                'updated' => self::int($progress['updated'] ?? 0),
                'failed' => self::int($progress['failed'] ?? 0),
                'errors' => $errors,
                'reason' => $state === 'failed' || $state === 'cancelled' ? $reason : null,
                'plan' => $plan,
                'can_retry' => $final && in_array($state, ['failed', 'cancelled'], true) && !in_array($reason, self::FINAL_REASONS, true),
                'can_cancel' => $snapshot !== null && !$final && $snapshot->cancelRequestedAt === null,
                'can_download' => $entry['kind'] === 'export' && $state === 'completed' && $this->files->exists($entry['ref'], StorageJobFiles::EXPORT),
                'can_apply' => $plan !== null && $plan['error_count'] === 0 && $plan['counts']['create'] + $plan['counts']['update'] > 0
                    && $this->files->exists($entry['ref'], StorageJobFiles::SOURCE),
            ];
        }

        return $jobs;
    }

    /** The kind of one of the person's jobs in a storage, or null when it is not theirs. */
    public function kind(string $actor, string $structureId, string $ref): ?string
    {
        $entry = StorageJobFiles::isRef($ref) ? $this->ledger->entry($actor, $ref) : null;

        return $entry !== null && $entry['structure_id'] === $structureId ? $entry['kind'] : null;
    }

    /** Runs a failed, timed-out or cancelled job of the person again; it continues from its saved progress. */
    public function retry(string $actor, string $structureId, string $ref): bool
    {
        $job = $this->owned($actor, $structureId, $ref);
        if ($job === null || $this->control === null) {
            return false;
        }
        try {
            $this->control->retry($job);

            return true;
        } catch (QueueOperationFailed) {
            return false;
        }
    }

    /** Asks the queue to stop a job of the person: a queued job ends at once, a running one after its chunk. */
    public function cancel(string $actor, string $structureId, string $ref): bool
    {
        $job = $this->owned($actor, $structureId, $ref);
        if ($job === null || $this->control === null) {
            return false;
        }
        try {
            $snapshot = $this->control->cancel($job);

            return $snapshot->status === JobStatus::Cancelled || $snapshot->cancelRequestedAt !== null;
        } catch (QueueOperationFailed) {
            return false;
        }
    }

    /** The finished export file of one of the person's export jobs, or null. */
    public function download(string $actor, string $structureId, string $ref): ?string
    {
        $entry = StorageJobFiles::isRef($ref) ? $this->ledger->entry($actor, $ref) : null;
        if ($entry === null || $entry['kind'] !== 'export' || $entry['structure_id'] !== $structureId
            || self::state($this->snapshot($entry['job_id'])) !== 'completed' || !$this->files->exists($ref, StorageJobFiles::EXPORT)) {
            return null;
        }

        return $this->files->path($ref, StorageJobFiles::EXPORT);
    }

    /**
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $payload
     */
    private function start(string $actor, string $ref, string $kind, string $structureId, array $progress, string $type, array $payload): ?string
    {
        if ($this->dispatcher === null) {
            return null;
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        try {
            // The ledger first: the worker accepts only a job its person's ledger names.
            $dropped = $this->ledger->add($actor, ['ref' => $ref, 'kind' => $kind, 'structure_id' => $structureId, 'job_id' => null, 'created_at' => $now],
                fn (string $old): bool => $this->finished($actor, $old));
            foreach ($dropped as $old) {
                $this->files->remove($old);
            }
            $this->ledger->saveProgress($actor, $ref, $progress);
            $result = $this->dispatcher->dispatch(new DispatchRequest(
                jobType: $type,
                payload: $payload,
                idempotencyKey: $type.':'.$ref,
                correlationId: 'storage-'.$kind.':'.$ref,
            ));
            $this->ledger->setJobId($actor, $ref, $result->jobId);

            return $ref;
        } catch (Throwable) {
            try {
                $this->ledger->remove($actor, $ref);
            } catch (Throwable) {
            }
            $this->files->remove($ref);

            return null;
        }
    }

    private function finished(string $actor, string $ref): bool
    {
        $entry = $this->ledger->entry($actor, $ref);
        $snapshot = $entry === null ? null : $this->snapshot($entry['job_id']);

        return $snapshot === null || $snapshot->isTerminal();
    }

    private function owned(string $actor, string $structureId, string $ref): ?string
    {
        $entry = StorageJobFiles::isRef($ref) ? $this->ledger->entry($actor, $ref) : null;

        return $entry !== null && $entry['structure_id'] === $structureId ? $entry['job_id'] : null;
    }

    private function snapshot(?string $jobId): ?QueueJobSnapshot
    {
        if ($jobId === null || $this->control === null) {
            return null;
        }
        try {
            return $this->control->status($jobId);
        } catch (QueueOperationFailed) {
            return null;
        }
    }

    /**
     * queued, waiting (the job waits for something not ready yet — a storage's list still being built — and goes on by
     * itself), running, retrying (continues after a slice or a passing failure), completed, failed, cancelled or missing.
     */
    private static function state(?QueueJobSnapshot $snapshot): string
    {
        if ($snapshot === null) {
            return 'missing';
        }

        return match ($snapshot->status) {
            JobStatus::Created, JobStatus::Queued => 'queued',
            JobStatus::Deferred => 'waiting',
            JobStatus::Leased, JobStatus::Running => $snapshot->cancelRequestedAt !== null ? 'cancelling' : 'running',
            JobStatus::Retrying => 'retrying',
            JobStatus::Completed => 'completed',
            JobStatus::Failed, JobStatus::TimedOut => 'failed',
            JobStatus::Cancelled => 'cancelled',
        };
    }

    /**
     * The checked plan a job's progress keeps, or null.
     *
     * @param array<string, mixed> $progress
     * @return array{columns: list<array{header: string, key: ?string, field: ?string, skipped: ?string}>, counts: array{create: int, update: int, same: int},
     *     errors: list<array{line: int, column: string, reason: string}>, error_count: int, digest: string}|null
     */
    private static function plan(array $progress): ?array
    {
        $plan = $progress['plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['counts'] ?? null) || !is_int($plan['error_count'] ?? null) || !is_string($plan['digest'] ?? null)) {
            return null;
        }
        $counts = [];
        foreach (['create', 'update', 'same'] as $action) {
            $counts[$action] = self::int($plan['counts'][$action] ?? 0);
        }
        $columns = [];
        foreach (is_array($plan['columns'] ?? null) ? $plan['columns'] : [] as $column) {
            if (is_array($column) && is_string($column['header'] ?? null)) {
                $columns[] = ['header' => $column['header'], 'key' => is_string($column['key'] ?? null) ? $column['key'] : null,
                    'field' => is_string($column['field'] ?? null) ? $column['field'] : null,
                    'skipped' => is_string($column['skipped'] ?? null) ? $column['skipped'] : null];
            }
        }
        $errors = [];
        foreach (is_array($plan['errors'] ?? null) ? $plan['errors'] : [] as $error) {
            if (is_array($error) && is_int($error['line'] ?? null) && is_string($error['column'] ?? null) && is_string($error['reason'] ?? null)) {
                $errors[] = ['line' => $error['line'], 'column' => $error['column'], 'reason' => $error['reason']];
            }
        }

        return ['columns' => $columns, 'counts' => $counts, 'errors' => $errors, 'error_count' => max($plan['error_count'], count($errors)),
            'digest' => $plan['digest']];
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
