<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use RuntimeException;

/**
 * A relation boundary refused an operation. The reason code is part of the frozen
 * contract; the message is not.
 */
final class RelationRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Relation boundary refused: ' . $reasonCode : $message);
    }
}
