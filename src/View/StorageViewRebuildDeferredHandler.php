<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Closure;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;

/**
 * Registers a Storage queue job (storage.view.rebuild, storage.schema.migrate) without building its handler: the
 * handler (and the database connection it holds) is made only when a worker runs the job, so registering at boot
 * binds no connection early.
 */
final readonly class StorageViewRebuildDeferredHandler implements QueueJobHandler
{
    /** @param Closure(): QueueJobHandler $make */
    public function __construct(private Closure $make)
    {
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        return ($this->make)()->handle($context, $payload);
    }
}
