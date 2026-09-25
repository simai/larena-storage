<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

use Larena\Storage\Contracts\StorageRecordVersionRef;

final readonly class BlockDocumentRevision
{
    /** @param array<string, mixed> $document */
    public function __construct(
        public string $documentId,
        public array $document,
        public string $semanticHash,
        public StorageRecordVersionRef $storageRef,
    ) {
    }
}
