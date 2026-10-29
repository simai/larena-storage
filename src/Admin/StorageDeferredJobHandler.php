<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Closure;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;

/**
 * A job handler made only when the queue runs its job: registering the Storage section's job types on every
 * request must not build the import and export with everything they read and write.
 */
final readonly class StorageDeferredJobHandler implements QueueJobHandler
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
