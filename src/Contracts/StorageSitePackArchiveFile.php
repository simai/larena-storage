<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * The sealed archive of a finished export, for its download: a private file on the server, its size and SHA-256, the
 * SitePack package id, and the file name to offer (`<structure key>-<date>.sitepack.zip`).
 */
final readonly class StorageSitePackArchiveFile
{
    public function __construct(
        public string $runRef,
        public string $path,
        public int $sizeBytes,
        public string $sha256,
        public string $packageId,
        public string $downloadName,
    ) {
    }
}
