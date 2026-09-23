<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use RuntimeException;

/**
 * A localized value boundary refused an operation. The reason code is part of the
 * frozen contract; the message is not.
 */
final class LocalizedValueRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Localized value boundary refused: ' . $reasonCode : $message);
    }
}
