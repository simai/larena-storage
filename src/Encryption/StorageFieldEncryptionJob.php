<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;

/**
 * The `storage.field.encryption.rewrite` queue job: runs one encryption run of a workbench structure
 * (DatabaseStorageWorkbench::runFieldEncryption, StorageFieldEncryptionRunner): every record version and translation
 * of the storage, in chunks, sealed, opened or sealed again under the new key.
 *
 * Payload: `{"run_ref": "storage-encryption-run-<32 hex>", "chunk": 500}` (chunk optional, 1 to
 * StorageFieldEncryptionRunner::MAX_CHUNK).
 *
 * - Resumable: the position is saved with every chunk; a retry, or a new job for the same run, continues there.
 * - Between chunks the job heartbeats its lease and checks the queue checkpoint, so a queue cancel or a timeout
 *   stops it after a committed chunk (the run stays running and a new job resumes it).
 * - Idempotent: a run that ended (done, failed) is not run again; a failed run runs again after its retry.
 * - A row that cannot be rewritten fails the run (and the job, without a retry).
 * - A newer walk that took the run over ends this one as a success (`taken_over`).
 */
final readonly class StorageFieldEncryptionJob implements QueueJobHandler
{
    public const string JOB_TYPE = 'storage.field.encryption.rewrite';
    public const string OPERATION_REF = 'storage.field.encryption.rewrite';
    public const string HANDLER_REF = 'storage.field.encryption.rewrite.handler';
    public const string PAYLOAD_SCHEMA_REF = 'larena.storage.field.encryption.rewrite.payload';
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
            'storage_field_encryption_rewrite',
            self::PAYLOAD_SCHEMA_REF,
            [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher, RuntimeProfile::ArtisanTick],
        );
    }

    /**
     * The dispatch request for one run. A retried run is a new request (its retry moved its updated time), so the
     * queue takes it again; asking twice for the same state gives the same job.
     */
    public static function request(string $runRef, int $chunk = StorageFieldEncryptionRunner::DEFAULT_CHUNK, ?string $stamp = null): DispatchRequest
    {
        $fingerprint = hash('sha256', json_encode([$runRef, $chunk, $stamp], JSON_THROW_ON_ERROR));

        return new DispatchRequest(
            self::JOB_TYPE,
            ['run_ref' => $runRef, 'chunk' => $chunk],
            self::JOB_TYPE . ':' . $fingerprint,
            'storage-field-encryption-' . substr($fingerprint, 0, 32),
        );
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $runRef = $payload['run_ref'] ?? null;
        $chunk = $payload['chunk'] ?? StorageFieldEncryptionRunner::DEFAULT_CHUNK;
        if (!is_string($runRef) || preg_match(StorageFieldEncryptionRunRow::RUN_REF_PATTERN, $runRef) !== 1
            || !is_int($chunk) || $chunk < 1 || $chunk > StorageFieldEncryptionRunner::MAX_CHUNK
            || array_diff(array_keys($payload), ['run_ref', 'chunk']) !== []) {
            return QueueJobResult::failure('storage_field_encryption_payload_invalid', false);
        }
        $context->checkpoint();
        try {
            $result = $this->workbench->runFieldEncryption($runRef, $chunk, static fn () => $context->heartbeat());
        } catch (StorageConflict $exception) {
            if ($exception->reasonCode === StorageFieldEncryptionRunner::REASON_INTERRUPTED) {
                // Another walk took the run over (or the structure was purged) and finishes it.
                return QueueJobResult::success(['state' => 'taken_over', 'rewritten' => 0, 'skipped' => 0, 'resumed' => false]);
            }

            return QueueJobResult::failure(self::reason($exception->reasonCode), true);
        } catch (StorageRejected $exception) {
            return QueueJobResult::failure(self::reason($exception->reasonCode), false);
        }
        if ($result['state'] === StorageWorkbenchEncryptionRun::STATE_FAILED) {
            return QueueJobResult::failure('storage_field_encryption_failed', false);
        }

        return QueueJobResult::success($result);
    }

    private static function reason(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'storage_field_encryption_failed';
    }
}
