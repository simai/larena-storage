<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;

/**
 * `storage.import.apply`: a planned CSV import written by the queue in chunks.
 *
 * The payload names the storage, the person, their scope, the job's private files ({@see StorageJobFiles}) and
 * the digest of the plan the person saw; never the file itself. The first run reads the file again into a plan
 * file and refuses when it no longer plans as the person saw it. Each chunk of {@see CHUNK} rows is then written
 * in one transaction together with the job's progress, and the job checks for cancellation and its time between
 * chunks. A retried, resumed or timed-out job continues after the last committed chunk, so no row is written
 * twice; a row Storage refuses is reported by its line and the others are kept.
 */
final readonly class StorageImportJobHandler implements QueueJobHandler
{
    public const JOB_TYPE = 'storage.import.apply';
    public const CHUNK = 200;

    public function __construct(
        private StorageCsvImport $import,
        private StorageWorkbenchGateway $gateway,
        private StorageSectionScope $scopes,
        private StorageJobLedger $ledger,
        private StorageJobFiles $files,
        private ConnectionInterface $database,
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
        if ($entry === null || $entry['kind'] !== 'import' || $entry['structure_id'] !== $structureId) {
            return QueueJobResult::failure('storage_import_job_unknown', false);
        }
        // The person still may import into this storage, in the scope they started in.
        $permissions = (new StorageImportRights($this->gateway, $this->scopes))->of($actor, $scope, $structureId);
        if (is_string($permissions)) {
            return $this->fail($actor, $ref, $permissions, $permissions === 'storage_import_unavailable');
        }
        $progress = $this->ledger->progress($actor, $ref);
        if (!in_array($progress['phase'] ?? 'queued', ['applying', 'done'], true)) {
            $planned = $this->import->planToFile($actor, $structureId, $this->files->path($ref, StorageJobFiles::SOURCE), $permissions,
                $this->files->path($ref, StorageJobFiles::PLAN));
            if ($planned['error_count'] > 0) {
                return $this->fail($actor, $ref, 'storage_import_plan_has_errors', false, ['errors' => $planned['errors'], 'failed' => $planned['error_count']]);
            }
            if (!hash_equals($planned['digest'], $job['digest'])) {
                return $this->fail($actor, $ref, 'storage_import_changed_meanwhile', false);
            }
            $progress = ['phase' => 'applying', 'total' => $planned['counts']['create'] + $planned['counts']['update'], 'done' => 0,
                'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'offset' => 0];
            $this->ledger->saveProgress($actor, $ref, $progress);
            $this->files->delete($ref, StorageJobFiles::SOURCE);
            $context->heartbeat();
        }
        while (($progress['phase'] ?? null) === 'applying') {
            $chunk = $this->files->lines($ref, StorageJobFiles::PLAN, self::int($progress['offset'] ?? 0), self::CHUNK);
            $rows = [];
            foreach ($chunk['lines'] as $line) {
                $row = self::row($line);
                if ($row === null) {
                    return $this->fail($actor, $ref, 'storage_import_plan_unreadable', false);
                }
                $rows[] = $row;
            }
            $progress = $this->database->transaction(function () use ($rows, $chunk, $progress, $scope, $structureId, $actor, $ref): array {
                $written = $this->import->applyRows($scope, $structureId, $rows, $actor);
                $errors = is_array($progress['errors'] ?? null) ? array_values($progress['errors']) : [];
                $next = [
                    'done' => self::int($progress['done'] ?? 0) + count($rows),
                    'created' => self::int($progress['created'] ?? 0) + $written['create'],
                    'updated' => self::int($progress['updated'] ?? 0) + $written['update'],
                    'failed' => self::int($progress['failed'] ?? 0) + count($written['errors']),
                    'errors' => array_slice([...$errors, ...$written['errors']], 0, StorageCsvImport::MAX_KEPT_ERRORS),
                    'offset' => $chunk['offset'],
                    'phase' => $rows === [] ? 'done' : 'applying',
                ] + $progress;
                // The chunk and the progress after it are committed together.
                $this->ledger->saveProgress($actor, $ref, $next);

                return $next;
            });
            if ($progress['phase'] === 'done') {
                $this->files->delete($ref, StorageJobFiles::PLAN);
                break;
            }
            $context->heartbeat();
        }

        return QueueJobResult::success([
            'created' => self::int($progress['created'] ?? 0),
            'updated' => self::int($progress['updated'] ?? 0),
            'failed' => self::int($progress['failed'] ?? 0),
            'total' => self::int($progress['total'] ?? 0),
        ]);
    }

    /**
     * A refusal the job ends with. Its reason is the queue's; the progress keeps its phase, so a retry after
     * the cause is fixed continues where the job stopped, and keeps what the person should see (the file's errors).
     *
     * @param array<string, mixed> $extra
     */
    private function fail(string $actor, string $ref, string $reason, bool $retryable, array $extra = []): QueueJobResult
    {
        if ($extra !== []) {
            $this->ledger->saveProgress($actor, $ref, $extra + $this->ledger->progress($actor, $ref));
        }

        return QueueJobResult::failure($reason, $retryable);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{structure_id: string, actor: string, scope_ref: string, file_ref: string, digest: string}|null
     */
    private static function payload(array $payload): ?array
    {
        $structureId = $payload['structure_id'] ?? null;
        $actor = $payload['actor'] ?? null;
        $scope = $payload['scope_ref'] ?? null;
        $ref = $payload['file_ref'] ?? null;
        $digest = $payload['digest'] ?? null;
        if (count($payload) !== 5 || !is_string($structureId) || preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', $structureId) !== 1
            || !is_string($actor) || trim($actor) === '' || strlen($actor) > 191
            || !is_string($scope) || preg_match('/\Ascope:[a-z][a-z0-9_.:-]{0,184}\z/D', $scope) !== 1
            || !is_string($ref) || !StorageJobFiles::isRef($ref) || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return null;
        }

        return ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref, 'digest' => $digest];
    }

    /** @return array{line: int, action: string, record_id: ?string, revision: ?int, values: array<string, mixed>}|null */
    private static function row(string $line): ?array
    {
        try {
            $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($row) || !is_int($row['line'] ?? null) || !in_array($row['action'] ?? null, ['create', 'update'], true)
            || !is_array($row['values'] ?? null) || ($row['values'] !== [] && array_is_list($row['values']))) {
            return null;
        }
        $recordId = $row['record_id'] ?? null;
        $revision = $row['revision'] ?? null;
        if ($row['action'] === 'update' && (!is_string($recordId) || !is_int($revision))) {
            return null;
        }
        $values = [];
        foreach ($row['values'] as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        return ['line' => $row['line'], 'action' => $row['action'], 'record_id' => is_string($recordId) ? $recordId : null,
            'revision' => is_int($revision) ? $revision : null, 'values' => $values];
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
