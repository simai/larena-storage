<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use RuntimeException;

/**
 * A read contract refused to answer. The reason code is part of the frozen contract.
 */
final class ReadContractRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Read contract refused: ' . $reasonCode : $message);
    }
}
