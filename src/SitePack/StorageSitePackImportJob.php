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
 * The two queue jobs of a storage SitePack import (DatabaseStorageSitePack::process):
 *
 * - `storage.sitepack.plan`: reads the whole pack and writes the plan and the run's id map, nothing else;
 * - `storage.sitepack.import`: writes the plan to the site, phase by phase.
 *
 * Payload: `{"run_ref": "storage-sitepack-<32 hex>"}`. Like the export job: resumable (the position is saved with every
 * chunk), heartbeats between chunks, idempotent (a run that ended, or that is not this job's, is not run), and a
 * retryable failure is retried by the queue. A refused access, a broken pack or a stale plan fails for good; an import
 * that fails for good discards what it wrote.
 */
final readonly class StorageSitePackImportJob implements QueueJobHandler
{
    public const string PLAN_JOB_TYPE = 'storage.sitepack.plan';
    public const string IMPORT_JOB_TYPE = 'storage.sitepack.import';
    public const string OPERATION_REF = 'storage.sitepack.import';
    public const string PLAN_HANDLER_REF = 'storage.sitepack.plan.handler';
    public const string IMPORT_HANDLER_REF = 'storage.sitepack.import.handler';
    public const string PAYLOAD_SCHEMA_REF = 'larena.storage.sitepack.import.payload';
    public const int TIMEOUT_SECONDS = 1800;
    public const int MAX_ATTEMPTS = 50;
    public const int RETRY_DELAY_SECONDS = 15;
    public const int LEASE_SECONDS = 300;

    public function __construct(private DatabaseStorageSitePack $sitePack, private string $jobType)
    {
        if (!in_array($jobType, [self::PLAN_JOB_TYPE, self::IMPORT_JOB_TYPE], true)) {
            throw new StorageRejected('storage_sitepack_job_type_invalid');
        }
    }

    public static function descriptor(string $jobType): ImmutableJobDescriptor
    {
        return new ImmutableJobDescriptor(
            $jobType,
            self::OPERATION_REF,
            $jobType === self::PLAN_JOB_TYPE ? self::PLAN_HANDLER_REF : self::IMPORT_HANDLER_REF,
            self::TIMEOUT_SECONDS,
            self::MAX_ATTEMPTS,
            self::RETRY_DELAY_SECONDS,
            self::LEASE_SECONDS,
            QueuePriority::Maintenance,
            $jobType === self::PLAN_JOB_TYPE ? 'storage_sitepack_plan' : 'storage_sitepack_import',
            self::PAYLOAD_SCHEMA_REF,
            [RuntimeProfile::LaravelWorker, RuntimeProfile::LarenaDispatcher, RuntimeProfile::ArtisanTick],
        );
    }

    /** The dispatch request for one run; each step of the run (its generation) is a new job. */
    public static function request(string $jobType, string $runRef, int $generation = 0): DispatchRequest
    {
        $fingerprint = hash('sha256', json_encode([$jobType, $runRef, $generation], JSON_THROW_ON_ERROR));

        return new DispatchRequest(
            $jobType,
            ['run_ref' => $runRef],
            $jobType . ':' . $fingerprint,
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
            $run = $this->sitePack->process($runRef, static fn () => $context->heartbeat(), $this->jobType);
        } catch (StorageRejected $exception) {
            if ($exception->reasonCode === DatabaseStorageSitePack::REASON_INTERRUPTED) {
                return QueueJobResult::success(['state' => 'taken_over']);
            }

            return QueueJobResult::failure(
                preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $exception->reasonCode) === 1 ? $exception->reasonCode : 'storage_sitepack_failed',
                in_array($exception->reasonCode, DatabaseStorageSitePack::RETRYABLE, true),
            );
        }
        if (in_array($run->state, [StorageSitePackRun::STATE_QUEUED, StorageSitePackRun::STATE_RUNNING], true)) {
            // The run waits for the other job of the import (planned, not yet applied, or the reverse).
            return QueueJobResult::success(['state' => 'not_this_job']);
        }
        if ($run->state === StorageSitePackRun::STATE_FAILED) {
            return QueueJobResult::failure('storage_sitepack_failed', false);
        }

        return QueueJobResult::success(['state' => $run->state, 'phase' => $run->phase]);
    }
}
