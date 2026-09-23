<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

/**
 * Read-only port to whatever owns logical files.
 *
 * Storage never uploads, mutates, deletes or delivers a file. The application that
 * composes Storage with Filesystem implements this port.
 */
interface BlockDocumentFileInspector
{
    public function inspect(string $logicalFileRef): BlockDocumentFileInspection;
}
