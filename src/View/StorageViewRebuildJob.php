<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\StorageMetrics;

/**
 * The `storage.view.rebuild` queue job: builds one storage's витрина from its
 * versions in chunks, through the same StorageViewRebuilder the command uses.
 *
 * Payload: `{"schema_id": "<storage schema id>", "chunk": 500}` (chunk optional,
 * 1 to StorageViewRebuilder::MAX_CHUNK).
 *
 * - Resumable: the position is saved in the registry with every chunk, so a job
 *   that timed out and is retried, or a new job for the same storage, continues
 *   where the build stopped.
 * - Between chunks the job heartbeats its lease and checks the queue checkpoint,
 *   so cancel and timeout stop it after a committed chunk.
 * - Idempotent: a retry that finds the витрина ready (an earlier attempt
 *   finished) does nothing; the витрина is marked ready only at the end.
 * - A newer build that took the storage over ends this one as a success
 *   (`taken_over`): the other run finishes it.
 */
final readonly class StorageViewRebuildJob implements QueueJobHandler
{
    public const string JOB_TYPE = 'storage.view.rebuild';
    public const string OPERATION_REF = 'storage.view.rebuild';
    public const string HANDLER_REF = 'storage.view.rebuild.handler';
    public const string PAYLOAD_SCHEMA_REF = 'larena.storage.view.rebuild.payload';
    public const int DEFAULT_CHUNK = 500;
    public const int TIMEOUT_SECONDS = 1800;
    public const int MAX_ATTEMPTS = 50;
    public const int RETRY_DELAY_SECONDS = 15;
    public const int LEASE_SECONDS = 300;

    public function __construct(
        private StorageViewSync $views,
        private StorageMetrics $metrics = new StorageMetrics(),
    ) {
    }

    public static function descriptor(): ImmutableJobDescriptor
    {
        return new ImmutableJobDescriptor(
            self::JOB_TYPE,
            self::OPERATION_REF,
            self::HANDLER_REF,
            self::TIMEOUT_SECONDS,
            self::MAX_ATTEMPTS,
            self::RETRY_DELAY_SECONDS,
            self::LEASE_SECONDS,
            QueuePriority::Maintenance,
            'storage_view_rebuild',
            self::PAYLOAD_SCHEMA_REF,
            [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher, RuntimeProfile::ArtisanTick],
        );
    }

    /**
     * The dispatch request for one storage. The idempotency key follows the
     * storage's registry row and the minute of the request: asking again in the
     * same minute while nothing has changed (a deploy step run twice) gives the
     * same job; once a build moved the row on, or a minute later, a new request
     * is a new job, which resumes the build.
     */
    public static function request(string $schemaId, int $chunk, ?StorageViewRegistration $registration, DateTimeImmutable $now): DispatchRequest
    {
        $payload = ['schema_id' => $schemaId, 'chunk' => $chunk];
        $fingerprint = hash('sha256', json_encode([
            $schemaId,
            $chunk,
            $now->setTimezone(new DateTimeZone('UTC'))->format('YmdHi'),
            $registration?->state,
            $registration?->layout->hash,
            $registration?->builtSchemaVersion,
            $registration?->buildCursor,
            $registration?->buildRunRef,
        ], JSON_THROW_ON_ERROR));

        return new DispatchRequest(
            self::JOB_TYPE,
            $payload,
            self::JOB_TYPE . ':' . $fingerprint,
            'storage-view-rebuild-' . substr($fingerprint, 0, 32),
        );
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $schemaId = $payload['schema_id'] ?? null;
        $chunk = $payload['chunk'] ?? self::DEFAULT_CHUNK;
        if (!is_string($schemaId) || $schemaId === '' || strlen($schemaId) > 191
            || !is_int($chunk) || $chunk < 1 || $chunk > StorageViewRebuilder::MAX_CHUNK
            || array_diff(array_keys($payload), ['schema_id', 'chunk']) !== []) {
            return QueueJobResult::failure('storage_view_rebuild_payload_invalid', false);
        }
        if (!$this->views->enabled()) {
            return QueueJobResult::failure('storage_view_unavailable', false);
        }
        $context->checkpoint();
        $registration = $this->views->registry()->find($schemaId, true);
        if ($context->attempt > 1 && $registration?->state === StorageViewRegistration::STATE_READY) {
            // An earlier attempt finished the build.
            return QueueJobResult::success(['state' => StorageViewRegistration::STATE_READY, 'records' => 0, 'resumed' => false]);
        }

        try {
            $result = (new StorageViewRebuilder($this->views, $this->metrics))->rebuild(
                $schemaId,
                $chunk,
                false,
                null,
                static fn () => $context->heartbeat(),
            );
        } catch (StorageConflict $exception) {
            if ($exception->reasonCode === 'storage_view_rebuild_interrupted') {
                // Another build took the storage over and finishes it.
                return QueueJobResult::success(['state' => 'taken_over', 'records' => 0, 'resumed' => false]);
            }

            return QueueJobResult::failure(self::reason($exception->reasonCode), true);
        } catch (StorageRejected $exception) {
            return QueueJobResult::failure(self::reason($exception->reasonCode), false);
        }

        return QueueJobResult::success([
            'state' => $result['state'],
            'records' => $result['records'],
            'resumed' => $result['resumed'],
        ]);
    }

    private static function reason(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'storage_view_rebuild_failed';
    }
}
