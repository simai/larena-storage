<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

/**
 * What a block document needs to know about a logical file: which one, and whether
 * it may be attached.
 *
 * Deliberately smaller than a Filesystem inspection. Storage and Filesystem are
 * siblings in the dependency layers, so Storage cannot name a Filesystem type; the
 * composing application maps one onto the other.
 */
final readonly class BlockDocumentFileInspection
{
    public function __construct(
        public string $logicalFileRef,
        public bool $attachable,
    ) {
    }

    public function isAttachable(): bool
    {
        return $this->attachable;
    }
}
