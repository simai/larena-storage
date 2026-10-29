<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;

/**
 * `storage.import.plan`: the check of a large CSV file, by the queue, before anything is written.
 *
 * The payload names the storage, the person, their scope and the job's private files ({@see StorageJobFiles});
 * never the file itself. The file is planned window by window ({@see StorageCsvImport::planStored()}); after each
 * window the state to continue from is saved in the person's job ledger and the job sends its heartbeat, where the
 * queue may stop it for its time or a cancel. A retried or resumed job continues after the last saved window.
 *
 * The finished plan is kept in the ledger as the person sees it — the counts, the column mapping, the first
 * {@see StorageCsvImport::MAX_KEPT_ERRORS} errors with the number of all, and the digest — and the file stays in
 * the job until the person applies it ({@see StorageBackgroundJobs::applyPlan()}: storage.import.apply, bound to
 * the digest) or the job drops out of the ledger.
 */
final readonly class StorageImportPlanJobHandler implements QueueJobHandler
{
    public const JOB_TYPE = 'storage.import.plan';

    public function __construct(
        private StorageCsvImport $import,
        private StorageWorkbenchGateway $gateway,
        private StorageSectionScope $scopes,
        private StorageJobLedger $ledger,
        private StorageJobFiles $files,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $job = self::payload($payload);
        if ($job === null) {
            return QueueJobResult::failure('storage_import_payload_invalid', false);
        }
        ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref] = $job;
        $entry = $this->ledger->entry($actor, $ref);
        $progress = $entry === null ? [] : $this->ledger->progress($actor, $ref);
        if ($entry === null || $entry['kind'] !== 'import' || $entry['structure_id'] !== $structureId || ($progress['stage'] ?? null) !== 'check') {
            return QueueJobResult::failure('storage_import_job_unknown', false);
        }
        $permissions = (new StorageImportRights($this->gateway, $this->scopes))->of($actor, $scope, $structureId);
        if (is_string($permissions)) {
            return QueueJobResult::failure($permissions, $permissions === 'storage_import_unavailable');
        }
        $planned = is_array($progress['plan'] ?? null) ? $progress['plan'] : null;
        if (($progress['phase'] ?? null) === 'planned' && $planned !== null) {
            return QueueJobResult::success(self::result($planned));
        }
        if (!$this->files->exists($ref, StorageJobFiles::SOURCE)) {
            return QueueJobResult::failure('storage_import_file_missing', false);
        }
        $total = is_int($progress['total'] ?? null) ? $progress['total'] : 0;
        $resume = is_array($progress['resume'] ?? null) ? $progress['resume'] : null;
        $plan = $this->import->planStored($actor, $structureId, $this->files->path($ref, StorageJobFiles::SOURCE), $permissions, $resume,
            function (array $state) use ($actor, $ref, $total, $context): void {
                // The window and the state after it are saved before the queue may stop the job.
                $this->ledger->saveProgress($actor, $ref, ['stage' => 'check', 'phase' => 'checking', 'total' => max($total, $state['rows']),
                    'done' => $state['rows'], 'resume' => $state]);
                $context->heartbeat();
            });
        $summary = ['columns' => $plan['columns'], 'counts' => $plan['counts'], 'errors' => $plan['errors'],
            'error_count' => $plan['error_count'], 'digest' => $plan['digest']];
        $this->ledger->saveProgress($actor, $ref, ['stage' => 'check', 'phase' => 'planned', 'total' => max($total, $plan['rows']),
            'done' => max($total, $plan['rows']), 'plan' => $summary]);

        return QueueJobResult::success(self::result($summary));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{create: int, update: int, same: int, errors: int}
     */
    private static function result(array $plan): array
    {
        $counts = is_array($plan['counts'] ?? null) ? $plan['counts'] : [];

        return [
            'create' => is_int($counts['create'] ?? null) ? $counts['create'] : 0,
            'update' => is_int($counts['update'] ?? null) ? $counts['update'] : 0,
            'same' => is_int($counts['same'] ?? null) ? $counts['same'] : 0,
            'errors' => is_int($plan['error_count'] ?? null) ? $plan['error_count'] : 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{structure_id: string, actor: string, scope_ref: string, file_ref: string}|null
     */
    private static function payload(array $payload): ?array
    {
        $structureId = $payload['structure_id'] ?? null;
        $actor = $payload['actor'] ?? null;
        $scope = $payload['scope_ref'] ?? null;
        $ref = $payload['file_ref'] ?? null;
        if (count($payload) !== 4 || !is_string($structureId) || preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', $structureId) !== 1
            || !is_string($actor) || trim($actor) === '' || strlen($actor) > 191
            || !is_string($scope) || preg_match('/\Ascope:[a-z][a-z0-9_.:-]{0,184}\z/D', $scope) !== 1 || !StorageJobFiles::isRef($ref)) {
            return null;
        }

        return ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => (string) $ref];
    }
}
