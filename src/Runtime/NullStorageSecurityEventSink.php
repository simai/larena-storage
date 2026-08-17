<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;

final readonly class NullStorageSecurityEventSink implements StorageSecurityEventSink
{
    public function emit(StorageSecurityEvent $event): void
    {
    }
}
