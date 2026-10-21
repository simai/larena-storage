<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Larena\Storage\Exceptions\StorageRejected;

/** The default when nothing that owns files is composed: no file can be exported or imported. */
final readonly class UnavailableStorageSitePackFiles implements StorageSitePackFiles, StorageSitePackFileImports
{
    public function available(): bool
    {
        return false;
    }

    public function snapshot(string $siteRef, string $logicalRef): ?StorageSitePackFile
    {
        return null;
    }

    public function importAvailable(): bool
    {
        return false;
    }

    public function belongsToSite(string $siteRef, string $logicalRef): bool
    {
        return false;
    }

    public function contentMatches(string $siteRef, string $sha256, int $sizeBytes, string $extension): array
    {
        return [];
    }

    public function logicalRefTaken(string $logicalRef): bool
    {
        return false;
    }

    public function publicIdTaken(string $publicId): bool
    {
        return false;
    }

    public function import(string $siteRef, StorageSitePackFile $file): string
    {
        throw new StorageRejected('storage_sitepack_files_unavailable');
    }
}
