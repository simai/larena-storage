<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

/**
 * The port a SitePack export reads files through. Storage holds only logical file refs (file@1 values); the
 * application that composes Storage with Filesystem implements this port over its portable file store, checking that
 * the file belongs to the site being exported (core's scope resource bindings).
 *
 * Without an owner of files composed, UnavailableStorageSitePackFiles answers: an export of a storage that has file
 * values then fails with `storage_sitepack_files_unavailable`; one without any exports as usual.
 */
interface StorageSitePackFiles
{
    /** Whether files can be read at all. */
    public function available(): bool;

    /**
     * The current content of one live file of the site being exported (the run's site ref, e.g. `site:main`), or null
     * when there is no such file there (deleted, never existed, or of another site). A failure to read throws.
     */
    public function snapshot(string $siteRef, string $logicalRef): ?StorageSitePackFile;
}
