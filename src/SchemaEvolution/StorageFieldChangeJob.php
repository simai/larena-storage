<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Storage\Contracts\StorageWorkbenchFieldChange;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;

/**
 * The `storage.schema.migrate` queue job: runs one field change of a workbench
 * structure (DatabaseStorageWorkbench::runFieldChange): every current record is
 * checked against the target fields in chunks, the витрина's new columns are
 * filled, and the structure switches in one transaction at the end. No record
 * version is rewritten.
 *
 * Payload: `{"change_ref": "storage-field-change-<32 hex>", "chunk": 500}` (chunk
 * optional, 1 to StorageFieldChangeRunner::MAX_CHUNK).
 *
 * - Resumable: the position is saved with every chunk; a retry, or a new job for the
 *   same change, continues where the walk stopped.
 * - Between chunks the job heartbeats its lease and checks the queue checkpoint, so a
 *   queue cancel or a timeout stops it after a committed chunk (the change stays
 *   running and a new job resumes it; DatabaseStorageWorkbench::cancelFieldChange
 *   cancels the change itself).
 * - Idempotent: a change that ended (switched, failed or cancelled) is not run again.
 * - A record that does not convert fails the change (and the job, without a retry);
 *   nothing switched.
 * - A newer run that took the change over ends this one as a success (`taken_over`).
 */
final readonly class StorageFieldChangeJob implements QueueJobHandler
{
    public const string JOB_TYPE = 'storage.schema.migrate';
    public const string OPERATION_REF = 'storage.schema.migrate';
    public const string HANDLER_REF = 'storage.schema.migrate.handler';
    public const string PAYLOAD_SCHEMA_REF = 'larena.storage.schema.migrate.payload';
    public const int TIMEOUT_SECONDS = 1800;
    public const int MAX_ATTEMPTS = 50;
    public const int RETRY_DELAY_SECONDS = 15;
    public const int LEASE_SECONDS = 300;

    public function __construct(private DatabaseStorageWorkbench $workbench)
    {
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
            'storage_schema_migrate',
            self::PAYLOAD_SCHEMA_REF,
            [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher, RuntimeProfile::ArtisanTick],
        );
    }

    /**
     * The dispatch request for one change. The idempotency key follows the change's
     * position: asking again while nothing moved gives the same job; once the walk moved
     * on (a job was cancelled or gave up after some chunks), a new request is a new job,
     * which resumes the walk.
     */
    public static function request(string $changeRef, int $chunk = StorageFieldChangeRunner::DEFAULT_CHUNK, ?string $cursor = null): DispatchRequest
    {
        $fingerprint = hash('sha256', json_encode([$changeRef, $chunk, $cursor], JSON_THROW_ON_ERROR));

        return new DispatchRequest(
            self::JOB_TYPE,
            ['change_ref' => $changeRef, 'chunk' => $chunk],
            self::JOB_TYPE . ':' . $fingerprint,
            'storage-field-change-' . substr($fingerprint, 0, 32),
        );
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $changeRef = $payload['change_ref'] ?? null;
        $chunk = $payload['chunk'] ?? StorageFieldChangeRunner::DEFAULT_CHUNK;
        if (!is_string($changeRef) || preg_match('/\Astorage-field-change-[0-9a-f]{32}\z/D', $changeRef) !== 1
            || !is_int($chunk) || $chunk < 1 || $chunk > StorageFieldChangeRunner::MAX_CHUNK
            || array_diff(array_keys($payload), ['change_ref', 'chunk']) !== []) {
            return QueueJobResult::failure('storage_field_change_payload_invalid', false);
        }
        $context->checkpoint();
        try {
            $result = $this->workbench->runFieldChange($changeRef, $chunk, static fn () => $context->heartbeat());
        } catch (StorageConflict $exception) {
            if ($exception->reasonCode === 'storage_field_change_interrupted') {
                // Another run took the change over (or it was cancelled) and finishes it.
                return QueueJobResult::success(['state' => 'taken_over', 'checked' => 0, 'resumed' => false]);
            }

            return QueueJobResult::failure(self::reason($exception->reasonCode), true);
        } catch (StorageRejected $exception) {
            if ($exception->reasonCode === 'storage_field_change_cancelled') {
                return QueueJobResult::success(['state' => StorageWorkbenchFieldChange::STATE_CANCELLED, 'checked' => 0, 'resumed' => false]);
            }

            return QueueJobResult::failure(self::reason($exception->reasonCode), false);
        }
        if ($result['state'] === StorageWorkbenchFieldChange::STATE_FAILED) {
            return QueueJobResult::failure('storage_field_change_failed', false);
        }

        return QueueJobResult::success($result);
    }

    private static function reason(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'storage_field_change_failed';
    }
}
