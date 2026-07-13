<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use RuntimeException;

final class StorageOwnedTableShapeRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $tableKey = 'topology',
    ) {
        parent::__construct($reasonCode);
    }
}
