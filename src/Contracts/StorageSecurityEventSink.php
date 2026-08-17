<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageSecurityEventSink
{
    public function emit(StorageSecurityEvent $event): void;
}
