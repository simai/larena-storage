<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Core\Scope\ScopeRef;
use Larena\Filesystem\Contracts\PortableLogicalFileStore;
use Larena\Filesystem\Exceptions\PortableLogicalFileFailed;
use Larena\Storage\SitePack\StorageSitePackFile;
use Larena\Storage\SitePack\StorageSitePackFiles;

/**
 * The files a storage's SitePack export carries, read from Filesystem's portable file store (larena-specs
 * docs/architecture/2026-10-22-storage-sitepack.md). A file goes into the pack only when core's scope resource
 * bindings say it belongs to the site being exported; a file of another site, a deleted one or one that never
 * existed is answered as absent. Any other failure to read it is Filesystem's, and it is thrown.
 */
final readonly class FilesystemStorageSitePackFiles implements StorageSitePackFiles
{
    public const string FILE = 'filesystem.logical_file';

    public function __construct(
        private PortableLogicalFileStore $files,
        private ScopeResourceBindings $resources,
    ) {
    }

    public function available(): bool
    {
        return true;
    }

    public function snapshot(string $siteRef, string $logicalRef): ?StorageSitePackFile
    {
        $site = ScopeRef::tryParse($siteRef);
        if ($site === null || !$this->resources->belongsTo(self::FILE, $logicalRef, $site)) {
            return null;
        }
        try {
            $snapshot = $this->files->snapshot($logicalRef);
        } catch (PortableLogicalFileFailed $exception) {
            if ($exception->reasonCode === 'filesystem_portable_file_not_found') {
                return null;
            }
            throw $exception;
        }

        return new StorageSitePackFile($snapshot->descriptor->toArray(), static fn (): mixed => $snapshot->openStream());
    }
}
