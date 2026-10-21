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
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\Site\PublishedSiteViewRegistration;
use Larena\Storage\View\Site\PublishedSiteViewSlot;
use Closure;

/**
 * The `storage.view.rebuild` queue job: builds one storage's витрина from its
 * versions in chunks, through the same StorageViewRebuilder the command uses.
 *
 * Payload: `{"schema_id": "<storage schema id>", "chunk": 500, "view": "site"}`
 * (chunk optional, 1 to StorageViewRebuilder::MAX_CHUNK; view optional, `admin` by
 * default, so a payload written before the site витрина is still valid). With
 * `view: site` it builds the storage's site витрина (PublishedSiteViewRebuilder),
 * slot by slot, with the same resume, checkpoint and take-over rules.
 *
 * - Resumable: the position is saved in the registry with every chunk, so a job
 *   that timed out and is retried, or a new job for the same storage, continues
 *   where the build stopped.
 * - Between chunks the job heartbeats its lease and checks the queue checkpoint,
 *   so cancel and timeout stop it after a committed chunk.
 * - Idempotent: a retry that finds the витрина ready (an earlier attempt
 *   finished) does nothing; the витрина is marked ready only at the end.
 * - A ready витрина that a change of field options left rows to fill is
 *   backfilled in place, resumably, and stays ready meanwhile.
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
    public const string VIEW_ADMIN = 'admin';
    public const string VIEW_SITE = 'site';

    /** @param (Closure(): PublishedSiteViewIndex)|null $site The site index, made when a site job runs. */
    public function __construct(
        private StorageViewSync $views,
        private StorageMetrics $metrics = new StorageMetrics(),
        private ?Closure $site = null,
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

    /**
     * The dispatch request for one storage's site витрина: the same idempotency rule,
     * following its registration and slots.
     *
     * @param list<PublishedSiteViewSlot> $slots
     */
    public static function siteRequest(string $schemaId, int $chunk, ?PublishedSiteViewRegistration $registration, array $slots, DateTimeImmutable $now): DispatchRequest
    {
        $payload = ['schema_id' => $schemaId, 'chunk' => $chunk, 'view' => self::VIEW_SITE];
        $fingerprint = hash('sha256', json_encode([
            self::VIEW_SITE,
            $schemaId,
            $chunk,
            $now->setTimezone(new DateTimeZone('UTC'))->format('YmdHi'),
            $registration?->state,
            $registration?->layout->hash,
            $registration?->publicFieldsHash,
            array_map(static fn (PublishedSiteViewSlot $slot): array => [$slot->id, $slot->state, $slot->localeChain, $slot->buildCursor, $slot->buildRunRef], $slots),
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
        $view = $payload['view'] ?? self::VIEW_ADMIN;
        if (!is_string($schemaId) || $schemaId === '' || strlen($schemaId) > 191
            || !is_int($chunk) || $chunk < 1 || $chunk > StorageViewRebuilder::MAX_CHUNK
            || !in_array($view, [self::VIEW_ADMIN, self::VIEW_SITE], true)
            || array_diff(array_keys($payload), ['schema_id', 'chunk', 'view']) !== []) {
            return QueueJobResult::failure('storage_view_rebuild_payload_invalid', false);
        }
        if ($view === self::VIEW_SITE) {
            return $this->handleSite($context, $schemaId, $chunk);
        }
        if (!$this->views->enabled()) {
            return QueueJobResult::failure('storage_view_unavailable', false);
        }
        $context->checkpoint();
        $registration = $this->views->registry()->find($schemaId, true);
        if ($context->attempt > 1 && $registration?->state === StorageViewRegistration::STATE_READY && !$registration->backfillPending()) {
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

    private function handleSite(QueueExecutionContext $context, string $schemaId, int $chunk): QueueJobResult
    {
        $site = $this->site === null ? null : ($this->site)();
        if ($site === null || !$site->enabled()) {
            return QueueJobResult::failure('storage_view_unavailable', false);
        }
        $context->checkpoint();
        if ($context->attempt > 1 && $this->siteReady($site, $schemaId)) {
            // An earlier attempt finished the build.
            return QueueJobResult::success(['state' => PublishedSiteViewSlot::STATE_READY, 'records' => 0, 'resumed' => false]);
        }

        try {
            $result = (new PublishedSiteViewRebuilder($site, $this->metrics))->rebuild(
                $schemaId,
                min($chunk, PublishedSiteViewRebuilder::MAX_CHUNK),
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

    /** The storage's site витрина is ready, for its current public fields, with every slot answering. */
    private function siteReady(PublishedSiteViewIndex $site, string $schemaId): bool
    {
        $registration = $site->registry()->find($schemaId, true);
        if ($registration === null || !$registration->ready() || $site->currentHash($schemaId) !== $registration->publicFieldsHash) {
            return false;
        }
        foreach ($site->registry()->slots($schemaId) as $slot) {
            if (!$slot->answers($site->readContracts()->localeChainKey($slot->locale))) {
                return false;
            }
        }

        return true;
    }

    private static function reason(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'storage_view_rebuild_failed';
    }
}
