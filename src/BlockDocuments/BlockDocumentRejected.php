<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

use DomainException;
use Throwable;

/**
 * A block document operation was refused.
 *
 * The reason codes keep their content_document_* spelling. They moved here from
 * larena/content with the code, and callers that match on them keep working —
 * renaming a reason code is a contract change, not a refactor.
 */
class BlockDocumentRejected extends DomainException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message = 'The block document operation was rejected.',
        ?Throwable $previous = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $reasonCode) !== 1) {
            throw new \InvalidArgumentException('Block document rejection reason codes must be stable lowercase identifiers.');
        }

        parent::__construct($message, 0, $previous);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
