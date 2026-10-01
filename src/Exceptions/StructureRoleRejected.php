<?php

declare(strict_types=1);

namespace Larena\Storage\Exceptions;

use RuntimeException;

/**
 * A structure role boundary refused an operation.
 *
 * The reason code is part of the frozen contract: callers and tests match on it,
 * so it is stable in a way a message never is.
 */
final class StructureRoleRejected extends RuntimeException
{
    /**
     * @param array<string, mixed> $diagnostic a structured account of the refusal, such as
     *                                         the conformance report of a refused bind
     */
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
        public readonly array $diagnostic = [],
    ) {
        parent::__construct($message === '' ? 'Structure role boundary refused: ' . $reasonCode : $message);
    }
}
