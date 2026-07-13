<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use Throwable;

final class StoragePersistenceFailed extends StorageRejected
{
    public static function from(Throwable $exception): self
    {
        return new self('storage_persistence_failed');
    }
}
