<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

/** The default when nothing that owns files is composed: no file can be exported. */
final readonly class UnavailableStorageSitePackFiles implements StorageSitePackFiles
{
    public function available(): bool
    {
        return false;
    }

    public function snapshot(string $siteRef, string $logicalRef): ?StorageSitePackFile
    {
        return null;
    }
}
