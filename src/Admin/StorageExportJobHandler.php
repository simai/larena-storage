<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use RuntimeException;

/**
 * `storage.export.csv`: a storage's records written by the queue to a private CSV file, in the format of the
 * list's «Export CSV» ({@see StorageCsvExportRows}), for the person who asked to download.
 *
 * The payload names the storage, the person, their scope, the job's private files, the person's locale and the
 * list's query in Storage's shape. Records are read page by page through the section's own list (its rights, scope
 * and filters) following the list's continuation, so a large storage is read by keyset rather than by offset.
 * After each page the file's length and the continuation are saved; a resumed job cuts the file back to the saved
 * length and goes on from the saved continuation, so no record is written twice.
 */
final readonly class StorageExportJobHandler implements QueueJobHandler
{
    public const JOB_TYPE = 'storage.export.csv';
    public const PAGE = 100;
    /**
     * The storage's list is still being built in the background (a large storage): the job waits — the queue runs it
     * again every {@see WAIT_SECONDS} without spending an attempt, up to the queue's deferral limit (about a day).
     */
    public const VIEW_NOT_READY = 'storage_export_view_not_ready';
    public const WAIT_SECONDS = 60;
    /** Refusals that pass: the queue runs the job again from its saved progress. */
    private const RETRYABLE = ['storage_export_unavailable'];

    public function __construct(
        private StorageWorkbenchGateway $gateway,
        private StorageSectionScope $scopes,
        private StorageJobLedger $ledger,
        private StorageJobFiles $files,
        private StorageCsvExportRows $rows,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $job = self::payload($payload);
        if ($job === null) {
            return QueueJobResult::failure('storage_export_payload_invalid', false);
        }
        ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref] = $job;
        $entry = $this->ledger->entry($actor, $ref);
        if ($entry === null || $entry['kind'] !== 'export' || $entry['structure_id'] !== $structureId) {
            return QueueJobResult::failure('storage_export_job_unknown', false);
        }
        try {
            if (!hash_equals($this->scopes->scopeForActor($actor), $scope)) {
                return QueueJobResult::failure('storage_export_scope_changed', false);
            }
        } catch (RuntimeException) {
            return QueueJobResult::failure('storage_export_denied', false);
        }
        $query = ['surface' => 'storage_section', 'structure_id' => $structureId, 'filters' => $job['query']['filters'],
            'search' => $job['query']['search'] === '' ? null : $job['query']['search'], 'sort' => $job['query']['sort']];
        $progress = $this->ledger->progress($actor, $ref);
        if (($progress['phase'] ?? 'queued') === 'done') {
            return QueueJobResult::success(['rows' => self::int($progress['done'] ?? 0)]);
        }
        if (($progress['phase'] ?? 'queued') !== 'writing') {
            // How many records the list finds (exact under Storage's threshold, an estimate above it) and its columns.
            $state = $this->gateway->snapshot($actor, $scope, $query + ['per_page' => 1]);
            $refusal = self::refusal($state, $structureId);
            if ($refusal !== null) {
                return self::refused($refusal);
            }
            $fields = self::fields($state);
            $keys = StorageCsvExportRows::shownKeys($fields);
            $handle = $this->files->appendAt($ref, StorageJobFiles::EXPORT_PART, 0);
            fwrite($handle, StorageCsvExportRows::BOM.$this->rows->header($keys, $fields));
            fclose($handle);
            $progress = ['phase' => 'writing', 'columns' => $keys, 'continuation' => null, 'bytes' => $this->files->size($ref, StorageJobFiles::EXPORT_PART),
                'done' => 0, 'total' => max(0, self::int($state['records']['matched_count'] ?? 0)),
                'total_exact' => ($state['records']['matched_count_exact'] ?? true) === true];
            $this->ledger->saveProgress($actor, $ref, $progress);
            $context->heartbeat();
        }
        $keys = array_values(array_filter(is_array($progress['columns'] ?? null) ? $progress['columns'] : [], 'is_string'));
        while (true) {
            $continuation = is_string($progress['continuation'] ?? null) ? $progress['continuation'] : null;
            $state = $this->gateway->snapshot($actor, $scope, $query + ['per_page' => self::PAGE, 'count' => 'skip']
                + ($continuation === null ? [] : ['continuation' => $continuation]));
            $refusal = self::refusal($state, $structureId);
            if ($refusal !== null) {
                return self::refused($refusal);
            }
            $fields = self::fields($state);
            $handle = $this->files->appendAt($ref, StorageJobFiles::EXPORT_PART, self::int($progress['bytes'] ?? 0));
            $written = 0;
            foreach (is_array($state['records']['items'] ?? null) ? $state['records']['items'] : [] as $record) {
                if (is_array($record) && is_string($record['record_id'] ?? null)) {
                    fwrite($handle, $this->rows->row($record['record_id'], is_array($record['values'] ?? null) ? $record['values'] : [],
                        $keys, $fields, $job['locale'], $actor, $scope));
                    $written++;
                }
            }
            fflush($handle);
            fclose($handle);
            $next = $state['records']['continuation'] ?? null;
            $done = self::int($progress['done'] ?? 0) + $written;
            $progress = ['continuation' => is_string($next) ? $next : null, 'bytes' => $this->files->size($ref, StorageJobFiles::EXPORT_PART),
                'done' => $done, 'total' => max($done, self::int($progress['total'] ?? 0))] + $progress;
            if (!is_string($next)) {
                if (!$this->files->move($ref, StorageJobFiles::EXPORT_PART, StorageJobFiles::EXPORT)) {
                    return QueueJobResult::failure('storage_export_file_unwritable', true);
                }
                $progress = ['phase' => 'done', 'total' => $done, 'total_exact' => true] + $progress;
                $this->ledger->saveProgress($actor, $ref, $progress);
                break;
            }
            $this->ledger->saveProgress($actor, $ref, $progress);
            $context->heartbeat();
        }

        return QueueJobResult::success(['rows' => self::int($progress['done'] ?? 0)]);
    }

    /** A list not built yet is waited for; any other refusal ends the run (retried when it may pass). */
    private static function refused(string $refusal): QueueJobResult
    {
        return $refusal === self::VIEW_NOT_READY
            ? QueueJobResult::retryLater(self::WAIT_SECONDS, self::VIEW_NOT_READY)
            : QueueJobResult::failure($refusal, in_array($refusal, self::RETRYABLE, true));
    }

    /** @param array<string, mixed> $state */
    private static function refusal(array $state, string $structureId): ?string
    {
        if (($state['status'] ?? null) !== 'ok') {
            return match (true) {
                in_array($state['status'] ?? null, ['denied', 'rejected'], true) => 'storage_export_denied',
                ($state['reason_code'] ?? null) === StorageSectionGateway::VIEW_NOT_READY => self::VIEW_NOT_READY,
                default => 'storage_export_unavailable',
            };
        }

        return is_array($state['selected_structure'] ?? null) && ($state['selected_structure']['structure_id'] ?? null) === $structureId
            ? null : 'storage_export_denied';
    }

    /**
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>>
     */
    private static function fields(array $state): array
    {
        $fields = [];
        foreach (is_array($state['selected_structure']['fields'] ?? null) ? $state['selected_structure']['fields'] : [] as $field) {
            if (is_array($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{structure_id: string, actor: string, scope_ref: string, file_ref: string, locale: string,
     *     query: array{search: string, filters: array<string, mixed>, sort: list<mixed>}}|null
     */
    private static function payload(array $payload): ?array
    {
        $structureId = $payload['structure_id'] ?? null;
        $actor = $payload['actor'] ?? null;
        $scope = $payload['scope_ref'] ?? null;
        $ref = $payload['file_ref'] ?? null;
        $locale = $payload['locale'] ?? null;
        $query = $payload['query'] ?? null;
        if (count($payload) !== 6 || !is_string($structureId) || preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', $structureId) !== 1
            || !is_string($actor) || trim($actor) === '' || strlen($actor) > 191
            || !is_string($scope) || preg_match('/\Ascope:[a-z][a-z0-9_.:-]{0,184}\z/D', $scope) !== 1
            || !is_string($ref) || !StorageJobFiles::isRef($ref) || !is_string($locale) || preg_match('/\A[a-z]{2}(?:[_-][A-Za-z]{2})?\z/D', $locale) !== 1
            || !is_array($query) || !is_string($query['search'] ?? null)) {
            return null;
        }
        $filters = $query['filters'] ?? [];
        $sort = $query['sort'] ?? [];
        if (!is_array($filters) || ($filters !== [] && array_is_list($filters)) || !is_array($sort) || !array_is_list($sort)) {
            return null;
        }
        $kept = [];
        foreach ($filters as $key => $filter) {
            if (is_string($key)) {
                $kept[$key] = $filter;
            }
        }

        return ['structure_id' => $structureId, 'actor' => $actor, 'scope_ref' => $scope, 'file_ref' => $ref, 'locale' => $locale,
            'query' => ['search' => $query['search'], 'filters' => $kept, 'sort' => $sort]];
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
