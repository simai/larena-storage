<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use RuntimeException;

class StorageRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
