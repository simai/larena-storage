<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Storage\Exceptions\StorageRejected;

/** Puts `storage.view.rebuild` jobs on the durable queue, one per storage (and one per storage's site витрина). */
final readonly class StorageViewRebuildQueue
{
    public function __construct(
        private StorageViewSync $views,
        private JobTypeRegistry $jobs,
        private DurableQueueDispatcher $dispatcher,
        private QueueClock $clock,
    ) {
    }

    public function enqueue(string $schemaId, int $chunk = StorageViewRebuildJob::DEFAULT_CHUNK): DispatchResult
    {
        if (!$this->jobs->has(StorageViewRebuildJob::JOB_TYPE)) {
            throw new StorageRejected('storage_view_rebuild_job_unregistered');
        }
        if ($chunk < 1 || $chunk > StorageViewRebuilder::MAX_CHUNK) {
            throw new StorageRejected('storage_view_rebuild_chunk_invalid');
        }

        return $this->dispatcher->dispatch(
            StorageViewRebuildJob::request($schemaId, $chunk, $this->views->registry()->find($schemaId, true), $this->clock->now()),
        );
    }

    /** A `storage.view.rebuild` job for one storage's site витрина (`view: site`). */
    public function enqueueSite(string $schemaId, int $chunk = StorageViewRebuildJob::DEFAULT_CHUNK): DispatchResult
    {
        if (!$this->jobs->has(StorageViewRebuildJob::JOB_TYPE)) {
            throw new StorageRejected('storage_view_rebuild_job_unregistered');
        }
        if ($chunk < 1 || $chunk > StorageViewRebuilder::MAX_CHUNK) {
            throw new StorageRejected('storage_view_rebuild_chunk_invalid');
        }
        $registry = $this->views->siteRegistry();

        return $this->dispatcher->dispatch(
            StorageViewRebuildJob::siteRequest($schemaId, $chunk, $registry->find($schemaId, true), $registry->slots($schemaId), $this->clock->now()),
        );
    }
}
