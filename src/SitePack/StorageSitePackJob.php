<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Enums\RuntimeProfile;
use Larena\Queue\Runtime\ImmutableJobDescriptor;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The `storage.sitepack.export` queue job: runs one storage SitePack export (DatabaseStorageSitePack::process).
 *
 * Payload: `{"run_ref": "storage-sitepack-<32 hex>"}`.
 *
 * - Resumable: the position is saved with every chunk; a retry, or a new job for the same run, continues there.
 * - Between chunks the job heartbeats its lease and checks the queue checkpoint, so a queue cancel or a timeout stops
 *   it after a saved chunk (the run is left failed with `storage_sitepack_interrupted` and resumes on the retry).
 * - Idempotent: a run that ended (done or cancelled) is not run again.
 * - The structure changing under the export fails it retryably (`storage_sitepack_structure_changed`); the retry
 *   starts over on the new structure. A file value without a file owner composed, a SitePack limit or a refused
 *   access fails it for good.
 */
final readonly class StorageSitePackJob implements QueueJobHandler
{
    public const string JOB_TYPE = 'storage.sitepack.export';
    public const string OPERATION_REF = 'storage.sitepack.export';
    public const string HANDLER_REF = 'storage.sitepack.export.handler';
    public const string PAYLOAD_SCHEMA_REF = 'larena.storage.sitepack.export.payload';
    public const int TIMEOUT_SECONDS = 1800;
    public const int MAX_ATTEMPTS = 50;
    public const int RETRY_DELAY_SECONDS = 15;
    public const int LEASE_SECONDS = 300;

    public function __construct(private DatabaseStorageSitePack $sitePack)
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
            'storage_sitepack_export',
            self::PAYLOAD_SCHEMA_REF,
            [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher, RuntimeProfile::ArtisanTick],
        );
    }

    /** The dispatch request for one run; each retry of the run (its generation) is a new job. */
    public static function request(string $runRef, int $generation = 0): DispatchRequest
    {
        $fingerprint = hash('sha256', json_encode([$runRef, $generation], JSON_THROW_ON_ERROR));

        return new DispatchRequest(
            self::JOB_TYPE,
            ['run_ref' => $runRef],
            self::JOB_TYPE . ':' . $fingerprint,
            'storage-sitepack-' . substr($fingerprint, 0, 32),
        );
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $runRef = $payload['run_ref'] ?? null;
        if (!is_string($runRef) || preg_match('/\Astorage-sitepack-[0-9a-f]{32}\z/D', $runRef) !== 1
            || array_keys($payload) !== ['run_ref']) {
            return QueueJobResult::failure('storage_sitepack_payload_invalid', false);
        }
        $context->checkpoint();
        try {
            $run = $this->sitePack->process($runRef, static fn () => $context->heartbeat(), self::JOB_TYPE);
        } catch (StorageRejected $exception) {
            if ($exception->reasonCode === DatabaseStorageSitePack::REASON_INTERRUPTED) {
                // Cancelled under the job, or taken over by another process, which finishes it.
                return QueueJobResult::success(['state' => 'taken_over']);
            }
            // storage_sitepack_run_busy (another process holds the run; it may have died) is retried until its
            // lease goes stale, like the other retryable reasons.

            return QueueJobResult::failure(
                self::reason($exception->reasonCode),
                in_array($exception->reasonCode, DatabaseStorageSitePack::RETRYABLE, true),
            );
        }
        if ($run->state !== StorageSitePackRun::STATE_DONE && $run->state !== StorageSitePackRun::STATE_CANCELLED) {
            return QueueJobResult::failure('storage_sitepack_failed', false);
        }

        return QueueJobResult::success(['state' => $run->state, 'records' => $run->done]);
    }

    private static function reason(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'storage_sitepack_failed';
    }
}
