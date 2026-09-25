<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

/**
 * The default when nothing that owns files is composed: every file is
 * unavailable. An image block then fails closed with content_block_file_unavailable
 * rather than being accepted against a file nobody checked.
 */
final readonly class UnavailableBlockDocumentFileInspector implements BlockDocumentFileInspector
{
    public function inspect(string $logicalFileRef): BlockDocumentFileInspection
    {
        return new BlockDocumentFileInspection($logicalFileRef, false);
    }
}
